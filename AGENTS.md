# SMP WP Text To Speech Project Context

Orientation for a new thread. It does not start an agent or a fixed workflow.

## Project

- Repository: `https://github.com/mikeyperes/smp-wp-text-to-speech.git`, branch `main`
- Plugin slug, folder and text domain: `smp-wp-text-to-speech`; hook, option and AJAX prefix `smp_wp_text_to_speech_`
- Identity and version: `src/PluginMetadata.php` (its `VERSION` matches the plugin header)
- Rules: `hexa-plugin-guidelines`; HexaWP Core is bundled at `lib/hexa-wordpress-plugin-core` (never edit the bundled copy)
- Tests: `for t in tests/*.php; do php "$t"; done`

## Structure

- `smp-wp-text-to-speech.php` only guards against loading twice, registers the autoloader and HexaWP Core, and boots on `plugins_loaded`.
- `src/Plugin.php` is the facade. Its behaviour lives in `src/Concerns/` (one trait per job: settings, settings page, post editor, generation, audio storage, player, schema, REST, diagnostics, admin assets, boot).
- `src/Modules/` hold one module per feature; each `register()` only adds hooks.

## Contracts other systems depend on

- Publish calls `\smp_text_to_speech\Plugin::generate_for_post()`, `get_settings()`, `prepare_generation_content()` and `encrypt_secret()` over SSH, and reads `Plugin::OPTION` (falling back to the legacy `hexa_tts_settings` for older plugins). Keep the class name, these methods and that constant.
- REST routes `smp-tts/v1/key-claim` and `smp-tts/v1/schema` are called by Publish.
- Post meta `_hexa_tts_*` and the `article_audio` field hold stored audio; never rename them.
- `smp_tts_site_api_key` is read by SMP Publication Integration (legacy name, kept beside `smp_wp_text_to_speech_site_api_key`). `smp_wp_text_to_speech_article_audio_enabled` reports the Listen switch to other plugins.
- Shortcodes `[hexa_tts_player]` and `[smp_tts_player]` are placed in site templates.

## Listen to this article

The `article_audio_enabled` setting (Features tab) is the whole feature. Off: no player, no player assets, no audio schema, no editor box, the audio field is hidden, and generation is refused. Shortcodes stay registered and print nothing.
