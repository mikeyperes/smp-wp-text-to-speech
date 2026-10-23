<?php

namespace smp_text_to_speech\Concerns;

use Hexa\PluginCore\CorePackageUpdates\CorePackageStatus;
use Hexa\PluginCore\PluginUpdates\PluginUpdateStatus;
use Hexa\PluginCore\WpAdminTabs\HostTabsRenderer;
use Hexa\PluginCore\WpAdminTabs\TabDefinition;
use Hexa\PluginCore\WpAdminTabs\TabRegistry;
use Smp\TextToSpeech\Admin\SettingsNavigation;
use Smp\TextToSpeech\Support\AcfAudioFieldResolver;
use Smp\TextToSpeech\Support\Text;

use Hexa\PluginCore\WpAdminComponents\CoreUi;

use Hexa\PluginCore\WpAdminComponents\DynamicButton;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

trait RendersSettingsPage {
    public static function render_settings_page() {
        if ( ! current_user_can( "manage_options" ) ) {
            return;
        }
        $navigation = self::settings_navigation();
        $registry = self::tab_registry( $navigation );
        $tabs = $registry->all();
        $requested = isset( $_GET["tab"] ) ? sanitize_key( wp_unslash( $_GET["tab"] ) ) : "overview";
        $active = $navigation->resolve( $requested );
        $sidebar_identity = self::sidebar_identity();
        $saved = isset( $_GET["hexa_tts_saved"] ) && "1" === $_GET["hexa_tts_saved"];
        $imported = isset( $_GET["hexa_tts_imported"] ) ? sanitize_key( wp_unslash( $_GET["hexa_tts_imported"] ) ) : "";
        ?>
        <div class="wrap hexa-tts-wrap hexa-tts-dashboard">
            <div class="hexa-tts-page-head"><div><h1>SMP WP Text To Speech</h1><p>Backend controls, Hexa WP Core updater, article-audio workflow, frontend player templates, and shortcode placement.</p></div></div>
            <?php if ( $saved ) : ?><div class="notice notice-success is-dismissible"><p>SMP WP Text To Speech settings saved.</p></div><?php endif; ?>
            <?php if ( "yes" === $imported ) : ?><div class="notice notice-success is-dismissible"><p>Imported Elementor primary color into the TTS display settings.</p></div><?php endif; ?>
            <?php if ( "no" === $imported ) : ?><div class="notice notice-warning is-dismissible"><p>No Elementor primary color was found, so the existing TTS display color was kept.</p></div><?php endif; ?>
            <?php
            if ( class_exists( "\\Hexa\\PluginCore\\WpAdminComponents\\CoreUi" ) ) {
                \Hexa\PluginCore\WpAdminComponents\CoreUi::render_assets();
            }
            ( new HostTabsRenderer() )->render(
                [
                    "tabs"                => $tabs,
                    "active"              => $active,
                    "page_url"            => admin_url( "options-general.php?page=" . self::SETTINGS_SLUG ),
                    "ajax_action"         => "smp_wp_text_to_speech_load_tab",
                    "nonce"               => wp_create_nonce( self::NONCE_ACTION ),
                    "nonce_field"         => "nonce",
                    "root_id"             => "smp-tts-core-tabs",
                    "panel_id"            => "smp-tts-tab-panel",
                    "label"               => "SMP WP Text To Speech sections",
                    "layout"              => "sidebar",
                    "groups"              => $navigation->groups(),
                    "sidebar_identity"    => $sidebar_identity,
                    "sidebar_collapsible" => true,
                    "sidebar_collapsed"   => false,
                    "sidebar_persist"     => true,
                    "render_callback"     => function( string $tab ) use ( $registry ): void {
                        self::render_registered_tab( $registry, $tab );
                    },
                ]
            );
            ?>
        </div>
        <?php
    }

    private static function settings_navigation(): SettingsNavigation {
        return new SettingsNavigation();
    }

    private static function tab_registry( ?SettingsNavigation $navigation = null ): TabRegistry {
        $navigation = $navigation ?? self::settings_navigation();

        return $navigation->registry(
            static function( string $id ): void {
                self::render_settings_tab( $id );
            },
            "manage_options"
        );
    }

    private static function render_registered_tab( TabRegistry $registry, string $id ): void {
        $definition = $registry->get( $id ) ?? $registry->get( "overview" );
        if ( ! $definition instanceof TabDefinition ) {
            return;
        }

        if (
            null !== $definition->capability
            && "" !== $definition->capability
            && ! current_user_can( $definition->capability )
        ) {
            echo '<div class="notice notice-error"><p>You do not have permission to view this section.</p></div>';
            return;
        }

        if ( is_callable( $definition->renderer ) ) {
            call_user_func( $definition->renderer );
        }
    }

    private static function tab_label( TabDefinition $definition ): string {
        return $definition->label . ( $definition->deprecated ? " (Deprecated)" : "" );
    }

    /**
     * @return array<string,string>
     */
    private static function sidebar_identity(): array {
        $plugin_status = [];
        $core_status = [];
        $updater_config = self::updater_config();
        $core_config = self::core_package_config();

        if ( $updater_config ) {
            $plugin_status = ( new PluginUpdateStatus( $updater_config ) )->get();
        }
        if ( $core_config ) {
            $core_status = ( new CorePackageStatus( $core_config ) )->get();
        }

        return [
            "plugin_name"     => (string) ( $plugin_status["plugin_name"] ?? "SMP WP Text To Speech" ),
            "current_version" => (string) ( $plugin_status["current_version"] ?? self::VERSION ),
            "github_version"  => (string) ( $plugin_status["latest_version"] ?? "Unknown" ),
            "github_url"      => (string) ( $plugin_status["github_url"] ?? "https://github.com/" . self::GITHUB_REPO ),
            "core_name"       => "Hexa WP Core",
            "core_version"    => (string) ( $core_status["current_version"] ?? "Unknown" ),
            "core_github_url" => (string) ( $core_status["github_url"] ?? "https://github.com/mikeyperes/hexa-wordpress-plugin-core" ),
        ];
    }

    public static function ajax_load_tab() {
        $tab = isset( $_POST["tab"] ) ? sanitize_key( wp_unslash( $_POST["tab"] ) ) : "overview";
        wp_send_json_success( self::tab_fragment( $tab ) );
    }

    private static function tab_fragment( string $id ): array {
        $navigation = self::settings_navigation();
        $registry = self::tab_registry( $navigation );
        $active = $navigation->resolve( $id );
        $definition = $registry->get( $active ) ?? $registry->get( "overview" );

        if ( ! $definition instanceof TabDefinition ) {
            return [ "tab" => "overview", "label" => "Dashboard", "html" => "" ];
        }

        $active = $definition->id;
        ob_start();
        self::render_registered_tab( $registry, $active );
        $html = ob_get_clean();
        return [
            "tab"   => $active,
            "label" => self::tab_label( $definition ),
            "html"  => is_string( $html ) ? $html : "",
        ];
    }

    private static function render_settings_tab( string $id ): void {
        if ( apply_filters( "smp_wp_text_to_speech_render_dashboard_tab", false, $id ) ) {
            return;
        }
        if ( "api" === $id ) { self::render_api_tab(); return; }
        if ( "features" === $id ) { self::render_features_tab(); return; }
        if ( "display" === $id ) { self::render_display_tab(); return; }
        if ( "shortcodes" === $id ) { self::render_shortcodes_tab(); return; }
        if ( "schema" === $id ) { self::render_schema_tab(); return; }
        self::render_overview_tab();
    }

    private static function render_overview_tab(): void {
        $settings = self::get_settings();
        $api_key = self::api_key();
        ?>
        <section class="hexa-tts-hero">
            <p class="hexa-tts-kicker">Article Audio System</p>
            <h2>One-click article narration with Media Library storage, ACF syncing, and front-end player placement.</h2>
            <p>The browser talks to WordPress only. WordPress performs the API request server-side, stores the MP3, and writes the configured ACF audio file field.</p>
        </section>
        <?php ob_start(); ?>
        <div class="hexa-tts-panel-body">
            <div class="hexa-tts-panel-head"><div><p>Current plugin identity and runtime wiring.</p></div><a class="hpc-button secondary" href="<?php echo esc_url( admin_url( "plugins.php" ) ); ?>">Plugins dashboard</a></div>
            <div class="hexa-tts-system-grid">
                <div><span>Plugin slug</span><code><?php echo esc_html( self::SETTINGS_SLUG ); ?></code></div>
                <div><span>Namespace</span><code>smp_text_to_speech</code></div>
                <div><span>GitHub</span><code><?php echo esc_html( self::GITHUB_REPO ); ?></code></div>
                <div><span>Version</span><code><?php echo esc_html( self::VERSION ); ?></code></div>
                <div><span>API key</span><strong><?php echo $api_key ? "Configured" : "Missing"; ?></strong></div>
                <div><span>Auto placement</span><strong><?php echo esc_html( self::placement_options()[ $settings["auto_player_placement"] ] ?? "Above article" ); ?></strong></div>
            </div>
        </div>
        <?php echo CoreUi::card( [ "title" => "System", "body_html" => (string) ob_get_clean() ] ); ?>
        <?php ob_start(); ?>
        <div class="hexa-tts-panel-body hexa-tts-core-panels">
            <div class="hexa-tts-panel-head"><div><p>Updater/status panels are rendered by the vendored Hexa WordPress Plugin Core.</p></div></div>
            <?php
            $updater_config = self::updater_config();
            $core_config = self::core_package_config();
            if ( $updater_config && class_exists( "\\Hexa\\PluginCore\\PluginUpdates\\UpdaterPanelRenderer" ) ) {
                ( new \Hexa\PluginCore\PluginUpdates\UpdaterPanelRenderer( $updater_config ) )->render();
            } else {
                echo '<div class="notice notice-warning inline"><p>Hexa plugin updater panel is not available.</p></div>';
            }
            if ( $core_config && class_exists( "\\Hexa\\PluginCore\\CorePackageUpdates\\CorePackagePanelRenderer" ) ) {
                ( new \Hexa\PluginCore\CorePackageUpdates\CorePackagePanelRenderer( $core_config ) )->render();
            } else {
                echo '<div class="notice notice-warning inline"><p>Hexa core package panel is not available.</p></div>';
            }
            ?>
        </div>
        <?php echo CoreUi::card( [ "title" => "Plugin & Hexa WP Core reporting dashboard", "body_html" => (string) ob_get_clean() ] ); ?>
        <?php
    }

    private static function render_api_tab(): void {
        $settings = self::get_settings();
        $api_key = self::api_key();
        $last_status = is_array( $settings["last_status"] ?? null ) ? $settings["last_status"] : [];
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>" class="hexa-tts-settings-form">
            <?php wp_nonce_field( self::NONCE_ACTION, "smp_wp_text_to_speech_nonce" ); ?>
            <input type="hidden" name="action" value="smp_wp_text_to_speech_save_settings">
            <input type="hidden" name="hexa_tts_tab" value="api">
            <?php ob_start(); ?>
            <div class="hexa-tts-panel-body" data-provider-card="central">
                <div class="hexa-tts-panel-head">
                    <div><p>Enter the assigned site key manually, or securely fetch an existing assignment after this WordPress site proves control of its own domain. The upstream API base is never printed into browser JavaScript.</p></div>
                    <div class="hexa-tts-panel-actions">
                        <?php echo DynamicButton::render( [ "label" => "Fetch Assigned API Key", "working_label" => "Verifying this domain...", "class" => "hpc-button secondary hexa-tts-fetch-source-api-key" ] ); ?>
                        <?php echo DynamicButton::render( [ "label" => "Test API Key", "working_label" => "Testing...", "class" => "hpc-button secondary hexa-tts-test-central-api hexa-tts-test-provider", "attrs" => [ "data-provider" => "central" ] ] ); ?>
                    </div>
                </div>
                <div class="hexa-tts-grid hexa-tts-grid-2">
                    <label><span>Site API Key</span><input class="hexa-tts-site-api-key" type="password" name="hexa_tts[api_key]" value="" placeholder="<?php echo esc_attr( $api_key ? "Saved: " . self::mask_secret( $api_key ) : "Paste Publish Scale site API key" ); ?>" autocomplete="off"><small>Stored encrypted in WordPress. Fetch only retrieves a key already assigned to this exact domain; it cannot create, rotate, or claim another site's key. Leave blank to keep the existing key.</small></label>
                    <label><span>ACF audio file field</span><input type="text" name="hexa_tts[acf_audio_field]" value="<?php echo esc_attr( AcfAudioFieldResolver::fieldName( $settings ) ); ?>"><small>Mashviral currently uses <code>article_audio</code>.</small></label>
                </div>
                <div class="hexa-tts-test-result hexa-tts-central-result" data-provider-result="central" aria-live="polite"></div>
                <?php if ( ! empty( $last_status["message"] ) ) : ?><div class="hexa-tts-status-card"><strong><?php echo esc_html( $last_status["message"] ); ?></strong><?php if ( ! empty( $last_status["usage"] ) ) : ?><span>Requests: <?php echo esc_html( $last_status["usage"]["requests"] ?? 0 ); ?> · Characters: <?php echo esc_html( $last_status["usage"]["characters"] ?? 0 ); ?> · Est. cost: $<?php echo esc_html( $last_status["usage"]["estimated_cost_usd"] ?? 0 ); ?></span><?php endif; ?></div><?php endif; ?>
                <?php self::render_diagnostics_panel(); ?>
            </div>
            <?php echo CoreUi::card( [ "title" => "Publish Scale API Connection", "body_html" => (string) ob_get_clean() ] ); ?>
            <?php ob_start(); ?>
            <div class="hexa-tts-panel-body">
                <div class="hexa-tts-panel-head"><div><p>Defaults for the one-click post and press-release workflow.</p></div></div>
                <div class="hexa-tts-grid hexa-tts-grid-4">
                    <label><span>Default API source</span><input type="text" name="hexa_tts[default_provider]" value="<?php echo esc_attr( $settings["default_provider"] ); ?>"></label>
                    <label><span>Default profile</span><input type="text" name="hexa_tts[default_profile]" value="<?php echo esc_attr( $settings["default_profile"] ); ?>"></label>
                    <label><span>Default voice</span><input type="text" name="hexa_tts[default_voice]" value="<?php echo esc_attr( $settings["default_voice"] ); ?>"></label>
                    <label><span>Generation speed</span><input type="number" step="0.05" name="hexa_tts[default_speed]" value="<?php echo esc_attr( $settings["default_speed"] ); ?>"></label>
                    <label><span>Max characters</span><input type="number" name="hexa_tts[max_characters]" value="<?php echo esc_attr( $settings["max_characters"] ); ?>" min="500" step="500"></label>
                    <?php echo CoreUi::toggle( "hexa_tts[auto_insert_player]", ! empty( $settings["auto_insert_player"] ), "Auto-insert player" ); ?>
                    <?php echo CoreUi::toggle( "hexa_tts[include_title]", ! empty( $settings["include_title"] ), "Include post title in generated narration" ); ?>
                    <?php echo CoreUi::toggle( "hexa_tts[enable_player_controls]", ! empty( $settings["enable_player_controls"] ), "Show enhanced frontend controls" ); ?>
                </div>
            </div>
            <?php echo CoreUi::card( [ "title" => "Generation Defaults", "body_html" => (string) ob_get_clean() ] ); ?>
            <p class="submit hexa-tts-submit"><button type="submit" class="hpc-button">Save API settings</button></p>
        </form>
        <?php
    }

    private static function primary_color_control_html( array $settings, string $id ): string {
        $value = self::sanitize_color( $settings["primary_color"] ?? "#3657e3" );

        if ( class_exists( "\\Hexa\\PluginCore\\WpAdminComponents\\ColorControl" ) ) {
            return \Hexa\PluginCore\WpAdminComponents\ColorControl::render(
                [
                    "id"              => $id,
                    "key"             => "primary_color",
                    "name"            => "hexa_tts[primary_color]",
                    "label"           => "Primary color",
                    "description"     => "Used directly by the live player CSS variable.",
                    "value"           => $value,
                    "default"         => "#3657e3",
                    "control_class"   => "hexa-tts-color-field",
                    "hex_input_class" => "hexa-tts-live-control hexa-tts-primary-color",
                    "picker_class"    => "hexa-tts-primary-color-picker",
                    "show_hex_code"   => false,
                ]
            );
        }

        return '<label class="hexa-tts-color-field"><span>Primary color</span><input type="text" class="hexa-tts-live-control hexa-tts-primary-color" name="hexa_tts[primary_color]" value="' . esc_attr( $value ) . '"><small>Used directly by the live player CSS variable.</small></label>';
    }

    private static function elementor_palette_detector_html( string $id ): string {
        if ( ! class_exists( "\\Hexa\\PluginCore\\WpAdminComponents\\ElementorPaletteDetector" ) ) {
            return "";
        }

        return \Hexa\PluginCore\WpAdminComponents\ElementorPaletteDetector::render(
            [
                "id"           => $id,
                "title"        => "Elementor palette",
                "button_label" => "Load Elementor colors",
                "description"  => "Reference only. Load the Elementor palette, then copy a hex value into the primary color field above.",
                "empty_label"  => "Click \"Load Elementor colors\" to show your Elementor palette.",
            ]
        );
    }

    private static function render_features_tab(): void {
        $settings = self::get_settings();
        $templates = self::template_options();
        $placements = self::placement_options();
        $shortcode = self::display_shortcode( $settings );
        $acf_field = AcfAudioFieldResolver::fieldName( $settings );
        $enabled = ! empty( $settings["auto_insert_player"] );
        ?>
        <style>
        .httf{max-width:1000px}
        .httf-section{padding:18px 22px;border-top:1px solid #f0f1f3}
        .httf-section h3{margin:0 0 3px;font-size:13px;font-weight:700;color:#1d2327;text-transform:none}
        .httf-hint{margin:0 0 14px;font-size:12px;color:#667085;line-height:1.5;max-width:78ch}
        .httf-grid{display:grid;grid-template-columns:1fr;gap:16px;align-items:start}
        .httf-field{display:grid !important;gap:6px;align-content:start;margin:0;font-weight:400}
        .httf-field>span{font-size:12px;font-weight:600;color:#344054}
        .httf-field input[type=text],.httf-field select{width:100%;box-sizing:border-box;height:36px;min-height:36px;max-width:100%;padding:0 11px;border:1px solid #cfd3da;border-radius:8px;font-size:13px;background:#fff;margin:0}
        .httf-field small{font-size:11px;color:#98a2b3;line-height:1.4}
        .httf-color{margin-top:18px;max-width:460px}
        .httf-color .hpc-color-head h3{font-size:12px;text-transform:none;letter-spacing:0}
        .httf-color .hpc-color-row{gap:8px}
        .httf-checks{display:flex;gap:14px 28px;flex-wrap:wrap;margin-top:18px}
        .httf-check{display:flex !important;align-items:center;gap:8px;font-size:13px;color:#344054;font-weight:500;margin:0;grid-template-columns:none !important}
        .httf-check input{width:auto !important;margin:0}
        .httf-proof{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px}
        .httf-proof>div{flex:1 1 180px;border:1px solid #eaecf0;border-radius:9px;background:#f8f9fb;padding:9px 12px}
        .httf-proof small{display:block;font-size:10px;text-transform:uppercase;letter-spacing:.05em;color:#98a2b3;margin:0 0 3px;font-weight:700}
        .httf-proof strong,.httf-proof code{font-size:13px;color:#1d2327}
        .httf-save{margin:0 !important;padding:16px 22px;border-top:1px solid #f0f1f3;background:#fafbfc;display:flex;align-items:center;gap:14px}
        .httf .hexa-tts-shortcode-card{margin:0}
        .httf .hexa-tts-selected-pill,.httf .hexa-tts-template-live-choice em{display:none}
        .httf .hexa-tts-choice-card.is-selected .hexa-tts-selected-pill,.httf .hexa-tts-template-live-row.is-selected .hexa-tts-template-live-choice em{display:inline-flex}
        @media(max-width:980px){.httf-grid{grid-template-columns:1fr}}
        @media(max-width:600px){.httf-grid{grid-template-columns:1fr}}
        </style>
        <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>" class="hexa-tts-settings-form hexa-tts-features-form hexa-tts-display-live-form httf">
            <?php wp_nonce_field( self::NONCE_ACTION, "smp_wp_text_to_speech_nonce" ); ?>
            <input type="hidden" name="action" value="smp_wp_text_to_speech_save_settings">
            <input type="hidden" name="hexa_tts_tab" value="features">

            <?php
            $audio_on = self::article_audio_enabled();
            ob_start();
            ?>
                <p class="httf-hint">The whole article audio feature. When it is off, nothing from it reaches the site: no player on articles, no player styles or scripts, no audio in the article schema, no audio box or <code><?php echo esc_html( $acf_field ); ?></code> field in the post editor, and no new audio generation. Saved audio files stay in the Media Library and come back when it is turned on again.</p>
                <?php echo CoreUi::toggle( "hexa_tts[article_audio_enabled]", $audio_on, $audio_on ? "Enabled" : "Disabled", [ "id" => "hexa-tts-article-audio-enabled" ] ); ?>
                <?php if ( ! $audio_on ) : ?>
                    <p class="httf-hint">Listen to this article is off. The player settings below are kept and apply again when it is turned on.</p>
                <?php endif; ?>
                <p class="submit httf-save"><button type="submit" class="hpc-button">Save</button></p>
            <?php
            echo CoreUi::card( [ "title" => "Listen to this article", "body_html" => (string) ob_get_clean() ] );

            ob_start();
            ?>
                <p class="httf-hint">Generated or uploaded audio is stored in the Media Library and synced to <code><?php echo esc_html( $acf_field ); ?></code>. Configure the player, choose where it renders, pick a design, and copy the shortcode.</p>
                <?php echo CoreUi::toggle( "hexa_tts[auto_insert_player]", $enabled, "Place the player automatically", [ "id" => "hexa-tts-auto-insert-player", "input_class" => "hexa-tts-live-control" ] ); ?>

                <section class="httf-section">
                    <h3>Core controls</h3>
                    <div class="httf-grid">
                        <label class="httf-field"><span>ACF audio file field</span><input class="hexa-tts-live-control" type="text" name="hexa_tts[acf_audio_field]" value="<?php echo esc_attr( $acf_field ); ?>"><small>Used by generated storage and the editor upload field.</small></label>
                        <label class="httf-field"><span>Player label</span><input class="hexa-tts-live-control" type="text" name="hexa_tts[player_label]" value="<?php echo esc_attr( $settings["player_label"] ); ?>"></label>
                        <label class="httf-field"><span>Player size</span><select class="hexa-tts-live-control" name="hexa_tts[player_size]"><?php self::render_options( self::size_options(), $settings["player_size"] ); ?></select></label>
                    </div>
                    <div class="httf-color"><?php echo self::primary_color_control_html( $settings, "hexa-tts-features-primary-color" ); ?></div>
                    <?php echo self::elementor_palette_detector_html( "hexa-tts-features-elementor-palette" ); ?>
                    <div class="httf-checks">
                        <?php echo CoreUi::toggle( "hexa_tts[include_title]", ! empty( $settings["include_title"] ), "Include post title in narration", [ "id" => "hexa-tts-include-title", "input_class" => "hexa-tts-live-control" ] ); ?>
                        <?php echo CoreUi::toggle( "hexa_tts[enable_player_controls]", ! empty( $settings["enable_player_controls"] ), "Show speed, skip, transcript, and resume controls", [ "id" => "hexa-tts-player-controls", "input_class" => "hexa-tts-live-control" ] ); ?>
                    </div>
                </section>

                <section class="httf-section">
                    <h3>Automatic placement</h3>
                    <p class="httf-hint">Where the player renders automatically. Choosing Manual shortcode disables automatic output.</p>
                    <?php echo self::placement_cards_html( $settings ); ?>
                </section>

                <section class="httf-section">
                    <h3>Template design</h3>
                    <p class="httf-hint">Each preview uses the same markup and CSS as the live single-article player.</p>
                    <div class="hexa-tts-live-preview-target"><?php echo self::template_preview_rows_html( $settings ); ?></div>
                </section>

                <section class="httf-section">
                    <h3>Shortcode</h3>
                    <p class="httf-hint">For Elementor or exact manual placement.</p>
                    <div class="hexa-tts-shortcode-card hexa-tts-dynamic-shortcode"><code><?php echo esc_html( $shortcode ); ?></code><?php echo CoreUi::copy_button( (string) $shortcode, "Copy shortcode" ); ?></div>
                    <div class="httf-proof">
                        <div><small>ACF field</small><code><?php echo esc_html( $acf_field ); ?></code></div>
                        <div><small>Current placement</small><strong><?php echo esc_html( $placements[ $settings["auto_player_placement"] ] ?? "Above article" ); ?></strong></div>
                        <div><small>Current template</small><strong><?php echo esc_html( $templates[ $settings["player_template"] ]["label"] ?? "Clean Card" ); ?></strong></div>
                    </div>
                </section>

                <p class="submit httf-save"><button type="submit" class="hpc-button">Save feature controls</button><span class="hexa-tts-live-save-state" aria-live="polite"></span></p>
            <?php
            echo CoreUi::card( [ "title" => "Article audio player", "body_html" => (string) ob_get_clean() ] );
            ?>
        </form>
        <?php
    }

    private static function render_display_tab(): void {
        $settings = self::get_settings();
        $shortcode = self::display_shortcode( $settings );
        ?>
        <?php ob_start(); ?>
        <div class="hexa-tts-panel-body">
            <div class="hexa-tts-panel-head"><div><p>Choose the exact live player design and automatic placement. Previews below use the same renderer and CSS as the frontend.</p></div></div>
            <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>" class="hexa-tts-settings-form hexa-tts-display-form hexa-tts-display-live-form">
                <?php wp_nonce_field( self::NONCE_ACTION, "smp_wp_text_to_speech_nonce" ); ?>
                <input type="hidden" name="action" value="smp_wp_text_to_speech_save_settings">
                <input type="hidden" name="hexa_tts_tab" value="display">
                <div class="hexa-tts-grid hexa-tts-grid-4">
                    <?php echo self::primary_color_control_html( $settings, "hexa-tts-display-primary-color" ); ?>
                    <label><span>Player label</span><input type="text" class="hexa-tts-live-control" name="hexa_tts[player_label]" value="<?php echo esc_attr( $settings["player_label"] ); ?>"></label>
                    <label><span>Player size</span><select class="hexa-tts-live-control" name="hexa_tts[player_size]"><?php self::render_options( self::size_options(), $settings["player_size"] ); ?></select></label>
                    <label><span>ACF audio file field</span><input type="text" class="hexa-tts-live-control" name="hexa_tts[acf_audio_field]" value="<?php echo esc_attr( AcfAudioFieldResolver::fieldName( $settings ) ); ?>"></label>
                    <?php echo CoreUi::toggle( "hexa_tts[auto_insert_player]", ! empty( $settings["auto_insert_player"] ), "Enable automatic player placement", [ "input_class" => "hexa-tts-live-control" ] ); ?>
                    <?php echo CoreUi::toggle( "hexa_tts[enable_player_controls]", ! empty( $settings["enable_player_controls"] ), "Show enhanced frontend controls", [ "input_class" => "hexa-tts-live-control" ] ); ?>
                </div>
                <?php echo self::elementor_palette_detector_html( "hexa-tts-display-elementor-palette" ); ?>

                <h3>Automatic placement</h3>
                <?php echo self::placement_cards_html( $settings ); ?>

                <h3>Template design</h3>
                <p class="description">One design per row. Each preview includes the current MP3 and is rendered with the same player markup used by <code>[smp_tts_player]</code>.</p>
                <div class="hexa-tts-live-preview-target"><?php echo self::template_preview_rows_html( $settings ); ?></div>

                <h3>Shortcode</h3>
                <div class="hexa-tts-shortcode-card hexa-tts-dynamic-shortcode"><code><?php echo esc_html( $shortcode ); ?></code><?php echo CoreUi::copy_button( (string) $shortcode, "Copy shortcode" ); ?></div>
                <div class="hexa-tts-live-save-state" aria-live="polite"></div>
                <p class="submit hexa-tts-submit"><button type="submit" class="hpc-button">Save display settings</button></p>
            </form>
        </div>
        <?php echo CoreUi::card( [ "title" => "Display Settings", "body_html" => (string) ob_get_clean() ] ); ?>
        <?php
    }

    private static function render_shortcodes_tab(): void {
        $settings = self::get_settings();
        $templates = array_keys( self::template_options() );
        $sizes = array_keys( self::size_options() );
        echo \Hexa\PluginCore\ShortcodeRegistry\ShortcodeBreakdown::render( [
            "tag"         => "hexa_tts_player",
            "title"       => "Listen to this article player",
            "description" => "Shows the article's audio player. Place it in the single-post template with a Shortcode widget, or anywhere else with post_id. While Listen to this article is off it prints nothing.",
            "aliases"     => [ "smp_tts_player" ],
            "attributes"  => [
                "post_id"           => [ "default" => "current post", "description" => "The post whose audio plays." ],
                "label"             => [ "default" => (string) $settings["player_label"], "description" => "The player's title." ],
                "template"          => [ "default" => (string) $settings["player_template"], "description" => "Player design. unstyled and unstyled_controls print the same markup with no plugin styling.", "options" => $templates ],
                "size"              => [ "default" => (string) $settings["player_size"], "description" => "Player size.", "options" => $sizes ],
                "enhanced_controls" => [ "default" => ! empty( $settings["enable_player_controls"] ) ? "1" : "0", "description" => "0 turns off speed, skip, transcript and resume. Alias: controls.", "options" => [ "1", "0" ] ],
                "preload"           => [ "default" => "metadata", "description" => "How much audio the browser loads before play.", "options" => [ "none", "metadata", "auto" ] ],
                "class"             => [ "default" => "", "description" => "Extra CSS class on the player." ],
                "color"             => [ "default" => (string) $settings["primary_color"], "description" => "Accent colour for styled templates." ],
                "show_meta"         => [ "default" => "", "description" => "Accepted for old shortcodes; no longer shown." ],
            ],
            "examples"    => [
                [ "label" => "Another post", "shortcode" => '[hexa_tts_player post_id="123"]' ],
                [ "label" => "No plugin styling", "shortcode" => '[hexa_tts_player template="unstyled"]' ],
            ],
        ] );
    }

    private static function render_schema_tab(): void {
        $post_id = self::latest_audio_post_id();
        $schema = $post_id ? self::generate_audio_schema_array( $post_id ) : [];
        $report = self::schema_integrity_report( $post_id );
        $debug_url = rest_url( self::REST_NS . "/schema" );
        $single_debug_url = $post_id ? add_query_arg( "post_id", $post_id, $debug_url ) : $debug_url;
        $post_url = $post_id ? (string) get_permalink( $post_id ) : "";
        $validator_url = class_exists( "\\Hexa\\PluginCore\\SchemaTools\\SchemaGraph" ) ? \Hexa\PluginCore\SchemaTools\SchemaGraph::validator_url( $post_url ) : ( $post_url ? "https://validator.schema.org/#url=" . rawurlencode( $post_url ) : "" );
        $settings = self::get_settings();
        $shortcode = $post_id ? self::display_shortcode( $settings, $post_id ) : self::display_shortcode( $settings );
        $scan_html = "";

        if ( $post_url && class_exists( "\\Hexa\\PluginCore\\SchemaDetection\\SchemaPageScanner" ) && class_exists( "\\Hexa\\PluginCore\\SchemaDetection\\SchemaScanRenderer" ) ) {
            $scan = ( new \Hexa\PluginCore\SchemaDetection\SchemaPageScanner() )->scanUrl( $post_url, [ "title" => "Latest audio post frontend" ] );
            $scan_html = ( new \Hexa\PluginCore\SchemaDetection\SchemaScanRenderer() )->renderReport(
                [ $scan ],
                [
                    "title" => "Frontend schema scan",
                    "subtitle" => "Checks the live article page for JSON-LD blocks. If Under Construction is active, this will correctly report no public schema.",
                    "expected" => [ "AudioObject from SMP WP Text To Speech", "NewsArticle from SMP Publication Integration when the article page is public" ],
                    "debug" => true,
                ]
            );
        }

        $ideal = [
            "@context" => "https://schema.org",
            "@graph" => [
                [
                    "@type" => "AudioObject",
                    "@id" => $post_url ? $post_url . "#audio" : "#audio",
                    "name" => "Audio version of article title",
                    "contentUrl" => "https://example.com/article-audio.mp3",
                    "encodingFormat" => "audio/mpeg",
                    "duration" => "PT8M12S",
                    "uploadDate" => current_time( DATE_W3C ),
                    "isPartOf" => $post_url ?: "",
                ],
            ],
        ];

        if ( class_exists( "\\Hexa\\PluginCore\\SchemaTools\\SchemaDashboardRenderer" ) ) {
            echo ( new \Hexa\PluginCore\SchemaTools\SchemaDashboardRenderer() )->render(
                [
                    "kicker" => "Text-to-Speech Schema",
                    "title" => "AudioObject schema and frontend verification",
                    "description" => "The text-to-speech plugin owns only the AudioObject schema. It links to the existing article node generated by the publication schema plugin.",
                    "status_cards" => [
                        [
                            "title" => "AudioObject graph",
                            "ok" => in_array( "AudioObject", $report["types"], true ),
                            "body" => $post_id ? "Audio post #" . $post_id . " types: " . implode( ", ", $report["types"] ) : "No post with generated audio was found.",
                        ],
                        [
                            "title" => "Debug JSON URL",
                            "ok" => true,
                            "body" => $single_debug_url,
                        ],
                        [
                            "title" => "Schema validator",
                            "ok" => "" !== $validator_url,
                            "body" => "" !== $validator_url ? $validator_url : "A published audio post is required for validator testing.",
                        ],
                    ],
                    "actions" => array_values(
                        array_filter(
                            [
                                [ "label" => "Open TTS schema JSON", "url" => $single_debug_url ],
                                $validator_url ? [ "label" => "Open Schema Validator", "url" => $validator_url ] : null,
                                $post_url ? [ "label" => "Open Latest Audio Post", "url" => $post_url ] : null,
                            ]
                        )
                    ),
                    "shortcode_card" => [
                        "title" => "Collapsed shortcode card",
                        "description" => "Use this shortcode in Elementor/manual placements. It stays collapsed by default through HexaWP Core.",
                        "shortcode" => $shortcode,
                        "open" => false,
                    ],
                    "integrity_sections" => [
                        [ "title" => "Audio Schema Integrity", "checks" => $report["checks"] ],
                    ],
                    "graphs" => [
                        [ "title" => "Ideal AudioObject Graph", "schema" => $ideal ],
                        [ "title" => "Actual Latest Audio Graph", "schema" => $schema ],
                    ],
                    "scan_report_html" => $scan_html,
                ]
            );
            return;
        }

        echo CoreUi::card( [
            "title"     => "AudioObject Schema",
            "body_html" => '<p>HexaWP Core schema dashboard renderer is unavailable.</p>' . \Hexa\PluginCore\WpAdminComponents\CodeDisplay::render( (string) wp_json_encode( $schema, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), [ "language" => "json" ] ),
        ] );
    }

    private static function render_options( array $options, string $selected ): void {
        foreach ( $options as $value => $label ) {
            if ( is_array( $label ) ) {
                $label = $label["label"] ?? $value;
            }
            echo '<option value="' . esc_attr( $value ) . '" ' . selected( $selected, $value, false ) . '>' . esc_html( $label ) . '</option>';
        }
    }

    private static function size_options(): array {
        return [
            "compact" => "Compact",
            "default" => "Default",
            "large" => "Large",
            "full" => "Full width",
        ];
    }

    private static function placement_options(): array {
        return [
            "above_article" => "Above article",
            "before_featured_image" => "Before featured image",
            "after_featured_image" => "After featured image",
            "manual" => "Manual shortcode only",
        ];
    }

    private static function template_options(): array {
        return [
            "unstyled" => [ "label" => "No design (unstyled)", "description" => "Functional player markup and controls with no plugin-owned presentation. Use this before styling the shortcode in Elementor." ],
            "unstyled_controls" => [ "label" => "No design with custom controls", "description" => "Accessible play, timeline, time, volume, skip, and speed controls with no plugin-owned visual presentation. Style the shortcode in Elementor." ],
            "clean_card" => [ "label" => "Top-accent article card", "description" => "White card with a straight color bar across the top and rounded lower corners." ],
            "editorial_bar" => [ "label" => "Left-accent editorial card", "description" => "Compact publication-style card with a strong color bar on the left." ],
            "compact_pill" => [ "label" => "Rounded compact pill", "description" => "Label and audio controls in a small rounded container for tight article headers." ],
            "media_panel" => [ "label" => "Tinted media panel", "description" => "Larger audio-first panel with a soft color tint and stronger visual weight." ],
            "minimal_audio" => [ "label" => "Minimal bottom-rule player", "description" => "Bare audio player with only a restrained bottom divider." ],
            "quiet_card" => [ "label" => "Neutral quiet card", "description" => "Simple one-pixel card with no shadow and a neutral label." ],
            "slim_line" => [ "label" => "Top accent line", "description" => "Borderless player placed beneath a thin color rule." ],
            "ghost" => [ "label" => "Transparent player", "description" => "No card or border; only the label and audio controls remain." ],
            "inline_label" => [ "label" => "Inline label and player", "description" => "Label and audio controls share one row for compact article headers." ],
            "dot" => [ "label" => "Accent-dot label", "description" => "Small color dot beside a neutral label with no surrounding container." ],
            "underline" => [ "label" => "Underlined label", "description" => "Label with a short color underline above a hairline divider." ],
            "tag" => [ "label" => "Pill label and player", "description" => "Small tinted Listen label beside the audio controls." ],
            "editorial_thin" => [ "label" => "Thin left-rule panel", "description" => "Soft panel with a restrained vertical color rule on the left." ],
            "caption" => [ "label" => "Caption below player", "description" => "Audio controls first with a small figure-style caption underneath." ],
            "eyebrow" => [ "label" => "Uppercase eyebrow label", "description" => "Letter-spaced uppercase label led by a short color tick." ],
            "framed" => [ "label" => "Simple framed box", "description" => "Quiet player inside a thin full border with no shadow." ],
            "rule_between" => [ "label" => "Label with divider", "description" => "Label, hairline divider, and audio controls arranged vertically." ],
            "corner" => [ "label" => "Square corner marker", "description" => "Small color square beside the label with no surrounding container." ],
            "mini" => [ "label" => "Smallest inline player", "description" => "Ultra-compact one-row treatment with the smallest footprint." ],
            "soft_tint" => [ "label" => "Soft color-tint panel", "description" => "Borderless player on a barely visible color-tinted background." ],
            "what_to_know" => [ "label" => "Summary-matched left rule", "description" => "Matches the What to know block with a left color rule, faint tint, and bold label." ],
            "narration_card" => [ "label" => "Dark narration card with timeline", "description" => "Dark reference-style card with a top color rule, large play button, timeline, duration, skip controls, and playback speeds." ],
        ];
    }

    private static function display_shortcode( array $settings, int $post_id = 560368 ): string {
        return "[smp_tts_player post_id=\"" . absint( $post_id ) . "\" label=\"" . sanitize_text_field( $settings["player_label"] ?? "Listen to this article" ) . "\" template=\"" . sanitize_key( $settings["player_template"] ?? "clean_card" ) . "\" size=\"" . sanitize_key( $settings["player_size"] ?? "default" ) . "\" enhanced_controls=\"" . ( ! empty( $settings["enable_player_controls"] ) ? "1" : "0" ) . "\" preload=\"metadata\"]";
    }

    private static function latest_audio_post_id(): int {
        global $wpdb;

        if ( ! $wpdb instanceof \wpdb ) {
            return 0;
        }

        $post_id = (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT pm.post_id FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id WHERE pm.meta_key = %s AND pm.meta_value LIKE %s AND p.post_status = %s ORDER BY pm.meta_id DESC LIMIT 1",
                "_hexa_tts_audio_url",
                "http%",
                "publish"
            )
        );

        if ( $post_id > 0 ) {
            return $post_id;
        }

        return (int) $wpdb->get_var(
            $wpdb->prepare(
                "SELECT p.ID FROM {$wpdb->posts} p INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID WHERE pm.meta_key = %s AND p.post_status = %s ORDER BY p.post_date_gmt DESC LIMIT 1",
                AcfAudioFieldResolver::fieldName( self::get_settings() ),
                "publish"
            )
        );
    }

    private static function sample_audio_url(): string {
        global $wpdb;
        $url = $wpdb instanceof \wpdb ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s ORDER BY meta_id DESC LIMIT 1", "_hexa_tts_audio_url", "http%" ) ) : "";
        if ( $url ) {
            return esc_url_raw( $url );
        }
        $settings = self::get_settings();
        $field = AcfAudioFieldResolver::fieldName( $settings );
        $value = $wpdb instanceof \wpdb ? $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s ORDER BY meta_id DESC LIMIT 1", $field ) ) : "";
        $resolved = self::resolve_audio_url( $value );
        return $resolved ? esc_url_raw( $resolved ) : "";
    }

    private static function placement_cards_html( array $settings ): string {
        $placements = self::placement_options();
        $descriptions = [
            "above_article" => "Prepends the player to the article body through the_content.",
            "before_featured_image" => "Injects before the theme featured image when WordPress thumbnail rendering is available.",
            "after_featured_image" => "Injects after the theme featured image when available.",
            "manual" => "Disables automatic output. Paste the shortcode wherever needed.",
        ];
        ob_start();
        ?>
        <div class="hexa-tts-placement-map hexa-tts-choice-grid" role="radiogroup" aria-label="Automatic player placement">
            <?php foreach ( $placements as $value => $label ) : ?>
                <?php $selected = ( $settings["auto_player_placement"] ?? "above_article" ) === $value; ?>
                <label class="hexa-tts-choice-card hexa-tts-placement-card <?php echo esc_attr( $selected ? "is-selected" : "" ); ?>">
                    <input class="hexa-tts-live-control" type="radio" name="hexa_tts[auto_player_placement]" value="<?php echo esc_attr( $value ); ?>" <?php checked( $selected ); ?>>
                    <span class="hexa-tts-choice-body"><strong><?php echo esc_html( $label ); ?></strong><small><?php echo esc_html( $descriptions[ $value ] ?? "" ); ?></small></span>
                    <span class="hexa-tts-selected-pill">Selected</span>
                </label>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function template_preview_rows_html( array $settings ): string {
        $templates = self::template_options();
        $audio_url = self::sample_audio_url();
        ob_start();
        ?>
        <div class="hexa-tts-template-live-list" role="radiogroup" aria-label="Player template design">
            <?php if ( ! $audio_url ) : ?>
                <div class="notice notice-warning inline"><p>No generated article audio was found yet. Generate or upload an MP3 to preview the actual audio player.</p></div>
            <?php endif; ?>
            <?php foreach ( $templates as $key => $template ) : ?>
                <?php $selected = ( $settings["player_template"] ?? "clean_card" ) === $key; ?>
                <article class="hexa-tts-template-live-row <?php echo esc_attr( $selected ? "is-selected" : "" ); ?>">
                    <label class="hexa-tts-template-live-choice">
                        <input class="hexa-tts-live-control" type="radio" name="hexa_tts[player_template]" value="<?php echo esc_attr( $key ); ?>" <?php checked( $selected ); ?>>
                        <span><strong><?php echo esc_html( $template["label"] ); ?></strong><small><?php echo esc_html( $template["description"] ); ?></small></span>
                        <em>Selected</em>
                    </label>
                    <div class="hexa-tts-template-live-preview">
                        <?php
                        if ( $audio_url ) {
                            echo self::player_markup( $audio_url, [
                                "template" => $key,
                                "size" => $settings["player_size"] ?? "default",
                                "label" => $settings["player_label"] ?? "Listen to this article",
                                "enhanced_controls" => ! empty( $settings["enable_player_controls"] ) ? "1" : "0",
                                "preload" => "metadata",
                                "class" => "hexa-tts-preview-player",
                                "color" => $settings["primary_color"] ?? "#3657e3",
                            ] );
                        }
                        ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    public static function ajax_preview_display() {
        $incoming = isset( $_POST["hexa_tts"] ) && is_array( $_POST["hexa_tts"] ) ? wp_unslash( $_POST["hexa_tts"] ) : [];
        $settings = self::sanitize_display_settings( $incoming, self::get_settings(), true );
        wp_send_json_success( [
            "preview_html" => self::template_preview_rows_html( $settings ),
            "shortcode" => self::display_shortcode( $settings ),
            "message" => "Preview refreshed. Click Save to persist these display settings.",
        ] );
    }
}
