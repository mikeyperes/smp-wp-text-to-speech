# SMP WP Text To Speech

Standalone WordPress plugin for converting post/page text into audio with provider-specific settings, AJAX credential validation, editor extraction, and one-click post audio generation.

Version 1.3.18 gives the Clean Card player a straight blue top accent by removing its top-left and top-right corner radius while retaining the rounded lower corners. Its release-fingerprinted stylesheet path also prevents stale edge-cache CSS from making the admin preview and frontend disagree.

Version 1.3.17 integrates the settings experience with the canonical Hexa WP Core admin shell. All settings sections now use shared tab definitions, a grouped and collapsible sidebar, AJAX navigation with browser-history support, plugin/Core Git identity reporting, and the shared updater dashboards. The vendored Hexa WP Core package is updated to 0.19.73.

Version 1.3.16 added secure retrieval of an existing domain-assigned site API key. Retrieval uses a one-time WordPress REST challenge, exact-domain verification, encrypted local storage, and immediate API validation; it never creates or rotates a key.

Plugin folder/slug:

```text
smp-wp-text-to-speech
```

Main file:

```text
smp-wp-text-to-speech.php
```

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
```

## Notes

This initial version performs single-request generation up to the configured character limit. For very long articles, the next production step should add chunking plus ffmpeg stitching/normalization.
