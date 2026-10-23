<?php

namespace smp_text_to_speech\Modules;

use smp_text_to_speech\Plugin;
use smp_text_to_speech\PluginMetadata;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

/**
 * The update-center tab: Core's plugin updater (Git Report) and Core's
 * bundled-package panel.
 */
final class UpdateCenterModule {
    public const TAB = "update-center";

    public function register(): void {
        add_filter( PluginMetadata::hook( "render_dashboard_tab" ), [ $this, "render_tab" ], 10, 2 );
    }

    public function render_tab( $rendered, string $id ): bool {
        if ( self::TAB !== $id ) {
            return (bool) $rendered;
        }
        $plugin = Plugin::updater_panel_config();
        $core = Plugin::core_panel_config();
        echo '<section class="hexa-tts-update-center">';
        if ( $plugin ) {
            ( new \Hexa\PluginCore\PluginUpdates\UpdaterPanelRenderer( $plugin ) )->render();
        }
        if ( $core ) {
            ( new \Hexa\PluginCore\CorePackageUpdates\CorePackagePanelRenderer( $core ) )->render();
        }
        echo '</section>';

        return true;
    }
}
