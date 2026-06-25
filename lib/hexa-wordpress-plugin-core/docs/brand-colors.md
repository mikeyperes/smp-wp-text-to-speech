# Brand Colors

Namespace:

```text
Hexa\PluginCore\BrandColors
```

Folder:

```text
src/BrandColors/
```

## Purpose

Brand color helpers keep host plugins from rebuilding the same HWS Base Tools Brand Assets lookup and color conversion logic.

## Classes

```text
BrandColorProvider
```

## Contract

- `BrandColorProvider::primary_color($fallback)` returns the HWS Base Tools primary color, normalized as a six-character hex value.
- `BrandColorProvider::secondary_color($fallback)` returns the HWS Base Tools secondary color.
- `BrandColorProvider::payload($fallback)` returns source labels, hex values, RGB strings, and the Brand Assets admin URL.
- `BrandColorProvider::rgb_string($hex)` converts a hex value to `rgb(r, g, b)`.
- `Hexa\PluginCore\WpAdminComponents\ColorControl::render()` owns the visual picker/hex/RGB/swatch/copy/import control.

## Host Plugin Rule

Host plugins pass setting keys and wire AJAX persistence. Core owns the reusable visual structure and HWS brand color lookup.

```php
use Hexa\PluginCore\BrandColors\BrandColorProvider;
use Hexa\PluginCore\WpAdminComponents\ColorControl;

$brand = BrandColorProvider::payload('#2d5277');

echo ColorControl::render([
    'key' => 'accent_color',
    'label' => 'Accent color',
    'value' => $settings['accent_color'] ?? $brand['primary_color'],
    'default' => $brand['primary_color'],
    'import_brand' => true,
]);
```
