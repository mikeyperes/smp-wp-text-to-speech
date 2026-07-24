<?php

declare(strict_types=1);

$root = dirname( __DIR__ );
$plugin = file_get_contents( $root . '/smp-wp-text-to-speech.php' );
$navigation = file_get_contents( $root . '/src/Admin/SettingsNavigation.php' );
$core_version = trim( (string) file_get_contents( $root . '/lib/hexa-wordpress-plugin-core/VERSION' ) );

if ( ! is_string( $plugin ) || ! is_string( $navigation ) ) {
    fwrite( STDERR, "FAIL: Plugin navigation sources could not be read.\n" );
    exit( 1 );
}

$required_plugin_tokens = [
    'use Hexa\\PluginCore\\CoreBootstrap\\CoreBootstrap;',
    'use Hexa\\PluginCore\\CoreRuntime\\PluginContext;',
    'use Hexa\\PluginCore\\WpAdminTabs\\HostTabsRenderer;',
    'use Hexa\\PluginCore\\WpAdminTabs\\TabDefinition;',
    'use Hexa\\PluginCore\\WpAdminTabs\\TabRegistry;',
    'use Hexa\\PluginCore\\PluginUpdates\\PluginUpdateStatus;',
    'use Hexa\\PluginCore\\CorePackageUpdates\\CorePackageStatus;',
    'new CoreBootstrap( $context )',
    '"tab_id"        => "hexa_core"',
    '"layout"              => "sidebar"',
    '"groups"              => $navigation->groups()',
    '"sidebar_identity"    => $sidebar_identity',
    '"sidebar_collapsible" => true',
    '"sidebar_collapsed"   => false',
    '"sidebar_persist"     => true',
    'self::render_registered_tab( $registry, $tab )',
    'new \\Hexa\\PluginCore\\PluginUpdates\\UpdaterPanelRenderer',
    'new \\Hexa\\PluginCore\\CorePackageUpdates\\CorePackagePanelRenderer',
];

foreach ( $required_plugin_tokens as $token ) {
    if ( ! str_contains( $plugin, $token ) ) {
        fwrite( STDERR, "FAIL: Required canonical integration token is missing: {$token}\n" );
        exit( 1 );
    }
}

foreach ( [ 'nav-tab-wrapper', 'private static function settings_tabs' ] as $legacy_token ) {
    if ( str_contains( $plugin, $legacy_token ) ) {
        fwrite( STDERR, "FAIL: Retired plugin-specific navigation remains: {$legacy_token}\n" );
        exit( 1 );
    }
}

if (
    ! str_contains( $navigation, 'new TabDefinition(' )
    || ! str_contains( $navigation, 'new TabRegistry()' )
    || ! str_contains( $navigation, '[ "label" => "Advanced", "tabs" => [ "shortcodes", "schema", "hexa_core" ] ]' )
) {
    fwrite( STDERR, "FAIL: SettingsNavigation is not built from shared Core registry primitives.\n" );
    exit( 1 );
}

if ( '0.19.73' !== $core_version ) {
    fwrite( STDERR, "FAIL: Expected vendored Hexa WP Core 0.19.73; found {$core_version}.\n" );
    exit( 1 );
}

echo "PASS: TTS settings shell uses canonical Hexa WP Core navigation and Git reporting.\n";
