<?php

namespace smp_text_to_speech\Modules;

use smp_text_to_speech\Plugin;
use smp_text_to_speech\PluginMetadata;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

/** AudioObject in Hexa WP Core's shared schema block, only while audio is on. */
final class SchemaModule {
    public function register(): void {
        if ( ! Plugin::article_audio_enabled() ) {
            return;
        }
        \Hexa\PluginCore\SchemaTools\SchemaPage::register( "smp-tts-audio", [ Plugin::class, "current_schema_nodes" ], 30 );
        add_filter( "hexa_plugin_core_schema_graph", [ Plugin::class, "link_audio_to_article" ] );
    }
}
