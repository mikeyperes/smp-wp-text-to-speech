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
        add_action( "wp_ajax_" . PluginMetadata::hook( "validate_central_api" ), [ Plugin::class, "ajax_validate_central_api" ] );
        add_action( "wp_ajax_" . PluginMetadata::hook( "validate_provider" ), [ Plugin::class, "ajax_validate_central_api" ] );
        add_action( "wp_ajax_" . PluginMetadata::hook( "check_credits" ), [ Plugin::class, "ajax_check_credits" ] );
        add_action( "wp_ajax_" . PluginMetadata::hook( "check_health" ), [ Plugin::class, "ajax_check_health" ] );
        add_action( "wp_ajax_" . PluginMetadata::hook( "fetch_source_api_key" ), [ Plugin::class, "ajax_fetch_source_api_key" ] );
    }
}
