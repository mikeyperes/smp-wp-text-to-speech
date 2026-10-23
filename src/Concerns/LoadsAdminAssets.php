<?php

namespace smp_text_to_speech\Concerns;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

trait LoadsAdminAssets {
    public static function enqueue_admin_assets( $hook ) {
        $screen = function_exists( "get_current_screen" ) ? get_current_screen() : null;
        $is_settings = "settings_page_" . self::SETTINGS_SLUG === $hook;
        $is_post = $screen && "post" === $screen->base;
        if ( ! $is_settings && ! $is_post ) {
            return;
        }
        if ( $is_post ) {
            wp_enqueue_media();
            \Hexa\PluginCore\Fields\Form::enqueue();
        }
        wp_enqueue_style( "hexa-tts-admin", plugin_dir_url( \smp_text_to_speech\PluginMetadata::main_file() ) . "assets/admin.css", [], self::VERSION );
        wp_enqueue_style( "smp-tts-frontend", plugin_dir_url( \smp_text_to_speech\PluginMetadata::main_file() ) . "assets/frontend-1.3.24.css", [ "hexa-tts-admin" ], self::VERSION );
        wp_enqueue_script( "smp-tts-frontend-player", plugin_dir_url( \smp_text_to_speech\PluginMetadata::main_file() ) . "assets/frontend.js", [], self::VERSION, true );
        wp_enqueue_script( "hexa-tts-admin", plugin_dir_url( \smp_text_to_speech\PluginMetadata::main_file() ) . "assets/admin.js", [ "jquery" ], self::VERSION, true );
        wp_localize_script( "hexa-tts-admin", "hexaTts", [ "ajaxUrl" => admin_url( "admin-ajax.php" ), "nonce" => wp_create_nonce( self::NONCE_ACTION ) ] );
        wp_add_inline_style( "hexa-tts-admin", self::admin_live_display_css() );
        wp_add_inline_script( "hexa-tts-admin", self::admin_inline_script(), "after" );
        wp_add_inline_script( "hexa-tts-admin", self::admin_live_display_script(), "after" );
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
        data: { action: "smp_wp_text_to_speech_generation_status", nonce: hexaTts.nonce, post_id: postId, client_request_id: requestId }
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

    payload.action = "smp_wp_text_to_speech_generate_audio";
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
          action: "smp_wp_text_to_speech_save_manual_audio",
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
.hexa-tts-feature-control-card{border:1px solid #dcdcde;border-radius:14px;background:#fff;padding:20px;margin:18px 0}.hexa-tts-feature-control-head{display:flex;align-items:flex-start;justify-content:space-between;gap:18px;border-bottom:1px solid #dcdcde;padding-bottom:16px;margin-bottom:18px}.hexa-tts-feature-control-head h2{margin:0 0 6px}.hexa-tts-feature-control-head p{margin:0;color:#50575e}.hexa-tts-feature-control-grid{display:grid;gap:22px}.hexa-tts-switch{display:flex;align-items:center;gap:10px;cursor:pointer}.hexa-tts-switch input{position:absolute;opacity:0}.hexa-tts-switch span{width:48px;height:26px;border-radius:999px;background:#c3c4c7;position:relative}.hexa-tts-switch span:before{content:"";position:absolute;width:20px;height:20px;left:3px;top:3px;border-radius:50%;background:#fff;transition:.15s}.hexa-tts-switch input:checked+span{background:#3657e3}.hexa-tts-switch input:checked+span:before{transform:translateX(22px)}.hexa-tts-choice-grid{display:grid;gap:14px}.hexa-tts-placement-map.hexa-tts-choice-grid{grid-template-columns:1fr}.hexa-tts-choice-card{position:relative;display:flex;gap:12px;align-items:flex-start;border:2px solid #dcdcde;border-radius:12px;background:#fff;padding:14px;cursor:pointer}.hexa-tts-choice-card input{margin-top:3px}.hexa-tts-choice-card.is-selected{border-color:#3657e3;box-shadow:0 0 0 2px rgba(54,87,227,.14)}.hexa-tts-choice-body strong,.hexa-tts-choice-body small{display:block}.hexa-tts-choice-body small{color:#646970;margin-top:5px}.hexa-tts-selected-pill,.hexa-tts-template-live-choice em{display:none;margin-left:auto;border-radius:999px;background:#eef2ff;color:#3657e3;font-style:normal;font-size:12px;font-weight:800;padding:3px 8px}.hexa-tts-choice-card.is-selected .hexa-tts-selected-pill,.hexa-tts-template-live-row.is-selected .hexa-tts-template-live-choice em{display:inline-flex}.hexa-tts-template-live-list{display:grid;grid-template-columns:1fr;gap:16px;margin-top:14px}.hexa-tts-template-live-row{border:2px solid #dcdcde;border-radius:14px;background:#fff;padding:16px}.hexa-tts-template-live-row.is-selected{border-color:#3657e3;box-shadow:0 0 0 2px rgba(54,87,227,.14)}.hexa-tts-template-live-choice{display:flex;align-items:flex-start;gap:12px;margin-bottom:12px;cursor:pointer}.hexa-tts-template-live-choice input{margin-top:4px}.hexa-tts-template-live-choice strong,.hexa-tts-template-live-choice small{display:block}.hexa-tts-template-live-choice small{color:#646970;margin-top:4px}.hexa-tts-template-live-preview{padding:12px;border-radius:12px;background:#f6f7f7}.hexa-tts-template-live-preview .hexa-tts-player{margin:0}.hexa-tts-live-save-state{min-height:22px;margin-top:12px;font-weight:700}.hexa-tts-live-save-state.is-loading{color:#646970}.hexa-tts-live-save-state.is-success{color:#008a20}.hexa-tts-live-save-state.is-error{color:#b32d2e}.hexa-tts-feature-proof{display:grid;gap:8px;margin-top:12px}.hexa-tts-feature-proof>div{display:flex;gap:10px;align-items:center;border:1px solid #dcdcde;border-radius:8px;background:#f6f7f7;padding:9px 10px}.hexa-tts-feature-proof span{font-weight:800;color:#646970}@media(max-width:1180px){.hexa-tts-placement-map.hexa-tts-choice-grid{grid-template-columns:1fr}}@media(max-width:760px){.hexa-tts-feature-control-head,.hexa-tts-placement-map.hexa-tts-choice-grid{display:grid;grid-template-columns:1fr}}
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
      data.push({ name: "action", value: "smp_wp_text_to_speech_preview_display" });
      data.push({ name: "nonce", value: hexaTts.nonce });
      state.removeClass("is-error is-success").addClass("is-loading").text("Refreshing preview...");
      $.ajax({ url: hexaTts.ajaxUrl, method: "POST", data: data })
        .done(function (response) {
          if (response && response.success) {
            if (response.data.preview_html) {
              form.find(".hexa-tts-live-preview-target").html(response.data.preview_html);
              document.dispatchEvent(new CustomEvent("hexa-tts-preview-updated"));
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
}
