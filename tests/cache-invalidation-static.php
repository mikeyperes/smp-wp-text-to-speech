<?php

declare(strict_types=1);

$plugin = file_get_contents( dirname( __DIR__ ) . '/smp-wp-text-to-speech.php' );

if ( ! is_string( $plugin ) ) {
    fwrite( STDERR, "FAIL: Plugin source could not be read.\n" );
    exit( 1 );
}

$required_tokens = [
    'private static function purge_frontend_cache( int $post_id = 0 ): void',
    'do_action( "litespeed_purge_post", $post_id );',
    'do_action( "litespeed_purge_url", $permalink );',
    '[ "litespeed_purge_all", "litespeed_purge_all_object" ]',
    'rocket_clean_post( $post_id );',
    'rocket_clean_domain();',
    'w3tc_flush_post( $post_id );',
    'w3tc_flush_all();',
];

foreach ( $required_tokens as $token ) {
    if ( ! str_contains( $plugin, $token ) ) {
        fwrite( STDERR, "FAIL: Cache invalidation token is missing: {$token}\n" );
        exit( 1 );
    }
}

if (
    ! preg_match( '/function handle_save_settings\(\).*?update_option\( self::OPTION, \$clean, false \);\s*self::purge_frontend_cache\(\);/s', $plugin )
    || ! preg_match( '/function handle_import_elementor_color\(\).*?update_option\( self::OPTION, \$settings, false \);\s*self::purge_frontend_cache\(\);/s', $plugin )
    || ! preg_match( '/function sync_audio_attachment\(.*?AcfAudioFieldResolver::updatePostValue\(.*?self::purge_frontend_cache\( \(int\) \$post_id \);/s', $plugin )
) {
    fwrite( STDERR, "FAIL: A settings or audio mutation does not invalidate its frontend cache.\n" );
    exit( 1 );
}

echo "PASS: Global display changes and per-post audio changes invalidate supported frontend caches.\n";
