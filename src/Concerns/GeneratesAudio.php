<?php

namespace smp_text_to_speech\Concerns;

use Hexa\PluginCore\CoreBootstrap\CoreBootstrap;
use Hexa\PluginCore\CorePackageUpdates\CorePackageAjaxController;
use Hexa\PluginCore\CorePackageUpdates\CorePackageStatus;
use Hexa\PluginCore\CoreRuntime\PluginContext;
use Hexa\PluginCore\PluginUpdates\GitHubPluginUpdater;
use Hexa\PluginCore\PluginUpdates\PluginUpdateStatus;
use Hexa\PluginCore\PluginUpdates\UpdaterAjaxController;
use Hexa\PluginCore\WpAdminTabs\CoreTabConfig;
use Hexa\PluginCore\WpAdminTabs\CoreTabModule;
use Hexa\PluginCore\WpAdminTabs\HostTabsRenderer;
use Hexa\PluginCore\WpAdminTabs\TabDefinition;
use Hexa\PluginCore\WpAdminTabs\TabRegistry;
use Smp\TextToSpeech\Admin\SettingsNavigation;
use Smp\TextToSpeech\Support\AcfAudioFieldResolver;
use Smp\TextToSpeech\Support\AudioAttachmentGuard;
use Smp\TextToSpeech\Support\PostVisibility;
use Smp\TextToSpeech\Support\Text;
use WP_Error;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

trait GeneratesAudio {
    private static function prepare_generation_content( int $post_id, array $settings, string $content, string $prepend = "", string $append = "", bool $shorten = false ) {
        $content = trim( $content );
        $extracted = false;

        if ( "" === $content ) {
            $extract = self::extract_post_text( $post_id );
            if ( is_wp_error( $extract ) ) {
                update_post_meta( $post_id, "_hexa_tts_status", "Extraction failed" );
                update_post_meta( $post_id, "_hexa_tts_error", $extract->get_error_message() );
                return $extract;
            }

            $content = (string) ( $extract["text"] ?? "" );
            $extracted = true;
        }

        $content = trim( implode( "\n\n", array_filter( [ trim( $prepend ), $content, trim( $append ) ] ) ) );
        if ( "" === $content ) {
            update_post_meta( $post_id, "_hexa_tts_status", "Empty" );
            return new WP_Error( "hexa_tts_empty_text", "No usable text was available for this post." );
        }

        $max = absint( $settings["max_characters"] ?? 0 );
        $characters = Text::length( $content );
        $shortened = false;
        if ( $max > 0 && $characters > $max ) {
            if ( $shorten ) {
                $content = Text::truncateWithDots( $content, max( 100, $max - 20 ) );
                $characters = Text::length( $content );
                $shortened = true;
            } else {
                update_post_meta( $post_id, "_hexa_tts_status", "Too long" );
                return new WP_Error( "hexa_tts_too_long", "Content is " . $characters . " characters, above the limit of " . $max . "." );
            }
        }

        return [
            "text" => $content,
            "characters" => $characters,
            "hash" => hash( "sha256", $content ),
            "extracted" => $extracted,
            "shortened" => $shortened,
        ];
    }

    private static function generation_api_payload( int $post_id, string $content, array $settings, array $source = [], string $client_request_id = "" ): array {
        $current_user = wp_get_current_user();
        $wordpress_user_id = get_current_user_id();
        if ( $wordpress_user_id < 1 ) {
            $wordpress_user_id = max( 1, (int) get_post_field( "post_author", $post_id ) );
        }

        $wordpress_user_login = $current_user ? (string) $current_user->user_login : "";
        if ( "" === $wordpress_user_login && $wordpress_user_id > 0 ) {
            $author = get_user_by( "id", $wordpress_user_id );
            $wordpress_user_login = $author ? (string) $author->user_login : "";
        }

        $payload = [
            "content" => $content,
            "article_url" => get_permalink( $post_id ),
            "post_id" => $post_id,
            "wordpress_user_id" => $wordpress_user_id,
            "wordpress_user_login" => $wordpress_user_login,
            "provider" => sanitize_key( $source["provider"] ?? $settings["default_provider"] ),
            "profile" => sanitize_key( $source["profile"] ?? $settings["default_profile"] ),
            "runtime" => [
                "voice" => sanitize_text_field( $source["voice"] ?? $settings["default_voice"] ),
                "speed" => sanitize_text_field( $source["speed"] ?? $settings["default_speed"] ),
            ],
        ];

        if ( "" !== $client_request_id ) {
            $payload["client_request_id"] = $client_request_id;
        }

        return $payload;
    }

    public static function ajax_generate_audio() {
        check_ajax_referer( self::NONCE_ACTION, "nonce" );
        $post_id = absint( $_POST["post_id"] ?? 0 );
        if ( ! $post_id || ! current_user_can( "edit_post", $post_id ) ) {
            wp_send_json_error( [ "message" => "You do not have permission to generate audio for this post." ], 403 );
        }
        if ( ! self::article_audio_enabled() ) {
            wp_send_json_error( [ "message" => "Listen to this article is turned off on this site." ], 409 );
        }
        $settings = self::get_settings();
        $client_request_id = self::sanitize_client_request_id( wp_unslash( (string) ( $_POST["client_request_id"] ?? "" ) ) );
        $previous_attachment_id = self::current_audio_attachment_id( $post_id, $settings );
        if ( $previous_attachment_id && empty( $_POST["replace_existing"] ) ) {
            wp_send_json_error( [ "message" => "Existing article_audio MP3 detected. Confirm replacement before generating a new one." ], 409 );
        }
        self::reset_generation_log( $post_id, $client_request_id );
        delete_post_meta( $post_id, "_hexa_tts_error" );
        self::add_generation_log( $post_id, "Preparing article content.", "working" );

        $raw_content = wp_unslash( (string) ( $_POST["content"] ?? "" ) );
        $prepend = trim( wp_unslash( (string) ( $_POST["prepend"] ?? "" ) ) );
        $append = trim( wp_unslash( (string) ( $_POST["append"] ?? "" ) ) );
        $prepared = self::prepare_generation_content( $post_id, $settings, $raw_content, $prepend, $append, ! empty( $_POST["shorten"] ) );
        if ( is_wp_error( $prepared ) ) {
            self::add_generation_log( $post_id, $prepared->get_error_message(), "error" );
            wp_send_json_error( [ "message" => $prepared->get_error_message(), "log" => self::generation_log( $post_id ) ] );
        }

        $content = $prepared["text"];
        if ( ! empty( $prepared["extracted"] ) ) {
            self::add_generation_log( $post_id, "Article text extracted from the post editor: " . (int) $prepared["characters"] . " characters.", "success" );
        }
        if ( ! empty( $prepared["shortened"] ) ) {
            self::add_generation_log( $post_id, "Content exceeded max length and was shortened locally.", "info" );
        }
        update_post_meta( $post_id, "_hexa_tts_status", "Waiting" );
        self::add_generation_log( $post_id, "WordPress payload prepared. Sending server-side request to Publish Scale API.", "working", [ "client_request_id" => $client_request_id ] );
        $source = [
            "provider" => wp_unslash( (string) ( $_POST["provider"] ?? $settings["default_provider"] ) ),
            "profile" => wp_unslash( (string) ( $_POST["profile"] ?? $settings["default_profile"] ) ),
            "voice" => wp_unslash( (string) ( $_POST["voice"] ?? $settings["default_voice"] ) ),
            "speed" => wp_unslash( (string) ( $_POST["speed"] ?? $settings["default_speed"] ) ),
        ];
        $result = self::api_request( "/synthesize", self::generation_api_payload( $post_id, $content, $settings, $source, $client_request_id ), 240 );
        if ( is_wp_error( $result ) ) {
            update_post_meta( $post_id, "_hexa_tts_status", "Failed" );
            update_post_meta( $post_id, "_hexa_tts_error", $result->get_error_message() );
            self::add_generation_log( $post_id, "Publish Scale API failed: " . $result->get_error_message(), "error" );
            wp_send_json_error( [ "message" => $result->get_error_message(), "log" => self::generation_log( $post_id ) ] );
        }
        self::add_generation_log( $post_id, "Publish Scale API returned audio bytes. Request " . ( $result["request_id"] ?? $client_request_id ) . " is ready for WordPress storage.", "success", [ "request_id" => $result["request_id"] ?? $client_request_id, "bytes" => $result["bytes"] ?? null, "cost_usd" => $result["cost_usd"] ?? null ] );
        self::add_generation_log( $post_id, "Saving returned MP3 to the WordPress Media Library.", "working" );
        $stored = self::store_api_audio( $post_id, $result, $content );
        if ( is_wp_error( $stored ) ) {
            update_post_meta( $post_id, "_hexa_tts_status", "Storage failed" );
            update_post_meta( $post_id, "_hexa_tts_error", $stored->get_error_message() );
            self::add_generation_log( $post_id, "Storage failed: " . $stored->get_error_message(), "error" );
            wp_send_json_error( [ "message" => $stored->get_error_message(), "log" => self::generation_log( $post_id ) ] );
        }
        self::add_generation_log( $post_id, "Attachment stored and ACF/meta field synced.", "success", [ "attachment_id" => $stored["attachment_id"] ?? null, "acf_field" => $stored["acf_field"] ?? null ] );
        $deleted_previous_attachment_id = self::delete_replaced_audio_attachment( $previous_attachment_id, (int) ( $stored["attachment_id"] ?? 0 ), $post_id );
        if ( $deleted_previous_attachment_id ) {
            self::add_generation_log( $post_id, "Deleted previous article audio attachment " . $deleted_previous_attachment_id . ".", "success", [ "deleted_attachment_id" => $deleted_previous_attachment_id ] );
        }
        delete_post_meta( $post_id, "_hexa_tts_error" );
        update_post_meta( $post_id, "_hexa_tts_status", "Ready" );
        update_post_meta( $post_id, "_hexa_tts_text_hash", (string) $prepared["hash"] );
        update_post_meta( $post_id, "_hexa_tts_character_count", (int) $prepared["characters"] );
        wp_send_json_success( array_merge( $stored, [ "message" => "Audio generated, stored in Media Library, and synced to ACF/meta.", "request_id" => $result["request_id"] ?? "", "archive_url" => $result["archive_url"] ?? "", "cost_usd" => $result["cost_usd"] ?? null, "deleted_attachment_id" => $deleted_previous_attachment_id, "log" => self::generation_log( $post_id ) ] ) );
    }

    public static function generate_for_post( $post_id, array $args = [] ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return new WP_Error( "hexa_tts_missing_post_id", "A WordPress post ID is required." );
        }

        $post = get_post( $post_id );
        if ( ! $post || ! in_array( $post->post_type, [ "post", "press-release" ], true ) ) {
            return new WP_Error( "hexa_tts_invalid_post", "Post not found or unsupported for article audio." );
        }
        if ( ! self::article_audio_enabled() ) {
            return new WP_Error( "hexa_tts_feature_disabled", "Listen to this article is turned off on this site." );
        }

        $settings = self::get_settings();
        $prepared = self::prepare_generation_content(
            $post_id,
            $settings,
            (string) ( $args["content"] ?? "" ),
            (string) ( $args["prepend"] ?? "" ),
            (string) ( $args["append"] ?? "" ),
            ! empty( $args["shorten"] )
        );
        if ( is_wp_error( $prepared ) ) {
            return $prepared;
        }

        $content = $prepared["text"];
        $hash = $prepared["hash"];
        $force = ! empty( $args["force"] );
        $acf_field = AcfAudioFieldResolver::fieldName( $settings );
        $existing_url = (string) get_post_meta( $post_id, "_hexa_tts_audio_url", true );
        $existing_attachment_id = absint( get_post_meta( $post_id, "_hexa_tts_attachment_id", true ) );
        if ( "" === $existing_url && $existing_attachment_id ) {
            $existing_url = (string) wp_get_attachment_url( $existing_attachment_id );
        }
        if ( "" === $existing_url ) {
            $existing_url = self::resolve_audio_url( get_post_meta( $post_id, $acf_field, true ) );
        }
        $existing_hash = (string) get_post_meta( $post_id, "_hexa_tts_text_hash", true );
        if ( ! $force && "" !== $existing_url && $existing_hash === $hash ) {
            return [
                "audio_url" => esc_url_raw( $existing_url ),
                "attachment_id" => $existing_attachment_id,
                "acf_field" => $acf_field,
                "acf_value" => $existing_attachment_id ?: get_post_meta( $post_id, $acf_field, true ),
                "bytes" => 0,
                "message" => "Existing article audio is current.",
                "request_id" => (string) get_post_meta( $post_id, "_hexa_tts_request_id", true ),
                "archive_url" => (string) get_post_meta( $post_id, "_hexa_tts_archive_url", true ),
                "cost_usd" => get_post_meta( $post_id, "_hexa_tts_cost_usd", true ),
                "provider" => (string) get_post_meta( $post_id, "_hexa_tts_provider", true ),
                "status" => "synced",
                "reused" => true,
            ];
        }
        $previous_attachment_id = self::current_audio_attachment_id( $post_id, $settings );

        update_post_meta( $post_id, "_hexa_tts_status", "Waiting" );
        delete_post_meta( $post_id, "_hexa_tts_error" );

        $result = self::api_request( "/synthesize", self::generation_api_payload( $post_id, $content, $settings, $args ), 240 );

        if ( is_wp_error( $result ) ) {
            update_post_meta( $post_id, "_hexa_tts_status", "Failed" );
            update_post_meta( $post_id, "_hexa_tts_error", $result->get_error_message() );
            return $result;
        }

        $stored = self::store_api_audio( $post_id, $result, $content );
        if ( is_wp_error( $stored ) ) {
            update_post_meta( $post_id, "_hexa_tts_status", "Storage failed" );
            update_post_meta( $post_id, "_hexa_tts_error", $stored->get_error_message() );
            return $stored;
        }

        update_post_meta( $post_id, "_hexa_tts_status", "Ready" );
        update_post_meta( $post_id, "_hexa_tts_text_hash", $hash );
        update_post_meta( $post_id, "_hexa_tts_character_count", (int) $prepared["characters"] );
        $deleted_previous_attachment_id = self::delete_replaced_audio_attachment( $previous_attachment_id, (int) ( $stored["attachment_id"] ?? 0 ), $post_id );

        return array_merge( $stored, [
            "message" => "Audio generated, stored in Media Library, and synced to ACF/meta.",
            "request_id" => $result["request_id"] ?? "",
            "archive_url" => $result["archive_url"] ?? "",
            "cost_usd" => $result["cost_usd"] ?? null,
            "provider" => sanitize_key( $args["provider"] ?? $settings["default_provider"] ),
            "status" => "synced",
            "reused" => false,
            "deleted_attachment_id" => $deleted_previous_attachment_id,
        ] );
    }

    private static function extract_post_text( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( "hexa_tts_missing_post", "Post not found." );
        }
        $settings = self::get_settings();
        $parts = [];
        if ( ! empty( $settings["include_title"] ) ) {
            $parts[] = get_the_title( $post );
        }
        $content = (string) $post->post_content;
        if ( function_exists( "do_blocks" ) ) {
            $content = do_blocks( $content );
        }
        $text = self::plain_text_from_content( $content );
        if ( "" !== $text ) {
            $parts[] = $text;
        }
        $elementor_text = self::extract_elementor_text( (int) $post_id );
        if ( "" !== $elementor_text && ( "" === $text || false === strpos( $text, $elementor_text ) ) ) {
            $parts[] = $elementor_text;
        }
        $final = trim( implode( "\n\n", array_filter( $parts ) ) );
        if ( "" === $final ) {
            return new WP_Error( "hexa_tts_empty_text", "No usable text was extracted from this post." );
        }
        return [ "text" => $final, "characters" => Text::length( $final ), "words" => str_word_count( wp_strip_all_tags( $final ) ), "hash" => hash( "sha256", $final ), "preview" => Text::slice( $final, 0, 5000 ) ];
    }

    private static function plain_text_from_content( string $content ): string {
        $content = strip_shortcodes( $content );
        $content = preg_replace( "#<(script|style|noscript)[^>]*>.*?</\\1>#is", " ", $content );
        $content = preg_replace( "#<(h[1-6]|p|li|blockquote|br)[^>]*>#i", "\n", $content );
        $text = wp_strip_all_tags( $content, true );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( "charset" ) ?: "UTF-8" );
        $text = preg_replace( "/[ \t]+/", " ", $text );
        $text = preg_replace( "/\n{3,}/", "\n\n", $text );
        return trim( $text );
    }

    private static function extract_elementor_text( int $post_id ): string {
        $raw = get_post_meta( $post_id, "_elementor_data", true );
        if ( ! is_string( $raw ) || "" === trim( $raw ) ) {
            return "";
        }

        $decoded = json_decode( $raw, true );
        if ( ! is_array( $decoded ) ) {
            $decoded = json_decode( wp_unslash( $raw ), true );
        }
        if ( ! is_array( $decoded ) ) {
            return "";
        }

        $texts = [];
        self::collect_elementor_text( $decoded, $texts );
        $texts = array_values( array_unique( array_filter( array_map( "trim", $texts ) ) ) );
        return trim( implode( "\n\n", $texts ) );
    }

    private static function collect_elementor_text( $node, array &$texts ): void {
        if ( ! is_array( $node ) ) {
            return;
        }

        $allowed_keys = [
            "title",
            "editor",
            "text",
            "description",
            "caption",
            "tab_title",
            "tab_content",
            "item_title",
            "item_description",
            "testimonial_content",
            "blockquote_content",
        ];

        foreach ( $node as $key => $value ) {
            $normalized_key = sanitize_key( (string) $key );
            if ( is_string( $value ) && in_array( $normalized_key, $allowed_keys, true ) ) {
                $text = self::plain_text_from_content( $value );
                if ( "" !== $text && ! preg_match( "#^https?://#i", $text ) ) {
                    $texts[] = $text;
                }
                continue;
            }

            if ( is_array( $value ) ) {
                self::collect_elementor_text( $value, $texts );
            }
        }
    }

    private static function sanitize_client_request_id( $value ): string {
        $value = strtolower( preg_replace( "/[^a-z0-9_\\-]/", "", (string) $value ) );
        if ( preg_match( "/^tts_[a-z0-9_\\-]{12,76}$/", $value ) ) {
            return $value;
        }

        return "tts_wp_" . strtolower( wp_generate_password( 20, false, false ) );
    }

    private static function reset_generation_log( int $post_id, string $client_request_id ): void {
        update_post_meta( $post_id, "_hexa_tts_generation_client_id", $client_request_id );
        update_post_meta( $post_id, "_hexa_tts_activity_log", wp_json_encode( [] ) );
        self::add_generation_log( $post_id, "WordPress accepted the generation request.", "working", [ "client_request_id" => $client_request_id ] );
    }

    private static function add_generation_log( int $post_id, string $message, string $state = "info", array $context = [] ): array {
        $log = self::generation_log( $post_id );
        $log[] = [
            "time" => current_time( "H:i:s" ),
            "state" => sanitize_key( $state ?: "info" ),
            "message" => sanitize_text_field( $message ),
            "context" => $context,
        ];
        update_post_meta( $post_id, "_hexa_tts_activity_log", wp_json_encode( $log ) );
        return $log;
    }

    private static function generation_log( int $post_id ): array {
        $raw = get_post_meta( $post_id, "_hexa_tts_activity_log", true );
        if ( is_array( $raw ) ) {
            return $raw;
        }
        $decoded = is_string( $raw ) && "" !== $raw ? json_decode( $raw, true ) : [];
        return is_array( $decoded ) ? $decoded : [];
    }

    private static function api_request_status( string $public_id ): ?array {
        $public_id = self::sanitize_client_request_id( $public_id );
        if ( "" === $public_id ) {
            return null;
        }

        $headers = [ "Accept" => "application/json" ];
        $api_key = self::api_key();
        if ( "" !== $api_key ) {
            $headers["X-SMP-TTS-Key"] = $api_key;
        }

        $response = wp_remote_get( self::API_BASE . "/requests/" . rawurlencode( $public_id ), [
            "timeout" => 8,
            "headers" => $headers,
        ] );
        if ( is_wp_error( $response ) ) {
            return [ "status" => "unavailable", "message" => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== (int) $code || ! is_array( $body ) || empty( $body["success"] ) || empty( $body["request"] ) || ! is_array( $body["request"] ) ) {
            return null;
        }

        return [
            "status" => sanitize_text_field( (string) ( $body["request"]["status"] ?? "" ) ),
            "message" => sanitize_text_field( (string) ( $body["request"]["message"] ?? "" ) ),
            "provider" => sanitize_text_field( (string) ( $body["request"]["provider"] ?? "" ) ),
            "audio_bytes" => absint( $body["request"]["audio_bytes"] ?? 0 ),
            "cost_usd" => isset( $body["request"]["cost_usd"] ) ? (float) $body["request"]["cost_usd"] : null,
        ];
    }

    private static function api_request( $path, array $payload, $timeout = 30 ) {
        $api_key = self::api_key();
        if ( "" === $api_key ) {
            return new WP_Error( "hexa_tts_missing_api_key", "Missing Publish Scale TTS API key." );
        }
        $response = wp_remote_post( self::API_BASE . $path, [ "timeout" => $timeout, "headers" => [ "Accept" => "application/json", "Content-Type" => "application/json", "X-SMP-TTS-Key" => $api_key ], "body" => wp_json_encode( $payload ) ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body["success"] ) ) {
            $message = is_array( $body ) && ! empty( $body["message"] ) ? $body["message"] : "Publish Scale API returned HTTP " . $code . ".";
            return new WP_Error( "hexa_tts_api_error", $message );
        }
        return $body;
    }
}
