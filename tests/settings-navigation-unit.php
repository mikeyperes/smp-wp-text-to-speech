<?php

declare(strict_types=1);

function sanitize_key( string $value ): string {
    return strtolower( preg_replace( '/[^a-zA-Z0-9_-]/', '', $value ) ?: '' );
}

function apply_filters( string $hook, mixed $value, ...$arguments ): mixed {
    if ( 'smp_tts_dashboard_tabs' === $hook && is_array( $value ) ) {
        $value['hexa_core'] = 'Hexa WP Core';
        $value['extension_diagnostics'] = 'Extension Diagnostics';
    }

    return $value;
}

$root = dirname( __DIR__ );
require $root . '/lib/hexa-wordpress-plugin-core/src/WpAdminTabs/TabDefinition.php';
require $root . '/lib/hexa-wordpress-plugin-core/src/WpAdminTabs/TabRegistry.php';
require $root . '/src/Admin/SettingsNavigation.php';

use Hexa\PluginCore\WpAdminTabs\TabDefinition;
use Hexa\PluginCore\WpAdminTabs\TabRegistry;
use Smp\TextToSpeech\Admin\SettingsNavigation;

$navigation = new SettingsNavigation();
$expected = [
    'overview'              => 'Dashboard',
    'api'                   => 'API Settings',
    'features'              => 'Features',
    'display'               => 'Display',
    'shortcodes'            => 'Shortcodes',
    'schema'                => 'Schema',
    'hexa_core'             => 'Hexa WP Core',
    'extension_diagnostics' => 'Extension Diagnostics',
];

if ( $expected !== $navigation->tabs() ) {
    fwrite( STDERR, "FAIL: Settings navigation does not expose the ordered filtered tab list.\n" );
    exit( 1 );
}

$rendered = [];
$registry = $navigation->registry(
    static function( string $id ) use ( &$rendered ): void {
        $rendered[] = $id;
    },
    'manage_options'
);

if ( ! $registry instanceof TabRegistry || array_keys( $expected ) !== array_keys( $registry->all() ) ) {
    fwrite( STDERR, "FAIL: Every settings tab must be represented in the Hexa WP Core registry.\n" );
    exit( 1 );
}

foreach ( $expected as $id => $label ) {
    $definition = $registry->get( $id );
    if (
        ! $definition instanceof TabDefinition
        || $id !== $definition->id
        || $label !== $definition->label
        || 'manage_options' !== $definition->capability
        || ! is_callable( $definition->renderer )
    ) {
        fwrite( STDERR, "FAIL: {$id} is not a complete Hexa WP Core TabDefinition.\n" );
        exit( 1 );
    }
}

call_user_func( $registry->get( 'api' )->renderer );
if ( [ 'api' ] !== $rendered ) {
    fwrite( STDERR, "FAIL: Tab definitions do not invoke the registered TTS renderer.\n" );
    exit( 1 );
}

$groups = $navigation->groups();
if (
    [ 'overview' ] !== ( $groups[0]['tabs'] ?? [] )
    || [ 'api', 'features', 'display' ] !== ( $groups[1]['tabs'] ?? [] )
    || [ 'shortcodes', 'schema', 'hexa_core' ] !== ( $groups[2]['tabs'] ?? [] )
    || [ 'extension_diagnostics' ] !== ( $groups[3]['tabs'] ?? [] )
) {
    fwrite( STDERR, "FAIL: Settings sidebar groups are incomplete or out of order.\n" );
    exit( 1 );
}

if ( 'hexa_core' !== $navigation->resolve( 'hexa-core' ) || 'overview' !== $navigation->resolve( 'invalid' ) ) {
    fwrite( STDERR, "FAIL: Legacy Core and invalid routes do not resolve safely.\n" );
    exit( 1 );
}

echo "PASS: TTS settings use ordered Core tab definitions and grouped sidebar navigation.\n";
