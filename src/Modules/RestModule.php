<?php

namespace smp_text_to_speech\Modules;

use smp_text_to_speech\Plugin;
use smp_text_to_speech\PluginMetadata;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

/** smp-tts/v1 REST routes Publish calls (key claim, schema). */
final class RestModule {
    public function register(): void {
        add_action( "rest_api_init", [ Plugin::class, "register_rest_routes" ] );
    }
}
