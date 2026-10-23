<?php

declare(strict_types=1);

$root = dirname( __DIR__ );
$plugin = file_get_contents( $root . '/smp-wp-text-to-speech.php' );
$script = file_get_contents( $root . '/assets/admin.js' );
$style = file_get_contents( $root . '/assets/admin.css' );

if ( ! is_string( $plugin ) || ! is_string( $script ) || ! is_string( $style ) ) {
    fwrite( STDERR, "FAIL: Diagnostic source files could not be read.\n" );
    exit( 1 );
}

$required = [
    'wp_ajax_hexa_tts_check_credits',
    'wp_ajax_hexa_tts_check_health',
    'self::api_request( "/credits", [], 120 )',
    'self::api_request( "/health", [], 120 )',
    'private static function local_health_checks',
    'class="button button-secondary hexa-tts-check-credits"',
    'class="button button-secondary hexa-tts-check-health"',
    'class="hexa-tts-health-checklist"',
];

foreach ( $required as $token ) {
    if ( ! str_contains( $plugin, $token ) ) {
        fwrite( STDERR, "FAIL: Missing plugin diagnostic token: {$token}\n" );
        exit( 1 );
    }
}

foreach ( [ 'renderCredit', 'renderHealth', 'hexa_tts_check_credits', 'hexa_tts_check_health' ] as $token ) {
    if ( ! str_contains( $script, $token ) ) {
        fwrite( STDERR, "FAIL: Missing diagnostic script token: {$token}\n" );
        exit( 1 );
    }
}

foreach ( [ '.hexa-tts-diagnostics', '.hexa-tts-credit-result.is-success', '.hexa-tts-health-checklist li.is-pass' ] as $token ) {
    if ( ! str_contains( $style, $token ) ) {
        fwrite( STDERR, "FAIL: Missing diagnostic style token: {$token}\n" );
        exit( 1 );
    }
}

echo "PASS: Credit and health diagnostics are wired through secured WordPress AJAX actions.\n";
