(function () {
  'use strict';

  var ROOT_SELECTOR = '.hexa-tts-player[data-hexa-tts-enhanced="1"], .hexa-tts-player[data-hexa-tts-custom="1"]';
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
      var active = button.getAttribute('data-hexa-tts-speed') === String(speed);
      button.classList.toggle('is-active', active);
      button.setAttribute('aria-pressed', active ? 'true' : 'false');
    });
  }

  function formatTime(seconds) {
    if (!isFinite(seconds) || seconds < 0) {
      return '0:00';
    }
    var minutes = Math.floor(seconds / 60);
    var remainder = Math.floor(seconds % 60);
    return minutes + ':' + (remainder < 10 ? '0' : '') + remainder;
  }

  function setCustomPlayState(root, playing) {
    var button = root.querySelector('[data-hexa-tts-play]');
    if (!button) {
      return;
    }
    button.classList.toggle('is-playing', playing);
    button.setAttribute('aria-label', playing ? 'Pause article narration' : 'Play article narration');
  }

  function syncCustomProgress(root, audio) {
    var timeline = root.querySelector('[data-hexa-tts-timeline]');
    if (!timeline) {
      return;
    }
    var current = formatTime(audio.currentTime);
    var duration = formatTime(audio.duration);
    var progress = audio.duration ? (audio.currentTime / audio.duration) * 100 : 0;
    timeline.value = String(progress);
    timeline.setAttribute('aria-valuetext', current + ' of ' + duration);

    var currentLabel = root.querySelector('[data-hexa-tts-current]');
    var durationLabel = root.querySelector('[data-hexa-tts-duration]');
    var metaDuration = root.querySelector('[data-hexa-tts-duration-label]');
    if (currentLabel) {
      currentLabel.textContent = current;
    }
    if (durationLabel) {
      durationLabel.textContent = duration;
    }
    if (metaDuration) {
      metaDuration.textContent = audio.duration ? duration : 'Duration unavailable';
    }
  }

  function syncCustomVolume(root, audio) {
    var volume = root.querySelector('[data-hexa-tts-volume]');
    var mute = root.querySelector('[data-hexa-tts-mute]');
    var effectiveVolume = audio.muted ? 0 : audio.volume;
    var percentage = Math.round(effectiveVolume * 100);

    if (volume) {
      volume.value = String(effectiveVolume);
      volume.setAttribute('aria-valuetext', percentage + '%');
    }
    if (mute) {
      mute.setAttribute('aria-pressed', audio.muted ? 'true' : 'false');
      mute.setAttribute('aria-label', audio.muted ? 'Unmute audio' : 'Mute audio');
      mute.textContent = audio.muted ? 'Unmute' : 'Mute';
    }
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

    var enhanced = root.getAttribute('data-hexa-tts-enhanced') === '1';
    var custom = root.getAttribute('data-hexa-tts-custom') === '1';
    var keyBase = STORAGE_PREFIX + (root.getAttribute('data-hexa-tts-key') || audio.currentSrc || audio.src || 'audio');
    var positionKey = keyBase + '_position';
    var speedKey = keyBase + '_speed';
    var storedSpeed = enhanced ? parseFloat(storageGet(speedKey) || '1') : 1;

    if (storedSpeed && isFinite(storedSpeed) && storedSpeed > 0) {
      audio.playbackRate = storedSpeed;
      setActiveSpeed(root, String(storedSpeed).replace(/\.00$/, ''));
    }

    audio.addEventListener('loadedmetadata', function () {
      if (enhanced) {
        var storedPosition = parseFloat(storageGet(positionKey) || '0');
        if (storedPosition > 5 && (!audio.duration || storedPosition < audio.duration - 5)) {
          audio.currentTime = storedPosition;
          setStatus(root, 'Resumed where you left off');
        }
      }
      syncCustomProgress(root, audio);
    });

    audio.addEventListener('timeupdate', function () {
      if (enhanced && audio.duration && audio.currentTime > 0 && !audio.ended) {
        storageSet(positionKey, String(Math.floor(audio.currentTime)));
      }
      syncCustomProgress(root, audio);
    });

    audio.addEventListener('ended', function () {
      if (enhanced) {
        storageSet(positionKey, '0');
      }
      setCustomPlayState(root, false);
      syncCustomProgress(root, audio);
      setStatus(root, 'Audio finished');
    });

    if (custom) {
      audio.addEventListener('play', function () {
        setCustomPlayState(root, true);
        setStatus(root, 'Audio playing');
      });
      audio.addEventListener('pause', function () {
        setCustomPlayState(root, false);
        if (!audio.ended) {
          setStatus(root, 'Audio paused');
        }
      });
      audio.addEventListener('durationchange', function () {
        syncCustomProgress(root, audio);
      });
      audio.addEventListener('volumechange', function () {
        syncCustomVolume(root, audio);
      });
      audio.addEventListener('error', function () {
        var playButton = root.querySelector('[data-hexa-tts-play]');
        if (playButton) {
          playButton.disabled = true;
        }
        setStatus(root, 'Audio unavailable');
      });

      var timeline = root.querySelector('[data-hexa-tts-timeline]');
      if (timeline) {
        timeline.addEventListener('input', function () {
          if (audio.duration) {
            audio.currentTime = (parseFloat(timeline.value) / 100) * audio.duration;
          }
        });
      }

      var volume = root.querySelector('[data-hexa-tts-volume]');
      if (volume) {
        volume.addEventListener('input', function () {
          var nextVolume = Math.max(0, Math.min(1, parseFloat(volume.value) || 0));
          audio.muted = false;
          audio.volume = nextVolume;
          setStatus(root, 'Volume ' + Math.round(nextVolume * 100) + '%');
        });
      }

      syncCustomProgress(root, audio);
      syncCustomVolume(root, audio);
    }

    root.addEventListener('click', function (event) {
      var playButton = event.target.closest('[data-hexa-tts-play]');
      if (playButton && root.contains(playButton)) {
        if (audio.paused) {
          var playResult = audio.play();
          if (playResult && typeof playResult.catch === 'function') {
            playResult.catch(function () {
              setStatus(root, 'Audio could not start');
            });
          }
        } else {
          audio.pause();
        }
        return;
      }

      var muteButton = event.target.closest('[data-hexa-tts-mute]');
      if (muteButton && root.contains(muteButton)) {
        audio.muted = !audio.muted;
        syncCustomVolume(root, audio);
        setStatus(root, audio.muted ? 'Audio muted' : 'Audio unmuted');
        return;
      }

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
