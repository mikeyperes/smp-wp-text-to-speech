<?php

namespace smp_text_to_speech\Modules;

use smp_text_to_speech\Plugin;
use smp_text_to_speech\PluginMetadata;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

/** Settings page, its assets, saving and the live display preview. */
final class AdminModule {
    public function register(): void {
        add_action( "admin_menu", [ Plugin::class, "register_admin_menu" ] );
        add_action( "admin_enqueue_scripts", [ Plugin::class, "enqueue_admin_assets" ] );
        add_action( "admin_post_" . PluginMetadata::hook( "save_settings" ), [ Plugin::class, "handle_save_settings" ] );
        add_action( "admin_post_" . PluginMetadata::hook( "import_elementor_color" ), [ Plugin::class, "handle_import_elementor_color" ] );
        Plugin::register_ajax(
            [
                "load_tab"        => [ "callback" => [ Plugin::class, "ajax_load_tab" ], "capability" => PluginMetadata::CAPABILITY ],
                "preview_display" => [ "callback" => [ Plugin::class, "ajax_preview_display" ], "capability" => PluginMetadata::CAPABILITY ],
            ]
        );
        add_filter( "plugin_action_links_" . plugin_basename( PluginMetadata::main_file() ), [ Plugin::class, "plugin_action_links" ] );
    }
}
