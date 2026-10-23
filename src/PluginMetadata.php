<?php

namespace smp_text_to_speech;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

/**
 * The plugin's identity in one place. VERSION always matches the header in
 * smp-wp-text-to-speech.php.
 */
final class PluginMetadata {
    public const NAME = "SMP WP Text To Speech";
    public const SLUG = "smp-wp-text-to-speech";
    public const PREFIX = "smp_wp_text_to_speech";
    public const ADMIN_PAGE = "smp-wp-text-to-speech";
    public const CAPABILITY = "manage_options";
    public const MAIN_FILE = "smp-wp-text-to-speech.php";
    public const REPO = "mikeyperes/smp-wp-text-to-speech";
    public const BRANCH = "main";
    public const VERSION = "1.6.2";

    public static function main_file(): string {
        return self::dir() . "/" . self::MAIN_FILE;
    }

    public static function dir(): string {
        return dirname( __DIR__ );
    }

    /** Prefixed hook, option or AJAX action name. */
    public static function hook( string $name ): string {
        return self::PREFIX . "_" . $name;
    }
}
