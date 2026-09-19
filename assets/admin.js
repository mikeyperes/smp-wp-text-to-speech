(function ($) {
  'use strict';

  function setResult($box, type, message, details) {
    $box.removeClass('is-success is-error is-loading');
    if (type) {
      $box.addClass('is-' + type);
    }
    $box.html(
      '<strong>' + escapeHtml(message || '') + '</strong>' +
        (details ? '<span>' + escapeHtml(details) + '</span>' : '')
    );
  }

  function escapeHtml(value) {
    return String(value || '').replace(/[&<>"']/g, function (char) {
      return {
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
      }[char];
    });
  }

  function providerFields($card) {
    var fields = {};
    $card.find('[data-field]').each(function () {
      var $input = $(this);
      fields[$input.data('field')] = $input.val();
    });
    return fields;
  }

  function postBoxPayload($box) {
    return {
      post_id: $box.data('post-id'),
      profile: $box.find('.hexa-tts-post-profile').val(),
      provider: $box.find('.hexa-tts-post-provider').val(),
      voice: $box.find('.hexa-tts-post-voice').val(),
      model: $box.find('.hexa-tts-post-model').val(),
      language: $box.find('.hexa-tts-post-language').val(),
      speed: $box.find('.hexa-tts-post-speed').val()
    };
  }

  function diagnosticError(xhr, fallback) {
    var payload = xhr && xhr.responseJSON;
    return payload && payload.data && payload.data.message
      ? payload.data.message
      : (xhr && xhr.statusText ? xhr.statusText : fallback);
  }

  function setDiagnosticButton($button, busy, label) {
    $button.prop('disabled', busy);
    if (busy) {
      $button.data('original-label', $button.text()).attr('aria-busy', 'true').text(label);
      return;
    }
    $button.removeAttr('aria-busy').text($button.data('original-label') || $button.text());
  }

  function renderCredit($scope, data) {
    var status = data && data.status ? data.status : 'unknown';
    var available = status === 'available';
    var unavailable = status === 'unavailable';
    var $result = $scope.find('.hexa-tts-credit-result');
    var $state = $scope.closest('.hexa-tts-postbox').find('.hexa-tts-credit-state');
    var label = available ? 'Credits available' : (unavailable ? 'No credits available' : 'Could not determine');

    $result.removeClass('is-success is-error is-warning').addClass(available ? 'is-success' : (unavailable ? 'is-error' : 'is-warning'));
    $result.html('<strong>' + escapeHtml(label) + '</strong><span>' + escapeHtml(data && data.message ? data.message : '') + '</span>');
    $state.removeClass('is-ready is-missing is-unknown').addClass(available ? 'is-ready' : (unavailable ? 'is-missing' : 'is-unknown')).text(label);
  }

  function renderHealth($scope, data) {
    var checks = data && Array.isArray(data.checks) ? data.checks : [];
    var $list = $scope.find('.hexa-tts-health-checklist');
    $list.empty().prop('hidden', false);
    checks.forEach(function (check) {
      var status = check && check.status ? check.status : 'warning';
      var symbol = status === 'pass' ? '&#10003;' : (status === 'fail' ? '&#10005;' : '!');
      $list.append(
        '<li class="is-' + escapeHtml(status) + '">' +
          '<span class="hexa-tts-check-icon" aria-hidden="true">' + symbol + '</span>' +
          '<div><strong>' + escapeHtml(check.label || 'Check') + '</strong><p>' + escapeHtml(check.message || '') + '</p></div>' +
        '</li>'
      );
    });
    if (data && data.credits) {
      renderCredit($scope, data.credits);
    }
  }

  $(document).on('click', '.hexa-tts-check-credits', function () {
    var $button = $(this);
    var $scope = $button.closest('.hexa-tts-diagnostics');
    setDiagnosticButton($button, true, 'Checking credits...');
    $.ajax({
      url: hexaTts.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: { action: 'hexa_tts_check_credits', nonce: hexaTts.nonce, post_id: $scope.data('post-id') || 0 }
    }).done(function (response) {
      if (response && response.success) {
        renderCredit($scope, response.data || {});
        return;
      }
      renderCredit($scope, { status: 'unknown', message: response && response.data ? response.data.message : 'Credit check failed.' });
    }).fail(function (xhr) {
      renderCredit($scope, { status: 'unknown', message: diagnosticError(xhr, 'Credit check failed.') });
    }).always(function () {
      setDiagnosticButton($button, false);
    });
  });

  $(document).on('click', '.hexa-tts-check-health', function () {
    var $button = $(this);
    var $scope = $button.closest('.hexa-tts-diagnostics');
    setDiagnosticButton($button, true, 'Running checks...');
    $.ajax({
      url: hexaTts.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: { action: 'hexa_tts_check_health', nonce: hexaTts.nonce, post_id: $scope.data('post-id') || 0 }
    }).done(function (response) {
      if (response && response.success) {
        renderHealth($scope, response.data || {});
        return;
      }
      renderCredit($scope, { status: 'unknown', message: response && response.data ? response.data.message : 'Health check failed.' });
    }).fail(function (xhr) {
      renderCredit($scope, { status: 'unknown', message: diagnosticError(xhr, 'Health check failed.') });
    }).always(function () {
      setDiagnosticButton($button, false);
    });
  });

  $(document).on('click', '.hexa-tts-test-provider', function () {
    var $button = $(this);
    var provider = $button.data('provider');
    var $card = $('[data-provider-card="' + provider + '"]');
    var $result = $('[data-provider-result="' + provider + '"]');

    $button.prop('disabled', true);
    $result.addClass('is-loading').text('Testing credentials...');

    $.ajax({
      url: hexaTts.ajaxUrl,
      method: 'POST',
      data: {
        action: 'hexa_tts_validate_provider',
        nonce: hexaTts.nonce,
        provider: provider,
        fields: providerFields($card)
      }
    })
      .done(function (response) {
        if (response && response.success) {
          setResult($result, 'success', response.data.message, response.data.details);
          return;
        }
        setResult($result, 'error', 'Validation failed', response && response.data ? response.data.message : 'Unknown error.');
      })
      .fail(function (xhr) {
        setResult($result, 'error', 'AJAX request failed', xhr.responseText || xhr.statusText);
      })
      .always(function () {
        $button.prop('disabled', false);
      });
  });

  $(document).on('click', '.hexa-tts-fetch-source-api-key', function () {
    var $button = $(this);
    var $card = $button.closest('[data-provider-card="central"]');
    var $result = $card.find('[data-provider-result="central"]');
    var $keyField = $card.find('.hexa-tts-site-api-key');
    var originalLabel = $button.text();

    $button.prop('disabled', true).attr('aria-busy', 'true').text('Verifying this domain...');
    setResult($result, 'loading', 'Proving control of this WordPress domain...', 'The source will return a key only if this exact domain already has an active assignment.');

    $.ajax({
      url: hexaTts.ajaxUrl,
      method: 'POST',
      dataType: 'json',
      data: {
        action: 'hexa_tts_fetch_source_api_key',
        nonce: hexaTts.nonce
      }
    })
      .done(function (response) {
        if (response && response.success) {
          $keyField.val('').attr('placeholder', 'Saved: ' + response.data.masked_key).addClass('hexa-tts-saved-secret');
          setResult($result, 'success', response.data.message, response.data.details);
          return;
        }
        setResult($result, 'error', 'API key was not fetched', response && response.data ? response.data.message : 'The source rejected the request.');
      })
      .fail(function (xhr) {
        var payload = xhr.responseJSON;
        var message = payload && payload.data && payload.data.message
          ? payload.data.message
          : (xhr.statusText || 'The source request failed.');
        setResult($result, 'error', 'API key was not fetched', message);
      })
      .always(function () {
        $button.prop('disabled', false).removeAttr('aria-busy').text(originalLabel);
      });
  });

  $(document).on('click', '.hexa-tts-extract-post', function () {
    var $box = $(this).closest('.hexa-tts-postbox');
    var $feedback = $box.find('.hexa-tts-post-feedback');
    var $preview = $box.find('.hexa-tts-extracted-preview');

    $feedback.removeClass('is-error is-success').addClass('is-loading').text('Extracting post content...');

    $.ajax({
      url: hexaTts.ajaxUrl,
      method: 'POST',
      data: {
        action: 'hexa_tts_extract_post_content',
        nonce: hexaTts.nonce,
        post_id: $box.data('post-id')
      }
    })
      .done(function (response) {
        $feedback.removeClass('is-loading');
        if (response && response.success) {
          $feedback.addClass('is-success').text(
            'Extracted ' + response.data.characters + ' characters / ' + response.data.words + ' words.'
          );
          $preview.val(response.data.preview);
          return;
        }
        $feedback.addClass('is-error').text(response && response.data ? response.data.message : 'Extraction failed.');
      })
      .fail(function (xhr) {
        $feedback.removeClass('is-loading').addClass('is-error').text(xhr.responseText || xhr.statusText);
      });
  });

})(jQuery);

(function ($) {
  'use strict';
  function initDisplayControls(scope) {
    var $scope = scope ? $(scope) : $(document);
    $scope.find('.hexa-tts-template-grid').each(function () {
      var $grid = $(this);
      $grid.find('.hexa-tts-template-card').removeClass('is-selected');
      $grid.find('input:checked').closest('.hexa-tts-template-card').addClass('is-selected');
    });
  }
  $(document).on('click change', '.hexa-tts-template-card, .hexa-tts-template-card input', function () {
    var $card = $(this).closest('.hexa-tts-template-card');
    var $input = $card.find('input[type="radio"]');
    if ($input.length) {
      $input.prop('checked', true).triggerHandler('change');
    }
    $card.closest('.hexa-tts-template-grid').find('.hexa-tts-template-card').removeClass('is-selected');
    $card.addClass('is-selected');
  });
  $(function () { initDisplayControls(document); });
  document.addEventListener('hexa-core-host-tab-loaded', function (event) {
    if (event && event.detail && event.detail.panel) {
      initDisplayControls(event.detail.panel);
    }
  });
})(jQuery);
