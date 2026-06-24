<?php
/**
 * Plugin Name: SMP WP Text To Speech
 * Plugin URI: https://code.hexawebsystems.com/manual-ai-reports/6/view
 * Description: Publish Scale text-to-speech client for WordPress article narration. Uses hidden server-side API calls, AJAX generation, Media Library storage, and ACF field syncing.
 * Version: 1.2.0
 * Author: Hexa Web Systems
 * Text Domain: smp-wp-text-to-speech
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

namespace smp_text_to_speech;

use WP_Error;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

final class Plugin {
    const VERSION = "1.2.0";
    const OPTION = "hexa_tts_settings";
    const NONCE_ACTION = "hexa_tts_admin_nonce";
    const SETTINGS_SLUG = "smp-wp-text-to-speech";
    const API_BASE = "https://publish.scalemypublication.com/api/smp-wordpress-tts/v1";

    public static function init() {
        add_action( "admin_menu", [ __CLASS__, "register_admin_menu" ] );
        add_action( "admin_enqueue_scripts", [ __CLASS__, "enqueue_admin_assets" ] );
        add_action( "acf/init", [ __CLASS__, "register_acf_audio_field" ] );
        add_action( "admin_post_hexa_tts_save_settings", [ __CLASS__, "handle_save_settings" ] );
        add_action( "add_meta_boxes", [ __CLASS__, "register_post_metabox" ] );
        add_action( "wp_ajax_hexa_tts_validate_central_api", [ __CLASS__, "ajax_validate_central_api" ] );
        add_action( "wp_ajax_hexa_tts_validate_provider", [ __CLASS__, "ajax_validate_central_api" ] );
        add_action( "wp_ajax_hexa_tts_extract_post_content", [ __CLASS__, "ajax_extract_post_content" ] );
        add_action( "wp_ajax_hexa_tts_generate_audio", [ __CLASS__, "ajax_generate_audio" ] );
        add_action( "wp_ajax_hexa_tts_save_manual_audio", [ __CLASS__, "ajax_save_manual_audio" ] );
        add_filter( "the_content", [ __CLASS__, "maybe_insert_player" ], 12 );
        add_shortcode( "hexa_tts_player", [ __CLASS__, "render_player_shortcode" ] );
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

    public static function enqueue_admin_assets( $hook ) {
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        $is_settings = "settings_page_" . self::SETTINGS_SLUG === $hook;
        $is_post = $screen && "post" === $screen->base;
        if ( ! $is_settings && ! $is_post ) {
            return;
        }
        if ( $is_post ) {
            wp_enqueue_media();
        }
        wp_enqueue_style( "hexa-tts-admin", plugin_dir_url( __FILE__ ) . "assets/admin.css", [], self::VERSION );
        wp_enqueue_script( "hexa-tts-admin", plugin_dir_url( __FILE__ ) . "assets/admin.js", [ "jquery" ], self::VERSION, true );
        wp_localize_script( "hexa-tts-admin", "hexaTts", [ "ajaxUrl" => admin_url( "admin-ajax.php" ), "nonce" => wp_create_nonce( self::NONCE_ACTION ) ] );
        wp_add_inline_script( "hexa-tts-admin", self::admin_inline_script(), "after" );
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
      audio.attr("preload", "none");
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
  jq(document).on("click", ".hexa-tts-generate-post", function () {
    var button = jq(this);
    var box = button.closest(".hexa-tts-postbox");
    var feedback = box.find(".hexa-tts-post-feedback");
    var payload = postPayload(box);

    payload.action = "hexa_tts_generate_audio";
    payload.nonce = hexaTts.nonce;

    button.prop("disabled", true);
    feedback.removeClass("is-error is-success").addClass("is-loading").text("Generating audio from the article text. Keep this editor tab open...");
    box.find(".hexa-tts-post-status").text("Generating");

    jq.ajax({ url: hexaTts.ajaxUrl, method: "POST", data: payload })
      .done(function (response) {
        if (response && response.success) {
          showSuccess(box, response.data, response.data.message || "Audio generated and saved to ACF.");
          return;
        }
        box.find(".hexa-tts-post-status").text("Failed");
        feedback.removeClass("is-loading is-success").addClass("is-error").text(response && response.data ? response.data.message : "Generation failed.");
      })
      .fail(function (xhr) {
        box.find(".hexa-tts-post-status").text("Failed");
        feedback.removeClass("is-loading is-success").addClass("is-error").text(xhr.responseText || xhr.statusText);
      })
      .always(function () {
        button.prop("disabled", false);
      });
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

    public static function register_acf_audio_field() {
        if ( ! function_exists( "acf_add_local_field_group" ) ) {
            return;
        }
        $settings = self::get_settings();
        $acf_field = sanitize_key( $settings["acf_audio_field"] ?: "article_audio" );
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

    public static function default_settings() {
        return [
            "api_key" => "",
            "default_provider" => "unrealspeech",
            "default_profile" => "default",
            "default_voice" => "af",
            "default_speed" => "0",
            "acf_audio_field" => "article_audio",
            "auto_insert_player" => 1,
            "include_title" => 1,
            "max_characters" => 20000,
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
        $settings = self::get_settings();
        $api_key = self::api_key();
        $saved = isset( $_GET["hexa_tts_saved"] ) && "1" === $_GET["hexa_tts_saved"];
        $last_status = is_array( $settings["last_status"] ?? null ) ? $settings["last_status"] : [];
        ?>
        <div class="wrap hexa-tts-wrap">
            <div class="hexa-tts-page-head"><div><h1>SMP WP Text To Speech</h1><p>WordPress client for Publish Scale article audio. Browser requests stay inside WordPress; upstream calls are server-side only.</p></div></div>
            <?php if ( $saved ) : ?><div class="notice notice-success is-dismissible"><p>SMP WP Text To Speech settings saved.</p></div><?php endif; ?>
            <form method="post" action="<?php echo esc_url( admin_url( "admin-post.php" ) ); ?>" class="hexa-tts-settings-form">
                <?php wp_nonce_field( self::NONCE_ACTION, "hexa_tts_nonce" ); ?>
                <input type="hidden" name="action" value="hexa_tts_save_settings">
                <section class="hexa-tts-panel" data-provider-card="central">
                    <div class="hexa-tts-panel-head"><div><h2>Publish Scale API Connection</h2><p>Paste the site API key generated in Publish Scale. The API base is not printed into admin JavaScript.</p></div><button type="button" class="button button-secondary hexa-tts-test-central-api hexa-tts-test-provider" data-provider="central">Test API Key</button></div>
                    <div class="hexa-tts-grid hexa-tts-grid-2">
                        <label><span>Site API Key</span><input type="password" name="hexa_tts[api_key]" value="" placeholder="<?php echo esc_attr( $api_key ? "Saved: " . self::mask_secret( $api_key ) : "Paste Publish Scale site API key" ); ?>" autocomplete="off"><small>Stored encrypted in WordPress. Leave blank to keep the existing key.</small></label>
                        <label><span>ACF / meta audio field</span><input type="text" name="hexa_tts[acf_audio_field]" value="<?php echo esc_attr( $settings["acf_audio_field"] ); ?>"><small>Mashviral currently uses article_audio.</small></label>
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
                        <label class="hexa-tts-check-row"><input type="checkbox" name="hexa_tts[include_title]" value="1" <?php checked( ! empty( $settings["include_title"] ) ); ?>><span>Include post title</span></label>
                    </div>
                </section>
                <p class="submit hexa-tts-submit"><button type="submit" class="button button-primary button-hero">Save SMP WP Text To Speech settings</button></p>
            </form>
        </div>
        <?php
    }

    public static function handle_save_settings() {
        if ( ! current_user_can( "manage_options" ) ) {
            wp_die( "Unauthorized." );
        }
        check_admin_referer( self::NONCE_ACTION, "hexa_tts_nonce" );
        $incoming = isset( $_POST["hexa_tts"] ) && is_array( $_POST["hexa_tts"] ) ? wp_unslash( $_POST["hexa_tts"] ) : [];
        $existing = self::get_settings();
        $clean = self::default_settings();
        $api_key = self::sanitize_secret( $incoming["api_key"] ?? "" );
        $clean["api_key"] = "" === $api_key ? ( $existing["api_key"] ?? "" ) : self::encrypt_secret( $api_key );
        $clean["default_provider"] = sanitize_key( $incoming["default_provider"] ?? $existing["default_provider"] );
        $clean["default_profile"] = sanitize_key( $incoming["default_profile"] ?? $existing["default_profile"] );
        $clean["default_voice"] = sanitize_text_field( $incoming["default_voice"] ?? $existing["default_voice"] );
        $clean["default_speed"] = (string) floatval( $incoming["default_speed"] ?? $existing["default_speed"] );
        $clean["acf_audio_field"] = sanitize_key( $incoming["acf_audio_field"] ?? $existing["acf_audio_field"] );
        $clean["auto_insert_player"] = empty( $incoming["auto_insert_player"] ) ? 0 : 1;
        $clean["include_title"] = empty( $incoming["include_title"] ) ? 0 : 1;
        $clean["max_characters"] = max( 500, absint( $incoming["max_characters"] ?? $existing["max_characters"] ) );
        $clean["last_status"] = is_array( $existing["last_status"] ?? null ) ? $existing["last_status"] : [];
        update_option( self::OPTION, $clean, false );
        wp_safe_redirect( add_query_arg( [ "page" => self::SETTINGS_SLUG, "hexa_tts_saved" => "1" ], admin_url( "options-general.php" ) ) );
        exit;
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

    public static function register_post_metabox() {
        foreach ( [ "post", "press-release" ] as $post_type ) {
            if ( post_type_exists( $post_type ) ) {
                add_meta_box( "hexa-tts-post-box", "SMP WP Text To Speech", [ __CLASS__, "render_post_metabox" ], $post_type, "normal", "high" );
            }
        }
    }


    public static function render_post_metabox( $post ) {
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
        <div class="hexa-tts-postbox hexa-tts-postbox-simple" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-acf-field="<?php echo esc_attr( $acf_field ); ?>">
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
                <button type="button" class="button button-primary button-hero hexa-tts-generate-post">Generate audio from article</button>
            </div>

            <div class="hexa-tts-upload-card">
                <div>
                    <h3>Audio file upload</h3>
                    <p>Upload or select your own MP3/M4A/WAV file. It saves directly into the ACF audio file field <code><?php echo esc_html( $acf_field ); ?></code>.</p>
                    <div class="hexa-tts-selected-audio"><?php if ( $audio_url ) : ?>Selected audio: <a href="<?php echo esc_url( $audio_url ); ?>" target="_blank" rel="noopener noreferrer">Open MP3</a><?php else : ?>No audio file selected yet.<?php endif; ?></div>
                </div>
                <button type="button" class="button button-secondary button-hero hexa-tts-select-audio">Upload/select audio file</button>
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
                <audio controls preload="none" src="<?php echo esc_url( $audio_url ); ?>"></audio>
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
        </div>
        <?php
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

    public static function ajax_generate_audio() {
        check_ajax_referer( self::NONCE_ACTION, "nonce" );
        $post_id = absint( $_POST["post_id"] ?? 0 );
        if ( ! $post_id || ! current_user_can( "edit_post", $post_id ) ) {
            wp_send_json_error( [ "message" => "You do not have permission to generate audio for this post." ], 403 );
        }
        $settings = self::get_settings();
        $log = [ "Request received by WordPress.", "Preparing article content." ];
        $content = trim( wp_unslash( (string) ( $_POST["content"] ?? "" ) ) );
        if ( "" === $content ) {
            $extract = self::extract_post_text( $post_id );
            if ( is_wp_error( $extract ) ) {
                update_post_meta( $post_id, "_hexa_tts_status", "Extraction failed" );
                wp_send_json_error( [ "message" => $extract->get_error_message(), "log" => $log ] );
            }
            $content = $extract["text"];
        }
        $prepend = trim( wp_unslash( (string) ( $_POST["prepend"] ?? "" ) ) );
        $append = trim( wp_unslash( (string) ( $_POST["append"] ?? "" ) ) );
        $content = trim( implode( "\n\n", array_filter( [ $prepend, $content, $append ] ) ) );
        $max = absint( $settings["max_characters"] );
        if ( strlen( $content ) > $max ) {
            if ( ! empty( $_POST["shorten"] ) ) {
                $content = substr( $content, 0, max( 100, $max - 20 ) ) . "...";
                $log[] = "Content exceeded max length and was shortened locally.";
            } else {
                update_post_meta( $post_id, "_hexa_tts_status", "Too long" );
                wp_send_json_error( [ "message" => "Content is " . strlen( $content ) . " characters, above the limit of " . $max . ".", "log" => $log ] );
            }
        }
        update_post_meta( $post_id, "_hexa_tts_status", "Waiting" );
        $log[] = "Sending server-side request to Publish Scale API.";
        $current_user = wp_get_current_user();
        $result = self::api_request( "/synthesize", [
            "content" => $content,
            "article_url" => get_permalink( $post_id ),
            "post_id" => $post_id,
            "wordpress_user_id" => get_current_user_id(),
            "wordpress_user_login" => $current_user ? $current_user->user_login : "",
            "provider" => sanitize_key( $_POST["provider"] ?? $settings["default_provider"] ),
            "profile" => sanitize_key( $_POST["profile"] ?? $settings["default_profile"] ),
            "runtime" => [ "voice" => sanitize_text_field( wp_unslash( $_POST["voice"] ?? $settings["default_voice"] ) ), "speed" => sanitize_text_field( wp_unslash( $_POST["speed"] ?? $settings["default_speed"] ) ) ],
        ], 240 );
        if ( is_wp_error( $result ) ) {
            update_post_meta( $post_id, "_hexa_tts_status", "Failed" );
            update_post_meta( $post_id, "_hexa_tts_error", $result->get_error_message() );
            $log[] = "Publish Scale API failed: " . $result->get_error_message();
            wp_send_json_error( [ "message" => $result->get_error_message(), "log" => $log ] );
        }
        $log[] = "Audio returned by API. Saving to WordPress Media Library.";
        $stored = self::store_api_audio( $post_id, $result, $content );
        if ( is_wp_error( $stored ) ) {
            update_post_meta( $post_id, "_hexa_tts_status", "Storage failed" );
            update_post_meta( $post_id, "_hexa_tts_error", $stored->get_error_message() );
            $log[] = "Storage failed: " . $stored->get_error_message();
            wp_send_json_error( [ "message" => $stored->get_error_message(), "log" => $log ] );
        }
        $log[] = "Attachment stored and ACF/meta field synced.";
        update_post_meta( $post_id, "_hexa_tts_status", "Ready" );
        wp_send_json_success( array_merge( $stored, [ "message" => "Audio generated, stored in Media Library, and synced to ACF/meta.", "request_id" => $result["request_id"] ?? "", "archive_url" => $result["archive_url"] ?? "", "cost_usd" => $result["cost_usd"] ?? null, "log" => $log ] ) );
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
        if ( empty( $settings["auto_insert_player"] ) || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }
        $player = self::player_html( get_the_ID() );
        return "" === $player ? $content : $player . $content;
    }

    public static function render_player_shortcode( $atts = [] ) {
        $atts = shortcode_atts( [ "post_id" => get_the_ID() ], $atts, "hexa_tts_player" );
        return self::player_html( absint( $atts["post_id"] ) );
    }

    private static function player_html( $post_id ) {
        $url = get_post_meta( $post_id, "_hexa_tts_audio_url", true );
        if ( ! $url ) {
            return "";
        }
        $provider = get_post_meta( $post_id, "_hexa_tts_provider", true );
        $generated = get_post_meta( $post_id, "_hexa_tts_generated_at", true );
        ob_start();
        ?>
        <aside class="hexa-tts-player" aria-label="Article audio narration"><div class="hexa-tts-player__label">Listen to this article</div><audio controls preload="none" src="<?php echo esc_url( $url ); ?>"></audio><div class="hexa-tts-player__meta"><?php if ( $provider ) : ?><span><?php echo esc_html( $provider ); ?></span><?php endif; ?><?php if ( $generated ) : ?><span><?php echo esc_html( mysql2date( "M j, Y g:i A", $generated ) ); ?></span><?php endif; ?></div></aside>
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
