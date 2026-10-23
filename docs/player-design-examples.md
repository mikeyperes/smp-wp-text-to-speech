# Player design examples

## Preview routes and skin choice

Open **Settings > SMP WP Text To Speech > Display** on an installed site:
`/wp-admin/options-general.php?page=smp-wp-text-to-speech&tab=display`.
The template picker previews all named designs using the player renderer.

- `narration_card`: plugin-owned dark narration card with custom controls.
- `unstyled_controls`: the same custom control surface without plugin design;
  use it for an Elementor-owned skin.
- `unstyled`: browser-native audio controls, not the custom timeline layout.
- Other named skins and their descriptions are listed in
  [template_options](../smp-wp-text-to-speech.php); their visual rules are in
  [the frontend stylesheet](../assets/frontend-1.3.24.css).

The common [Elementor plugin-element contract](https://github.com/mikeyperes/hws-agent-operations/blob/main/skills/elementor-build-guidelines/SKILL.md#plugin-rendered-elements)
owns widget placement and styling location. Example CSS below uses Elementor's
`selector` placeholder; values are editable examples, not a second design guide.
Do not paste the example HTML into a live HTML widget. It is a representative
fixture of plugin output, not a working standalone audio demo.

## Listen player

Shortcode: `[smp_tts_player template="unstyled_controls" enhanced_controls="1" preload="metadata"]`.
`[hexa_tts_player]` is an alias. Omit `post_id` for the current article.

Source: [render_player_shortcode and player_markup](../smp-wp-text-to-speech.php).
The real output adds per-post audio, runtime keys and a transcript when one is
available. Without saved audio the shortcode emits nothing.

Representative HTML (no transcript in this fixture):

```html
<aside class="hexa-tts-player hexa-tts-player--unstyled_controls hexa-tts-player--size-default hexa-tts-player--enhanced"
       data-hexa-tts-enhanced="1" data-hexa-tts-custom="1"
       data-hexa-tts-skin="unstyled" data-hexa-tts-key="post-123"
       aria-label="Article audio narration">
  <div class="hexa-tts-narration__eyebrow">Listen</div>
  <h2 class="hexa-tts-player__label hexa-tts-narration__title">Listen to this article</h2>
  <div class="hexa-tts-narration__meta">
    <span>AI narration</span><span aria-hidden="true">/</span>
    <span data-hexa-tts-duration-label>Loading duration</span>
  </div>
  <audio hidden preload="metadata" src="/path/to/article-audio.mp3"></audio>
  <div class="hexa-tts-narration__main">
    <button type="button" class="hexa-tts-narration__play" data-hexa-tts-play
            aria-label="Play article narration"><span aria-hidden="true"></span></button>
    <div class="hexa-tts-narration__track">
      <input type="range" class="hexa-tts-narration__timeline" data-hexa-tts-timeline
             min="0" max="100" value="0" step="0.1" aria-label="Audio progress"
             aria-valuetext="0:00 of 0:00">
      <div class="hexa-tts-narration__time">
        <span data-hexa-tts-current>0:00</span><span data-hexa-tts-duration>0:00</span>
      </div>
    </div>
  </div>
  <div class="hexa-tts-narration__volume" aria-label="Volume controls">
    <button type="button" class="hexa-tts-player__button hexa-tts-narration__mute"
            data-hexa-tts-mute aria-label="Mute audio" aria-pressed="false">Mute</button>
    <input type="range" class="hexa-tts-narration__volume-range" data-hexa-tts-volume
           min="0" max="1" value="1" step="0.05" aria-label="Volume" aria-valuetext="100%">
  </div>
  <div class="hexa-tts-player__controls" aria-label="Audio playback controls">
    <div class="hexa-tts-player__skip">
      <button type="button" class="hexa-tts-player__button" data-hexa-tts-skip="-10">Back 10s</button>
      <button type="button" class="hexa-tts-player__button" data-hexa-tts-skip="30">Forward 30s</button>
    </div>
    <div class="hexa-tts-player__speed" aria-label="Playback speed">
      <button type="button" class="hexa-tts-player__button" data-hexa-tts-speed="0.75" aria-pressed="false">0.75x</button>
      <button type="button" class="hexa-tts-player__button is-active" data-hexa-tts-speed="1" aria-pressed="true">1x</button>
      <button type="button" class="hexa-tts-player__button" data-hexa-tts-speed="1.25" aria-pressed="false">1.25x</button>
      <button type="button" class="hexa-tts-player__button" data-hexa-tts-speed="1.5" aria-pressed="false">1.5x</button>
      <button type="button" class="hexa-tts-player__button" data-hexa-tts-speed="2" aria-pressed="false">2x</button>
    </div>
  </div>
  <span class="hexa-tts-player__status hexa-tts-narration__status" aria-live="polite">Audio ready</span>
</aside>
```

Example CSS for the custom surface:

```css
selector .hexa-tts-player { padding: 20px; border: 1px solid #ddd; color: #222; }
selector .hexa-tts-narration__eyebrow { font-size: 11px; font-weight: 700; }
selector .hexa-tts-narration__title { margin: 6px 0 4px; font-size: 19px; font-weight: 700; line-height: 1.25; }
selector .hexa-tts-narration__meta { display: flex; flex-wrap: wrap; gap: 6px; font-size: 12px; }
selector .hexa-tts-narration__main { display: flex; align-items: center; gap: 12px; margin-top: 12px; }
selector .hexa-tts-narration__play {
  flex: 0 0 46px; width: 46px; height: 46px; padding: 0;
  display: flex; align-items: center; justify-content: center;
  border: 0; border-radius: 50%; background: #b42318; color: #fff;
}
selector .hexa-tts-narration__play > span { width: 0; height: 0; border-top: 6px solid transparent; border-bottom: 6px solid transparent; border-left: 9px solid currentColor; }
selector .hexa-tts-narration__play.is-playing > span { width: 8px; height: 12px; border: 0; border-left: 3px solid currentColor; border-right: 3px solid currentColor; }
selector .hexa-tts-narration__track { flex: 1; min-width: 0; }
selector .hexa-tts-narration__timeline { width: 100%; accent-color: #b42318; }
selector .hexa-tts-narration__time { display: flex; justify-content: space-between; font-size: 12px; }
selector .hexa-tts-narration__volume { display: flex; align-items: center; gap: 8px; margin-top: 12px; }
selector .hexa-tts-narration__volume-range { flex: 1; min-width: 0; accent-color: #b42318; }
selector .hexa-tts-player__controls, selector .hexa-tts-player__skip, selector .hexa-tts-player__speed { display: flex; flex-wrap: wrap; gap: 6px; }
selector .hexa-tts-player__controls { margin-top: 12px; }
selector .hexa-tts-player__button { padding: 7px 9px; border: 1px solid #ccc; background: #fff; color: #333; font-size: 11px; line-height: 1.3; }
selector .hexa-tts-player__button.is-active { background: #b42318; border-color: #b42318; color: #fff; }
selector .hexa-tts-player :is(button, input):focus-visible { outline: 2px solid #b42318; outline-offset: 2px; }
selector .hexa-tts-player__transcript { margin-top: 12px; font-size: 14px; line-height: 1.55; }
selector .hexa-tts-player__transcript[hidden] { display: none; }
selector .hexa-tts-player__status { display: block; margin-top: 8px; font-size: 12px; }
@media (max-width: 1024px) {
  selector .hexa-tts-player__button { min-height: 44px; min-width: 44px; }
  selector .hexa-tts-narration__timeline, selector .hexa-tts-narration__volume-range { min-height: 44px; }
}
```

## Selector map

- **Frame/background:** `.hexa-tts-player`.
- **Label/title/metadata:** `.hexa-tts-narration__eyebrow`, `__title`, `__meta`.
- **Play/pause:** `.hexa-tts-narration__play` and its `.is-playing` state.
- **Timeline/time/volume:** `.hexa-tts-narration__timeline`, `__time`, `__volume-range`.
- **Skip/speed buttons:** `.hexa-tts-player__button`; `.is-active` marks selected speed.
- **Transcript:** `.hexa-tts-player__transcript` and `[hidden]`.
- **Status:** `.hexa-tts-player__status`, an `aria-live` region.

Abbreviated names use the preceding full class family. Preserve all
`data-hexa-tts-*`, ARIA, `hidden` and audio elements. Playback, seek, mute,
speed, transcript and error handling remain owned by
[assets/frontend.js](../assets/frontend.js). CSS must not replace that behavior.
Use manual placement when the Elementor template inserts the shortcode.

The [summary, Contents and FAQ examples](https://github.com/mikeyperes/smp-publication-integration/blob/main/docs/article-design-examples.md)
live in SMP Publication Integration's repository.
