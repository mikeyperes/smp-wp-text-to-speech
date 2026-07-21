# UI Namespace

Namespace:

```text
Hexa\PluginCore\WpAdminComponents
```

Folder:

```text
src/WpAdminComponents/
```

## Purpose

The UI namespace owns shared visual primitives for Hexa plugin admin screens.

Host plugins should use these primitives instead of rebuilding card, button, tooltip, and collapsible markup differently in each plugin.

## Classes

```text
CoreUi
ScopedCssOverride
ColorControl
ColorPalette
ElementorPaletteDetector
DetailedColorPicker
```

## Components

```text
render_assets()
card()
subcard()
collapsible()
pill()
tooltip()
copy_button()
ScopedCssOverride::render()
ColorControl::render()
ColorPalette::render()
ElementorPaletteDetector::render()
DetailedColorPicker::render()
```

## Example

```php
use Hexa\PluginCore\WpAdminComponents\CoreUi;

CoreUi::render_assets();

echo CoreUi::card(
    [
        'title'     => 'Plugin Status',
        'body_html' => '<p>All systems are healthy.</p>',
        'meta_html' => CoreUi::pill( 'Healthy', 'success' ),
    ]
);
```

Scoped CSS editor or reference example:

```php
use Hexa\PluginCore\WpAdminComponents\ScopedCssOverride;

echo ScopedCssOverride::render(
    [
        'title'        => 'Header CSS override',
        'selector'     => 'body .example-header',
        'instructions' => [ 'Keep every rule inside this selector.' ],
        'html_example' => '<header class="example-header">...</header>',
        'css_example'  => "body .example-header {\n  color: #111827;\n}",
        'open'         => false,
    ]
);
```

## Query-Backed Collapsibles

`CoreUi::collapsible()` automatically gives every titled section a stable query key. Opening or closing a section updates the current URL with repeated `hpc_open` parameters, and Core restores those sections after a full refresh or an AJAX tab load.

```php
echo CoreUi::collapsible(
    [
        'title'     => 'Article first-letter drop cap',
        'body_html' => '<p>Settings...</p>',
        'query_key' => 'article-first-letter-drop-cap', // Optional stable override.
    ]
);
```

The title slug is used when `query_key` is omitted. Set `query_state => false` only for a section whose open state must never appear in the URL. `persist_key` remains available for local-storage fallback; query-string state is authoritative whenever `hpc_open` is present.

## Rule

If a host plugin needs cards, subcards, collapsibles, tooltips, status pills, copy buttons, scoped CSS override editors and references, brand-aware isolated color controls, saved color palettes, or Elementor palette detection, add the missing parameter or helper here first.
