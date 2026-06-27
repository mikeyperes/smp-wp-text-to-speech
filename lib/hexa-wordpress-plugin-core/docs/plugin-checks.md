# Plugin Checks

Namespace:

```text
Hexa\PluginCore\PluginChecks
```

Folder:

```text
src/PluginChecks/
```

## Purpose

Plugin Checks is the shared Hexa WP Core feature for dependency/plugin requirement tabs.

Host plugins pass a list of plugin definitions. Core checks whether each plugin is installed, active, and up to date, then renders each plugin as a clean row with an inline emoji checklist directly under the plugin name, AJAX install/activate actions, and an activity log.

## Public Classes

- `PluginCheckDefinition`: normalizes one plugin requirement.
- `PluginCheckService`: resolves installed/active/update status and calls shared install/activate helpers.
- `PluginChecksRenderer`: renders the admin UI, summary pills, plugin cards, dynamic buttons, and activity log.
- `PluginChecksAjaxController`: registers AJAX actions for status refresh, update-cache refresh, install-and-activate, and activate.

## Definition Shape

```php
[
    'id'          => 'classic-editor',
    'name'        => 'Classic Editor',
    'plugin_file' => 'classic-editor/classic-editor.php',
    'slug'        => 'classic-editor',
    'source'      => 'wordpress_org',
    'checks'      => [
        'installed'  => true,
        'active'     => true,
        'up_to_date' => true,
    ],
    'notes'       => 'Required for the editorial workflow.',
]
```

Supported sources:

- `wordpress_org`: installs through the WordPress.org plugin API using `wp_org_slug` or `slug`.
- `github`: installs from a GitHub repository using `github_repo` and optional `github_branch`.
- `pro`: shows the configured `download_url` and does not attempt AJAX install.
- `manual`: shows the configured `download_url` or the WordPress plugin upload page.

## Host Integration

Register AJAX:

```php
( new \Hexa\PluginCore\PluginChecks\PluginChecksAjaxController(
    my_plugin_required_plugins(),
    [
        'capability'    => 'update_plugins',
        'nonce_action'  => 'my_plugin_admin',
        'nonce_field'   => 'nonce',
        'action_prefix' => 'my_plugin_plugin_checks',
    ]
) )->register();
```

Render the tab:

```php
echo ( new \Hexa\PluginCore\PluginChecks\PluginChecksRenderer() )->render(
    my_plugin_required_plugins(),
    [
        'title'         => 'Plugin Checks',
        'description'   => 'Required plugin health for this integration.',
        'nonce'         => wp_create_nonce( 'my_plugin_admin' ),
        'nonce_field'   => 'nonce',
        'action_prefix' => 'my_plugin_plugin_checks',
    ]
);
```

## Behavior

- Missing WordPress.org and GitHub plugins get an AJAX **Install and activate** action.
- Installed but inactive plugins get an AJAX **Activate** action.
- Pro/manual plugins show an external download/upload link.
- The **Refresh checks** button refreshes the WordPress plugin update cache through AJAX.
- The **Install and activate missing** button processes visible install/activate actions sequentially with no page refresh.
