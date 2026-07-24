<?php

declare(strict_types=1);

$root = dirname( __DIR__ );
$css = file_get_contents( $root . '/assets/frontend-1.3.18.css' );
$plugin = file_get_contents( $root . '/smp-wp-text-to-speech.php' );

if ( ! is_string( $css ) || ! is_string( $plugin ) ) {
    fwrite( STDERR, "FAIL: Clean Card design sources could not be read.\n" );
    exit( 1 );
}

if ( str_contains( $plugin, 'assets/frontend.css' ) || ! str_contains( $plugin, 'assets/frontend-1.3.18.css' ) ) {
    fwrite( STDERR, "FAIL: The player must use its release-fingerprinted stylesheet path.\n" );
    exit( 1 );
}

if (
    ! preg_match(
        '/\.hexa-tts-player--clean_card\s*\{[^}]*border-top:\s*4px solid var\(--smp-tts-primary\);[^}]*border-top-left-radius:\s*0;[^}]*border-top-right-radius:\s*0;[^}]*\}/s',
        $css
    )
) {
    fwrite( STDERR, "FAIL: Clean Card must retain a straight accent and square top corners.\n" );
    exit( 1 );
}

if ( ! str_contains( $plugin, 'Article-audio card with a straight top accent and softly rounded lower corners.' ) ) {
    fwrite( STDERR, "FAIL: Clean Card description does not match its rendered design.\n" );
    exit( 1 );
}

echo "PASS: Clean Card uses a straight top accent with rounded lower corners.\n";
