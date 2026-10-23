<?php

namespace smp_text_to_speech\Concerns;

use Smp\TextToSpeech\Support\AcfAudioFieldResolver;
use Smp\TextToSpeech\Support\AudioAttachmentGuard;
use WP_Error;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

trait StoresAudio {
    private static function resolve_audio_url( $value ) {
        return AcfAudioFieldResolver::resolveUrl( $value );
    }

    private static function audio_attachment_id_from_value( $value ): int {
        return AcfAudioFieldResolver::attachmentIdFromValue( $value );
    }

    private static function current_audio_attachment_id( int $post_id, array $settings = [] ): int {
        if ( empty( $settings ) ) {
            $settings = self::get_settings();
        }
        $acf_field = AcfAudioFieldResolver::fieldName( $settings );
        foreach ( [ get_post_meta( $post_id, $acf_field, true ), get_post_meta( $post_id, "_hexa_tts_attachment_id", true ) ] as $value ) {
            $attachment_id = self::audio_attachment_id_from_value( $value );
            if ( $attachment_id && "attachment" === get_post_type( $attachment_id ) && 0 === strpos( (string) get_post_mime_type( $attachment_id ), "audio/" ) ) {
                return $attachment_id;
            }
        }
        return 0;
    }

    private static function delete_replaced_audio_attachment( int $previous_attachment_id, int $new_attachment_id, int $post_id ): int {
        if ( ! AudioAttachmentGuard::canDeleteReplacement( $previous_attachment_id, $new_attachment_id, $post_id ) ) {
            return 0;
        }
        return wp_delete_attachment( $previous_attachment_id, true ) ? $previous_attachment_id : 0;
    }

    private static function sync_audio_attachment( $post_id, $attachment_id, $audio_url, $file_path = "", array $api_result = [] ) {
        $settings = self::get_settings();
        $acf_field = AcfAudioFieldResolver::fieldName( $settings );
        update_post_meta( $post_id, "_hexa_tts_audio_url", esc_url_raw( $audio_url ) );
        update_post_meta( $post_id, "_hexa_tts_attachment_id", (int) $attachment_id );
        if ( $file_path ) {
            update_post_meta( $post_id, "_hexa_tts_audio_path", $file_path );
        }
        if ( ! empty( $api_result["request_id"] ) ) {
            update_post_meta( $post_id, "_hexa_tts_request_id", sanitize_text_field( $api_result["request_id"] ) );
        }
        if ( ! empty( $api_result["archive_url"] ) ) {
            update_post_meta( $post_id, "_hexa_tts_archive_url", esc_url_raw( $api_result["archive_url"] ) );
        }
        update_post_meta( $post_id, "_hexa_tts_cost_usd", sanitize_text_field( (string) ( $api_result["cost_usd"] ?? "0" ) ) );
        update_post_meta( $post_id, "_hexa_tts_generated_at", current_time( "mysql" ) );
        update_post_meta( $post_id, "_hexa_tts_provider", sanitize_key( $api_result["provider"] ?? "manual_upload" ) );
        update_post_meta( $post_id, "_hexa_tts_provider_key_last4", sanitize_text_field( $api_result["provider_key_last4"] ?? "" ) );
        update_post_meta( $post_id, "_hexa_tts_acf_field", $acf_field );
        AcfAudioFieldResolver::updatePostValue( (int) $post_id, $acf_field, (int) $attachment_id );
        self::purge_frontend_cache( (int) $post_id );
        $bytes = $file_path && file_exists( $file_path ) ? filesize( $file_path ) : 0;
        return [ "audio_url" => $audio_url, "attachment_id" => (int) $attachment_id, "acf_field" => $acf_field, "acf_value" => (int) $attachment_id, "bytes" => (int) $bytes ];
    }

    private static function purge_frontend_cache( int $post_id = 0 ): void {
        if ( $post_id > 0 ) {
            clean_post_cache( $post_id );

            if ( has_action( "litespeed_purge_post" ) ) {
                do_action( "litespeed_purge_post", $post_id );
            }

            $permalink = get_permalink( $post_id );
            if ( $permalink && has_action( "litespeed_purge_url" ) ) {
                do_action( "litespeed_purge_url", $permalink );
            }

            if ( function_exists( "rocket_clean_post" ) ) {
                rocket_clean_post( $post_id );
            }

            if ( function_exists( "w3tc_flush_post" ) ) {
                w3tc_flush_post( $post_id );
            }

            return;
        }

        if ( function_exists( "wp_cache_flush" ) ) {
            wp_cache_flush();
        }

        foreach ( [ "litespeed_purge_all", "litespeed_purge_all_object" ] as $action ) {
            if ( has_action( $action ) ) {
                do_action( $action );
            }
        }

        if ( function_exists( "rocket_clean_domain" ) ) {
            rocket_clean_domain();
        }

        if ( function_exists( "w3tc_flush_all" ) ) {
            w3tc_flush_all();
        }
    }

    private static function store_api_audio( $post_id, array $api_result, $content ) {
        if ( empty( $api_result["audio_base64"] ) ) {
            return new WP_Error( "hexa_tts_missing_audio", "API response did not include audio_base64." );
        }
        $bytes = base64_decode( (string) $api_result["audio_base64"], true );
        if ( ! is_string( $bytes ) || strlen( $bytes ) < 100 ) {
            return new WP_Error( "hexa_tts_bad_audio", "Decoded audio payload is empty." );
        }
        $request_id = sanitize_key( $api_result["request_id"] ?? substr( hash( "sha256", $content ), 0, 12 ) );
        return self::store_audio_bytes( $post_id, $bytes, $request_id, $api_result );
    }

    private static function store_audio_bytes( $post_id, $bytes, $request_id, array $api_result ) {
        $mime = sanitize_mime_type( $api_result["audio_mime"] ?? "audio/mpeg" );
        $filename = sanitize_file_name( "hexa-tts-" . $post_id . "-" . $request_id . "." . self::audio_extension_from_mime( $mime ) );
        $upload = wp_upload_bits( $filename, null, $bytes );
        if ( ! empty( $upload["error"] ) ) {
            return new WP_Error( "hexa_tts_upload_failed", $upload["error"] );
        }
        require_once ABSPATH . "wp-admin/includes/file.php";
        require_once ABSPATH . "wp-admin/includes/media.php";
        require_once ABSPATH . "wp-admin/includes/image.php";
        $attachment_id = wp_insert_attachment( [ "post_mime_type" => $mime, "post_title" => sanitize_text_field( get_the_title( $post_id ) . " narration" ), "post_content" => "", "post_status" => "inherit" ], $upload["file"], $post_id );
        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            return new WP_Error( "hexa_tts_attachment_failed", is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : "Could not insert media attachment." );
        }
        AudioAttachmentGuard::markManaged( (int) $attachment_id, (int) $post_id, (string) $request_id );
        $metadata = wp_generate_attachment_metadata( $attachment_id, $upload["file"] );
        if ( is_array( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }
        return self::sync_audio_attachment( $post_id, (int) $attachment_id, $upload["url"], $upload["file"], $api_result );
    }

    private static function audio_extension_from_mime( string $mime ): string {
        $map = [
            "audio/mpeg" => "mp3",
            "audio/mp3" => "mp3",
            "audio/wav" => "wav",
            "audio/x-wav" => "wav",
            "audio/aac" => "aac",
            "audio/ogg" => "ogg",
            "audio/mp4" => "m4a",
            "audio/x-m4a" => "m4a",
        ];

        return $map[ strtolower( $mime ) ] ?? "mp3";
    }
}
