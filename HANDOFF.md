# AI Agent Handoff: SMP WP Text To Speech

## Current Build

Created a new standalone WordPress plugin:

```text
/Users/mp/Projects/smp-wp-text-to-speech
```

Plugin identity:

- Plugin name: `SMP WP Text To Speech`
- Slug/folder: `smp-wp-text-to-speech`
- Main file: `smp-wp-text-to-speech.php`
- Version: `1.3.9`

## Implemented

Settings page:

- Location: Settings -> SMP WP Text To Speech
- Stores settings in option `hexa_tts_settings`.
- Provides default service/profile controls.
- Provides profile presets: `default`, `local`, `premium`.
- Provides API/service settings for all target providers from the research report:
  - Kokoro local service
  - Piper local service
  - Amazon Polly
  - Google Cloud Text-to-Speech
  - ElevenLabs
  - Deepgram Aura
  - Cartesia Sonic
- Secret fields are saved without re-displaying the raw secret.
- Saved secrets display a masked indicator with the final characters.
- Secrets are encrypted with AES-256-CBC when OpenSSL is available; otherwise they fall back to normal option storage.
- Each provider has instructions and external links with `target="_blank"` and `rel="noopener noreferrer"`.

AJAX validation:

- Action: `hexa_tts_validate_provider`
- Capability: `manage_options`
- Nonce: `hexa_tts_admin_nonce`
- Provider validation endpoints:
  - Kokoro/Piper: tries `/health`, `/voices`, and base URL.
  - Amazon Polly: signed SigV4 `GET /v1/voices`.
  - Google TTS: `GET https://texttospeech.googleapis.com/v1/voices`.
  - ElevenLabs: `GET https://api.elevenlabs.io/v1/models`.
  - Deepgram: `GET https://api.deepgram.com/v1/auth/token`.
  - Cartesia: `GET https://api.cartesia.ai/voices`.

Post editor integration:

- Metabox title: `SMP WP Text To Speech`
- Appears on all public post types.
- AJAX content extraction action: `hexa_tts_extract_post_content`.
- AJAX audio generation action: `hexa_tts_generate_audio`.
- User can choose profile, provider override, voice, model, language, and speed inside `post.php`.
- `Extract content` button returns character count, word count, hash, and preview without refreshing.
- `One-click generate audio` extracts content and generates audio using defaults/overrides.

Audio generation:

- Local Kokoro/Piper service generation through `POST /synthesize`.
- Amazon Polly `POST /v1/speech`.
- Google Cloud `POST /v1/text:synthesize`.
- ElevenLabs `POST /v1/text-to-speech/{voice_id}`.
- Deepgram `POST /v1/speak`.
- Cartesia `POST /tts/bytes`.
- Saves output to `wp-content/uploads/hexa-text-to-speech/`.
- Stores generated audio metadata in post meta.

Frontend:

- Auto-inserts audio player above post content when enabled.
- Provides `[hexa_tts_player]` shortcode.

## Files

```text
smp-wp-text-to-speech/
  smp-wp-text-to-speech.php
  README.md
  HANDOFF.md
  assets/
    admin.css
    admin.js
```

## Important Constraints

This version performs single-request generation up to the configured max character count. Default is `20000`.

For long articles, the next production build should add:

- paragraph/sentence chunking,
- per-chunk retry,
- durable background queue,
- ffmpeg stitching,
- loudness normalization,
- duration extraction,
- CDN/R2/S3 storage option.

The plugin deliberately keeps WordPress/PHP separate from local ML runtime. Kokoro and Piper should be served by a local HTTP service, not loaded inside PHP.

## Provider Docs Used

- Kokoro: https://huggingface.co/hexgrad/Kokoro-82M
- Piper: https://github.com/rhasspy/piper
- Amazon Polly: https://docs.aws.amazon.com/polly/latest/dg/API_ListVoices.html
- AWS SigV4: https://docs.aws.amazon.com/IAM/latest/UserGuide/reference_sigv.html
- Google voices.list: https://cloud.google.com/text-to-speech/docs/reference/rest/v1/voices/list
- ElevenLabs authentication: https://elevenlabs.io/docs/api-reference/authentication
- Deepgram authentication: https://developers.deepgram.com/docs/authenticating
- Deepgram TTS: https://developers.deepgram.com/reference/text-to-speech-api
- Cartesia voices: https://docs.cartesia.ai/api-reference/voices/list
- Cartesia TTS bytes: https://docs.cartesia.ai/api-reference/tts/bytes

## Testing Needed On A Real WordPress Site

Static tests can verify syntax and JavaScript parsing locally. Full A-Z browser testing requires a WordPress install with the plugin activated and provider credentials or local TTS services.

Recommended live test path:

1. Upload folder to `wp-content/plugins/smp-wp-text-to-speech`.
2. Activate plugin.
3. Open Settings -> SMP WP Text To Speech.
4. Save defaults once.
5. Validate Kokoro/Piper local service if available.
6. Validate cloud provider keys one by one.
7. Edit a normal post in `post.php`.
8. Click `Extract content`; confirm no page reload and text preview appears.
9. Click `One-click generate audio`; confirm status becomes Ready.
10. Confirm audio file exists under uploads.
11. View post frontend; confirm player appears.
12. Test shortcode `[hexa_tts_player]`.
13. Update article content and regenerate; confirm new hash/audio path.

## Known Follow-Up Work

- Add Action Scheduler queue for production-scale generation.
- Add chunk/stitch pipeline.
- Add provider voice discovery dropdowns.
- Add provider cost estimate before generation.
- Add "delete audio" and "regenerate if stale" buttons.
- Add bulk generation dashboard.
- Add REST endpoint for headless/editor integrations.
