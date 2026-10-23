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

trait ManagesSettings {
    public static function default_settings() {
        return [
            "api_key" => "",
            "default_provider" => "unrealspeech",
            "default_profile" => "default",
            "default_voice" => "af",
            "default_speed" => "0",
            "article_audio_enabled" => 1,
            "acf_audio_field" => "article_audio",
            "auto_insert_player" => 1,
            "auto_player_placement" => "above_article",
            "include_title" => 1,
            "max_characters" => 20000,
            "primary_color" => "#3657e3",
            "player_size" => "default",
            "player_template" => "clean_card",
            "player_label" => "Listen to this article",
            "show_player_meta" => 1,
            "enable_player_controls" => 1,
            "last_status" => [],
        ];
    }

    /** The post field that holds the article's audio file. */
    public static function audio_field_name(): string {
        return AcfAudioFieldResolver::fieldName( self::get_settings() );
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

    public static function handle_save_settings() {
        if ( ! current_user_can( "manage_options" ) ) {
            wp_die( "Unauthorized." );
        }
        check_admin_referer( self::NONCE_ACTION, "smp_wp_text_to_speech_nonce" );
        $incoming = isset( $_POST["hexa_tts"] ) && is_array( $_POST["hexa_tts"] ) ? wp_unslash( $_POST["hexa_tts"] ) : [];
        $existing = self::get_settings();
        $tab = isset( $_POST["hexa_tts_tab"] ) ? sanitize_key( wp_unslash( $_POST["hexa_tts_tab"] ) ) : "api";
        $clean = self::default_settings();
        $api_key = self::sanitize_secret( $incoming["api_key"] ?? "" );
        $clean["api_key"] = "" === $api_key ? ( $existing["api_key"] ?? "" ) : self::encrypt_secret( $api_key );
        $clean["default_provider"] = sanitize_key( $incoming["default_provider"] ?? $existing["default_provider"] );
        $clean["default_profile"] = sanitize_key( $incoming["default_profile"] ?? $existing["default_profile"] );
        $clean["default_voice"] = sanitize_text_field( $incoming["default_voice"] ?? $existing["default_voice"] );
        $clean["default_speed"] = (string) floatval( $incoming["default_speed"] ?? $existing["default_speed"] );
        $clean["acf_audio_field"] = AcfAudioFieldResolver::fieldName( [ "acf_audio_field" => $incoming["acf_audio_field"] ?? $existing["acf_audio_field"] ?? "article_audio" ] );
        $clean["article_audio_enabled"] = array_key_exists( "article_audio_enabled", $incoming ) ? 1 : ( "features" === $tab ? 0 : (int) ( $existing["article_audio_enabled"] ?? 1 ) );
        $clean["auto_insert_player"] = array_key_exists( "auto_insert_player", $incoming ) ? 1 : ( in_array( $tab, [ "display", "features" ], true ) ? 0 : (int) ( $existing["auto_insert_player"] ?? 1 ) );
        $clean["include_title"] = array_key_exists( "include_title", $incoming ) ? 1 : ( in_array( $tab, [ "api", "features" ], true ) ? 0 : (int) ( $existing["include_title"] ?? 1 ) );
        $clean["show_player_meta"] = array_key_exists( "show_player_meta", $incoming ) ? 1 : ( in_array( $tab, [ "display", "features" ], true ) ? 0 : (int) ( $existing["show_player_meta"] ?? 1 ) );
        $clean["enable_player_controls"] = array_key_exists( "enable_player_controls", $incoming ) ? 1 : ( in_array( $tab, [ "display", "features" ], true ) ? 0 : (int) ( $existing["enable_player_controls"] ?? 1 ) );
        $clean["max_characters"] = max( 500, absint( $incoming["max_characters"] ?? $existing["max_characters"] ) );
        $clean["primary_color"] = self::sanitize_color( $incoming["primary_color"] ?? $existing["primary_color"] );
        $clean["player_label"] = sanitize_text_field( $incoming["player_label"] ?? $existing["player_label"] );
        $clean["player_size"] = array_key_exists( sanitize_key( $incoming["player_size"] ?? "" ), self::size_options() ) ? sanitize_key( $incoming["player_size"] ) : ( $existing["player_size"] ?? "default" );
        $clean["player_template"] = array_key_exists( sanitize_key( $incoming["player_template"] ?? "" ), self::template_options() ) ? sanitize_key( $incoming["player_template"] ) : ( $existing["player_template"] ?? "clean_card" );
        $clean["auto_player_placement"] = array_key_exists( sanitize_key( $incoming["auto_player_placement"] ?? "" ), self::placement_options() ) ? sanitize_key( $incoming["auto_player_placement"] ) : ( $existing["auto_player_placement"] ?? "above_article" );
        $clean["last_status"] = is_array( $existing["last_status"] ?? null ) ? $existing["last_status"] : [];
        update_option( self::OPTION, $clean, false );
        self::purge_frontend_cache();
        wp_safe_redirect( add_query_arg( [ "page" => self::SETTINGS_SLUG, "tab" => $tab, "hexa_tts_saved" => "1" ], admin_url( "options-general.php" ) ) );
        exit;
    }

    public static function handle_import_elementor_color() {
        if ( ! current_user_can( "manage_options" ) ) {
            wp_die( "Unauthorized." );
        }
        check_admin_referer( self::NONCE_ACTION, "smp_wp_text_to_speech_nonce" );
        $color = self::detect_elementor_primary_color();
        $settings = self::get_settings();
        $imported = "no";
        if ( $color ) {
            $settings["primary_color"] = $color;
            update_option( self::OPTION, $settings, false );
            self::purge_frontend_cache();
            $imported = "yes";
        }
        wp_safe_redirect( add_query_arg( [ "page" => self::SETTINGS_SLUG, "tab" => "display", "hexa_tts_imported" => $imported ], admin_url( "options-general.php" ) ) );
        exit;
    }

    private static function detect_elementor_primary_color(): string {
        $kit_id = absint( get_option( "elementor_active_kit" ) );
        if ( $kit_id ) {
            $settings = get_post_meta( $kit_id, "_elementor_page_settings", true );
            if ( is_array( $settings ) ) {
                foreach ( [ "system_colors", "custom_colors" ] as $group ) {
                    if ( empty( $settings[ $group ] ) || ! is_array( $settings[ $group ] ) ) {
                        continue;
                    }
                    foreach ( $settings[ $group ] as $item ) {
                        if ( ! is_array( $item ) || empty( $item["color"] ) ) {
                            continue;
                        }
                        $identity = strtolower( (string) ( $item["_id"] ?? "" ) . " " . ( $item["title"] ?? "" ) );
                        if ( false !== strpos( $identity, "primary" ) ) {
                            return self::sanitize_color( $item["color"] );
                        }
                    }
                }
            }
        }
        $legacy = get_option( "elementor_scheme_color" );
        if ( is_array( $legacy ) ) {
            foreach ( [ "1", "primary", "color_1" ] as $key ) {
                if ( ! empty( $legacy[ $key ] ) ) {
                    return self::sanitize_color( $legacy[ $key ] );
                }
            }
        }
        return "";
    }

    private static function sanitize_color( $value ): string {
        $color = function_exists( "sanitize_hex_color" ) ? sanitize_hex_color( (string) $value ) : "";
        return $color ?: "#3657e3";
    }

    private static function sanitize_display_settings( array $incoming, array $existing, bool $checkbox_context = false ): array {
        $clean = $existing;
        $clean["acf_audio_field"] = AcfAudioFieldResolver::fieldName( [ "acf_audio_field" => $incoming["acf_audio_field"] ?? $existing["acf_audio_field"] ?? "article_audio" ] );
        $clean["primary_color"] = self::sanitize_color( $incoming["primary_color"] ?? $existing["primary_color"] ?? "#3657e3" );
        $clean["player_label"] = sanitize_text_field( $incoming["player_label"] ?? $existing["player_label"] ?? "Listen to this article" );
        $clean["player_size"] = array_key_exists( sanitize_key( $incoming["player_size"] ?? "" ), self::size_options() ) ? sanitize_key( $incoming["player_size"] ) : ( $existing["player_size"] ?? "default" );
        $clean["player_template"] = array_key_exists( sanitize_key( $incoming["player_template"] ?? "" ), self::template_options() ) ? sanitize_key( $incoming["player_template"] ) : ( $existing["player_template"] ?? "clean_card" );
        $clean["auto_player_placement"] = array_key_exists( sanitize_key( $incoming["auto_player_placement"] ?? "" ), self::placement_options() ) ? sanitize_key( $incoming["auto_player_placement"] ) : ( $existing["auto_player_placement"] ?? "above_article" );
        $clean["auto_insert_player"] = array_key_exists( "auto_insert_player", $incoming ) ? 1 : ( $checkbox_context ? 0 : (int) ( $existing["auto_insert_player"] ?? 1 ) );
        $clean["include_title"] = array_key_exists( "include_title", $incoming ) ? 1 : ( $checkbox_context ? 0 : (int) ( $existing["include_title"] ?? 1 ) );
        $clean["show_player_meta"] = array_key_exists( "show_player_meta", $incoming ) ? 1 : ( $checkbox_context ? 0 : (int) ( $existing["show_player_meta"] ?? 1 ) );
        $clean["enable_player_controls"] = array_key_exists( "enable_player_controls", $incoming ) ? 1 : ( $checkbox_context ? 0 : (int) ( $existing["enable_player_controls"] ?? 1 ) );
        return $clean;
    }

    /**
     * The site's working Publish Scale key (decrypted), for other SMP plugins
     * that use the same key, e.g. SMP content generation:
     * apply_filters( "smp_tts_site_api_key", "" ).
     */
    public static function site_api_key_filter( $value ) {
        $key = (string) self::api_key();
        return "" !== $key ? $key : $value;
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
