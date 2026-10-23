<?php

namespace smp_text_to_speech\Modules;

use smp_text_to_speech\Plugin;
use smp_text_to_speech\PluginMetadata;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

/**
 * Shares the Publish site key and the audio switch with other plugins.
 * smp_tts_site_api_key is the legacy name SMP Publication Integration reads.
 */
final class SiteKeyModule {
    public function register(): void {
        add_filter( "smp_tts_site_api_key", [ Plugin::class, "site_api_key_filter" ] );
        add_filter( PluginMetadata::hook( "site_api_key" ), [ Plugin::class, "site_api_key_filter" ] );
        add_filter( PluginMetadata::hook( "article_audio_enabled" ), [ $this, "article_audio_enabled" ] );
    }

    public function article_audio_enabled( $enabled = false ): bool {
        return Plugin::article_audio_enabled();
    }
}
