<?php

namespace smp_text_to_speech\Concerns;

use Hexa\PluginCore\CoreBootstrap\CoreBootstrap;
use Hexa\PluginCore\CorePackageUpdates\CorePackageAjaxController;
use Hexa\PluginCore\CorePackageUpdates\CorePackageStatus;
use Hexa\PluginCore\CoreRuntime\PluginContext;
use Hexa\PluginCore\PluginUpdates\GitHubPluginUpdater;
use Hexa\PluginCore\PluginUpdates\PluginUpdateStatus;
use Hexa\PluginCore\PluginUpdates\UpdaterAjaxController;
use Hexa\PluginCore\WpAdminTabs\CoreTabConfig;
use Hexa\PluginCore\WpAdminTabs\CoreTabModule;
use Hexa\PluginCore\WpAdminTabs\HostTabsRenderer;
use Hexa\PluginCore\WpAdminTabs\TabDefinition;
use Hexa\PluginCore\WpAdminTabs\TabRegistry;
use Smp\TextToSpeech\Admin\SettingsNavigation;
use Smp\TextToSpeech\Support\AcfAudioFieldResolver;
use Smp\TextToSpeech\Support\AudioAttachmentGuard;
use Smp\TextToSpeech\Support\PostVisibility;
use Smp\TextToSpeech\Support\Text;
use WP_Error;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

trait BootsPlugin {
    public static function activate() {
        if ( ! get_option( self::OPTION ) ) {
            add_option( self::OPTION, self::default_settings(), "", false );
        }
    }

    public static function register_admin_menu() {
        add_options_page( "SMP WP Text To Speech", "SMP WP Text To Speech", "manage_options", self::SETTINGS_SLUG, [ __CLASS__, "render_settings_page" ] );
    }

    public static function plugin_action_links( array $links ): array {
        $settings_link = '<a href="' . esc_url( admin_url( "options-general.php?page=" . self::SETTINGS_SLUG ) ) . '">Settings</a>';
        array_unshift( $links, $settings_link );
        return $links;
    }

    private static function plugin_basename(): string {
        return plugin_basename( \smp_text_to_speech\PluginMetadata::main_file() );
    }

    private static function core_root(): string {
        return \smp_text_to_speech\PluginMetadata::dir() . "/lib/hexa-wordpress-plugin-core";
    }

    /** Updater settings for the update-center tab. */
    public static function updater_panel_config() {
        return self::updater_config();
    }

    /** Bundled Core settings for the update-center tab. */
    public static function core_panel_config() {
        return self::core_package_config();
    }

    private static function updater_config() {
        if ( ! class_exists( "\\Hexa\\PluginCore\\PluginUpdates\\UpdaterConfig" ) ) {
            return null;
        }

        static $config = null;
        if ( $config instanceof \Hexa\PluginCore\PluginUpdates\UpdaterConfig ) {
            return $config;
        }

        $config = \Hexa\PluginCore\PluginUpdates\UpdaterConfig::from_plugin_file(
            \smp_text_to_speech\PluginMetadata::main_file(),
            self::GITHUB_REPO,
            [
                "plugin_slug"               => self::SETTINGS_SLUG,
                "proper_folder_name"        => self::SETTINGS_SLUG,
                "runtime_folder_name"       => self::SETTINGS_SLUG,
                "plugin_basename"           => self::plugin_basename(),
                "canonical_plugin_basename" => self::SETTINGS_SLUG . "/smp-wp-text-to-speech.php",
                "plugin_starter_file"       => "smp-wp-text-to-speech.php",
                "github_branch"             => self::GITHUB_BRANCH,
                "requires"                  => "6.0",
                "tested"                    => "6.8",
                "requires_php"              => "8.0",
                "nonce_action"              => self::NONCE_ACTION,
                "nonce_param"               => "nonce",
                "ajax_action_prefix"        => "smp_wp_text_to_speech_core_updater",
                "progress_key"              => "smp_wp_text_to_speech_core_update_progress",
            ]
        );

        return $config;
    }

    private static function core_package_config() {
        if ( ! class_exists( "\\Hexa\\PluginCore\\CorePackageUpdates\\CorePackageConfig" ) ) {
            return null;
        }

        static $config = null;
        if ( $config instanceof \Hexa\PluginCore\CorePackageUpdates\CorePackageConfig ) {
            return $config;
        }

        $config = \Hexa\PluginCore\CorePackageUpdates\CorePackageConfig::from_core_root(
            self::core_root(),
            [
                "github_repo"        => "mikeyperes/hexa-wordpress-plugin-core",
                "github_branch"      => "main",
                "nonce_action"       => self::NONCE_ACTION,
                "nonce_param"        => "nonce",
                "ajax_action_prefix" => "smp_wp_text_to_speech_core_package",
                "cache_key"          => "smp_wp_text_to_speech_hexa_plugin_core_package",
            ]
        );

        return $config;
    }

    private static function boot_hexa_core(): void {
        static $booted = false;
        if ( $booted ) {
            return;
        }

        if ( ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && ! ( defined( "WP_CLI" ) && WP_CLI ) ) {
            return;
        }

        if ( ! class_exists( CoreBootstrap::class ) || ! class_exists( PluginContext::class ) ) {
            return;
        }

        $context = new PluginContext(
            [
                "slug"        => self::SETTINGS_SLUG,
                "basename"    => self::plugin_basename(),
                "version"     => self::VERSION,
                "path"        => plugin_dir_path( \smp_text_to_speech\PluginMetadata::main_file() ),
                "url"         => plugin_dir_url( \smp_text_to_speech\PluginMetadata::main_file() ),
                "github_repo" => self::GITHUB_REPO,
                "admin_page"  => self::SETTINGS_SLUG,
                "capability"  => "manage_options",
            ]
        );
        $core = new CoreBootstrap( $context );
        $updater_config = self::updater_config();
        if ( $updater_config ) {
            $core->add_module( new GitHubPluginUpdater( $updater_config ) );
        }

        if ( is_admin() || wp_doing_ajax() ) {
            if ( $updater_config ) {
                $core->add_module( new UpdaterAjaxController( $updater_config ) );
            }

            $core_config = self::core_package_config();
            if ( $core_config ) {
                $core->add_module( new CorePackageAjaxController( $core_config ) );
            }

            $core->add_module(
                new CoreTabModule(
                    new CoreTabConfig(
                        [
                            "tab_id"        => "hexa_core",
                            "label"         => "Hexa WP Core",
                            "tabs_filter"   => "smp_wp_text_to_speech_dashboard_tabs",
                            "render_filter" => "smp_wp_text_to_speech_render_dashboard_tab",
                            "capability"    => "manage_options",
                            "core_root"     => self::core_root(),
                            "readme_path"   => self::core_root() . "/README.md",
                            "library_path"  => \smp_text_to_speech\PluginMetadata::dir() . "/HEXA_PLUGIN_CORE_LIBRARY.md",
                        ]
                    )
                )
            );
        }

        $core->boot();
        $booted = true;
    }
}
