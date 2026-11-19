<?php

namespace Smp\TextToSpeech\Support;

/** Resolves article audio and the existing attachment metadata. */
final class AudioFieldResolver {
    public static function fieldName( array $settings ): string {
        $name = isset( $settings["acf_audio_field"] ) ? sanitize_key( $settings["acf_audio_field"] ) : "";
        return "" !== $name ? $name : "article_audio";
    }

    public static function fallbackFieldKey( string $field_name ): string {
        return "field_smp_tts_" . substr( hash( "sha256", $field_name ), 0, 12 );
    }

    public static function renderFieldKey( string $field_name ): string {
        return self::fieldKey( $field_name ) ?: self::fallbackFieldKey( $field_name );
    }

    public static function fieldKey( string $field_name ): string {
        $field_name = sanitize_key( $field_name );
        if ( "" === $field_name ) {
            return "";
        }

        $groups = \Hexa\PluginCore\Fields\FieldGroups::all();
        if ( ! is_array( $groups ) ) {
            return "";
        }

        foreach ( $groups as $group ) {
            $fields = \Hexa\PluginCore\Fields\FieldGroups::fields( $group );
            $key = self::findFieldKey( is_array( $fields ) ? $fields : [], $field_name );
            if ( "" !== $key ) {
                return $key;
            }
        }

        return "";
    }

    /** Compatibility alias for integrations using the original method name. */
    public static function acfFieldKey( string $field_name ): string {
        return self::fieldKey( $field_name );
    }

    public static function postedAttachmentId( string $field_name ): int {
        // Bug: an ACF form could process the Core picker input as its own field.
        // Fix: read the input from Core's namespace. Why: the stored attachment and reference keys remain unchanged.
        $posted = $_POST[\Hexa\PluginCore\Fields\Renderer::INPUT] ?? [];
        if ( ! is_array( $posted ) ) {
            return 0;
        }

        $field_key = self::fieldKey( $field_name );
        $candidates = array_filter( [ $field_key, self::fallbackFieldKey( $field_name ), $field_name ] );
        foreach ( $candidates as $selector ) {
            if ( array_key_exists( $selector, $posted ) ) {
                return self::attachmentIdFromValue( function_exists( "wp_unslash" ) ? wp_unslash( $posted[ $selector ] ) : $posted[ $selector ] );
            }
        }

        return 0;
    }

    public static function attachmentIdFromValue( $value ): int {
        if ( is_numeric( $value ) ) {
            return absint( $value );
        }

        if ( is_array( $value ) ) {
            foreach ( [ "ID", "id", "attachment_id" ] as $key ) {
                if ( isset( $value[ $key ] ) && is_numeric( $value[ $key ] ) ) {
                    return absint( $value[ $key ] );
                }
            }
        }

        return 0;
    }

    public static function resolveUrl( $value ): string {
        $attachment_id = self::attachmentIdFromValue( $value );
        if ( $attachment_id ) {
            $url = wp_get_attachment_url( $attachment_id );
            return $url ? $url : "";
        }

        if ( is_array( $value ) && ! empty( $value["url"] ) && is_string( $value["url"] ) ) {
            $value = $value["url"];
        }

        if ( is_string( $value ) && preg_match( "#^https?://#i", $value ) ) {
            return $value;
        }

        return "";
    }

    public static function updatePostValue( int $post_id, string $field_name, int $attachment_id ): void {
        // Core also owns writes when the configured field has no registered definition.
        $selector = self::fieldKey( $field_name ) ?: $field_name;
        \Hexa\PluginCore\Fields\Field::update( $selector, $attachment_id, $post_id );
    }

    private static function findFieldKey( array $fields, string $field_name ): string {
        foreach ( $fields as $field ) {
            if ( ! is_array( $field ) ) {
                continue;
            }

            if ( $field_name === sanitize_key( (string) ( $field["name"] ?? "" ) ) && ! empty( $field["key"] ) ) {
                return (string) $field["key"];
            }

            foreach ( [ "sub_fields", "layouts" ] as $child_key ) {
                if ( empty( $field[ $child_key ] ) || ! is_array( $field[ $child_key ] ) ) {
                    continue;
                }

                $nested = self::findNestedFieldKey( $field[ $child_key ], $field_name );
                if ( "" !== $nested ) {
                    return $nested;
                }
            }
        }

        return "";
    }

    private static function findNestedFieldKey( array $items, string $field_name ): string {
        foreach ( $items as $item ) {
            if ( isset( $item["sub_fields"] ) && is_array( $item["sub_fields"] ) ) {
                $found = self::findFieldKey( $item["sub_fields"], $field_name );
                if ( "" !== $found ) {
                    return $found;
                }
                continue;
            }

            if ( is_array( $item ) ) {
                $found = self::findFieldKey( [ $item ], $field_name );
                if ( "" !== $found ) {
                    return $found;
                }
            }
        }

        return "";
    }
}

// Preserve the original helper class for existing integrations.
class_alias( AudioFieldResolver::class, __NAMESPACE__ . '\\AcfAudioFieldResolver' );
