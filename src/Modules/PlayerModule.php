<?php

namespace smp_text_to_speech\Modules;

use smp_text_to_speech\Plugin;
use smp_text_to_speech\PluginMetadata;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

/**
 * "Listen to this article" player. The shortcodes stay registered while the
 * feature is off so a template's shortcode prints nothing instead of its
 * raw text; automatic placement and assets are added only while it is on.
 */
final class PlayerModule {
    public function register(): void {
        add_shortcode( "hexa_tts_player", [ Plugin::class, "render_player_shortcode" ] );
        add_shortcode( "smp_tts_player", [ Plugin::class, "render_player_shortcode" ] );
        if ( ! Plugin::article_audio_enabled() ) {
            return;
        }
        add_action( "wp_enqueue_scripts", [ Plugin::class, "enqueue_frontend_assets" ] );
        add_filter( "the_content", [ Plugin::class, "maybe_insert_player" ], 12 );
        add_filter( "post_thumbnail_html", [ Plugin::class, "maybe_insert_player_around_featured_image" ], 20, 5 );
    }
}
