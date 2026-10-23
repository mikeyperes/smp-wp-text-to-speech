<?php
/**
 * Plugin structure (thin main file, metadata, modules) and the
 * "Listen to this article" switch that removes the whole feature when off.
 */

declare(strict_types=1);

$root = dirname( __DIR__ );
$read = static fn( string $path ): string => (string) file_get_contents( $root . '/' . $path );

$main = $read( 'smp-wp-text-to-speech.php' );
$metadata = $read( 'src/PluginMetadata.php' );
$plugin = $read( 'src/Plugin.php' );
$player = $read( 'src/Modules/PlayerModule.php' );
$schema = $read( 'src/Modules/SchemaModule.php' );
$editor = $read( 'src/Modules/PostEditorModule.php' );
$site_key = $read( 'src/Modules/SiteKeyModule.php' );
$player_code = $read( 'src/Concerns/RendersPlayer.php' );
$generation = $read( 'src/Concerns/GeneratesAudio.php' );
$schema_code = $read( 'src/Concerns/BuildsAudioSchema.php' );
$settings = $read( 'src/Concerns/ManagesSettings.php' );
$features = $read( 'src/Concerns/RendersSettingsPage.php' );

preg_match( '/\* Version: ([0-9.]+)/', $main, $header );

$checks = [
    'Main file only guards, autoloads, registers Core and boots.' => substr_count( $main, "\n" ) < 80
        && ! str_contains( $main, 'class Plugin' )
        && str_contains( $main, 'SMP_WP_TEXT_TO_SPEECH_LOADED' )
        && str_contains( $main, 'hexa_plugin_core_register_package(' )
        && str_contains( $main, 'spl_autoload_register(' )
        && str_contains( $main, 'add_action( "plugins_loaded", [ Plugin::class, "init" ], 0 );' ),
    'PluginMetadata version matches the header.' => isset( $header[1] ) && str_contains( $metadata, 'public const VERSION = "' . $header[1] . '";' ),
    'Publish contract methods stay on smp_text_to_speech\\Plugin.' => str_contains( $plugin, 'namespace smp_text_to_speech;' ) && str_contains( $plugin, 'final class Plugin {' )
        && str_contains( $generation, 'public static function generate_for_post(' ) && str_contains( $generation, 'function prepare_generation_content(' )
        && str_contains( $settings, 'public static function get_settings()' ) && str_contains( $settings, 'function encrypt_secret(' ),
    'Settings use the prefixed option and move the old one across.' => str_contains( $plugin, 'const OPTION = "smp_wp_text_to_speech_settings";' ) && str_contains( $plugin, 'const LEGACY_OPTION = "hexa_tts_settings";' ) && str_contains( $settings, 'function migrate_legacy_settings()' ),
    'Settings panels are Hexa WP Core cards.' => ! str_contains( $features, '<section class="hexa-tts-panel' ) && substr_count( $features, 'CoreUi::card(' ) >= 8,
    'Action and copy buttons are Hexa WP Core buttons.' => ! preg_match( '/class="button[ "]/', $features . $read( 'src/Concerns/RendersPostEditor.php' ) . $read( 'src/Concerns/RunsDiagnostics.php' ) ) && str_contains( $read( 'src/Concerns/RunsDiagnostics.php' ), 'DynamicButton::render(' ) && str_contains( $features, 'CoreUi::copy_button(' ),
    'Checkboxes are Hexa WP Core toggles.' => ! str_contains( $features . $read( 'src/Concerns/RendersPostEditor.php' ), 'hexa-tts-check-row' ),
    'The switch defaults on and is saved from the Features tab.' => str_contains( $settings, '"article_audio_enabled" => 1,' )
        && str_contains( $settings, '$clean["article_audio_enabled"] = array_key_exists( "article_audio_enabled", $incoming )' )
        && str_contains( $features, 'CoreUi::toggle( "hexa_tts[article_audio_enabled]"' ) && str_contains( $features, '"title" => "Listen to this article"' ),
    'Off: no automatic player, assets or content filters.' => (bool) preg_match( '/add_shortcode\( "smp_tts_player".*?if \( ! Plugin::article_audio_enabled\(\) \) \{\s+return;\s+\}\s+add_action\( "wp_enqueue_scripts"/s', $player ),
    'Off: shortcodes print nothing.' => (bool) preg_match( '/function render_player_shortcode\( \$atts = \[\] \) \{\s+if \( ! self::article_audio_enabled\(\) \) \{\s+return "";/', $player_code ),
    'Off: no audio schema.' => str_contains( $schema, 'if ( ! Plugin::article_audio_enabled() ) {' ) && str_contains( $schema_code, '! self::article_audio_enabled()' ),
    'Off: no editor box, and the audio field is hidden.' => str_contains( $editor, '\\Hexa\\PluginCore\\Fields\\Hooks::on( "prepare_field/name=" . Plugin::audio_field_name(), "__return_false" );' ),
    'Off: generation is refused.' => str_contains( $generation, '"hexa_tts_feature_disabled"' ) && substr_count( $generation, 'self::article_audio_enabled()' ) >= 2,
    'Other plugins can read the switch.' => str_contains( $site_key, 'PluginMetadata::hook( "article_audio_enabled" )' ),
    'Front-end assets load only where a player is shown.' => str_contains( $player_code, 'is_singular( [ "post", "press-release" ] )' )
        && str_contains( $player_code, 'self::player_audio_url( (int) get_queried_object_id() )' )
        && str_contains( $player_code, "if ( ! is_admin() ) {\n            self::enqueue_player_assets();" ),
    'Update Center tab is registered.' => str_contains( $read( 'src/Admin/SettingsNavigation.php' ), '"update-center" => "Update Center"' )
        && str_contains( $read( 'src/Modules/UpdateCenterModule.php' ), 'UpdaterPanelRenderer' ) && str_contains( $read( 'src/Modules/UpdateCenterModule.php' ), 'CorePackagePanelRenderer' ),
    'AJAX actions go through Hexa WP Core with capability and nonce checks.' => str_contains( $plugin, 'AjaxActionRegistry::create(' )
        && ! preg_match( '/check_ajax_referer|add_action\( "wp_ajax_/', $read( 'src/Modules/AdminModule.php' ) . $read( 'src/Modules/DiagnosticsModule.php' ) . $read( 'src/Modules/PostEditorModule.php' ) . $generation . $settings . $features . $read( 'src/Concerns/RunsDiagnostics.php' ) . $read( 'src/Concerns/RendersPostEditor.php' ) ),
    'The site key lives in Hexa WP Core CredentialVault.' => str_contains( $settings, 'new CredentialStore()' ) && str_contains( $plugin, 'const VAULT_MARKER' ) && ! str_contains( $settings, 'openssl_encrypt' ),
    'The generation log uses Hexa WP Core ActivityLogger.' => str_contains( $generation, 'new ActivityLogger(' ) && ! str_contains( $generation, 'update_post_meta( $post_id, "_hexa_tts_activity_log"' ),
    'The Shortcodes tab uses Hexa WP Core ShortcodeBreakdown.' => str_contains( $features, 'ShortcodeBreakdown::render(' ),
    'Features cards and toggles come from Hexa WP Core.' => str_contains( $features, 'CoreUi::card(' ) && str_contains( $features, 'CoreUi::toggle( "hexa_tts[article_audio_enabled]"' ) && ! str_contains( $features, 'hexa-tts-switch' ),
    'Settings fields are one column.' => ! preg_match( '/\.(hexa-tts-(grid-4|grid-2|profile-grid|provider-fields|editor-grid|feature-grid|placement-map|template-grid)|httf-grid)[^{}]*\{[^}]*grid-template-columns:\s*repeat\([2-5]/', $read( 'assets/admin.css' ) . $read( 'src/Concerns/LoadsAdminAssets.php' ) . $features ),
    'Root Core guide matches the bundled Core.' => $read( 'HEXA_PLUGIN_CORE_LIBRARY.md' ) === $read( 'lib/hexa-wordpress-plugin-core/HEXA_PLUGIN_CORE_LIBRARY.md' ),
    'README follows the feature-base shape.' => (bool) preg_match( '/^# [^\n]+\n\n> Feature base for HWS Skills\./', $read( 'README.md' ) ) && str_contains( $read( 'README.md' ), '**Depends on:**' ) && str_contains( $read( 'README.md' ), "\n## Changelog\n" ),
    'Repository docs exist.' => is_readable( $root . '/AGENTS.md' ) && is_readable( $root . '/docs/asset-loading.md' ),
];

$failed = 0;
foreach ( $checks as $label => $passed ) {
    echo ( $passed ? 'PASS' : 'FAIL' ) . ' - ' . $label . PHP_EOL;
    $failed += $passed ? 0 : 1;
}
exit( $failed ? 1 : 0 );
