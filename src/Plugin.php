<?php

namespace smp_text_to_speech;

use smp_text_to_speech\Concerns\BootsPlugin;
use smp_text_to_speech\Concerns\BuildsAudioSchema;
use smp_text_to_speech\Concerns\GeneratesAudio;
use smp_text_to_speech\Concerns\HandlesRestRoutes;
use smp_text_to_speech\Concerns\LoadsAdminAssets;
use smp_text_to_speech\Concerns\ManagesSettings;
use smp_text_to_speech\Concerns\RendersPlayer;
use smp_text_to_speech\Concerns\RendersPostEditor;
use smp_text_to_speech\Concerns\RendersSettingsPage;
use smp_text_to_speech\Concerns\RunsDiagnostics;
use smp_text_to_speech\Concerns\StoresAudio;
use smp_text_to_speech\Modules\AdminModule;
use smp_text_to_speech\Modules\DiagnosticsModule;
use smp_text_to_speech\Modules\PlayerModule;
use smp_text_to_speech\Modules\PostEditorModule;
use smp_text_to_speech\Modules\RestModule;
use smp_text_to_speech\Modules\SchemaModule;
use smp_text_to_speech\Modules\SiteKeyModule;
use smp_text_to_speech\Modules\UpdateCenterModule;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

/**
 * Plugin facade. Publish calls generate_for_post(), get_settings(),
 * prepare_generation_content() and encrypt_secret() on this exact class over
 * SSH, so its name and those method names are a cross-system contract.
 * Behaviour lives in the Concerns traits; hooks are added by the Modules.
 */
final class Plugin {
    use BootsPlugin;
    use LoadsAdminAssets;
    use ManagesSettings;
    use RendersSettingsPage;
    use RunsDiagnostics;
    use RendersPostEditor;
    use GeneratesAudio;
    use StoresAudio;
    use RendersPlayer;
    use BuildsAudioSchema;
    use HandlesRestRoutes;

    const VERSION = PluginMetadata::VERSION;
    // Stored data name shared with Publish (config smp-text-to-speech.wordpress_option); never rename.
    const OPTION = "hexa_tts_settings";
    const NONCE_ACTION = "smp_wp_text_to_speech_admin";
    const SETTINGS_SLUG = PluginMetadata::ADMIN_PAGE;
    const API_BASE = "https://publish.scalemypublication.com/api/smp-text-to-speech/v1";
    const GITHUB_REPO = PluginMetadata::REPO;
    const GITHUB_BRANCH = PluginMetadata::BRANCH;
    const REST_NS = "smp-tts/v1";

    public static function init(): void {
        self::boot_hexa_core();
        foreach ( self::modules() as $module ) {
            $module->register();
        }
    }

    /** @return array<int,object> */
    private static function modules(): array {
        return [
            new AdminModule(),
            new DiagnosticsModule(),
            new UpdateCenterModule(),
            new RestModule(),
            new SiteKeyModule(),
            new PlayerModule(),
            new SchemaModule(),
            new PostEditorModule(),
        ];
    }

    /**
     * The "Listen to this article" switch. Off removes the player, its
     * styles and scripts, the audio schema, the editor box and audio field,
     * and refuses new generation.
     */
    public static function article_audio_enabled(): bool {
        $settings = self::get_settings();

        return ! empty( $settings["article_audio_enabled"] );
    }
}
