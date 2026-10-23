<?php
/**
 * Plugin Name: SMP WP Text To Speech
 * Plugin URI: https://code.hexawebsystems.com/manual-ai-reports/6/view
 * Description: Publish Scale text-to-speech client for WordPress article narration. Uses hidden server-side API calls, AJAX generation, Media Library storage, and ACF field syncing.
 * Version: 1.6.1
 * Author: Hexa Web Systems
 * Text Domain: smp-wp-text-to-speech
 * Requires at least: 6.0
 * Requires PHP: 8.0
 * GitHub Plugin URI: https://github.com/mikeyperes/smp-wp-text-to-speech/
 * GitHub Branch: main
 */

namespace smp_text_to_speech;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

// Load once even when another copy or Publish's remote loader includes this file.
if ( defined( "SMP_WP_TEXT_TO_SPEECH_LOADED" ) ) {
    return;
}
define( "SMP_WP_TEXT_TO_SPEECH_LOADED", __FILE__ );

$hexa_plugin_core_root = __DIR__ . "/lib/hexa-wordpress-plugin-core";
require_once $hexa_plugin_core_root . "/bootstrap.php";
\hexa_plugin_core_register_package( "smp-wp-text-to-speech", $hexa_plugin_core_root );

/**
 * The plugin's own autoloader for src/: smp_text_to_speech\ (plugin,
 * metadata, modules, concerns) and Smp\TextToSpeech\ (admin and support).
 */
spl_autoload_register(
    static function( string $class_name ): void {
        foreach ( [ "smp_text_to_speech\\", "Smp\\TextToSpeech\\" ] as $prefix ) {
            if ( 0 !== strncmp( $class_name, $prefix, strlen( $prefix ) ) ) {
                continue;
            }
            $file = __DIR__ . "/src/" . str_replace( "\\", DIRECTORY_SEPARATOR, substr( $class_name, strlen( $prefix ) ) ) . ".php";
            if ( is_readable( $file ) ) {
                require_once $file;
            }
            return;
        }
    },
    true,
    true
);

register_activation_hook( __FILE__, [ Plugin::class, "activate" ] );

if ( function_exists( "did_action" ) && did_action( "plugins_loaded" ) ) {
    Plugin::init();
} else {
    add_action( "plugins_loaded", [ Plugin::class, "init" ], 0 );
}
