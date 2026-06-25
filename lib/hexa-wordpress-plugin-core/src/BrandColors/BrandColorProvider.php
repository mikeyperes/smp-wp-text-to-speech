<?php

namespace Hexa\PluginCore\BrandColors;

final class BrandColorProvider {
    public const HWS_PRIMARY_OPTION = "hws_brand_primary_color";
    public const HWS_SECONDARY_OPTION = "hws_brand_secondary_color";

    public static function primary_color( string $fallback = "#2d5277" ): string {
        return self::hws_color( self::HWS_PRIMARY_OPTION, $fallback );
    }

    public static function secondary_color( string $fallback = "#111827" ): string {
        return self::hws_color( self::HWS_SECONDARY_OPTION, $fallback );
    }

    public static function payload( string $fallback = "#2d5277" ): array {
        $primary = self::primary_color( $fallback );
        $secondary = self::secondary_color( "#111827" );

        return [
            "source" => "hws-base-tools",
            "source_label" => "HWS Base Tools Brand Assets",
            "primary_color" => $primary,
            "primary_rgb" => self::rgb_string( $primary ),
            "secondary_color" => $secondary,
            "secondary_rgb" => self::rgb_string( $secondary ),
            "admin_url" => self::brand_assets_admin_url(),
        ];
    }

    public static function normalize_hex( string $value, string $fallback = "#000000" ): string {
        $value = trim( strtolower( $value ) );
        if ( "" !== $value && "#" !== $value[0] ) {
            $value = "#" . $value;
        }

        if ( preg_match( "/^#[0-9a-f]{3}$/", $value ) ) {
            $value = "#" . $value[1] . $value[1] . $value[2] . $value[2] . $value[3] . $value[3];
        }

        if ( function_exists( "sanitize_hex_color" ) ) {
            $sanitized = sanitize_hex_color( $value );
            if ( is_string( $sanitized ) && preg_match( "/^#[0-9a-f]{6}$/", strtolower( $sanitized ) ) ) {
                return strtolower( $sanitized );
            }
        }

        if ( preg_match( "/^#[0-9a-f]{6}$/", $value ) ) {
            return $value;
        }

        if ( $fallback !== $value ) {
            return self::normalize_hex( $fallback, "#000000" );
        }

        return "#000000";
    }

    public static function rgb_array( string $hex ): array {
        $hex = self::normalize_hex( $hex );

        return [
            "r" => hexdec( substr( $hex, 1, 2 ) ),
            "g" => hexdec( substr( $hex, 3, 2 ) ),
            "b" => hexdec( substr( $hex, 5, 2 ) ),
        ];
    }

    public static function rgb_string( string $hex ): string {
        $rgb = self::rgb_array( $hex );
        return "rgb(" . $rgb["r"] . ", " . $rgb["g"] . ", " . $rgb["b"] . ")";
    }

    private static function hws_color( string $option, string $fallback ): string {
        if ( function_exists( "hws_get_brand_colors_payload" ) ) {
            $payload = hws_get_brand_colors_payload();
            $key = self::HWS_PRIMARY_OPTION === $option ? "primary_color" : "secondary_color";
            if ( is_array( $payload ) && ! empty( $payload[ $key ] ) && is_scalar( $payload[ $key ] ) ) {
                return self::normalize_hex( (string) $payload[ $key ], $fallback );
            }
        }

        if ( function_exists( "get_option" ) ) {
            $stored = get_option( $option, "" );
            if ( is_scalar( $stored ) && "" !== trim( (string) $stored ) ) {
                return self::normalize_hex( (string) $stored, $fallback );
            }
        }

        return self::normalize_hex( $fallback );
    }

    private static function brand_assets_admin_url(): string {
        if ( ! function_exists( "admin_url" ) ) {
            return "";
        }

        return admin_url( "options-general.php?page=hws-core-tools&tab=brand-assets" );
    }
}
