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
