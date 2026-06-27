<?php
/**
 * Plugin Name: SMP WP Text To Speech
 * Plugin URI: https://code.hexawebsystems.com/manual-ai-reports/6/view
 * Description: Publish Scale text-to-speech client for WordPress article narration. Uses hidden server-side API calls, AJAX generation, Media Library storage, and ACF field syncing.
 * Version: 1.2.13
 * Author: Hexa Web Systems
 * Text Domain: smp-wp-text-to-speech
 * Requires at least: 6.0
 * Requires PHP: 7.4
 * GitHub Plugin URI: https://github.com/mikeyperes/smp-wp-text-to-speech/
 * GitHub Branch: main
 */

namespace smp_text_to_speech;

use WP_Error;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

function register_hexa_plugin_core_autoloader(): void {
    static $registered = false;

    if ( $registered ) {
        return;
    }

    $base_dir = __DIR__ . "/lib/hexa-wordpress-plugin-core/src/";
    $prefix   = "Hexa\\PluginCore\\";

    spl_autoload_register(
        static function( string $class_name ) use ( $base_dir, $prefix ): void {
            if ( strncmp( $class_name, $prefix, strlen( $prefix ) ) !== 0 ) {
                return;
            }

            $relative_class = substr( $class_name, strlen( $prefix ) );
            $file = $base_dir . str_replace( "\\", DIRECTORY_SEPARATOR, $relative_class ) . ".php";

            if ( is_readable( $file ) ) {
                require_once $file;
            }
        },
        true,
        true
    );

    $registered = true;
}

register_hexa_plugin_core_autoloader();

final class Plugin {
    const VERSION = "1.2.13";
    const OPTION = "hexa_tts_settings";
    const NONCE_ACTION = "hexa_tts_admin_nonce";
    const SETTINGS_SLUG = "smp-wp-text-to-speech";
    const API_BASE = "https://publish.scalemypublication.com/api/smp-text-to-speech/v1";
    const GITHUB_REPO = "mikeyperes/smp-wp-text-to-speech";
    const GITHUB_BRANCH = "main";

    public static function init() {
        self::boot_hexa_core();
        add_action( "admin_menu", [ __CLASS__, "register_admin_menu" ] );
        add_action( "admin_enqueue_scripts", [ __CLASS__, "enqueue_admin_assets" ] );
        add_action( "wp_enqueue_scripts", [ __CLASS__, "enqueue_frontend_assets" ] );
        add_action( "admin_post_hexa_tts_save_settings", [ __CLASS__, "handle_save_settings" ] );
        add_action( "admin_post_hexa_tts_import_elementor_color", [ __CLASS__, "handle_import_elementor_color" ] );
        add_action( "add_meta_boxes", [ __CLASS__, "register_post_metabox" ] );
        add_action( "save_post", [ __CLASS__, "save_embedded_acf_audio" ], 20, 2 );
        add_action( "wp_ajax_smp_tts_load_tab", [ __CLASS__, "ajax_load_tab" ] );
        add_action( "wp_ajax_hexa_tts_validate_central_api", [ __CLASS__, "ajax_validate_central_api" ] );
        add_action( "wp_ajax_hexa_tts_validate_provider", [ __CLASS__, "ajax_validate_central_api" ] );
        add_action( "wp_ajax_hexa_tts_extract_post_content", [ __CLASS__, "ajax_extract_post_content" ] );
        add_action( "wp_ajax_hexa_tts_generate_audio", [ __CLASS__, "ajax_generate_audio" ] );
        add_action( "wp_ajax_hexa_tts_generation_status", [ __CLASS__, "ajax_generation_status" ] );
        add_action( "wp_ajax_hexa_tts_save_manual_audio", [ __CLASS__, "ajax_save_manual_audio" ] );
        add_action( "wp_ajax_hexa_tts_preview_display", [ __CLASS__, "ajax_preview_display" ] );
        add_filter( "plugin_action_links_" . plugin_basename( __FILE__ ), [ __CLASS__, "plugin_action_links" ] );
        add_filter( "the_content", [ __CLASS__, "maybe_insert_player" ], 12 );
        add_filter( "post_thumbnail_html", [ __CLASS__, "maybe_insert_player_around_featured_image" ], 20, 5 );
        add_shortcode( "hexa_tts_player", [ __CLASS__, "render_player_shortcode" ] );
        add_shortcode( "smp_tts_player", [ __CLASS__, "render_player_shortcode" ] );
        register_activation_hook( __FILE__, [ __CLASS__, "activate" ] );
    }

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
        return plugin_basename( __FILE__ );
    }

    private static function core_root(): string {
        return __DIR__ . "/lib/hexa-wordpress-plugin-core";
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
            __FILE__,
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
                "requires_php"              => "7.4",
                "nonce_action"              => self::NONCE_ACTION,
                "nonce_param"               => "nonce",
                "ajax_action_prefix"        => "smp_tts_core_updater",
                "progress_key"              => "smp_tts_core_update_progress",
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
                "ajax_action_prefix" => "smp_tts_core_package",
                "cache_key"          => "smp_tts_hexa_plugin_core_package",
            ]
        );

        return $config;
    }

    private static function boot_hexa_core(): void {
        if ( ! is_admin() && ! wp_doing_ajax() && ! wp_doing_cron() && ! ( defined( "WP_CLI" ) && WP_CLI ) ) {
            return;
        }

        $updater_config = self::updater_config();
        if ( $updater_config && class_exists( "\\Hexa\\PluginCore\\PluginUpdates\\GitHubPluginUpdater" ) ) {
            ( new \Hexa\PluginCore\PluginUpdates\GitHubPluginUpdater( $updater_config ) )->register();
        }

        if ( ! is_admin() && ! wp_doing_ajax() ) {
            return;
        }

        if ( $updater_config && class_exists( "\\Hexa\\PluginCore\\PluginUpdates\\UpdaterAjaxController" ) ) {
            ( new \Hexa\PluginCore\PluginUpdates\UpdaterAjaxController( $updater_config ) )->register();
        }

        $core_config = self::core_package_config();
        if ( $core_config && class_exists( "\\Hexa\\PluginCore\\CorePackageUpdates\\CorePackageAjaxController" ) ) {
            ( new \Hexa\PluginCore\CorePackageUpdates\CorePackageAjaxController( $core_config ) )->register();
        }

        if ( class_exists( "\\Hexa\\PluginCore\\WpAdminTabs\\CoreTabModule" ) && class_exists( "\\Hexa\\PluginCore\\WpAdminTabs\\CoreTabConfig" ) ) {
            ( new \Hexa\PluginCore\WpAdminTabs\CoreTabModule(
                new \Hexa\PluginCore\WpAdminTabs\CoreTabConfig(
                    [
                        "tabs_filter"   => "smp_tts_dashboard_tabs",
                        "render_filter" => "smp_tts_render_dashboard_tab",
                        "capability"    => "manage_options",
                        "core_root"     => self::core_root(),
                        "readme_path"   => self::core_root() . "/README.md",
                        "library_path"  => __DIR__ . "/HEXA_PLUGIN_CORE_LIBRARY.md",
                    ]
                )
            ) )->register();
        }
    }

    public static function enqueue_admin_assets( $hook ) {
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        $is_settings = "settings_page_" . self::SETTINGS_SLUG === $hook;
        $is_post = $screen && "post" === $screen->base;
        if ( ! $is_settings && ! $is_post ) {
            return;
        }
        if ( $is_settings ) {
            wp_enqueue_style( "wp-color-picker" );
            wp_enqueue_script( "wp-color-picker" );
        }
        if ( $is_post ) {
            wp_enqueue_media();
            if ( function_exists( "acf_enqueue_scripts" ) ) {
                acf_enqueue_scripts();
            }
        }
        wp_enqueue_style( "hexa-tts-admin", plugin_dir_url( __FILE__ ) . "assets/admin.css", [], self::VERSION );
        wp_enqueue_style( "smp-tts-frontend", plugin_dir_url( __FILE__ ) . "assets/frontend.css", [ "hexa-tts-admin" ], self::VERSION );
        wp_add_inline_style( "smp-tts-frontend", self::frontend_player_css() );
        wp_enqueue_script( "hexa-tts-admin", plugin_dir_url( __FILE__ ) . "assets/admin.js", [ "jquery", "wp-color-picker" ], self::VERSION, true );
        wp_localize_script( "hexa-tts-admin", "hexaTts", [ "ajaxUrl" => admin_url( "admin-ajax.php" ), "nonce" => wp_create_nonce( self::NONCE_ACTION ) ] );
        wp_add_inline_style( "hexa-tts-admin", self::admin_live_display_css() );
        wp_add_inline_script( "hexa-tts-admin", self::admin_inline_script(), "after" );
        wp_add_inline_script( "hexa-tts-admin", self::admin_live_display_script(), "after" );
    }

    public static function enqueue_frontend_assets() {
        if ( is_admin() ) {
            return;
        }
        wp_enqueue_style( "smp-tts-frontend", plugin_dir_url( __FILE__ ) . "assets/frontend.css", [], self::VERSION );
        wp_add_inline_style( "smp-tts-frontend", self::frontend_player_css() );
    }

    private static function frontend_player_css(): string {
        $path = __DIR__ . "/assets/frontend.css";
        if ( ! is_readable( $path ) ) {
            return "";
        }

        $css = file_get_contents( $path );
        return is_string( $css ) ? $css : "";
    }

    private static function admin_inline_script() {
        return <<<JS
(function () {
  var jq = jQuery;

  function escapeHtml(value) {
    return String(value || "").replace(/[&<>\"\u0027]/g, function (char) {
      return { "&": "&amp;", "<": "&lt;", ">": "&gt;", "\"": "&quot;", "\u0027": "&#039;" }[char];
    });
  }

  function postPayload(box) {
    return {
      post_id: box.data("post-id"),
      profile: box.find(".hexa-tts-post-profile").val(),
      provider: box.find(".hexa-tts-post-provider").val(),
      voice: box.find(".hexa-tts-post-voice").val(),
      speed: box.find(".hexa-tts-post-speed").val(),
      content: box.find(".hexa-tts-extracted-preview").val(),
      prepend: box.find(".hexa-tts-post-prepend").val(),
      append: box.find(".hexa-tts-post-append").val(),
      shorten: box.find(".hexa-tts-post-shorten").is(":checked") ? 1 : 0
    };
  }

  function ensureAudio(box, audioUrl) {
    var audio = box.find("audio");
    if (!audio.length) {
      audio = jq(document.createElement("audio"));
      audio.attr("controls", "controls");
      audio.attr("preload", "metadata");
      audio.insertAfter(box.find(".hexa-tts-post-feedback"));
    }
    audio.attr("src", audioUrl);
  }

  function renderActivityLog(box, entries, apiStatus) {
    var log = box.find(".hexa-tts-activity-log");
    if (!log.length) { return; }
    log.empty();
    log.attr("aria-live", "polite");
    entries = entries || [];
    if (!entries.length) {
      return;
    }
    entries.forEach(function (entry) {
      var line = typeof entry === "string" ? { message: entry, state: "info" } : (entry || {});
      var row = jq(document.createElement("div"));
      var dot = jq(document.createElement("span"));
      var body = jq(document.createElement("p"));
      row.addClass("hexa-tts-log-line is-" + (line.state || "info"));
      if (line.time) {
        var time = jq(document.createElement("time"));
        time.text(line.time);
        body.append(time);
      }
      body.append(document.createTextNode(line.message || "Working..."));
      row.append(dot).append(body);
      log.append(row);
    });
    if (apiStatus && apiStatus.status) {
      var apiRow = jq(document.createElement("div"));
      var apiDot = jq(document.createElement("span"));
      var apiBody = jq(document.createElement("p"));
      apiRow.addClass("hexa-tts-log-line is-api");
      apiBody.text("Publish Scale API: " + apiStatus.status + (apiStatus.message ? " — " + apiStatus.message : ""));
      apiRow.append(apiDot).append(apiBody);
      log.append(apiRow);
    }
  }

  function addClientActivity(box, message, state) {
    var current = box.data("hexaTtsClientLog") || [];
    current.push({ time: new Date().toLocaleTimeString(), state: state || "info", message: message });
    box.data("hexaTtsClientLog", current);
    renderActivityLog(box, current, null);
  }

  function buttonState(button, state, text) {
    var original = button.data("hexaTtsOriginalText") || button.text();
    if (!button.data("hexaTtsOriginalText")) {
      button.data("hexaTtsOriginalText", original);
    }
    button.removeClass("hexa-tts-button-working hexa-tts-button-ok hexa-tts-button-error");
    if (state === "working") {
      button.addClass("hexa-tts-button-working").prop("disabled", true).html('<span class="hexa-tts-button-spinner" aria-hidden="true"></span><span>' + escapeHtml(text || "Generating audio...") + '</span>');
      return;
    }
    if (state === "ok") {
      button.addClass("hexa-tts-button-ok").prop("disabled", false).html('<span class="hexa-tts-button-mark" aria-hidden="true">✓</span><span>' + escapeHtml(text || "Audio generated") + '</span>');
      window.setTimeout(function () { button.removeClass("hexa-tts-button-ok").text(original); }, 4000);
      return;
    }
    if (state === "error") {
      button.addClass("hexa-tts-button-error").prop("disabled", false).html('<span class="hexa-tts-button-mark" aria-hidden="true">×</span><span>' + escapeHtml(text || "Generation failed") + '</span>');
      window.setTimeout(function () { button.removeClass("hexa-tts-button-error").text(original); }, 5000);
      return;
    }
    button.prop("disabled", false).text(original);
  }

  function clientRequestId() {
    return "tts_" + Date.now().toString(36) + "_" + Math.random().toString(36).slice(2, 14);
  }

  function hasExistingAudio(box) {
    var attr = String(box.attr("data-has-audio") || "");
    var attachmentId = parseInt(box.attr("data-existing-attachment-id") || "0", 10);
    return attr === "1" || attachmentId > 0 || !!box.find(".hexa-tts-open-audio").attr("href") || !!box.find("audio").attr("src");
  }

  function stopGenerationPoll(box) {
    var timer = box.data("hexaTtsGenerationPoll");
    if (timer) {
      window.clearInterval(timer);
      box.removeData("hexaTtsGenerationPoll");
    }
  }

  function pollGenerationStatus(box, requestId) {
    stopGenerationPoll(box);
    var postId = box.data("post-id") || box.attr("data-post-id");
    var timer = window.setInterval(function () {
      jq.ajax({
        url: hexaTts.ajaxUrl,
        method: "POST",
        data: { action: "hexa_tts_generation_status", nonce: hexaTts.nonce, post_id: postId, client_request_id: requestId }
      }).done(function (response) {
        if (response && response.success && response.data) {
          renderActivityLog(box, response.data.log || [], response.data.api_status || null);
          if (response.data.status) {
            box.find(".hexa-tts-post-status").text(response.data.status);
          }
        }
      });
    }, 1500);
    box.data("hexaTtsGenerationPoll", timer);
  }

  function ensureAudio(box, audioUrl) {
    var audio = box.find("audio");
    if (!audio.length) {
      audio = jq(document.createElement("audio"));
      audio.attr("controls", "controls");
      audio.attr("preload", "metadata");
      audio.insertAfter(box.find(".hexa-tts-post-feedback"));
    }
    audio.attr("src", audioUrl);
  }

  function showSuccess(box, data, message) {
    var feedback = box.find(".hexa-tts-post-feedback");
    var link = jq(document.createElement("a"));
    link.attr("href", data.audio_url);
    link.attr("target", "_blank");
    link.attr("rel", "noopener noreferrer");
    link.text("Open audio");

    box.find(".hexa-tts-post-status").text("Ready");
    feedback.removeClass("is-loading is-error").addClass("is-success").empty();
    feedback.append(document.createTextNode(message + " "));
    feedback.append(link);
    ensureAudio(box, data.audio_url);
    box.find(".hexa-tts-storage-state").text("Saved to Media Library and ACF");
    box.find(".hexa-tts-storage-note").text("Current " + (data.acf_field || "article_audio") + " value is set.");
    box.attr("data-has-audio", "1");
    if (data.attachment_id) {
      box.attr("data-existing-attachment-id", data.attachment_id);
    }
    var selected = box.find(".hexa-tts-selected-audio");
    var selectedLink = jq(document.createElement("a"));
    selectedLink.attr("href", data.audio_url);
    selectedLink.attr("target", "_blank");
    selectedLink.attr("rel", "noopener noreferrer");
    selectedLink.text("Open MP3");
    selected.empty();
    selected.append(document.createTextNode("Selected audio: "));
    selected.append(selectedLink);
  }

  jq(document).off("click", ".hexa-tts-generate-post");
  jq(document).on("click", ".hexa-tts-generate-post", function (event) {
    if (event) {
      event.preventDefault();
      event.stopPropagation();
    }
    var button = jq(this);
    var box = button.closest(".hexa-tts-postbox");
    var feedback = box.find(".hexa-tts-post-feedback");
    var payload = postPayload(box);
    var requestId = clientRequestId();
    var replacingExisting = hasExistingAudio(box);

    if (replacingExisting && !window.confirm("Are you sure you want to create a new one? This will delete the old one.")) {
      feedback.removeClass("is-loading is-success is-error").text("Generation cancelled. Existing MP3 was kept.");
      return;
    }

    payload.action = "hexa_tts_generate_audio";
    payload.nonce = hexaTts.nonce;
    payload.client_request_id = requestId;
    payload.replace_existing = replacingExisting ? 1 : 0;

    box.data("hexaTtsClientLog", []);
    addClientActivity(box, "Generate clicked. WordPress request ID created: " + requestId + ".", "working");
    addClientActivity(box, "Collecting article text and current voice settings.", "working");
    addClientActivity(box, "Sending AJAX request to WordPress.", "working");
    buttonState(button, "working", "Generating audio...");
    feedback.removeClass("is-error is-success").addClass("is-loading").text("Generation started. Activity log is updating below.");
    box.find(".hexa-tts-post-status").text("Starting");
    pollGenerationStatus(box, requestId);

    jq.ajax({ url: hexaTts.ajaxUrl, method: "POST", data: payload })
      .done(function (response) {
        stopGenerationPoll(box);
        if (response && response.success) {
          renderActivityLog(box, response.data.log || [], { status: "complete", message: response.data.request_id ? "Request " + response.data.request_id + " completed." : "Central API completed." });
          showSuccess(box, response.data, response.data.message || "Audio generated and saved to ACF.");
          buttonState(button, "ok", "Audio generated");
          return;
        }
        var message = response && response.data ? response.data.message : "Generation failed.";
        box.find(".hexa-tts-post-status").text("Failed");
        if (response && response.data && response.data.log) {
          renderActivityLog(box, response.data.log, { status: "failed", message: message });
        } else {
          addClientActivity(box, message, "error");
        }
        feedback.removeClass("is-loading is-success").addClass("is-error").text(message);
        buttonState(button, "error", "Generation failed");
      })
      .fail(function (xhr) {
        stopGenerationPoll(box);
        var message = xhr.responseText || xhr.statusText || "Generation request failed.";
        box.find(".hexa-tts-post-status").text("Failed");
        addClientActivity(box, message, "error");
        feedback.removeClass("is-loading is-success").addClass("is-error").text(message);
        buttonState(button, "error", "Generation failed");
      });
  });

  jq(document).on("click", ".hexa-tts-copy", function () {
    var button = jq(this);
    var text = button.data("copy-text") || "";
    function done() {
      var old = button.text();
      button.text("Copied");
      setTimeout(function () { button.text(old); }, 1400);
    }
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(done);
    } else {
      var tmp = jq("<textarea>").val(text).appendTo("body").select();
      document.execCommand("copy");
      tmp.remove();
      done();
    }
  });

  jq(document).on("click", ".hexa-tts-select-audio", function () {
    var button = jq(this);
    var box = button.closest(".hexa-tts-postbox");
    var feedback = box.find(".hexa-tts-post-feedback");

    if (typeof wp === "undefined" || !wp.media) {
      feedback.removeClass("is-loading is-success").addClass("is-error").text("WordPress media uploader is not available on this screen.");
      return;
    }

    var frame = wp.media({
      title: "Select article audio",
      button: { text: "Use this audio file" },
      library: { type: "audio" },
      multiple: false
    });

    frame.on("select", function () {
      var attachment = frame.state().get("selection").first().toJSON();
      if (!attachment || !attachment.id) {
        return;
      }

      button.prop("disabled", true);
      feedback.removeClass("is-error is-success").addClass("is-loading").text("Saving selected audio to ACF field...");

      jq.ajax({
        url: hexaTts.ajaxUrl,
        method: "POST",
        data: {
          action: "hexa_tts_save_manual_audio",
          nonce: hexaTts.nonce,
          post_id: box.data("post-id"),
          attachment_id: attachment.id
        }
      })
        .done(function (response) {
          if (response && response.success) {
            showSuccess(box, response.data, response.data.message || "Audio file saved to ACF.");
            return;
          }
          feedback.removeClass("is-loading is-success").addClass("is-error").text(response && response.data ? response.data.message : "Could not save selected audio.");
        })
        .fail(function (xhr) {
          feedback.removeClass("is-loading is-success").addClass("is-error").text(xhr.responseText || xhr.statusText);
        })
        .always(function () {
          button.prop("disabled", false);
        });
    });

    frame.open();
  });
})();
JS;
    }

    private static function admin_live_display_css() {
        return <<<CSS
.hexa-tts-feature-control-card{border:1px solid #dcdcde;border-radius:14px;background:#fff;padding:20px;margin:18px 0}.hexa-tts-feature-control-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;border-bottom:1px solid #dcdcde;padding-bottom:16px;margin-bottom:18px}.hexa-tts-feature-control-head h2{margin:0 0 6px}.hexa-tts-feature-control-head p{margin:0;color:#50575e}.hexa-tts-feature-control-grid{display:grid;gap:22px}.hexa-tts-switch{display:flex;align-items:center;gap:10px;cursor:pointer}.hexa-tts-switch input{position:absolute;opacity:0}.hexa-tts-switch span{width:48px;height:26px;border-radius:999px;background:#c3c4c7;position:relative}.hexa-tts-switch span:before{content:"";position:absolute;width:20px;height:20px;left:3px;top:3px;border-radius:50%;background:#fff;transition:.15s}.hexa-tts-switch input:checked+span{background:#3657e3}.hexa-tts-switch input:checked+span:before{transform:translateX(22px)}.hexa-tts-choice-grid{display:grid;gap:14px}.hexa-tts-placement-map.hexa-tts-choice-grid{grid-template-columns:repeat(4,minmax(0,1fr))}.hexa-tts-choice-card{position:relative;display:flex;gap:12px;align-items:flex-start;border:2px solid #dcdcde;border-radius:12px;background:#fff;padding:14px;cursor:pointer}.hexa-tts-choice-card input{margin-top:3px}.hexa-tts-choice-card.is-selected{border-color:#3657e3;box-shadow:0 0 0 2px rgba(54,87,227,.14)}.hexa-tts-choice-body strong,.hexa-tts-choice-body small{display:block}.hexa-tts-choice-body small{color:#646970;margin-top:5px}.hexa-tts-selected-pill,.hexa-tts-template-live-choice em{display:none;margin-left:auto;border-radius:999px;background:#eef2ff;color:#3657e3;font-style:normal;font-size:12px;font-weight:800;padding:3px 8px}.hexa-tts-choice-card.is-selected .hexa-tts-selected-pill,.hexa-tts-template-live-row.is-selected .hexa-tts-template-live-choice em{display:inline-flex}.hexa-tts-template-live-list{display:grid;grid-template-columns:1fr;gap:16px;margin-top:14px}.hexa-tts-template-live-row{border:2px solid #dcdcde;border-radius:14px;background:#fff;padding:16px}.hexa-tts-template-live-row.is-selected{border-color:#3657e3;box-shadow:0 0 0 2px rgba(54,87,227,.14)}.hexa-tts-template-live-choice{display:flex;align-items:flex-start;gap:12px;margin-bottom:12px;cursor:pointer}.hexa-tts-template-live-choice input{margin-top:4px}.hexa-tts-template-live-choice strong,.hexa-tts-template-live-choice small{display:block}.hexa-tts-template-live-choice small{color:#646970;margin-top:4px}.hexa-tts-template-live-preview{padding:12px;border-radius:12px;background:#f6f7f7}.hexa-tts-template-live-preview .hexa-tts-player{margin:0}.hexa-tts-live-save-state{min-height:22px;margin-top:12px;font-weight:700}.hexa-tts-live-save-state.is-loading{color:#646970}.hexa-tts-live-save-state.is-success{color:#008a20}.hexa-tts-live-save-state.is-error{color:#b32d2e}.hexa-tts-feature-proof{display:grid;gap:8px;margin-top:12px}.hexa-tts-feature-proof>div{display:flex;gap:10px;align-items:center;border:1px solid #dcdcde;border-radius:8px;background:#f6f7f7;padding:9px 10px}.hexa-tts-feature-proof span{font-weight:800;color:#646970}@media(max-width:1180px){.hexa-tts-placement-map.hexa-tts-choice-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}@media(max-width:760px){.hexa-tts-feature-control-head,.hexa-tts-placement-map.hexa-tts-choice-grid{display:grid;grid-template-columns:1fr}}
CSS;
    }

    private static function admin_live_display_script() {
        return <<<JS
(function ($) {
  "use strict";
  function initLiveChoices(scope) {
    var root = scope ? $(scope) : $(document);
    root.find(".hexa-tts-placement-map").each(function () {
      var group = $(this);
      group.find(".hexa-tts-placement-card").removeClass("is-selected");
      group.find("input:checked").closest(".hexa-tts-placement-card").addClass("is-selected");
    });
    root.find(".hexa-tts-template-live-list").each(function () {
      var group = $(this);
      group.find(".hexa-tts-template-live-row").removeClass("is-selected");
      group.find("input:checked").closest(".hexa-tts-template-live-row").addClass("is-selected");
    });
  }
  function ajaxPreview(form) {
    window.clearTimeout(form.data("hexaTtsLiveTimer"));
    var timer = window.setTimeout(function () {
      var state = form.find(".hexa-tts-live-save-state");
      var data = form.serializeArray().filter(function (item) { return item.name !== "action"; });
      data.push({ name: "action", value: "hexa_tts_preview_display" });
      data.push({ name: "nonce", value: hexaTts.nonce });
      state.removeClass("is-error is-success").addClass("is-loading").text("Saving and refreshing preview...");
      $.ajax({ url: hexaTts.ajaxUrl, method: "POST", data: data })
        .done(function (response) {
          if (response && response.success) {
            if (response.data.preview_html) {
              form.find(".hexa-tts-live-preview-target").html(response.data.preview_html);
            }
            if (response.data.shortcode) {
              form.find(".hexa-tts-dynamic-shortcode code").text(response.data.shortcode);
              form.find(".hexa-tts-dynamic-shortcode .hexa-tts-copy").attr("data-copy-text", response.data.shortcode).data("copy-text", response.data.shortcode);
            }
            initLiveChoices(form);
            state.removeClass("is-loading is-error").addClass("is-success").text(response.data.message || "Saved.");
            return;
          }
          state.removeClass("is-loading is-success").addClass("is-error").text(response && response.data ? response.data.message : "Preview update failed.");
        })
        .fail(function (xhr) {
          state.removeClass("is-loading is-success").addClass("is-error").text(xhr.responseText || xhr.statusText);
        });
    }, 350);
    form.data("hexaTtsLiveTimer", timer);
  }
  $(document).on("click", ".hexa-tts-placement-card, .hexa-tts-template-live-row", function (event) {
    if ($(event.target).is("input, audio, button, a") || $(event.target).closest("audio, button, a").length) { return; }
    var input = $(this).find("input[type=radio]");
    if (input.length && !input.prop("checked")) { input.prop("checked", true).trigger("change"); }
  });
  $(document).on("input change", ".hexa-tts-display-live-form .hexa-tts-live-control", function () {
    var form = $(this).closest(".hexa-tts-display-live-form");
    initLiveChoices(form);
    ajaxPreview(form);
  });
  $(function () { initLiveChoices(document); });
  document.addEventListener("hexa-core-host-tab-loaded", function (event) { if (event && event.detail && event.detail.panel) { initLiveChoices(event.detail.panel); } });
})(jQuery);
JS;
    }

    public static function register_acf_audio_field_unused() {
        if ( ! function_exists( "acf_add_local_field_group" ) ) {
            return;
        }
        $settings = self::get_settings();
        $acf_field = sanitize_key( $settings["acf_audio_field"] ?: "article_audio" );
        return;
        acf_add_local_field_group( [
            "key" => "group_hexa_tts_article_audio",
            "title" => "Article Audio",
            "fields" => [
                [
                    "key" => self::acf_audio_field_key(),
                    "label" => "Article Audio",
                    "name" => $acf_field,
                    "type" => "file",
                    "instructions" => "Upload or select the audio file for this article. Generated TTS audio is saved here automatically.",
                    "required" => 0,
                    "return_format" => "url",
                    "library" => "all",
                    "mime_types" => "mp3,m4a,wav,aac,ogg",
                ],
            ],
            "location" => [
                [ [ "param" => "post_type", "operator" => "==", "value" => "post" ] ],
                [ [ "param" => "post_type", "operator" => "==", "value" => "press-release" ] ],
            ],
            "position" => "acf_after_title",
            "style" => "default",
            "label_placement" => "top",
            "instruction_placement" => "label",
            "active" => true,
        ] );
    }


    private static function embedded_acf_audio_field( $post_id, $acf_field ) {
        $acf_value = get_post_meta( $post_id, $acf_field, true );
        $attachment_id = is_numeric( $acf_value ) ? (int) $acf_value : (int) get_post_meta( $post_id, "_hexa_tts_attachment_id", true );
        if ( function_exists( "acf_render_field_wrap" ) ) {
            $field = [
                "key" => self::acf_audio_field_key(),
                "label" => "Article Audio",
                "name" => $acf_field,
                "type" => "file",
                "instructions" => "Upload or select the audio file for this article. Generated TTS audio is saved here automatically.",
                "required" => 0,
                "value" => $attachment_id ?: "",
                "prefix" => "acf",
                "return_format" => "url",
                "library" => "all",
                "mime_types" => "mp3,m4a,wav,aac,ogg",
                "wrapper" => [ "width" => "", "class" => "hexa-tts-embedded-acf-file", "id" => "" ],
            ];
            echo '<div class="hexa-tts-embedded-acf">';
            acf_render_field_wrap( $field );
            echo '</div>';
            return;
        }
        echo '<p class="hexa-tts-acf-missing">ACF file field UI is unavailable. Confirm ACF Pro is active.</p>';
    }

    public static function default_settings() {
        return [
            "api_key" => "",
            "default_provider" => "unrealspeech",
            "default_profile" => "default",
            "default_voice" => "af",
            "default_speed" => "0",
            "acf_audio_field" => "article_audio",
            "auto_insert_player" => 1,
            "auto_player_placement" => "above_article",
            "include_title" => 1,
            "max_characters" => 20000,
            "primary_color" => "#3657e3",
            "player_size" => "default",
            "player_template" => "clean_card",
            "player_label" => "Listen to this article",
            "show_player_meta" => 1,
            "last_status" => [],
        ];
    }

    public static function get_settings() {
        $stored = get_option( self::OPTION, [] );
        return self::merge_settings( self::default_settings(), is_array( $stored ) ? $stored : [] );
    }

    private static function merge_settings( array $defaults, array $stored ) {
        foreach ( $stored as $key => $value ) {
            if ( is_array( $value ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) ) {
                $defaults[ $key ] = self::merge_settings( $defaults[ $key ], $value );
            } else {
                $defaults[ $key ] = $value;
            }
        }
        return $defaults;
    }

    public static function render_settings_page() {
        if ( ! current_user_can( "manage_options" ) ) {
            return;
        }
        $tabs = self::settings_tabs();
        $active = isset( $_GET["tab"] ) ? sanitize_key( wp_unslash( $_GET["tab"] ) ) : "overview";
        $active = isset( $tabs[ $active ] ) ? $active : "overview";
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
            if ( class_exists( "\\Hexa\\PluginCore\\WpAdminTabs\\HostTabsRenderer" ) ) {
                ( new \Hexa\PluginCore\WpAdminTabs\HostTabsRenderer() )->render(
                    [
                        "tabs"            => $tabs,
                        "active"          => $active,
                        "page_url"        => admin_url( "options-general.php?page=" . self::SETTINGS_SLUG ),
                        "ajax_action"     => "smp_tts_load_tab",
                        "nonce"           => wp_create_nonce( self::NONCE_ACTION ),
                        "nonce_field"     => "nonce",
                        "root_id"         => "smp-tts-core-tabs",
                        "panel_id"        => "smp-tts-tab-panel",
                        "label"           => "SMP WP Text To Speech sections",
                        "render_callback" => function( string $tab ): void { self::render_settings_tab( $tab ); },
                    ]
                );
            } else {
                echo '<nav class="nav-tab-wrapper">';
                foreach ( $tabs as $id => $label ) {
                    $url = add_query_arg( [ "page" => self::SETTINGS_SLUG, "tab" => $id ], admin_url( "options-general.php" ) );
                    echo '<a class="nav-tab ' . esc_attr( $id === $active ? "nav-tab-active" : "" ) . '" href="' . esc_url( $url ) . '">' . esc_html( $label ) . '</a>';
                }
                echo '</nav>';
                self::render_settings_tab( $active );
            }
            ?>
        </div>
        <?php
    }

    private static function settings_tabs(): array {
        return apply_filters( "smp_tts_dashboard_tabs", [
            "overview"   => "Overview",
            "api"        => "API Settings",
            "features"   => "Features",
            "display"    => "Display",
            "shortcodes" => "Shortcodes",
        ] );
    }

    public static function ajax_load_tab() {
        if ( ! current_user_can( "manage_options" ) ) {
            wp_send_json_error( [ "message" => "Unauthorized." ], 403 );
        }
        check_ajax_referer( self::NONCE_ACTION, "nonce" );
        $tab = isset( $_POST["tab"] ) ? sanitize_key( wp_unslash( $_POST["tab"] ) ) : "overview";
        wp_send_json_success( self::tab_fragment( $tab ) );
    }

    private static function tab_fragment( string $id ): array {
        $tabs = self::settings_tabs();
        $active = isset( $tabs[ $id ] ) ? $id : "overview";
        ob_start();
        self::render_settings_tab( $active );
        $html = ob_get_clean();
        return [ "tab" => $active, "label" => $tabs[ $active ], "html" => is_string( $html ) ? $html : "" ];
    }

    private static function render_settings_tab( string $id ): void {
        if ( apply_filters( "smp_tts_render_dashboard_tab", false, $id ) ) {
            return;
        }
        if ( "api" === $id ) { self::render_api_tab(); return; }
        if ( "features" === $id ) { self::render_features_tab(); return; }
        if ( "display" === $id ) { self::render_display_tab(); return; }
        if ( "shortcodes" === $id ) { self::render_shortcodes_tab(); return; }
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
        <section class="hexa-tts-panel">
            <div class="hexa-tts-panel-head"><div><h2>System</h2><p>Current plugin identity and runtime wiring.</p></div><a class="button" href="<?php echo esc_url( admin_url( "plugins.php" ) ); ?>">Plugins dashboard</a></div>
            <div class="hexa-tts-system-grid">
                <div><span>Plugin slug</span><code><?php echo esc_html( self::SETTINGS_SLUG ); ?></code></div>
                <div><span>Namespace</span><code>smp_text_to_speech</code></div>
                <div><span>GitHub</span><code><?php echo esc_html( self::GITHUB_REPO ); ?></code></div>
                <div><span>Version</span><code><?php echo esc_html( self::VERSION ); ?></code></div>
                <div><span>API key</span><strong><?php echo $api_key ? "Configured" : "Missing"; ?></strong></div>
                <div><span>Auto placement</span><strong><?php echo esc_html( self::placement_options()[ $settings["auto_player_placement"] ] ?? "Above article" ); ?></strong></div>
            </div>
        </section>
        <section class="hexa-tts-panel hexa-tts-core-panels">
            <div class="hexa-tts-panel-head"><div><h2>Plugin &amp; Hexa WP Core reporting dashboard</h2><p>Updater/status panels are rendered by the vendored Hexa WordPress Plugin Core.</p></div></div>
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
        </section>
        <?php
    }

    private static function render_api_tab(): void {
        $settings = self::get_settings();
        $api_key = self::api_key();
        $last_status = is_array( $settings["last_status"] ?? null ) ? $settings["last_status"] : [];
        ?>
        <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>" class="hexa-tts-settings-form">
            <?php wp_nonce_field( self::NONCE_ACTION, "hexa_tts_nonce" ); ?>
            <input type="hidden" name="action" value="hexa_tts_save_settings">
            <input type="hidden" name="hexa_tts_tab" value="api">
            <section class="hexa-tts-panel" data-provider-card="central">
                <div class="hexa-tts-panel-head"><div><h2>Publish Scale API Connection</h2><p>Paste the site API key generated in Publish Scale. The upstream API base is never printed into browser JavaScript.</p></div><button type="button" class="button button-secondary hexa-tts-test-central-api hexa-tts-test-provider" data-provider="central">Test API Key</button></div>
                <div class="hexa-tts-grid hexa-tts-grid-2">
                    <label><span>Site API Key</span><input type="password" name="hexa_tts[api_key]" value="" placeholder="<?php echo esc_attr( $api_key ? "Saved: " . self::mask_secret( $api_key ) : "Paste Publish Scale site API key" ); ?>" autocomplete="off"><small>Stored encrypted in WordPress. Leave blank to keep the existing key.</small></label>
                    <label><span>ACF audio file field</span><input type="text" name="hexa_tts[acf_audio_field]" value="<?php echo esc_attr( $settings["acf_audio_field"] ); ?>"><small>Mashviral currently uses <code>article_audio</code>.</small></label>
                </div>
                <div class="hexa-tts-test-result hexa-tts-central-result" data-provider-result="central" aria-live="polite"></div>
                <?php if ( ! empty( $last_status["message"] ) ) : ?><div class="hexa-tts-status-card"><strong><?php echo esc_html( $last_status["message"] ); ?></strong><?php if ( ! empty( $last_status["usage"] ) ) : ?><span>Requests: <?php echo esc_html( $last_status["usage"]["requests"] ?? 0 ); ?> · Characters: <?php echo esc_html( $last_status["usage"]["characters"] ?? 0 ); ?> · Est. cost: $<?php echo esc_html( $last_status["usage"]["estimated_cost_usd"] ?? 0 ); ?></span><?php endif; ?></div><?php endif; ?>
            </section>
            <section class="hexa-tts-panel">
                <div class="hexa-tts-panel-head"><div><h2>Generation Defaults</h2><p>Defaults for the one-click post and press-release workflow.</p></div></div>
                <div class="hexa-tts-grid hexa-tts-grid-4">
                    <label><span>Default API source</span><input type="text" name="hexa_tts[default_provider]" value="<?php echo esc_attr( $settings["default_provider"] ); ?>"></label>
                    <label><span>Default profile</span><input type="text" name="hexa_tts[default_profile]" value="<?php echo esc_attr( $settings["default_profile"] ); ?>"></label>
                    <label><span>Default voice</span><input type="text" name="hexa_tts[default_voice]" value="<?php echo esc_attr( $settings["default_voice"] ); ?>"></label>
                    <label><span>Generation speed</span><input type="number" step="0.05" name="hexa_tts[default_speed]" value="<?php echo esc_attr( $settings["default_speed"] ); ?>"></label>
                    <label><span>Max characters</span><input type="number" name="hexa_tts[max_characters]" value="<?php echo esc_attr( $settings["max_characters"] ); ?>" min="500" step="500"></label>
                    <label class="hexa-tts-check-row"><input type="checkbox" name="hexa_tts[auto_insert_player]" value="1" <?php checked( ! empty( $settings["auto_insert_player"] ) ); ?>><span>Auto-insert player</span></label>
                    <label class="hexa-tts-check-row"><input type="checkbox" name="hexa_tts[include_title]" value="1" <?php checked( ! empty( $settings["include_title"] ) ); ?>><span>Include post title in generated narration</span></label>
                    <label class="hexa-tts-check-row"><input type="checkbox" name="hexa_tts[show_player_meta]" value="1" <?php checked( ! empty( $settings["show_player_meta"] ) ); ?>><span>Show player metadata</span></label>
                </div>
            </section>
            <p class="submit hexa-tts-submit"><button type="submit" class="button button-primary button-hero">Save API settings</button></p>
        </form>
        <?php
    }

    private static function render_features_tab(): void {
        $settings = self::get_settings();
        $templates = self::template_options();
        $placements = self::placement_options();
        $shortcode = self::display_shortcode( $settings );
        $acf_field = sanitize_key( $settings["acf_audio_field"] ?: "article_audio" );
        $enabled = ! empty( $settings["auto_insert_player"] );
        ?>
        <style>
        .httf{max-width:1000px}
        .httf-card{border:1px solid #e3e5e9;border-radius:14px;background:#fff;box-shadow:0 1px 3px rgba(16,24,40,.05);overflow:hidden}
        .httf-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;flex-wrap:wrap;padding:18px 22px;background:#f8f9fb;border-bottom:1px solid #e7e9ee}
        .httf-head h2{margin:0 0 4px;font-size:18px;color:#101828}
        .httf-head p{margin:0;font-size:12.5px;color:#667085;max-width:64ch;line-height:1.5}
        .httf-head code{background:#eef0f3;border-radius:4px;padding:1px 6px;font-size:12px}
        .httf-section{padding:18px 22px;border-top:1px solid #f0f1f3}
        .httf-section h3{margin:0 0 3px;font-size:13px;font-weight:700;color:#1d2327;text-transform:none}
        .httf-hint{margin:0 0 14px;font-size:12px;color:#667085;line-height:1.5;max-width:78ch}
        .httf-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:16px;align-items:start}
        .httf-field{display:grid !important;gap:6px;align-content:start;margin:0;font-weight:400}
        .httf-field>span{font-size:12px;font-weight:600;color:#344054}
        .httf-field input[type=text],.httf-field select{width:100%;box-sizing:border-box;height:36px;min-height:36px;max-width:100%;padding:0 11px;border:1px solid #cfd3da;border-radius:8px;font-size:13px;background:#fff;margin:0}
        .httf-field small{font-size:11px;color:#98a2b3;line-height:1.4}
        .httf-color{margin-top:18px;max-width:340px}
        .httf-color .wp-picker-container{margin-top:2px;display:block}
        .httf-color-label{display:block;font-size:12px;font-weight:600;color:#344054;margin:0 0 6px}
        .httf-color .wp-color-result.button{margin:0}
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
        @media(max-width:980px){.httf-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        @media(max-width:600px){.httf-grid{grid-template-columns:1fr}}
        </style>
        <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>" class="hexa-tts-settings-form hexa-tts-features-form hexa-tts-display-live-form httf">
            <?php wp_nonce_field( self::NONCE_ACTION, "hexa_tts_nonce" ); ?>
            <input type="hidden" name="action" value="hexa_tts_save_settings">
            <input type="hidden" name="hexa_tts_tab" value="features">

            <div class="httf-card">
                <header class="httf-head">
                    <div>
                        <h2>Article audio player</h2>
                        <p>Generated or uploaded audio is stored in the Media Library and synced to <code><?php echo esc_html( $acf_field ); ?></code>. Configure the player, choose where it renders, pick a design, and copy the shortcode.</p>
                    </div>
                    <label class="hexa-tts-switch">
                        <input class="hexa-tts-live-control" type="checkbox" name="hexa_tts[auto_insert_player]" value="1" <?php checked( $enabled ); ?>>
                        <span></span>
                        <strong><?php echo $enabled ? esc_html__( "Enabled", "smp-wp-text-to-speech" ) : esc_html__( "Disabled", "smp-wp-text-to-speech" ); ?></strong>
                    </label>
                </header>

                <section class="httf-section">
                    <h3>Core controls</h3>
                    <div class="httf-grid">
                        <label class="httf-field"><span>ACF audio file field</span><input class="hexa-tts-live-control" type="text" name="hexa_tts[acf_audio_field]" value="<?php echo esc_attr( $acf_field ); ?>"><small>Used by generated storage and the editor upload field.</small></label>
                        <label class="httf-field"><span>Player label</span><input class="hexa-tts-live-control" type="text" name="hexa_tts[player_label]" value="<?php echo esc_attr( $settings["player_label"] ); ?>"></label>
                        <label class="httf-field"><span>Player size</span><select class="hexa-tts-live-control" name="hexa_tts[player_size]"><?php self::render_options( self::size_options(), $settings["player_size"] ); ?></select></label>
                    </div>
                    <div class="httf-color"><span class="httf-color-label">Primary color</span><input class="hexa-tts-color-picker hexa-tts-live-control" type="text" name="hexa_tts[primary_color]" value="<?php echo esc_attr( self::sanitize_color( $settings["primary_color"] ) ); ?>" data-default-color="#3657e3"></div>
                    <div class="httf-checks">
                        <label class="httf-check"><input class="hexa-tts-live-control" type="checkbox" name="hexa_tts[include_title]" value="1" <?php checked( ! empty( $settings["include_title"] ) ); ?>><span>Include post title in narration</span></label>
                        <label class="httf-check"><input class="hexa-tts-live-control" type="checkbox" name="hexa_tts[show_player_meta]" value="1" <?php checked( ! empty( $settings["show_player_meta"] ) ); ?>><span>Show provider and date metadata</span></label>
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
                    <div class="hexa-tts-shortcode-card hexa-tts-dynamic-shortcode"><code><?php echo esc_html( $shortcode ); ?></code><button type="button" class="button hexa-tts-copy" data-copy-text="<?php echo esc_attr( $shortcode ); ?>">Copy shortcode</button></div>
                    <div class="httf-proof">
                        <div><small>ACF field</small><code><?php echo esc_html( $acf_field ); ?></code></div>
                        <div><small>Current placement</small><strong><?php echo esc_html( $placements[ $settings["auto_player_placement"] ] ?? "Above article" ); ?></strong></div>
                        <div><small>Current template</small><strong><?php echo esc_html( $templates[ $settings["player_template"] ]["label"] ?? "Clean Card" ); ?></strong></div>
                    </div>
                </section>

                <p class="submit httf-save"><button type="submit" class="button button-primary button-hero">Save feature controls</button><span class="hexa-tts-live-save-state" aria-live="polite"></span></p>
            </div>
        </form>
        <?php
    }

    private static function render_display_tab(): void {
        $settings = self::get_settings();
        $shortcode = self::display_shortcode( $settings );
        ?>
        <section class="hexa-tts-panel">
            <div class="hexa-tts-panel-head"><div><h2>Display Settings</h2><p>Choose the exact live player design and automatic placement. Previews below use the same renderer and CSS as the frontend.</p></div><a class="button" href="<?php echo esc_url( wp_nonce_url( admin_url( "admin-post.php?action=hexa_tts_import_elementor_color" ), self::NONCE_ACTION, "hexa_tts_nonce" ) ); ?>">Import Elementor primary color</a></div>
            <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>" class="hexa-tts-settings-form hexa-tts-display-form hexa-tts-display-live-form">
                <?php wp_nonce_field( self::NONCE_ACTION, "hexa_tts_nonce" ); ?>
                <input type="hidden" name="action" value="hexa_tts_save_settings">
                <input type="hidden" name="hexa_tts_tab" value="display">
                <div class="hexa-tts-grid hexa-tts-grid-4">
                    <label><span>Primary color</span><input type="text" class="hexa-tts-color-picker hexa-tts-live-control" name="hexa_tts[primary_color]" value="<?php echo esc_attr( self::sanitize_color( $settings["primary_color"] ) ); ?>" data-default-color="#3657e3"><small>Used directly by the live player CSS variable.</small></label>
                    <label><span>Player label</span><input type="text" class="hexa-tts-live-control" name="hexa_tts[player_label]" value="<?php echo esc_attr( $settings["player_label"] ); ?>"></label>
                    <label><span>Player size</span><select class="hexa-tts-live-control" name="hexa_tts[player_size]"><?php self::render_options( self::size_options(), $settings["player_size"] ); ?></select></label>
                    <label><span>ACF audio file field</span><input type="text" class="hexa-tts-live-control" name="hexa_tts[acf_audio_field]" value="<?php echo esc_attr( sanitize_key( $settings["acf_audio_field"] ?: "article_audio" ) ); ?>"></label>
                    <label class="hexa-tts-check-row"><input class="hexa-tts-live-control" type="checkbox" name="hexa_tts[auto_insert_player]" value="1" <?php checked( ! empty( $settings["auto_insert_player"] ) ); ?>><span>Enable automatic player placement</span></label>
                    <label class="hexa-tts-check-row"><input class="hexa-tts-live-control" type="checkbox" name="hexa_tts[show_player_meta]" value="1" <?php checked( ! empty( $settings["show_player_meta"] ) ); ?>><span>Show player metadata</span></label>
                </div>

                <h3>Automatic placement</h3>
                <?php echo self::placement_cards_html( $settings ); ?>

                <h3>Template design</h3>
                <p class="description">One design per row. Each preview includes the current MP3 and is rendered with the same player markup used by <code>[smp_tts_player]</code>.</p>
                <div class="hexa-tts-live-preview-target"><?php echo self::template_preview_rows_html( $settings ); ?></div>

                <h3>Shortcode</h3>
                <div class="hexa-tts-shortcode-card hexa-tts-dynamic-shortcode"><code><?php echo esc_html( $shortcode ); ?></code><button type="button" class="button hexa-tts-copy" data-copy-text="<?php echo esc_attr( $shortcode ); ?>">Copy shortcode</button></div>
                <div class="hexa-tts-live-save-state" aria-live="polite"></div>
                <p class="submit hexa-tts-submit"><button type="submit" class="button button-primary button-hero">Save display settings</button></p>
            </form>
        </section>
        <?php
    }

    private static function render_shortcodes_tab(): void {
        $settings = self::get_settings();
        $shortcode = '[smp_tts_player post_id="560368" label="' . sanitize_text_field( $settings["player_label"] ) . '" template="' . sanitize_key( $settings["player_template"] ) . '" size="' . sanitize_key( $settings["player_size"] ) . '" show_meta="1" preload="metadata"]';
        ?>
        <section class="hexa-tts-panel">
            <div class="hexa-tts-panel-head"><div><h2>Shortcodes</h2><p>Manual placement remains available when automatic placement is set to Manual shortcode.</p></div></div>
            <div class="hexa-tts-shortcode-card"><code><?php echo esc_html( $shortcode ); ?></code><button type="button" class="button hexa-tts-copy" data-copy-text="<?php echo esc_attr( $shortcode ); ?>">Copy shortcode</button></div>
            <table class="widefat striped hexa-tts-shortcode-table"><tbody>
                <tr><th>post_id</th><td>Optional. Defaults to the current post when inside the loop.</td></tr>
                <tr><th>label</th><td>Overrides the configured player label.</td></tr>
                <tr><th>template</th><td><?php echo esc_html( implode( ", ", array_keys( self::template_options() ) ) ); ?></td></tr>
                <tr><th>size</th><td><?php echo esc_html( implode( ", ", array_keys( self::size_options() ) ) ); ?></td></tr>
                <tr><th>show_meta</th><td>Use <code>0</code> to hide provider/date metadata.</td></tr>
                <tr><th>preload</th><td><code>none</code>, <code>metadata</code>, or <code>auto</code>.</td></tr>
            </tbody></table>
        </section>
        <?php
    }

    public static function handle_save_settings() {
        if ( ! current_user_can( "manage_options" ) ) {
            wp_die( "Unauthorized." );
        }
        check_admin_referer( self::NONCE_ACTION, "hexa_tts_nonce" );
        $incoming = isset( $_POST["hexa_tts"] ) && is_array( $_POST["hexa_tts"] ) ? wp_unslash( $_POST["hexa_tts"] ) : [];
        $existing = self::get_settings();
        $tab = isset( $_POST["hexa_tts_tab"] ) ? sanitize_key( wp_unslash( $_POST["hexa_tts_tab"] ) ) : "api";
        $clean = self::default_settings();
        $api_key = self::sanitize_secret( $incoming["api_key"] ?? "" );
        $clean["api_key"] = "" === $api_key ? ( $existing["api_key"] ?? "" ) : self::encrypt_secret( $api_key );
        $clean["default_provider"] = sanitize_key( $incoming["default_provider"] ?? $existing["default_provider"] );
        $clean["default_profile"] = sanitize_key( $incoming["default_profile"] ?? $existing["default_profile"] );
        $clean["default_voice"] = sanitize_text_field( $incoming["default_voice"] ?? $existing["default_voice"] );
        $clean["default_speed"] = (string) floatval( $incoming["default_speed"] ?? $existing["default_speed"] );
        $clean["acf_audio_field"] = sanitize_key( $incoming["acf_audio_field"] ?? $existing["acf_audio_field"] );
        $clean["auto_insert_player"] = array_key_exists( "auto_insert_player", $incoming ) ? 1 : ( in_array( $tab, [ "display", "features" ], true ) ? 0 : (int) ( $existing["auto_insert_player"] ?? 1 ) );
        $clean["include_title"] = array_key_exists( "include_title", $incoming ) ? 1 : ( in_array( $tab, [ "api", "features" ], true ) ? 0 : (int) ( $existing["include_title"] ?? 1 ) );
        $clean["show_player_meta"] = array_key_exists( "show_player_meta", $incoming ) ? 1 : ( in_array( $tab, [ "display", "features" ], true ) ? 0 : (int) ( $existing["show_player_meta"] ?? 1 ) );
        $clean["max_characters"] = max( 500, absint( $incoming["max_characters"] ?? $existing["max_characters"] ) );
        $clean["primary_color"] = self::sanitize_color( $incoming["primary_color"] ?? $existing["primary_color"] );
        $clean["player_label"] = sanitize_text_field( $incoming["player_label"] ?? $existing["player_label"] );
        $clean["player_size"] = array_key_exists( sanitize_key( $incoming["player_size"] ?? "" ), self::size_options() ) ? sanitize_key( $incoming["player_size"] ) : ( $existing["player_size"] ?? "default" );
        $clean["player_template"] = array_key_exists( sanitize_key( $incoming["player_template"] ?? "" ), self::template_options() ) ? sanitize_key( $incoming["player_template"] ) : ( $existing["player_template"] ?? "clean_card" );
        $clean["auto_player_placement"] = array_key_exists( sanitize_key( $incoming["auto_player_placement"] ?? "" ), self::placement_options() ) ? sanitize_key( $incoming["auto_player_placement"] ) : ( $existing["auto_player_placement"] ?? "above_article" );
        $clean["last_status"] = is_array( $existing["last_status"] ?? null ) ? $existing["last_status"] : [];
        update_option( self::OPTION, $clean, false );
        wp_safe_redirect( add_query_arg( [ "page" => self::SETTINGS_SLUG, "tab" => $tab, "hexa_tts_saved" => "1" ], admin_url( "options-general.php" ) ) );
        exit;
    }

    public static function handle_import_elementor_color() {
        if ( ! current_user_can( "manage_options" ) ) {
            wp_die( "Unauthorized." );
        }
        check_admin_referer( self::NONCE_ACTION, "hexa_tts_nonce" );
        $color = self::detect_elementor_primary_color();
        $settings = self::get_settings();
        $imported = "no";
        if ( $color ) {
            $settings["primary_color"] = $color;
            update_option( self::OPTION, $settings, false );
            $imported = "yes";
        }
        wp_safe_redirect( add_query_arg( [ "page" => self::SETTINGS_SLUG, "tab" => "display", "hexa_tts_imported" => $imported ], admin_url( "options-general.php" ) ) );
        exit;
    }

    private static function detect_elementor_primary_color(): string {
        $kit_id = absint( get_option( "elementor_active_kit" ) );
        if ( $kit_id ) {
            $settings = get_post_meta( $kit_id, "_elementor_page_settings", true );
            if ( is_array( $settings ) ) {
                foreach ( [ "system_colors", "custom_colors" ] as $group ) {
                    if ( empty( $settings[ $group ] ) || ! is_array( $settings[ $group ] ) ) {
                        continue;
                    }
                    foreach ( $settings[ $group ] as $item ) {
                        if ( ! is_array( $item ) || empty( $item["color"] ) ) {
                            continue;
                        }
                        $identity = strtolower( (string) ( $item["_id"] ?? "" ) . " " . ( $item["title"] ?? "" ) );
                        if ( false !== strpos( $identity, "primary" ) ) {
                            return self::sanitize_color( $item["color"] );
                        }
                    }
                }
            }
        }
        $legacy = get_option( "elementor_scheme_color" );
        if ( is_array( $legacy ) ) {
            foreach ( [ "1", "primary", "color_1" ] as $key ) {
                if ( ! empty( $legacy[ $key ] ) ) {
                    return self::sanitize_color( $legacy[ $key ] );
                }
            }
        }
        return "";
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
            "clean_card" => [ "label" => "Clean Card", "description" => "Rounded article-audio card for normal article pages." ],
            "editorial_bar" => [ "label" => "Editorial Bar", "description" => "Thin publication-style bar above or below visual content." ],
            "compact_pill" => [ "label" => "Compact Pill", "description" => "Small lightweight pill for tight article headers." ],
            "media_panel" => [ "label" => "Media Panel", "description" => "Larger audio-first module with stronger visual weight." ],
            "minimal_audio" => [ "label" => "Minimal Audio", "description" => "Bare player with minimal border and metadata." ],
            "quiet_card" => [ "label" => "Quiet Card", "description" => "Soft 1px card, no shadow, neutral label. The sleek default." ],
            "slim_line" => [ "label" => "Slim Line", "description" => "Borderless player under a thin accent rule. Maximum restraint." ],
            "ghost" => [ "label" => "Ghost", "description" => "Fully transparent — just a small label and the player." ],
            "inline_label" => [ "label" => "Inline Label", "description" => "Label and player on one row for tight article headers." ],
            "dot" => [ "label" => "Dot", "description" => "Small accent dot beside a neutral label, no container." ],
            "underline" => [ "label" => "Underline", "description" => "Label with an accent underline over a hairline divider." ],
            "tag" => [ "label" => "Listen Tag", "description" => "Small tinted pill label beside the player." ],
            "editorial_thin" => [ "label" => "Editorial Thin", "description" => "Refined thin left rule on a soft ground." ],
            "caption" => [ "label" => "Caption", "description" => "Player first, small caption label beneath — like a figure caption." ],
            "eyebrow" => [ "label" => "Eyebrow", "description" => "Uppercase letter-spaced label with a short accent tick." ],
            "framed" => [ "label" => "Framed", "description" => "Thin full border, no shadow, metadata aligned right. Quiet box." ],
            "rule_between" => [ "label" => "Divider", "description" => "Label, a hairline divider, then the player below." ],
            "corner" => [ "label" => "Corner Tick", "description" => "Small accent square beside the label, no container." ],
            "mini" => [ "label" => "Mini", "description" => "Ultra-compact one-row player, smallest footprint." ],
            "soft_tint" => [ "label" => "Soft Tint", "description" => "Barely-there accent-tinted ground, no border." ],
            "what_to_know" => [ "label" => "What to Know Match", "description" => "Identical to the What to Know summary block: left blue rule, faint tint, bold label." ],
        ];
    }

    private static function display_shortcode( array $settings, int $post_id = 560368 ): string {
        return "[smp_tts_player post_id=\"" . absint( $post_id ) . "\" label=\"" . sanitize_text_field( $settings["player_label"] ?? "Listen to this article" ) . "\" template=\"" . sanitize_key( $settings["player_template"] ?? "clean_card" ) . "\" size=\"" . sanitize_key( $settings["player_size"] ?? "default" ) . "\" show_meta=\"" . ( ! empty( $settings["show_player_meta"] ) ? "1" : "0" ) . "\" preload=\"metadata\"]";
    }

    private static function sample_audio_url(): string {
        global $wpdb;
        $url = $wpdb instanceof \wpdb ? (string) $wpdb->get_var( $wpdb->prepare( "SELECT meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value LIKE %s ORDER BY meta_id DESC LIMIT 1", "_hexa_tts_audio_url", "http%" ) ) : "";
        if ( $url ) {
            return esc_url_raw( $url );
        }
        $settings = self::get_settings();
        $field = sanitize_key( $settings["acf_audio_field"] ?: "article_audio" );
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
                                "show_meta" => ! empty( $settings["show_player_meta"] ) ? "1" : "0",
                                "preload" => "metadata",
                                "class" => "hexa-tts-preview-player",
                                "color" => $settings["primary_color"] ?? "#3657e3",
                            ], "UnrealSpeech preview", current_time( "mysql" ) );
                        }
                        ?>
                    </div>
                </article>
            <?php endforeach; ?>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    private static function sanitize_color( $value ): string {
        $color = function_exists( "sanitize_hex_color" ) ? sanitize_hex_color( (string) $value ) : "";
        return $color ?: "#3657e3";
    }

    public static function ajax_validate_central_api() {
        if ( ! current_user_can( "manage_options" ) ) {
            wp_send_json_error( [ "message" => "You do not have permission to validate the TTS API." ], 403 );
        }
        check_ajax_referer( self::NONCE_ACTION, "nonce" );
        $result = self::api_request( "/status", [] );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ "message" => $result->get_error_message() ] );
        }
        $settings = self::get_settings();
        $settings["last_status"] = $result;
        update_option( self::OPTION, $settings, false );
        wp_send_json_success( $result );
    }

    public static function ajax_preview_display() {
        if ( ! current_user_can( "manage_options" ) ) {
            wp_send_json_error( [ "message" => "Unauthorized." ], 403 );
        }
        check_ajax_referer( self::NONCE_ACTION, "nonce" );
        $incoming = isset( $_POST["hexa_tts"] ) && is_array( $_POST["hexa_tts"] ) ? wp_unslash( $_POST["hexa_tts"] ) : [];
        $settings = self::sanitize_display_settings( $incoming, self::get_settings(), true );
        update_option( self::OPTION, $settings, false );
        wp_send_json_success( [
            "preview_html" => self::template_preview_rows_html( $settings ),
            "shortcode" => self::display_shortcode( $settings ),
            "message" => "Display settings saved and preview refreshed.",
        ] );
    }

    private static function sanitize_display_settings( array $incoming, array $existing, bool $checkbox_context = false ): array {
        $clean = $existing;
        $clean["acf_audio_field"] = sanitize_key( $incoming["acf_audio_field"] ?? $existing["acf_audio_field"] ?? "article_audio" );
        $clean["primary_color"] = self::sanitize_color( $incoming["primary_color"] ?? $existing["primary_color"] ?? "#3657e3" );
        $clean["player_label"] = sanitize_text_field( $incoming["player_label"] ?? $existing["player_label"] ?? "Listen to this article" );
        $clean["player_size"] = array_key_exists( sanitize_key( $incoming["player_size"] ?? "" ), self::size_options() ) ? sanitize_key( $incoming["player_size"] ) : ( $existing["player_size"] ?? "default" );
        $clean["player_template"] = array_key_exists( sanitize_key( $incoming["player_template"] ?? "" ), self::template_options() ) ? sanitize_key( $incoming["player_template"] ) : ( $existing["player_template"] ?? "clean_card" );
        $clean["auto_player_placement"] = array_key_exists( sanitize_key( $incoming["auto_player_placement"] ?? "" ), self::placement_options() ) ? sanitize_key( $incoming["auto_player_placement"] ) : ( $existing["auto_player_placement"] ?? "above_article" );
        $clean["auto_insert_player"] = array_key_exists( "auto_insert_player", $incoming ) ? 1 : ( $checkbox_context ? 0 : (int) ( $existing["auto_insert_player"] ?? 1 ) );
        $clean["include_title"] = array_key_exists( "include_title", $incoming ) ? 1 : ( $checkbox_context ? 0 : (int) ( $existing["include_title"] ?? 1 ) );
        $clean["show_player_meta"] = array_key_exists( "show_player_meta", $incoming ) ? 1 : ( $checkbox_context ? 0 : (int) ( $existing["show_player_meta"] ?? 1 ) );
        return $clean;
    }

    public static function register_post_metabox() {
        foreach ( [ "post", "press-release" ] as $post_type ) {
            if ( post_type_exists( $post_type ) ) {
                add_meta_box( "hexa-tts-post-box", "SMP WP Text To Speech", [ __CLASS__, "render_post_metabox" ], $post_type, "normal", "high" );
            }
        }
    }


    public static function render_post_metabox( $post ) {
        if ( class_exists( "\\Hexa\\PluginCore\\WpAdminComponents\\CoreUi" ) ) {
            \Hexa\PluginCore\WpAdminComponents\CoreUi::render_assets();
        }
        $settings = self::get_settings();
        $acf_field = sanitize_key( $settings["acf_audio_field"] ?: "article_audio" );
        $acf_value = get_post_meta( $post->ID, $acf_field, true );
        $audio_url = get_post_meta( $post->ID, "_hexa_tts_audio_url", true );
        $attachment_id = get_post_meta( $post->ID, "_hexa_tts_attachment_id", true );
        if ( ! $audio_url ) {
            $audio_url = self::resolve_audio_url( $acf_value );
        }
        if ( ! $attachment_id && is_numeric( $acf_value ) ) {
            $attachment_id = (int) $acf_value;
        }
        $status = get_post_meta( $post->ID, "_hexa_tts_status", true );
        $api_ready = "" !== self::api_key();
        $provider_label = trim( implode( " / ", array_filter( [ $settings["default_provider"] ?? "", $settings["default_voice"] ?? "" ] ) ) );
        ?>
        <div class="hexa-tts-postbox hexa-tts-postbox-simple hpc-ui" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-acf-field="<?php echo esc_attr( $acf_field ); ?>" data-has-audio="<?php echo $audio_url ? "1" : "0"; ?>" data-existing-attachment-id="<?php echo esc_attr( absint( $attachment_id ) ); ?>">
            <input type="hidden" class="hexa-tts-post-provider" value="<?php echo esc_attr( $settings["default_provider"] ); ?>">
            <input type="hidden" class="hexa-tts-post-profile" value="<?php echo esc_attr( $settings["default_profile"] ); ?>">
            <input type="hidden" class="hexa-tts-post-voice" value="<?php echo esc_attr( $settings["default_voice"] ); ?>">
            <input type="hidden" class="hexa-tts-post-speed" value="<?php echo esc_attr( $settings["default_speed"] ); ?>">

            <div class="hexa-tts-simple-status">
                <div><span class="hexa-tts-kicker">Connection</span><strong class="hexa-tts-api-state <?php echo $api_ready ? "is-ready" : "is-missing"; ?>"><?php echo $api_ready ? "API connected" : "Missing API key"; ?></strong></div>
                <div><span class="hexa-tts-kicker">Audio</span><strong class="hexa-tts-post-status"><?php echo esc_html( $status ?: ( $audio_url ? "Ready" : "Not generated" ) ); ?></strong></div>
                <div><span class="hexa-tts-kicker">ACF audio file</span><strong><?php echo esc_html( $acf_field ); ?></strong></div>
            </div>

            <div class="hexa-tts-one-click-card">
                <div>
                    <h3>Use article text</h3>
                    <p>This uses the post title and body automatically. No copy, paste, or extra fields are required for the normal workflow.</p>
                    <?php if ( $provider_label ) : ?><p class="hexa-tts-muted">Default voice: <?php echo esc_html( $provider_label ); ?></p><?php endif; ?>
                </div>
                <button type="button" class="hpc-button hexa-tts-generate-post">Generate audio from article</button>
            </div>

            <div class="hexa-tts-acf-card">
                <div class="hexa-tts-acf-card-head">
                    <div>
                        <h3>Article Audio</h3>
                        <p>This is the actual ACF audio file field <code><?php echo esc_html( $acf_field ); ?></code>. Upload/select your own audio here, or generate audio above.</p>
                    </div>
                    <?php if ( $audio_url ) : ?><a href="<?php echo esc_url( $audio_url ); ?>" target="_blank" rel="noopener noreferrer">Open current MP3</a><?php endif; ?>
                </div>
                <?php self::embedded_acf_audio_field( $post->ID, $acf_field ); ?>
            </div>

            <div class="hexa-tts-storage-row">
                <span>Audio storage</span>
                <?php if ( $audio_url ) : ?>
                    <strong class="hexa-tts-storage-state">Saved to Media Library and ACF</strong>
                    <a class="hexa-tts-open-audio" href="<?php echo esc_url( $audio_url ); ?>" target="_blank" rel="noopener noreferrer">Open MP3</a>
                <?php else : ?>
                    <strong class="hexa-tts-storage-state">Will save to Media Library and <?php echo esc_html( $acf_field ); ?></strong>
                <?php endif; ?>
                <?php if ( $acf_value ) : ?><small class="hexa-tts-storage-note">Current <?php echo esc_html( $acf_field ); ?> value is set.</small><?php else : ?><small class="hexa-tts-storage-note">No value is currently stored in <?php echo esc_html( $acf_field ); ?>.</small><?php endif; ?>
            </div>

            <?php if ( $audio_url ) : ?>
                <audio controls preload="metadata" src="<?php echo esc_url( $audio_url ); ?>"></audio>
                <p class="hexa-tts-current-storage">Attachment ID: <?php echo esc_html( $attachment_id ?: "n/a" ); ?> · ACF audio file field: <?php echo esc_html( $acf_field ); ?></p>
            <?php endif; ?>

            <div class="hexa-tts-post-feedback" aria-live="polite"></div>
            <div class="hexa-tts-activity-log" aria-live="polite"></div>

            <details class="hexa-tts-advanced-box">
                <summary>Optional: preview or customize narration text</summary>
                <p>Leave this closed for the normal one-click flow. Open it only when the narration needs manual editing.</p>
                <div class="hexa-tts-post-actions hexa-tts-post-actions-left"><button type="button" class="button hexa-tts-extract-post">Import article text for editing</button></div>
                <div class="hexa-tts-grid hexa-tts-grid-2">
                    <label><span>Add before article</span><textarea class="hexa-tts-post-prepend" rows="2" placeholder="Optional intro"></textarea></label>
                    <label><span>Add after article</span><textarea class="hexa-tts-post-append" rows="2" placeholder="Optional outro"></textarea></label>
                </div>
                <label class="hexa-tts-check-row"><input type="checkbox" class="hexa-tts-post-shorten"><span>Shorten automatically if the article is over the limit</span></label>
                <label><span>Narration text</span><textarea class="hexa-tts-extracted-preview" placeholder="Click Import article text for editing, or leave blank to use the article automatically."></textarea></label>
            </details>
            <details class="hexa-tts-technical-box">
                <summary>Technical details</summary>
                <div class="hexa-tts-tech-grid">
                    <div><span>Shortcode</span><code>[smp_tts_player post_id="<?php echo esc_attr( $post->ID ); ?>" label="Listen to this article" show_meta="1" preload="metadata"]</code><button type="button" class="button hexa-tts-copy" data-copy-text="<?php echo esc_attr( '[smp_tts_player post_id="' . $post->ID . '" label="Listen to this article" show_meta="1" preload="metadata"]' ); ?>">Copy shortcode</button></div>
                    <div><span>Legacy shortcode</span><code>[hexa_tts_player post_id="<?php echo esc_attr( $post->ID ); ?>"]</code><button type="button" class="button hexa-tts-copy" data-copy-text="<?php echo esc_attr( '[hexa_tts_player post_id="' . $post->ID . '"]' ); ?>">Copy legacy</button></div>
                    <div><span>ACF field</span><code><?php echo esc_html( $acf_field ); ?></code></div>
                    <div><span>Attachment ID</span><code><?php echo esc_html( $attachment_id ?: 'none' ); ?></code></div>
                    <div><span>Audio URL</span><code><?php echo esc_html( $audio_url ?: 'none' ); ?></code></div>
                </div>
            </details>
        </div>
        <?php
    }


    public static function save_embedded_acf_audio( $post_id, $post ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( ! $post || ! in_array( $post->post_type, [ "post", "press-release" ], true ) ) {
            return;
        }
        if ( ! current_user_can( "edit_post", $post_id ) ) {
            return;
        }
        $posted = $_POST["acf"][ self::acf_audio_field_key() ] ?? null;
        if ( null === $posted ) {
            return;
        }
        $attachment_id = absint( wp_unslash( $posted ) );
        if ( ! $attachment_id ) {
            return;
        }
        $mime = (string) get_post_mime_type( $attachment_id );
        if ( 0 !== strpos( $mime, "audio/" ) ) {
            return;
        }
        $audio_url = wp_get_attachment_url( $attachment_id );
        if ( ! $audio_url ) {
            return;
        }
        $file_path = get_attached_file( $attachment_id );
        self::sync_audio_attachment( $post_id, $attachment_id, $audio_url, $file_path ?: "", [ "provider" => "manual_acf_upload", "cost_usd" => 0 ] );
        update_post_meta( $post_id, "_hexa_tts_status", "Ready" );
    }

    public static function ajax_save_manual_audio() {
        check_ajax_referer( self::NONCE_ACTION, "nonce" );
        $post_id = absint( $_POST["post_id"] ?? 0 );
        $attachment_id = absint( $_POST["attachment_id"] ?? 0 );
        if ( ! $post_id || ! current_user_can( "edit_post", $post_id ) ) {
            wp_send_json_error( [ "message" => "You do not have permission to update audio for this post." ], 403 );
        }
        if ( ! $attachment_id ) {
            wp_send_json_error( [ "message" => "No audio attachment was selected." ], 400 );
        }
        $mime = (string) get_post_mime_type( $attachment_id );
        if ( 0 !== strpos( $mime, "audio/" ) ) {
            wp_send_json_error( [ "message" => "Selected file is not an audio attachment." ], 400 );
        }
        $audio_url = wp_get_attachment_url( $attachment_id );
        if ( ! $audio_url ) {
            wp_send_json_error( [ "message" => "Could not resolve selected audio URL." ], 400 );
        }
        $file_path = get_attached_file( $attachment_id );
        $stored = self::sync_audio_attachment( $post_id, $attachment_id, $audio_url, $file_path ?: "", [ "provider" => "manual_upload", "cost_usd" => 0 ] );
        update_post_meta( $post_id, "_hexa_tts_status", "Ready" );
        wp_send_json_success( array_merge( $stored, [ "message" => "Audio file saved to ACF and Media Library." ] ) );
    }

    public static function ajax_extract_post_content() {
        check_ajax_referer( self::NONCE_ACTION, "nonce" );
        $post_id = absint( $_POST["post_id"] ?? 0 );
        if ( ! $post_id || ! current_user_can( "edit_post", $post_id ) ) {
            wp_send_json_error( [ "message" => "You do not have permission to extract this post." ], 403 );
        }
        $extract = self::extract_post_text( $post_id );
        if ( is_wp_error( $extract ) ) {
            wp_send_json_error( [ "message" => $extract->get_error_message() ] );
        }
        wp_send_json_success( $extract );
    }

    public static function ajax_generation_status() {
        check_ajax_referer( self::NONCE_ACTION, "nonce" );
        $post_id = absint( $_POST["post_id"] ?? 0 );
        if ( ! $post_id || ! current_user_can( "edit_post", $post_id ) ) {
            wp_send_json_error( [ "message" => "You do not have permission to view generation status for this post." ], 403 );
        }

        $client_request_id = self::sanitize_client_request_id( wp_unslash( (string) ( $_POST["client_request_id"] ?? "" ) ) );
        $api_status = $client_request_id ? self::api_request_status( $client_request_id ) : null;

        wp_send_json_success( [
            "status" => (string) get_post_meta( $post_id, "_hexa_tts_status", true ),
            "log" => self::generation_log( $post_id ),
            "api_status" => $api_status,
            "client_request_id" => $client_request_id,
        ] );
    }

    public static function ajax_generate_audio() {
        check_ajax_referer( self::NONCE_ACTION, "nonce" );
        $post_id = absint( $_POST["post_id"] ?? 0 );
        if ( ! $post_id || ! current_user_can( "edit_post", $post_id ) ) {
            wp_send_json_error( [ "message" => "You do not have permission to generate audio for this post." ], 403 );
        }
        $settings = self::get_settings();
        $client_request_id = self::sanitize_client_request_id( wp_unslash( (string) ( $_POST["client_request_id"] ?? "" ) ) );
        $previous_attachment_id = self::current_audio_attachment_id( $post_id, $settings );
        if ( $previous_attachment_id && empty( $_POST["replace_existing"] ) ) {
            wp_send_json_error( [ "message" => "Existing article_audio MP3 detected. Confirm replacement before generating a new one." ], 409 );
        }
        self::reset_generation_log( $post_id, $client_request_id );
        delete_post_meta( $post_id, "_hexa_tts_error" );
        self::add_generation_log( $post_id, "Preparing article content.", "working" );
        $log = self::generation_log( $post_id );
        $content = trim( wp_unslash( (string) ( $_POST["content"] ?? "" ) ) );
        if ( "" === $content ) {
            $extract = self::extract_post_text( $post_id );
            if ( is_wp_error( $extract ) ) {
                update_post_meta( $post_id, "_hexa_tts_status", "Extraction failed" );
                self::add_generation_log( $post_id, "Article text extraction failed: " . $extract->get_error_message(), "error" );
                wp_send_json_error( [ "message" => $extract->get_error_message(), "log" => self::generation_log( $post_id ) ] );
            }
            $content = $extract["text"];
            self::add_generation_log( $post_id, "Article text extracted from the post editor: " . strlen( $content ) . " characters.", "success" );
        }
        $prepend = trim( wp_unslash( (string) ( $_POST["prepend"] ?? "" ) ) );
        $append = trim( wp_unslash( (string) ( $_POST["append"] ?? "" ) ) );
        $content = trim( implode( "\n\n", array_filter( [ $prepend, $content, $append ] ) ) );
        $max = absint( $settings["max_characters"] );
        if ( strlen( $content ) > $max ) {
            if ( ! empty( $_POST["shorten"] ) ) {
                $content = substr( $content, 0, max( 100, $max - 20 ) ) . "...";
                $log[] = "Content exceeded max length and was shortened locally.";
                self::add_generation_log( $post_id, "Content exceeded max length and was shortened locally.", "info" );
            } else {
                update_post_meta( $post_id, "_hexa_tts_status", "Too long" );
                self::add_generation_log( $post_id, "Content is " . strlen( $content ) . " characters, above the limit of " . $max . ".", "error" );
                wp_send_json_error( [ "message" => "Content is " . strlen( $content ) . " characters, above the limit of " . $max . ".", "log" => self::generation_log( $post_id ) ] );
            }
        }
        update_post_meta( $post_id, "_hexa_tts_status", "Waiting" );
        self::add_generation_log( $post_id, "WordPress payload prepared. Sending server-side request to Publish Scale API.", "working", [ "client_request_id" => $client_request_id ] );
        $log[] = "Sending server-side request to Publish Scale API.";
        $current_user = wp_get_current_user();
        $wordpress_user_id = get_current_user_id();
        if ( $wordpress_user_id < 1 ) {
            $wordpress_user_id = max( 1, (int) get_post_field( "post_author", $post_id ) );
        }
        $wordpress_user_login = $current_user ? (string) $current_user->user_login : "";
        if ( "" === $wordpress_user_login && $wordpress_user_id > 0 ) {
            $author = get_user_by( "id", $wordpress_user_id );
            $wordpress_user_login = $author ? (string) $author->user_login : "";
        }
        $result = self::api_request( "/synthesize", [
            "content" => $content,
            "article_url" => get_permalink( $post_id ),
            "post_id" => $post_id,
            "wordpress_user_id" => $wordpress_user_id,
            "wordpress_user_login" => $wordpress_user_login,
            "provider" => sanitize_key( $_POST["provider"] ?? $settings["default_provider"] ),
            "profile" => sanitize_key( $_POST["profile"] ?? $settings["default_profile"] ),
            "runtime" => [ "voice" => sanitize_text_field( wp_unslash( $_POST["voice"] ?? $settings["default_voice"] ) ), "speed" => sanitize_text_field( wp_unslash( $_POST["speed"] ?? $settings["default_speed"] ) ) ],
            "client_request_id" => $client_request_id,
        ], 240 );
        if ( is_wp_error( $result ) ) {
            update_post_meta( $post_id, "_hexa_tts_status", "Failed" );
            update_post_meta( $post_id, "_hexa_tts_error", $result->get_error_message() );
            $log[] = "Publish Scale API failed: " . $result->get_error_message();
            self::add_generation_log( $post_id, "Publish Scale API failed: " . $result->get_error_message(), "error" );
            wp_send_json_error( [ "message" => $result->get_error_message(), "log" => self::generation_log( $post_id ) ] );
        }
        $log[] = "Audio returned by API. Saving to WordPress Media Library.";
        self::add_generation_log( $post_id, "Publish Scale API returned audio bytes. Request " . ( $result["request_id"] ?? $client_request_id ) . " is ready for WordPress storage.", "success", [ "request_id" => $result["request_id"] ?? $client_request_id, "bytes" => $result["bytes"] ?? null, "cost_usd" => $result["cost_usd"] ?? null ] );
        self::add_generation_log( $post_id, "Saving returned MP3 to the WordPress Media Library.", "working" );
        $stored = self::store_api_audio( $post_id, $result, $content );
        if ( is_wp_error( $stored ) ) {
            update_post_meta( $post_id, "_hexa_tts_status", "Storage failed" );
            update_post_meta( $post_id, "_hexa_tts_error", $stored->get_error_message() );
            $log[] = "Storage failed: " . $stored->get_error_message();
            self::add_generation_log( $post_id, "Storage failed: " . $stored->get_error_message(), "error" );
            wp_send_json_error( [ "message" => $stored->get_error_message(), "log" => self::generation_log( $post_id ) ] );
        }
        $log[] = "Attachment stored and ACF/meta field synced.";
        self::add_generation_log( $post_id, "Attachment stored and ACF/meta field synced.", "success", [ "attachment_id" => $stored["attachment_id"] ?? null, "acf_field" => $stored["acf_field"] ?? null ] );
        $deleted_previous_attachment_id = self::delete_replaced_audio_attachment( $previous_attachment_id, (int) ( $stored["attachment_id"] ?? 0 ) );
        if ( $deleted_previous_attachment_id ) {
            self::add_generation_log( $post_id, "Deleted previous article audio attachment " . $deleted_previous_attachment_id . ".", "success", [ "deleted_attachment_id" => $deleted_previous_attachment_id ] );
        }
        delete_post_meta( $post_id, "_hexa_tts_error" );
        update_post_meta( $post_id, "_hexa_tts_status", "Ready" );
        wp_send_json_success( array_merge( $stored, [ "message" => "Audio generated, stored in Media Library, and synced to ACF/meta.", "request_id" => $result["request_id"] ?? "", "archive_url" => $result["archive_url"] ?? "", "cost_usd" => $result["cost_usd"] ?? null, "deleted_attachment_id" => $deleted_previous_attachment_id, "log" => self::generation_log( $post_id ) ] ) );
    }

    public static function generate_for_post( $post_id, array $args = [] ) {
        $post_id = absint( $post_id );
        if ( ! $post_id ) {
            return new WP_Error( "hexa_tts_missing_post_id", "A WordPress post ID is required." );
        }

        $post = get_post( $post_id );
        if ( ! $post || ! in_array( $post->post_type, [ "post", "press-release" ], true ) ) {
            return new WP_Error( "hexa_tts_invalid_post", "Post not found or unsupported for article audio." );
        }

        $settings = self::get_settings();
        $content = trim( (string) ( $args["content"] ?? "" ) );
        if ( "" === $content ) {
            $extract = self::extract_post_text( $post_id );
            if ( is_wp_error( $extract ) ) {
                update_post_meta( $post_id, "_hexa_tts_status", "Extraction failed" );
                update_post_meta( $post_id, "_hexa_tts_error", $extract->get_error_message() );
                return $extract;
            }
            $content = (string) ( $extract["text"] ?? "" );
        }

        $prepend = trim( (string) ( $args["prepend"] ?? "" ) );
        $append  = trim( (string) ( $args["append"] ?? "" ) );
        $content = trim( implode( "\n\n", array_filter( [ $prepend, $content, $append ] ) ) );
        if ( "" === $content ) {
            update_post_meta( $post_id, "_hexa_tts_status", "Empty" );
            return new WP_Error( "hexa_tts_empty_text", "No usable text was available for this post." );
        }

        $max = absint( $settings["max_characters"] ?? 0 );
        if ( $max > 0 && strlen( $content ) > $max ) {
            if ( ! empty( $args["shorten"] ) ) {
                $content = substr( $content, 0, max( 100, $max - 20 ) ) . "...";
            } else {
                update_post_meta( $post_id, "_hexa_tts_status", "Too long" );
                return new WP_Error( "hexa_tts_too_long", "Content is " . strlen( $content ) . " characters, above the limit of " . $max . "." );
            }
        }

        $hash = hash( "sha256", $content );
        $force = ! empty( $args["force"] );
        $acf_field = sanitize_key( $settings["acf_audio_field"] ?: "article_audio" );
        $existing_url = (string) get_post_meta( $post_id, "_hexa_tts_audio_url", true );
        $existing_attachment_id = absint( get_post_meta( $post_id, "_hexa_tts_attachment_id", true ) );
        if ( "" === $existing_url && $existing_attachment_id ) {
            $existing_url = (string) wp_get_attachment_url( $existing_attachment_id );
        }
        if ( "" === $existing_url ) {
            $existing_url = self::resolve_audio_url( get_post_meta( $post_id, $acf_field, true ) );
        }
        $existing_hash = (string) get_post_meta( $post_id, "_hexa_tts_text_hash", true );
        if ( ! $force && "" !== $existing_url && $existing_hash === $hash ) {
            return [
                "audio_url" => esc_url_raw( $existing_url ),
                "attachment_id" => $existing_attachment_id,
                "acf_field" => $acf_field,
                "acf_value" => $existing_attachment_id ?: get_post_meta( $post_id, $acf_field, true ),
                "bytes" => 0,
                "message" => "Existing article audio is current.",
                "request_id" => (string) get_post_meta( $post_id, "_hexa_tts_request_id", true ),
                "archive_url" => (string) get_post_meta( $post_id, "_hexa_tts_archive_url", true ),
                "cost_usd" => get_post_meta( $post_id, "_hexa_tts_cost_usd", true ),
                "provider" => (string) get_post_meta( $post_id, "_hexa_tts_provider", true ),
                "status" => "synced",
                "reused" => true,
            ];
        }
        $previous_attachment_id = self::current_audio_attachment_id( $post_id, $settings );

        update_post_meta( $post_id, "_hexa_tts_status", "Waiting" );
        delete_post_meta( $post_id, "_hexa_tts_error" );

        $current_user = wp_get_current_user();
        $wordpress_user_id = get_current_user_id();
        if ( $wordpress_user_id < 1 ) {
            $wordpress_user_id = max( 1, (int) get_post_field( "post_author", $post_id ) );
        }
        $wordpress_user_login = $current_user ? (string) $current_user->user_login : "";
        if ( "" === $wordpress_user_login && $wordpress_user_id > 0 ) {
            $author = get_user_by( "id", $wordpress_user_id );
            $wordpress_user_login = $author ? (string) $author->user_login : "";
        }
        $result = self::api_request( "/synthesize", [
            "content" => $content,
            "article_url" => get_permalink( $post_id ),
            "post_id" => $post_id,
            "wordpress_user_id" => $wordpress_user_id,
            "wordpress_user_login" => $wordpress_user_login,
            "provider" => sanitize_key( $args["provider"] ?? $settings["default_provider"] ),
            "profile" => sanitize_key( $args["profile"] ?? $settings["default_profile"] ),
            "runtime" => [
                "voice" => sanitize_text_field( $args["voice"] ?? $settings["default_voice"] ),
                "speed" => sanitize_text_field( $args["speed"] ?? $settings["default_speed"] ),
            ],
        ], 240 );

        if ( is_wp_error( $result ) ) {
            update_post_meta( $post_id, "_hexa_tts_status", "Failed" );
            update_post_meta( $post_id, "_hexa_tts_error", $result->get_error_message() );
            return $result;
        }

        $stored = self::store_api_audio( $post_id, $result, $content );
        if ( is_wp_error( $stored ) ) {
            update_post_meta( $post_id, "_hexa_tts_status", "Storage failed" );
            update_post_meta( $post_id, "_hexa_tts_error", $stored->get_error_message() );
            return $stored;
        }

        update_post_meta( $post_id, "_hexa_tts_status", "Ready" );
        update_post_meta( $post_id, "_hexa_tts_text_hash", $hash );
        update_post_meta( $post_id, "_hexa_tts_character_count", strlen( $content ) );
        $deleted_previous_attachment_id = self::delete_replaced_audio_attachment( $previous_attachment_id, (int) ( $stored["attachment_id"] ?? 0 ) );

        return array_merge( $stored, [
            "message" => "Audio generated, stored in Media Library, and synced to ACF/meta.",
            "request_id" => $result["request_id"] ?? "",
            "archive_url" => $result["archive_url"] ?? "",
            "cost_usd" => $result["cost_usd"] ?? null,
            "provider" => sanitize_key( $args["provider"] ?? $settings["default_provider"] ),
            "status" => "synced",
            "reused" => false,
            "deleted_attachment_id" => $deleted_previous_attachment_id,
        ] );
    }

    private static function extract_post_text( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( "hexa_tts_missing_post", "Post not found." );
        }
        $settings = self::get_settings();
        $parts = [];
        if ( ! empty( $settings["include_title"] ) ) {
            $parts[] = get_the_title( $post );
        }
        $content = $post->post_content;
        if ( function_exists( "do_blocks" ) ) {
            $content = do_blocks( $content );
        }
        $content = strip_shortcodes( $content );
        $content = preg_replace( "#<(script|style|noscript)[^>]*>.*?</\\1>#is", " ", $content );
        $content = preg_replace( "#<(h[1-6]|p|li|blockquote|br)[^>]*>#i", "\n", $content );
        $text = wp_strip_all_tags( $content, true );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( "charset" ) ?: "UTF-8" );
        $text = preg_replace( "/[ \t]+/", " ", $text );
        $text = preg_replace( "/\n{3,}/", "\n\n", $text );
        $text = trim( $text );
        if ( "" !== $text ) {
            $parts[] = $text;
        }
        $final = trim( implode( "\n\n", array_filter( $parts ) ) );
        if ( "" === $final ) {
            return new WP_Error( "hexa_tts_empty_text", "No usable text was extracted from this post." );
        }
        return [ "text" => $final, "characters" => strlen( $final ), "words" => str_word_count( wp_strip_all_tags( $final ) ), "hash" => hash( "sha256", $final ), "preview" => function_exists( "mb_substr" ) ? mb_substr( $final, 0, 5000 ) : substr( $final, 0, 5000 ) ];
    }

    private static function sanitize_client_request_id( $value ): string {
        $value = strtolower( preg_replace( "/[^a-z0-9_\\-]/", "", (string) $value ) );
        if ( preg_match( "/^tts_[a-z0-9_\\-]{12,76}$/", $value ) ) {
            return $value;
        }

        return "tts_wp_" . strtolower( wp_generate_password( 20, false, false ) );
    }

    private static function reset_generation_log( int $post_id, string $client_request_id ): void {
        update_post_meta( $post_id, "_hexa_tts_generation_client_id", $client_request_id );
        update_post_meta( $post_id, "_hexa_tts_activity_log", wp_json_encode( [] ) );
        self::add_generation_log( $post_id, "WordPress accepted the generation request.", "working", [ "client_request_id" => $client_request_id ] );
    }

    private static function add_generation_log( int $post_id, string $message, string $state = "info", array $context = [] ): array {
        $log = self::generation_log( $post_id );
        $log[] = [
            "time" => current_time( "H:i:s" ),
            "state" => sanitize_key( $state ?: "info" ),
            "message" => sanitize_text_field( $message ),
            "context" => $context,
        ];
        update_post_meta( $post_id, "_hexa_tts_activity_log", wp_json_encode( $log ) );
        return $log;
    }

    private static function generation_log( int $post_id ): array {
        $raw = get_post_meta( $post_id, "_hexa_tts_activity_log", true );
        if ( is_array( $raw ) ) {
            return $raw;
        }
        $decoded = is_string( $raw ) && "" !== $raw ? json_decode( $raw, true ) : [];
        return is_array( $decoded ) ? $decoded : [];
    }

    private static function api_request_status( string $public_id ): ?array {
        $public_id = self::sanitize_client_request_id( $public_id );
        if ( "" === $public_id ) {
            return null;
        }

        $response = wp_remote_get( self::API_BASE . "/requests/" . rawurlencode( $public_id ), [
            "timeout" => 8,
            "headers" => [ "Accept" => "application/json" ],
        ] );
        if ( is_wp_error( $response ) ) {
            return [ "status" => "unavailable", "message" => $response->get_error_message() ];
        }

        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( 200 !== (int) $code || ! is_array( $body ) || empty( $body["success"] ) || empty( $body["request"] ) || ! is_array( $body["request"] ) ) {
            return null;
        }

        return [
            "status" => sanitize_text_field( (string) ( $body["request"]["status"] ?? "" ) ),
            "message" => sanitize_text_field( (string) ( $body["request"]["message"] ?? "" ) ),
            "provider" => sanitize_text_field( (string) ( $body["request"]["provider"] ?? "" ) ),
            "audio_bytes" => absint( $body["request"]["audio_bytes"] ?? 0 ),
            "cost_usd" => isset( $body["request"]["cost_usd"] ) ? (float) $body["request"]["cost_usd"] : null,
        ];
    }

    private static function api_request( $path, array $payload, $timeout = 30 ) {
        $api_key = self::api_key();
        if ( "" === $api_key ) {
            return new WP_Error( "hexa_tts_missing_api_key", "Missing Publish Scale TTS API key." );
        }
        $response = wp_remote_post( self::API_BASE . $path, [ "timeout" => $timeout, "headers" => [ "Accept" => "application/json", "Content-Type" => "application/json", "X-SMP-TTS-Key" => $api_key ], "body" => wp_json_encode( $payload ) ] );
        if ( is_wp_error( $response ) ) {
            return $response;
        }
        $code = wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body["success"] ) ) {
            $message = is_array( $body ) && ! empty( $body["message"] ) ? $body["message"] : "Publish Scale API returned HTTP " . $code . ".";
            return new WP_Error( "hexa_tts_api_error", $message );
        }
        return $body;
    }


    private static function acf_audio_field_key() {
        return "field_643ce428d9b50";
    }

    private static function resolve_audio_url( $value ) {
        if ( is_numeric( $value ) ) {
            $url = wp_get_attachment_url( (int) $value );
            return $url ? $url : "";
        }
        if ( is_string( $value ) && preg_match( "#^https?://#i", $value ) ) {
            return $value;
        }
        return "";
    }

    private static function audio_attachment_id_from_value( $value ): int {
        if ( is_numeric( $value ) ) {
            return absint( $value );
        }
        if ( is_array( $value ) ) {
            foreach ( [ "ID", "id", "attachment_id" ] as $key ) {
                if ( isset( $value[ $key ] ) && is_numeric( $value[ $key ] ) ) {
                    return absint( $value[ $key ] );
                }
            }
        }
        return 0;
    }

    private static function current_audio_attachment_id( int $post_id, array $settings = [] ): int {
        if ( empty( $settings ) ) {
            $settings = self::get_settings();
        }
        $acf_field = sanitize_key( $settings["acf_audio_field"] ?? "article_audio" );
        foreach ( [ get_post_meta( $post_id, $acf_field, true ), get_post_meta( $post_id, "_hexa_tts_attachment_id", true ) ] as $value ) {
            $attachment_id = self::audio_attachment_id_from_value( $value );
            if ( $attachment_id && "attachment" === get_post_type( $attachment_id ) && 0 === strpos( (string) get_post_mime_type( $attachment_id ), "audio/" ) ) {
                return $attachment_id;
            }
        }
        return 0;
    }

    private static function delete_replaced_audio_attachment( int $previous_attachment_id, int $new_attachment_id ): int {
        if ( ! $previous_attachment_id || $previous_attachment_id === $new_attachment_id ) {
            return 0;
        }
        if ( "attachment" !== get_post_type( $previous_attachment_id ) ) {
            return 0;
        }
        if ( 0 !== strpos( (string) get_post_mime_type( $previous_attachment_id ), "audio/" ) ) {
            return 0;
        }
        return wp_delete_attachment( $previous_attachment_id, true ) ? $previous_attachment_id : 0;
    }

    private static function sync_audio_attachment( $post_id, $attachment_id, $audio_url, $file_path = "", array $api_result = [] ) {
        $settings = self::get_settings();
        $acf_field = sanitize_key( $settings["acf_audio_field"] ?: "article_audio" );
        update_post_meta( $post_id, "_hexa_tts_audio_url", esc_url_raw( $audio_url ) );
        update_post_meta( $post_id, "_hexa_tts_attachment_id", (int) $attachment_id );
        if ( $file_path ) {
            update_post_meta( $post_id, "_hexa_tts_audio_path", $file_path );
        }
        if ( ! empty( $api_result["request_id"] ) ) {
            update_post_meta( $post_id, "_hexa_tts_request_id", sanitize_text_field( $api_result["request_id"] ) );
        }
        if ( ! empty( $api_result["archive_url"] ) ) {
            update_post_meta( $post_id, "_hexa_tts_archive_url", esc_url_raw( $api_result["archive_url"] ) );
        }
        update_post_meta( $post_id, "_hexa_tts_cost_usd", sanitize_text_field( (string) ( $api_result["cost_usd"] ?? "0" ) ) );
        update_post_meta( $post_id, "_hexa_tts_generated_at", current_time( "mysql" ) );
        update_post_meta( $post_id, "_hexa_tts_provider", sanitize_key( $api_result["provider"] ?? "manual_upload" ) );
        update_post_meta( $post_id, "_hexa_tts_provider_key_last4", sanitize_text_field( $api_result["provider_key_last4"] ?? "" ) );
        update_post_meta( $post_id, "_hexa_tts_acf_field", $acf_field );
        if ( function_exists( "update_field" ) ) {
            update_field( self::acf_audio_field_key(), (int) $attachment_id, $post_id );
        } else {
            update_post_meta( $post_id, $acf_field, (int) $attachment_id );
        }
        $bytes = $file_path && file_exists( $file_path ) ? filesize( $file_path ) : 0;
        return [ "audio_url" => $audio_url, "attachment_id" => (int) $attachment_id, "acf_field" => $acf_field, "acf_value" => (int) $attachment_id, "bytes" => (int) $bytes ];
    }

    private static function store_api_audio( $post_id, array $api_result, $content ) {
        if ( empty( $api_result["audio_base64"] ) ) {
            return new WP_Error( "hexa_tts_missing_audio", "API response did not include audio_base64." );
        }
        $bytes = base64_decode( (string) $api_result["audio_base64"], true );
        if ( ! is_string( $bytes ) || strlen( $bytes ) < 100 ) {
            return new WP_Error( "hexa_tts_bad_audio", "Decoded audio payload is empty." );
        }
        $request_id = sanitize_key( $api_result["request_id"] ?? substr( hash( "sha256", $content ), 0, 12 ) );
        return self::store_audio_bytes( $post_id, $bytes, $request_id, $api_result );
    }

    private static function store_audio_bytes( $post_id, $bytes, $request_id, array $api_result ) {
        $filename = sanitize_file_name( "hexa-tts-" . $post_id . "-" . $request_id . ".mp3" );
        $upload = wp_upload_bits( $filename, null, $bytes );
        if ( ! empty( $upload["error"] ) ) {
            return new WP_Error( "hexa_tts_upload_failed", $upload["error"] );
        }
        require_once ABSPATH . "wp-admin/includes/file.php";
        require_once ABSPATH . "wp-admin/includes/media.php";
        require_once ABSPATH . "wp-admin/includes/image.php";
        $attachment_id = wp_insert_attachment( [ "post_mime_type" => $api_result["audio_mime"] ?? "audio/mpeg", "post_title" => sanitize_text_field( get_the_title( $post_id ) . " narration" ), "post_content" => "", "post_status" => "inherit" ], $upload["file"], $post_id );
        if ( is_wp_error( $attachment_id ) || ! $attachment_id ) {
            return new WP_Error( "hexa_tts_attachment_failed", is_wp_error( $attachment_id ) ? $attachment_id->get_error_message() : "Could not insert media attachment." );
        }
        $metadata = wp_generate_attachment_metadata( $attachment_id, $upload["file"] );
        if ( is_array( $metadata ) ) {
            wp_update_attachment_metadata( $attachment_id, $metadata );
        }
        return self::sync_audio_attachment( $post_id, (int) $attachment_id, $upload["url"], $upload["file"], $api_result );
    }

    public static function maybe_insert_player( $content ) {
        $settings = self::get_settings();
        if ( ! in_the_loop() || ! is_main_query() || ! self::should_auto_insert_player( get_the_ID(), $settings ) || "above_article" !== ( $settings["auto_player_placement"] ?? "above_article" ) ) {
            return $content;
        }
        $player = self::player_html( get_the_ID() );
        return "" === $player ? $content : $player . $content;
    }

    public static function maybe_insert_player_around_featured_image( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
        static $injected = [];
        $settings = self::get_settings();
        $placement = $settings["auto_player_placement"] ?? "above_article";
        if ( ! in_array( $placement, [ "before_featured_image", "after_featured_image" ], true ) || ! self::should_auto_insert_player( $post_id, $settings ) ) {
            return $html;
        }
        if ( ! empty( $injected[ $post_id ][ $placement ] ) ) {
            return $html;
        }
        $player = self::player_html( $post_id );
        if ( "" === $player ) {
            return $html;
        }
        $injected[ $post_id ][ $placement ] = true;
        return "before_featured_image" === $placement ? $player . $html : $html . $player;
    }

    private static function should_auto_insert_player( $post_id, array $settings ): bool {
        if ( empty( $settings["auto_insert_player"] ) || "manual" === ( $settings["auto_player_placement"] ?? "above_article" ) ) {
            return false;
        }
        if ( ! is_singular( [ "post", "press-release" ] ) ) {
            return false;
        }
        $queried = (int) get_queried_object_id();
        return $post_id && ( 0 === $queried || (int) $post_id === $queried );
    }

    public static function render_player_shortcode( $atts = [] ) {
        $settings = self::get_settings();
        $atts = shortcode_atts( [
            "post_id" => get_the_ID(),
            "label" => $settings["player_label"] ?? "Listen to this article",
            "show_meta" => ! empty( $settings["show_player_meta"] ) ? "1" : "0",
            "preload" => "metadata",
            "class" => "",
            "template" => $settings["player_template"] ?? "clean_card",
            "size" => $settings["player_size"] ?? "default",
            "color" => $settings["primary_color"] ?? "#3657e3",
        ], $atts, "smp_tts_player" );
        return self::player_html( absint( $atts["post_id"] ), $atts );
    }

    private static function player_html( $post_id, array $args = [] ) {
        $url = get_post_meta( $post_id, "_hexa_tts_audio_url", true );
        $settings = self::get_settings();
        if ( ! $url ) {
            $url = self::resolve_audio_url( get_post_meta( $post_id, sanitize_key( $settings["acf_audio_field"] ?: "article_audio" ), true ) );
        }
        if ( ! $url ) {
            return "";
        }
        return self::player_markup( $url, $args, get_post_meta( $post_id, "_hexa_tts_provider", true ), get_post_meta( $post_id, "_hexa_tts_generated_at", true ) );
    }

    private static function player_markup( string $url, array $args = [], string $provider = "", string $generated = "" ): string {
        $settings = self::get_settings();
        $templates = self::template_options();
        $sizes = self::size_options();
        $template = sanitize_key( $args["template"] ?? ( $settings["player_template"] ?? "clean_card" ) );
        $template = isset( $templates[ $template ] ) ? $template : "clean_card";
        $size = sanitize_key( $args["size"] ?? ( $settings["player_size"] ?? "default" ) );
        $size = isset( $sizes[ $size ] ) ? $size : "default";
        $label = isset( $args["label"] ) ? (string) $args["label"] : ( $settings["player_label"] ?? "Listen to this article" );
        $show_meta = ! isset( $args["show_meta"] ) || "0" !== (string) $args["show_meta"];
        $raw_preload = isset( $args["preload"] ) && "" !== (string) $args["preload"] ? (string) $args["preload"] : "metadata";
        $preload = in_array( $raw_preload, [ "none", "metadata", "auto" ], true ) ? $raw_preload : "metadata";
        $extra_class = sanitize_html_class( (string) ( $args["class"] ?? "" ) );
        $color = self::sanitize_color( $args["color"] ?? ( $settings["primary_color"] ?? "#3657e3" ) );
        $classes = trim( "hexa-tts-player hexa-tts-player--" . $template . " hexa-tts-player--size-" . $size . " " . $extra_class );
        ob_start();
        ?>
        <aside class="<?php echo esc_attr( $classes ); ?>" style="--smp-tts-primary: <?php echo esc_attr( $color ); ?>;" aria-label="Article audio narration"><div class="hexa-tts-player__label"><?php echo esc_html( $label ); ?></div><audio controls preload="<?php echo esc_attr( $preload ); ?>" src="<?php echo esc_url( $url ); ?>"></audio><?php if ( $show_meta ) : ?><div class="hexa-tts-player__meta"><?php if ( $provider ) : ?><span><?php echo esc_html( $provider ); ?></span><?php endif; ?><?php if ( $generated ) : ?><span><?php echo esc_html( mysql2date( "M j, Y g:i A", $generated ) ); ?></span><?php endif; ?></div><?php endif; ?></aside>
        <?php
        return ob_get_clean();
    }

    private static function api_key() {
        $settings = self::get_settings();
        return self::decrypt_secret( $settings["api_key"] ?? "" );
    }

    private static function sanitize_secret( $value ) {
        $value = trim( (string) $value );
        return preg_replace( "/[\\x00-\\x1F\\x7F]/", "", $value );
    }

    private static function secret_key() {
        $material = ( defined( "AUTH_KEY" ) ? AUTH_KEY : "" ) . ( defined( "SECURE_AUTH_KEY" ) ? SECURE_AUTH_KEY : "" ) . ( defined( "LOGGED_IN_KEY" ) ? LOGGED_IN_KEY : "" );
        return hash( "sha256", "" === $material ? wp_salt( "auth" ) : $material, true );
    }

    private static function encrypt_secret( $value ) {
        if ( "" === $value || ! function_exists( "openssl_encrypt" ) ) {
            return $value;
        }
        $iv = function_exists( "random_bytes" ) ? random_bytes( 16 ) : substr( hash( "sha256", wp_rand() . microtime( true ), true ), 0, 16 );
        $cipher = openssl_encrypt( $value, "AES-256-CBC", self::secret_key(), OPENSSL_RAW_DATA, $iv );
        return false === $cipher ? $value : "enc:" . base64_encode( $iv . $cipher );
    }

    private static function decrypt_secret( $value ) {
        if ( ! is_string( $value ) || 0 !== strpos( $value, "enc:" ) || ! function_exists( "openssl_decrypt" ) ) {
            return (string) $value;
        }
        $raw = base64_decode( substr( $value, 4 ), true );
        if ( false === $raw || strlen( $raw ) < 17 ) {
            return "";
        }
        $plain = openssl_decrypt( substr( $raw, 16 ), "AES-256-CBC", self::secret_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
        return false === $plain ? "" : $plain;
    }

    private static function mask_secret( $value ) {
        $value = (string) $value;
        if ( "" === $value ) {
            return "";
        }
        return str_repeat( "*", min( 8, max( 4, strlen( $value ) - 4 ) ) ) . substr( $value, -4 );
    }
}

Plugin::init();
