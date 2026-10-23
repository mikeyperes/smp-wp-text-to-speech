<?php

namespace smp_text_to_speech\Modules;

use smp_text_to_speech\Plugin;
use smp_text_to_speech\PluginMetadata;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

/** API key, credit and health checks on the settings page and post editor. */
final class DiagnosticsModule {
    public function register(): void {
        // Credit and health checks also run from the post editor, so they need
        // edit_posts; each handler then checks the exact post or settings page.
        Plugin::register_ajax(
            [
                "validate_central_api" => [ "callback" => [ Plugin::class, "ajax_validate_central_api" ], "capability" => PluginMetadata::CAPABILITY ],
                "validate_provider"    => [ "callback" => [ Plugin::class, "ajax_validate_central_api" ], "capability" => PluginMetadata::CAPABILITY ],
                "fetch_source_api_key" => [ "callback" => [ Plugin::class, "ajax_fetch_source_api_key" ], "capability" => PluginMetadata::CAPABILITY ],
                "check_credits"        => [ "callback" => [ Plugin::class, "ajax_check_credits" ], "capability" => "edit_posts" ],
                "check_health"         => [ "callback" => [ Plugin::class, "ajax_check_health" ], "capability" => "edit_posts" ],
            ]
        );
    }
}
