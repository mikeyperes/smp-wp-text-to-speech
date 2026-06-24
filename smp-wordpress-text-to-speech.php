<?php
/**
 * Plugin Name: HexaTextToSpeech
 * Plugin URI: https://code.hexawebsystems.com/manual-ai-reports/6/view
 * Description: Publish Scale text-to-speech client for WordPress article narration. Uses hidden server-side API calls, AJAX generation, Media Library storage, and ACF field syncing.
 * Version: 1.1.0
 * Author: Hexa Web Systems
 * Text Domain: smp-wordpress-text-to-speech
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

final class HexaTextToSpeech {
    const VERSION = "1.1.0";
    const OPTION = "hexa_tts_settings";
    const NONCE_ACTION = "hexa_tts_admin_nonce";
    const SETTINGS_SLUG = "hexa-text-to-speech";
    const API_BASE = "https://publish.scalemypublication.com/api/smp-wordpress-tts/v1";

    public static function init() {
        add_action( "admin_menu", [ __CLASS__, "register_admin_menu" ] );
        add_action( "admin_enqueue_scripts", [ __CLASS__, "enqueue_admin_assets" ] );
        add_action( "admin_post_hexa_tts_save_settings", [ __CLASS__, "handle_save_settings" ] );
        add_action( "add_meta_boxes", [ __CLASS__, "register_post_metabox" ] );
        add_action( "wp_ajax_hexa_tts_validate_central_api", [ __CLASS__, "ajax_validate_central_api" ] );
        add_action( "wp_ajax_hexa_tts_validate_provider", [ __CLASS__, "ajax_validate_central_api" ] );
        add_action( "wp_ajax_hexa_tts_extract_post_content", [ __CLASS__, "ajax_extract_post_content" ] );
        add_action( "wp_ajax_hexa_tts_generate_audio", [ __CLASS__, "ajax_generate_audio" ] );
        add_filter( "the_content", [ __CLASS__, "maybe_insert_player" ], 12 );
        add_shortcode( "hexa_tts_player", [ __CLASS__, "render_player_shortcode" ] );
        register_activation_hook( __FILE__, [ __CLASS__, "activate" ] );
    }

    public static function activate() {
        if ( ! get_option( self::OPTION ) ) {
            add_option( self::OPTION, self::default_settings(), "", false );
        }
    }

    public static function register_admin_menu() {
        add_options_page( "HexaTextToSpeech", "HexaTextToSpeech", "manage_options", self::SETTINGS_SLUG, [ __CLASS__, "render_settings_page" ] );
    }

    public static function enqueue_admin_assets( $hook ) {
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        $is_settings = "settings_page_" . self::SETTINGS_SLUG === $hook;
        $is_post = $screen && "post" === $screen->base;
        if ( ! $is_settings && ! $is_post ) {
            return;
        }
        wp_enqueue_style( "hexa-tts-admin", plugin_dir_url( __FILE__ ) . "assets/admin.css", [], self::VERSION );
        wp_enqueue_script( "hexa-tts-admin", plugin_dir_url( __FILE__ ) . "assets/admin.js", [ "jquery" ], self::VERSION, true );
        wp_localize_script( "hexa-tts-admin", "hexaTts", [ "ajaxUrl" => admin_url( "admin-ajax.php" ), "nonce" => wp_create_nonce( self::NONCE_ACTION ) ] );
    }

    public static function default_settings() {
        return [
            "api_key" => "",
            "default_provider" => "unrealspeech",
            "default_profile" => "default",
            "default_voice" => "af",
            "default_speed" => "0",
            "acf_audio_field" => "article_audio",
            "auto_insert_player" => 1,
            "include_title" => 1,
            "max_characters" => 20000,
            "last_status" => [],
        ];
    }

    public static function get_settings() {
        $stored = get_option( self::OPTION, [] );
        return self::merge_settings( self::default_settings(), is_array( $stored ) ? $stored : [] );
    }

    private static function merge_settings( array $defaults, array $stored ) {
        foreach ( $stored as $key => $value ) {
            if ( is_array( $value ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) ) {
                $defaults[ $key ] = self::merge_settings( $defaults[ $key ], $value );
            } else {
                $defaults[ $key ] = $value;
            }
        }
        return $defaults;
    }

    public static function render_settings_page() {
        if ( ! current_user_can( "manage_options" ) ) {
            return;
        }
        $settings = self::get_settings();
        $api_key = self::api_key();
        $saved = isset( $_GET["hexa_tts_saved"] ) && "1" === $_GET["hexa_tts_saved"];
        $last_status = is_array( $settings["last_status"] ?? null ) ? $settings["last_status"] : [];
        ?>
        <div class="wrap hexa-tts-wrap">
            <div class="hexa-tts-page-head"><div><h1>HexaTextToSpeech</h1><p>WordPress client for Publish Scale article audio. Browser requests stay inside WordPress; upstream calls are server-side only.</p></div></div>
            <?php if ( $saved ) : ?><div class="notice notice-success is-dismissible"><p>HexaTextToSpeech settings saved.</p></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>" class="hexa-tts-settings-form">
                <?php wp_nonce_field( self::NONCE_ACTION, "hexa_tts_nonce" ); ?>
                <input type="hidden" name="action" value="hexa_tts_save_settings">
                <section class="hexa-tts-panel" data-provider-card="central">
                    <div class="hexa-tts-panel-head"><div><h2>Publish Scale API Connection</h2><p>Paste the site API key generated in Publish Scale. The API base is not printed into admin JavaScript.</p></div><button type="button" class="button button-secondary hexa-tts-test-central-api hexa-tts-test-provider" data-provider="central">Test API Key</button></div>
                    <div class="hexa-tts-grid hexa-tts-grid-2">
                        <label><span>Site API Key</span><input type="password" name="hexa_tts[api_key]" value="" placeholder="<?php echo esc_attr( $api_key ? "Saved: " . self::mask_secret( $api_key ) : "Paste Publish Scale site API key" ); ?>" autocomplete="off"><small>Stored encrypted in WordPress. Leave blank to keep the existing key.</small></label>
                        <label><span>ACF / meta audio field</span><input type="text" name="hexa_tts[acf_audio_field]" value="<?php echo esc_attr( $settings["acf_audio_field"] ); ?>"><small>Mashviral currently uses article_audio.</small></label>
                    </div>
                    <div class="hexa-tts-test-result hexa-tts-central-result" data-provider-result="central" aria-live="polite"></div>
                    <?php if ( ! empty( $last_status["message"] ) ) : ?><div class="hexa-tts-status-card"><strong><?php echo esc_html( $last_status["message"] ); ?></strong><?php if ( ! empty( $last_status["usage"] ) ) : ?><span>Requests: <?php echo esc_html( $last_status["usage"]["requests"] ?? 0 ); ?> · Characters: <?php echo esc_html( $last_status["usage"]["characters"] ?? 0 ); ?> · Est. cost: $<?php echo esc_html( $last_status["usage"]["estimated_cost_usd"] ?? 0 ); ?></span><?php endif; ?></div><?php endif; ?>
                </section>
                <section class="hexa-tts-panel">
                    <div class="hexa-tts-panel-head"><div><h2>Generation Defaults</h2><p>Defaults for the one-click post and press-release workflow.</p></div></div>
                    <div class="hexa-tts-grid hexa-tts-grid-4">
                        <label><span>Default API source</span><input type="text" name="hexa_tts[default_provider]" value="<?php echo esc_attr( $settings["default_provider"] ); ?>"></label>
                        <label><span>Default profile</span><input type="text" name="hexa_tts[default_profile]" value="<?php echo esc_attr( $settings["default_profile"] ); ?>"></label>
                        <label><span>Default voice</span><input type="text" name="hexa_tts[default_voice]" value="<?php echo esc_attr( $settings["default_voice"] ); ?>"></label>
                        <label><span>Generation speed</span><input type="number" step="0.05" name="hexa_tts[default_speed]" value="<?php echo esc_attr( $settings["default_speed"] ); ?>"></label>
                        <label><span>Max characters</span><input type="number" name="hexa_tts[max_characters]" value="<?php echo esc_attr( $settings["max_characters"] ); ?>" min="500" step="500"></label>
                        <label class="hexa-tts-check-row"><input type="checkbox" name="hexa_tts[auto_insert_player]" value="1" <?php checked( ! empty( $settings["auto_insert_player"] ) ); ?>><span>Auto-insert player</span></label>
                        <label class="hexa-tts-check-row"><input type="checkbox" name="hexa_tts[include_title]" value="1" <?php checked( ! empty( $settings["include_title"] ) ); ?>><span>Include post title</span></label>
                    </div>
                </section>
                <p class="submit hexa-tts-submit"><button type="submit" class="button button-primary button-hero">Save HexaTextToSpeech settings</button></p>
            </form>
        </div>
        <?php
    }

    public static function handle_save_settings() {
        if ( ! current_user_can( "manage_options" ) ) {
            wp_die( "Unauthorized." );
        }
        check_admin_referer( self::NONCE_ACTION, "hexa_tts_nonce" );
        $incoming = isset( $_POST["hexa_tts"] ) && is_array( $_POST["hexa_tts"] ) ? wp_unslash( $_POST["hexa_tts"] ) : [];
        $existing = self::get_settings();
        $clean = self::default_settings();
        $api_key = self::sanitize_secret( $incoming["api_key"] ?? "" );
        $clean["api_key"] = "" === $api_key ? ( $existing["api_key"] ?? "" ) : self::encrypt_secret( $api_key );
        $clean["default_provider"] = sanitize_key( $incoming["default_provider"] ?? $existing["default_provider"] );
        $clean["default_profile"] = sanitize_key( $incoming["default_profile"] ?? $existing["default_profile"] );
        $clean["default_voice"] = sanitize_text_field( $incoming["default_voice"] ?? $existing["default_voice"] );
        $clean["default_speed"] = (string) floatval( $incoming["default_speed"] ?? $existing["default_speed"] );
        $clean["acf_audio_field"] = sanitize_key( $incoming["acf_audio_field"] ?? $existing["acf_audio_field"] );
        $clean["auto_insert_player"] = empty( $incoming["auto_insert_player"] ) ? 0 : 1;
        $clean["include_title"] = empty( $incoming["include_title"] ) ? 0 : 1;
        $clean["max_characters"] = max( 500, absint( $incoming["max_characters"] ?? $existing["max_characters"] ) );
        $clean["last_status"] = is_array( $existing["last_status"] ?? null ) ? $existing["last_status"] : [];
        update_option( self::OPTION, $clean, false );
        wp_safe_redirect( add_query_arg( [ "page" => self::SETTINGS_SLUG, "hexa_tts_saved" => "1" ], admin_url( "options-general.php" ) ) );
        exit;
    }

    public static function ajax_validate_central_api() {
        if ( ! current_user_can( "manage_options" ) ) {
            wp_send_json_error( [ "message" => "You do not have permission to validate the TTS API." ], 403 );
        }
        check_ajax_referer( self::NONCE_ACTION, "nonce" );
        $result = self::api_request( "/status", [] );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ "message" => $result->get_error_message() ] );
        }
        $settings = self::get_settings();
        $settings["last_status"] = $result;
        update_option( self::OPTION, $settings, false );
        wp_send_json_success( $result );
    }

    public static function register_post_metabox() {
        foreach ( [ "post", "press-release" ] as $post_type ) {
            if ( post_type_exists( $post_type ) ) {
                add_meta_box( "hexa-tts-post-box", "HexaTextToSpeech", [ __CLASS__, "render_post_metabox" ], $post_type, "normal", "high" );
            }
        }
    }

    public static function render_post_metabox( $post ) {
        $settings = self::get_settings();
        $audio_url = get_post_meta( $post->ID, "_hexa_tts_audio_url", true );
        $attachment_id = get_post_meta( $post->ID, "_hexa_tts_attachment_id", true );
        $status = get_post_meta( $post->ID, "_hexa_tts_status", true );
        ?>
        <div class="hexa-tts-postbox" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
            <div class="hexa-tts-postbox-top"><div><strong>API Status:</strong> <span class="hexa-tts-api-state <?php echo self::api_key() ? "is-ready" : "is-missing"; ?>"><?php echo self::api_key() ? "Configured" : "Missing API key"; ?></span></div><div><strong>Generation Status:</strong> <span class="hexa-tts-post-status"><?php echo esc_html( $status ?: "Not generated" ); ?></span></div><?php if ( $audio_url ) : ?><a href="<?php echo esc_url( $audio_url ); ?>" target="_blank" rel="noopener noreferrer">Open current audio</a><?php endif; ?></div>
            <div class="hexa-tts-editor-grid"><label><span>API source</span><input type="text" class="hexa-tts-post-provider" value="<?php echo esc_attr( $settings["default_provider"] ); ?>"></label><label><span>Profile</span><input type="text" class="hexa-tts-post-profile" value="<?php echo esc_attr( $settings["default_profile"] ); ?>"></label><label><span>Voice</span><input type="text" class="hexa-tts-post-voice" value="<?php echo esc_attr( $settings["default_voice"] ); ?>"></label><label><span>Speed</span><input type="number" step="0.05" class="hexa-tts-post-speed" value="<?php echo esc_attr( $settings["default_speed"] ); ?>"></label><label class="hexa-tts-check-row"><input type="checkbox" class="hexa-tts-post-shorten"><span>Shorten to max if needed</span></label></div>
            <div class="hexa-tts-grid hexa-tts-grid-2"><label><span>Text before article</span><textarea class="hexa-tts-post-prepend" rows="3" placeholder="Optional intro text"></textarea></label><label><span>Text after article</span><textarea class="hexa-tts-post-append" rows="3" placeholder="Optional outro text"></textarea></label></div>
            <div class="hexa-tts-post-actions"><button type="button" class="button hexa-tts-extract-post">Pull from editor</button><button type="button" class="button button-primary hexa-tts-generate-post">Submit Article</button></div>
            <div class="hexa-tts-post-feedback" aria-live="polite"></div><div class="hexa-tts-activity-log" aria-live="polite"></div><textarea class="hexa-tts-extracted-preview" placeholder="Click Pull from editor to populate this text, or paste/customize narration content here before submitting."></textarea>
            <?php if ( $audio_url ) : ?><audio controls preload="none" src="<?php echo esc_url( $audio_url ); ?>"></audio><p class="hexa-tts-current-storage">Stored attachment ID: <?php echo esc_html( $attachment_id ?: "n/a" ); ?> · ACF/meta field: <?php echo esc_html( $settings["acf_audio_field"] ); ?></p><?php endif; ?>
        </div>
        <?php
    }

    public static function ajax_extract_post_content() {
        check_ajax_referer( self::NONCE_ACTION, "nonce" );
        $post_id = absint( $_POST["post_id"] ?? 0 );
        if ( ! $post_id || ! current_user_can( "edit_post", $post_id ) ) {
            wp_send_json_error( [ "message" => "You do not have permission to extract this post." ], 403 );
        }
        $extract = self::extract_post_text( $post_id );
        if ( is_wp_error( $extract ) ) {
            wp_send_json_error( [ "message" => $extract->get_error_message() ] );
        }
        wp_send_json_success( $extract );
    }

    public static function ajax_generate_audio() {
        check_ajax_referer( self::NONCE_ACTION, "nonce" );
        $post_id = absint( $_POST["post_id"] ?? 0 );
        if ( ! $post_id || ! current_user_can( "edit_post", $post_id ) ) {
            wp_send_json_error( [ "message" => "You do not have permission to generate audio for this post." ], 403 );
        }
        $settings = self::get_settings();
        $log = [ "Request received by WordPress.", "Preparing article content." ];
        $content = trim( wp_unslash( (string) ( $_POST["content"] ?? "" ) ) );
        if ( "" === $content ) {
            $extract = self::extract_post_text( $post_id );
            if ( is_wp_error( $extract ) ) {
                update_post_meta( $post_id, "_hexa_tts_status", "Extraction failed" );
                wp_send_json_error( [ "message" => $extract->get_error_message(), "log" => $log ] );
            }
            $content = $extract["text"];
        }
        $prepend = trim( wp_unslash( (string) ( $_POST["prepend"] ?? "" ) ) );
        $append = trim( wp_unslash( (string) ( $_POST["append"] ?? "" ) ) );
        $content = trim( implode( "\n\n", array_filter( [ $prepend, $content, $append ] ) ) );
        $max = absint( $settings["max_characters"] );
        if ( strlen( $content ) > $max ) {
            if ( ! empty( $_POST["shorten"] ) ) {
                $content = substr( $content, 0, max( 100, $max - 20 ) ) . "...";
                $log[] = "Content exceeded max length and was shortened locally.";
            } else {
                update_post_meta( $post_id, "_hexa_tts_status", "Too long" );
                wp_send_json_error( [ "message" => "Content is " . strlen( $content ) . " characters, above the limit of " . $max . ".", "log" => $log ] );
            }
        }
        update_post_meta( $post_id, "_hexa_tts_status", "Waiting" );
        $log[] = "Sending server-side request to Publish Scale API.";
        $current_user = wp_get_current_user();
        $result = self::api_request( "/synthesize", [
            "content" => $content,
            "article_url" => get_permalink( $post_id ),
            "post_id" => $post_id,
            "wordpress_user_id" => get_current_user_id(),
            "wordpress_user_login" => $current_user ? $current_user->user_login : "",
            "provider" => sanitize_key( $_POST["provider"] ?? $settings["default_provider"] ),
            "profile" => sanitize_key( $_POST["profile"] ?? $settings["default_profile"] ),
            "runtime" => [ "voice" => sanitize_text_field( wp_unslash( $_POST["voice"] ?? $settings["default_voice"] ) ), "speed" => sanitize_text_field( wp_unslash( $_POST["speed"] ?? $settings["default_speed"] ) ) ],
        ], 240 );
        if ( is_wp_error( $result ) ) {
            update_post_meta( $post_id, "_hexa_tts_status", "Failed" );
            update_post_meta( $post_id, "_hexa_tts_error", $result->get_error_message() );
            $log[] = "Publish Scale API failed: " . $result->get_error_message();
            wp_send_json_error( [ "message" => $result->get_error_message(), "log" => $log ] );
        }
        $log[] = "Audio returned by API. Saving to WordPress Media Library.";
        $stored = self::store_api_audio( $post_id, $result, $content );
        if ( is_wp_error( $stored ) ) {
            update_post_meta( $post_id, "_hexa_tts_status", "Storage failed" );
            update_post_meta( $post_id, "_hexa_tts_error", $stored->get_error_message() );
            $log[] = "Storage failed: " . $stored->get_error_message();
            wp_send_json_error( [ "message" => $stored->get_error_message(), "log" => $log ] );
        }
        $log[] = "Attachment stored and ACF/meta field synced.";
        update_post_meta( $post_id, "_hexa_tts_status", "Ready" );
        wp_send_json_success( array_merge( $stored, [ "message" => "Audio generated, stored in Media Library, and synced to ACF/meta.", "request_id" => $result["request_id"] ?? "", "archive_url" => $result["archive_url"] ?? "", "cost_usd" => $result["cost_usd"] ?? null, "log" => $log ] ) );
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
        $content = $post->post_content;
        if ( function_exists( "do_blocks" ) ) {
            $content = do_blocks( $content );
        }
        $content = strip_shortcodes( $content );
        $content = preg_replace( "#<(script|style|noscript)[^>]*>.*?</\\1>#is", " ", $content );
        $content = preg_replace( "#<(h[1-6]|p|li|blockquote|br)[^>]*>#i", "\n", $content );
        $text = wp_strip_all_tags( $content, true );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( "charset" ) ?: "UTF-8" );
        $text = preg_replace( "/[ \t]+/", " ", $text );
        $text = preg_replace( "/\n{3,}/", "\n\n", $text );
        $text = trim( $text );
        if ( "" !== $text ) {
            $parts[] = $text;
        }
        $final = trim( implode( "\n\n", array_filter( $parts ) ) );
        if ( "" === $final ) {
            return new WP_Error( "hexa_tts_empty_text", "No usable text was extracted from this post." );
        }
        return [ "text" => $final, "characters" => strlen( $final ), "words" => str_word_count( wp_strip_all_tags( $final ) ), "hash" => hash( "sha256", $final ), "preview" => function_exists( "mb_substr" ) ? mb_substr( $final, 0, 5000 ) : substr( $final, 0, 5000 ) ];
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
        $filename = sanitize_file_name( "hexa-tts-" . $post_id . "-" . $request_id . ".mp3" );
        $upload = wp_upload_bits( $filename, null, $bytes );
        if ( ! empty( $upload["error"] ) ) {
            return new WP_Error( "hexa_tts_upload_failed", $upload["error"] );
        }
        require_once ABSPATH . "wp-admin/includes/file.php";
        require_once ABSPATH . "wp-admin/includes/media.php";
        require_once ABSPATH . "wp-admin/includes/image.php";
        $attachment_id = wp_insert_attachment( [ "post_mime_type" => $api_result["audio_mime"] ?? "audio/mpeg", "post_title" => sanitize_text_field( get_the_title( $post_id ) . " narration" ), "post_content" => "", "post_status" => "inherit" ], $upload["file"], $post_id );
        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            return new WP_Error( "hexa_tts_attachment_failed", is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : "Could not insert media attachment." );
        }
        $metadata = wp_generate_attachment_metadata( $attachment_id, $upload["file"] );
        if ( is_array( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }
        $settings = self::get_settings();
        $acf_field = sanitize_key( $settings["acf_audio_field"] ?: "article_audio" );
        update_post_meta( $post_id, "_hexa_tts_audio_url", esc_url_raw( $upload["url"] ) );
        update_post_meta( $post_id, "_hexa_tts_attachment_id", (int) $attachment_id );
        update_post_meta( $post_id, "_hexa_tts_audio_path", $upload["file"] );
        update_post_meta( $post_id, "_hexa_tts_request_id", sanitize_text_field( $api_result["request_id"] ?? "" ) );
        update_post_meta( $post_id, "_hexa_tts_archive_url", esc_url_raw( $api_result["archive_url"] ?? "" ) );
        update_post_meta( $post_id, "_hexa_tts_cost_usd", sanitize_text_field( (string) ( $api_result["cost_usd"] ?? "" ) ) );
        update_post_meta( $post_id, "_hexa_tts_generated_at", current_time( "mysql" ) );
        update_post_meta( $post_id, "_hexa_tts_provider", sanitize_key( $api_result["provider"] ?? "" ) );
        update_post_meta( $post_id, "_hexa_tts_provider_key_last4", sanitize_text_field( $api_result["provider_key_last4"] ?? "" ) );
        update_post_meta( $post_id, $acf_field, esc_url_raw( $upload["url"] ) );
        if ( function_exists( "update_field" ) ) {
            update_field( $acf_field, esc_url_raw( $upload["url"] ), $post_id );
        }
        return [ "audio_url" => $upload["url"], "attachment_id" => (int) $attachment_id, "acf_field" => $acf_field, "bytes" => strlen( $bytes ) ];
    }

    public static function maybe_insert_player( $content ) {
        $settings = self::get_settings();
        if ( empty( $settings["auto_insert_player"] ) || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }
        $player = self::player_html( get_the_ID() );
        return "" === $player ? $content : $player . $content;
    }

    public static function render_player_shortcode( $atts = [] ) {
        $atts = shortcode_atts( [ "post_id" => get_the_ID() ], $atts, "hexa_tts_player" );
        return self::player_html( absint( $atts["post_id"] ) );
    }

    private static function player_html( $post_id ) {
        $url = get_post_meta( $post_id, "_hexa_tts_audio_url", true );
        if ( ! $url ) {
            return "";
        }
        $provider = get_post_meta( $post_id, "_hexa_tts_provider", true );
        $generated = get_post_meta( $post_id, "_hexa_tts_generated_at", true );
        ob_start();
        ?>
        <aside class="hexa-tts-player" aria-label="Article audio narration"><div class="hexa-tts-player__label">Listen to this article</div><audio controls preload="none" src="<?php echo esc_url( $url ); ?>"></audio><div class="hexa-tts-player__meta"><?php if ( $provider ) : ?><span><?php echo esc_html( $provider ); ?></span><?php endif; ?><?php if ( $generated ) : ?><span><?php echo esc_html( mysql2date( "M j, Y g:i A", $generated ) ); ?></span><?php endif; ?></div></aside>
        <?php
        return ob_get_clean();
    }

    private static function api_key() {
        $settings = self::get_settings();
        return self::decrypt_secret( $settings["api_key"] ?? "" );
    }

    private static function sanitize_secret( $value ) {
        $value = trim( (string) $value );
        return preg_replace( "/[\\x00-\\x1F\\x7F]/", "", $value );
    }

    private static function secret_key() {
        $material = ( defined( "AUTH_KEY" ) ? AUTH_KEY : "" ) . ( defined( "SECURE_AUTH_KEY" ) ? SECURE_AUTH_KEY : "" ) . ( defined( "LOGGED_IN_KEY" ) ? LOGGED_IN_KEY : "" );
        return hash( "sha256", "" === $material ? wp_salt( "auth" ) : $material, true );
    }

    private static function encrypt_secret( $value ) {
        if ( "" === $value || ! function_exists( "openssl_encrypt" ) ) {
            return $value;
        }
        $iv = function_exists( "random_bytes" ) ? random_bytes( 16 ) : substr( hash( "sha256", wp_rand() . microtime( true ), true ), 0, 16 );
        $cipher = openssl_encrypt( $value, "AES-256-CBC", self::secret_key(), OPENSSL_RAW_DATA, $iv );
        return false === $cipher ? $value : "enc:" . base64_encode( $iv . $cipher );
    }

    private static function decrypt_secret( $value ) {
        if ( ! is_string( $value ) || 0 !== strpos( $value, "enc:" ) || ! function_exists( "openssl_decrypt" ) ) {
            return (string) $value;
        }
        $raw = base64_decode( substr( $value, 4 ), true );
        if ( false === $raw || strlen( $raw ) < 17 ) {
            return "";
        }
        $plain = openssl_decrypt( substr( $raw, 16 ), "AES-256-CBC", self::secret_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
        return false === $plain ? "" : $plain;
    }

    private static function mask_secret( $value ) {
        $value = (string) $value;
        if ( "" === $value ) {
            return "";
        }
        return str_repeat( "*", min( 8, max( 4, strlen( $value ) - 4 ) ) ) . substr( $value, -4 );
    }
}

HexaTextToSpeech::init();
