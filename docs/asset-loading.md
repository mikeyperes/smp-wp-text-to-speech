# Asset loading

How SMP WP Text To Speech follows the asset rule in `hexa-plugin-guidelines`.

## How each asset is scoped

- Admin CSS and JS (`assets/admin.css`, `assets/admin.js`, inline settings scripts): the plugin's settings page and the post edit screen only (`enqueue_admin_assets` checks the hook and screen).
- Player CSS and JS (`assets/frontend-1.3.24.css`, `assets/frontend.js`) on the front end:
  - in the head only on a single post or press release that has audio, while Listen to this article is on (`enqueue_frontend_assets`);
  - anywhere else, with the first player a shortcode prints (`player_markup` enqueues them);
  - never while Listen to this article is off, and never on pages without a player.
- Audio schema: added to Hexa WP Core's shared schema block only on single articles with audio, while the feature is on.

## How to check

```sh
curl -s "https://<site>/?nocache=1" | grep -oE '(href|src)="[^"]*smp-wp-text-to-speech[^"]*"'
```

Check the home page (expect nothing), an article with audio (expect the player CSS and JS), an article without audio (expect nothing), and the plugin's settings page and a post edit screen in the admin. Repeat with Listen to this article off: nothing on any front-end page.
