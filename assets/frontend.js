(function () {
  'use strict';

  var ROOT_SELECTOR = '.hexa-tts-player[data-hexa-tts-enhanced="1"]';
  var STORAGE_PREFIX = 'smp_tts_player_';

  function storageGet(key) {
    try {
      return window.localStorage ? window.localStorage.getItem(key) : null;
    } catch (error) {
      return null;
    }
  }

  function storageSet(key, value) {
    try {
      if (window.localStorage) {
        window.localStorage.setItem(key, value);
      }
    } catch (error) {}
  }

  function setStatus(root, text) {
    var status = root.querySelector('.hexa-tts-player__status');
    if (!status) {
      return;
    }
    status.textContent = text || '';
    if (text) {
      window.setTimeout(function () {
        if (status.textContent === text) {
          status.textContent = '';
        }
      }, 2400);
    }
  }

  function setActiveSpeed(root, speed) {
    var buttons = root.querySelectorAll('[data-hexa-tts-speed]');
    Array.prototype.forEach.call(buttons, function (button) {
      button.classList.toggle('is-active', button.getAttribute('data-hexa-tts-speed') === String(speed));
    });
  }

  function initPlayer(root) {
    if (!root || root.getAttribute('data-hexa-tts-ready') === '1') {
      return;
    }
    root.setAttribute('data-hexa-tts-ready', '1');

    var audio = root.querySelector('audio');
    if (!audio) {
      return;
    }

    var keyBase = STORAGE_PREFIX + (root.getAttribute('data-hexa-tts-key') || audio.currentSrc || audio.src || 'audio');
    var positionKey = keyBase + '_position';
    var speedKey = keyBase + '_speed';
    var storedSpeed = parseFloat(storageGet(speedKey) || '1');

    if (storedSpeed && isFinite(storedSpeed) && storedSpeed > 0) {
      audio.playbackRate = storedSpeed;
      setActiveSpeed(root, String(storedSpeed).replace(/\.00$/, ''));
    }

    audio.addEventListener('loadedmetadata', function () {
      var storedPosition = parseFloat(storageGet(positionKey) || '0');
      if (storedPosition > 5 && (!audio.duration || storedPosition < audio.duration - 5)) {
        audio.currentTime = storedPosition;
        setStatus(root, 'Resumed where you left off');
      }
    });

    audio.addEventListener('timeupdate', function () {
      if (!audio.duration || audio.currentTime <= 0 || audio.ended) {
        return;
      }
      storageSet(positionKey, String(Math.floor(audio.currentTime)));
    });

    audio.addEventListener('ended', function () {
      storageSet(positionKey, '0');
    });

    root.addEventListener('click', function (event) {
      var speedButton = event.target.closest('[data-hexa-tts-speed]');
      if (speedButton && root.contains(speedButton)) {
        var speed = parseFloat(speedButton.getAttribute('data-hexa-tts-speed') || '1');
        if (speed && isFinite(speed)) {
          audio.playbackRate = speed;
          storageSet(speedKey, String(speed));
          setActiveSpeed(root, speedButton.getAttribute('data-hexa-tts-speed'));
          setStatus(root, speed + 'x playback');
        }
        return;
      }

      var skipButton = event.target.closest('[data-hexa-tts-skip]');
      if (skipButton && root.contains(skipButton)) {
        var offset = parseFloat(skipButton.getAttribute('data-hexa-tts-skip') || '0');
        if (isFinite(offset)) {
          var nextTime = Math.max(0, audio.currentTime + offset);
          if (audio.duration) {
            nextTime = Math.min(audio.duration, nextTime);
          }
          audio.currentTime = nextTime;
          setStatus(root, offset < 0 ? 'Back 10 seconds' : 'Forward 30 seconds');
        }
        return;
      }

      var transcriptButton = event.target.closest('[data-hexa-tts-transcript-toggle]');
      if (transcriptButton && root.contains(transcriptButton)) {
        var transcript = root.querySelector('[data-hexa-tts-transcript]');
        if (!transcript) {
          return;
        }
        var hidden = transcript.hasAttribute('hidden');
        if (hidden) {
          transcript.removeAttribute('hidden');
        } else {
          transcript.setAttribute('hidden', 'hidden');
        }
        transcriptButton.setAttribute('aria-expanded', hidden ? 'true' : 'false');
        transcriptButton.textContent = hidden ? 'Hide transcript' : 'Transcript';
      }
    });
  }

  function initAll() {
    Array.prototype.forEach.call(document.querySelectorAll(ROOT_SELECTOR), initPlayer);
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initAll);
  } else {
    initAll();
  }

  document.addEventListener('hexa-tts-preview-updated', initAll);
})();
