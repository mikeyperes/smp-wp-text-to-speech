# SMP WP Text To Speech

> Feature base for HWS Skills. Read before building on this plugin; use or
> extend these features instead of rebuilding them.

**Purpose:** turns an article's text into audio and plays it on the page as "Listen to this article". **Admin:** WP Admin → Settings → SMP WP Text To Speech (one-click generation in the post editor). **Depends on:** Hexa WP Core (bundled); Publish Scale text-to-speech API; ACF optional.

## Design examples and styling

- **Examples:** [Player HTML, Elementor CSS and selector map](docs/player-design-examples.md).
- **Visual previews:** Settings > SMP WP Text To Speech > Display, `/wp-admin/options-general.php?page=smp-wp-text-to-speech&tab=display`. The template picker previews every named skin using the player renderer.
- **Related features:** [Summary, Contents and FAQ examples](https://github.com/mikeyperes/smp-publication-integration/blob/main/docs/article-design-examples.md) belong to SMP Publication Integration.

## Features

### Listen to this article (feature switch)
- **Does:** turns the whole article audio feature on or off for the site.
- **Switch:** `article_audio_enabled` (Features tab, "Listen to this article"; on by default). Off: no player on articles, no player CSS or JS, no AudioObject in the schema, no "Listen to this article" box or audio field in the post editor (a site's own `article_audio` field is hidden too), and generation is refused. Saved audio stays in the Media Library. Shortcodes stay registered and print nothing.
- **Read it from another plugin:** `apply_filters( "smp_wp_text_to_speech_article_audio_enabled", false )`.
- **Code:** `src/Plugin.php` (`article_audio_enabled`), `src/Modules/PlayerModule.php`, `src/Modules/SchemaModule.php`, `src/Modules/PostEditorModule.php`

### Audio player
- **Does:** an audio player for the post's generated audio. Its CSS and JS load only on a single article with audio, or with the first player a shortcode prints ([asset loading](docs/asset-loading.md)).
- **Switch:** player defaults in settings: `player_label`, `show_player_meta`, `player_template` (default `clean_card`), `player_size`, `primary_color`, `enable_player_controls`.
- **Use:** `[hexa_tts_player post_id="" label="Listen to this article" show_meta="" preload="metadata" class="" template="clean_card" size="default" color="#3657e3" controls="" enhanced_controls=""]` (alias `[smp_tts_player]`). Place it in the single-post template with a Shortcode widget.
- **Style:** `.hexa-tts-player`, `.hexa-tts-player--<template>`, `.hexa-tts-player--size-<size>`; colour variable `--smp-tts-primary`. Selector map in [player-design-examples](docs/player-design-examples.md).
- **Extend:** `smp_wp_text_to_speech_article_audio_enabled` (read the switch), `smp_wp_text_to_speech_site_api_key` / legacy `smp_tts_site_api_key` (the working Publish site key).
- **Code:** `src/Concerns/RendersPlayer.php`, `src/Modules/PlayerModule.php`
- **Custom Elementor skin:** use `template="unstyled_controls"` for the full custom control surface. Plain `unstyled` keeps browser-native audio controls. See the [HTML/CSS example](docs/player-design-examples.md#listen-player).

### Audio generation
- **Does:** extracts the article text, sends it to the chosen provider and stores the audio on the post.
- **Use:** "Generate audio" in the post editor; credentials validated in settings.

### REST routes for Publish
- **Does:** lets Publish claim the site key (`POST /wp-json/smp-tts/v1/key-claim`) and read the article audio schema (`GET /wp-json/smp-tts/v1/schema`).
- **Use:** called by Publish only.
- **Code:** `src/Concerns/HandlesRestRoutes.php`, `src/Modules/RestModule.php`

### Audio schema
- **Does:** adds the article's AudioObject to Hexa WP Core's shared `hexa-schema` block and links it from the article as `audio` and `hasPart`.
- **Use:** automatic on posts and press releases with generated audio.
- **Code:** `src/Concerns/BuildsAudioSchema.php` (`current_schema_nodes`, `link_audio_to_article`), `src/Modules/SchemaModule.php`

## Internals

- Update Center tab: plugin version against GitHub with an update button, and bundled Hexa WP Core against Core on GitHub (`src/Modules/UpdateCenterModule.php`).
- Settings: option `smp_wp_text_to_speech_settings`; the pre-1.6.2 `hexa_tts_settings` is moved into it on first read.
- Site key: stored in Hexa WP Core's CredentialVault; the settings option keeps only a marker. Keys saved by 1.6.0 and earlier move into the vault when first read.
- Editor generation log: Hexa WP Core activity log, one per post, kept for a week.
- Settings actions: registered through Hexa WP Core's AJAX registry (capability and nonce checks).
- Folder and slug `smp-wp-text-to-speech`, main file `smp-wp-text-to-speech.php`; structure and contracts in `AGENTS.md`.

## Providers

- Kokoro-82M local service
- Piper local service
- Amazon Polly
- Google Cloud Text-to-Speech
- ElevenLabs
- Deepgram Aura
- Cartesia Sonic

## Admin Workflow

1. Install the folder under `wp-content/plugins/smp-wp-text-to-speech`.
2. Activate `SMP WP Text To Speech`.
3. Open Settings -> SMP WP Text To Speech. The canonical Hexa WP Core sidebar provides Dashboard, API Settings, Features, Display, Shortcodes, Schema, and Hexa WP Core sections.
4. Fill provider credentials.
5. Use each provider card's `Test credentials` button. Validation runs through `admin-ajax.php` and does not refresh the page.
6. Set default service and default profile.
7. Edit a post in `post.php`.
8. Use the `SMP WP Text To Speech` metabox:
   - Extract content
   - Review the AJAX preview
   - One-click generate audio
   - Optionally override profile/service/voice/model/language/speed

## Local Service Contract

Kokoro and Piper are expected to be behind a local HTTP wrapper. The plugin supports:

- `GET /health`
- `GET /voices`
- `POST /synthesize`

Recommended JSON body:

```json
{
  "text": "Article text",
  "voice": "af_heart",
  "model": "kokoro-82m",
  "language": "en-US",
  "speed": 1,
  "format": "mp3"
}
```

The service can return raw audio bytes, or JSON:

```json
{
  "audio_base64": "...",
  "mime_type": "audio/mpeg",
  "extension": "mp3"
}
```

It can also return:

```json
{
  "audio_url": "https://..."
}
```

## Output

Generated audio is saved under:

```text
wp-content/uploads/hexa-text-to-speech/
```

Post meta:

- `_hexa_tts_audio_url`
- `_hexa_tts_audio_path`
- `_hexa_tts_status`
- `_hexa_tts_provider`
- `_hexa_tts_profile`
- `_hexa_tts_voice`
- `_hexa_tts_model`
- `_hexa_tts_language`
- `_hexa_tts_speed`
- `_hexa_tts_text_hash`
- `_hexa_tts_character_count`
- `_hexa_tts_generated_at`
- `_hexa_tts_error`

## Shortcode

```text
[hexa_tts_player]
[hexa_tts_player post_id="123"]
[smp_tts_player post_id="123" template="unstyled"]
```

## Notes

This initial version performs single-request generation up to the configured character limit. For very long articles, the next production step should add chunking plus ffmpeg stitching/normalization.

## Changelog

Version 1.6.2 finishes the guidelines check: settings are stored under the prefixed `smp_wp_text_to_speech_settings` option (the old `hexa_tts_settings` moves across on first read; Publish 0.4.2 reads the new name and falls back for older plugins), every settings panel is a Hexa WP Core card, action and copy buttons are Core buttons, checkboxes are Core toggles, the audio-field hide uses Core's field hooks, and Hexa WP Core 3.34.2 is bundled.

Version 1.6.1 completes `hexa-plugin-guidelines` compliance: settings actions through Hexa WP Core's AJAX registry, the site key in Core's CredentialVault (older keys move on first read; Publish's key push is unchanged), the editor generation log on Core's activity log, the Shortcodes tab on Core's shortcode breakdown, Core cards and toggles on the Features tab, one-column settings, this README in the feature-base shape, and the root Core guide matching bundled Hexa WP Core 3.34.1.

Version 1.6.0 adds the "Listen to this article" switch (Features tab): off removes the player, its assets, the audio schema, the editor box and audio field, and refuses generation. The plugin now follows `hexa-plugin-guidelines`: a thin main file, `PluginMetadata`, one module per feature, an Update Center tab, player assets only on pages that show a player, and `smp_wp_text_to_speech_` hook and AJAX names. Bundles Hexa WP Core 3.34.1.

Version 1.5.0 adds the article's AudioObject to Hexa WP Core 3.22.0's shared schema block, linked to the article as its `audio`, instead of printing a separate schema block. Bundles Hexa WP Core 3.22.0.

Version 1.4.1 exposes the site's working (decrypted) Publish Scale key through the `smp_tts_site_api_key` filter so SMP content generation can use the same key.

Version 1.4.0 removes the ACF Pro requirement: the embedded Article Audio file field, field-key lookup and audio saving run on Hexa WP Core 3.4.5 `Fields`, which uses ACF when it is active and renders and stores the same field natively when it is not. The vendored Hexa WP Core package is updated to 3.4.5.

Version 1.3.25 adds on-demand provider credit detection and a readable integration-health checklist in both the article editor and API settings. Credit checks use a small production-sized synthesis probe because UnrealSpeech does not publish a read-only balance endpoint.

Version 1.3.24 adds an unstyled custom-control player for Elementor-owned presentation, including play/pause, timeline, elapsed and duration labels, volume and mute, skip, speed, keyboard-native range controls, and live status. Narration Card selectors are isolated so no visual skin reaches the unstyled control surface.

Version 1.3.23 lowers the shared styled-player foundation with `:where()` so named templates reliably control their own design and Elementor can override the stable player markup without a specificity fight. The unstyled template remains free of plugin-owned presentation.

Version 1.3.22 adds a supported `No design (unstyled)` player template for Elementor-owned presentation. It preserves the shortcode markup, native audio, enhanced controls, transcript behavior, JavaScript hooks, and ARIA attributes while excluding every plugin-owned skin rule and inline design token from that template.

Version 1.3.21 isolates the Dark Narration Card's play button and title from active-theme button and heading decorations so its frontend output matches the admin preview.

Version 1.3.20 adds the reference-style Dark Narration Card template with a large play button, timeline, duration, skip controls, playback speeds, and selected-color accents. Player template labels now describe the visible treatment so each design is easy to identify in the settings UI.

Version 1.3.19 makes frontend display changes immediate by centrally invalidating supported page/object caches after global player settings change and after generated or uploaded audio is synchronized to a post. LiteSpeed receives targeted post/URL purges for audio changes and a global purge for template, placement, color, label, size, and control changes; WP Rocket and W3 Total Cache fallbacks are included.

Version 1.3.18 gives the Clean Card player a straight blue top accent by removing its top-left and top-right corner radius while retaining the rounded lower corners. Its release-fingerprinted stylesheet path also prevents stale edge-cache CSS from making the admin preview and frontend disagree.

Version 1.3.17 integrates the settings experience with the canonical Hexa WP Core admin shell. All settings sections now use shared tab definitions, a grouped and collapsible sidebar, AJAX navigation with browser-history support, plugin/Core Git identity reporting, and the shared updater dashboards. The vendored Hexa WP Core package is updated to 0.19.73.

Version 1.3.16 added secure retrieval of an existing domain-assigned site API key. Retrieval uses a one-time WordPress REST challenge, exact-domain verification, encrypted local storage, and immediate API validation; it never creates or rotates a key.
