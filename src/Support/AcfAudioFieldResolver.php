<?php

namespace Smp\TextToSpeech\Support;

final class AcfAudioFieldResolver {
    public static function fieldName( array $settings ): string {
        $name = isset( $settings["acf_audio_field"] ) ? sanitize_key( $settings["acf_audio_field"] ) : "";
        return "" !== $name ? $name : "article_audio";
    }

    public static function fallbackFieldKey( string $field_name ): string {
        return "field_smp_tts_" . substr( hash( "sha256", $field_name ), 0, 12 );
    }

    public static function renderFieldKey( string $field_name ): string {
        return self::acfFieldKey( $field_name ) ?: self::fallbackFieldKey( $field_name );
    }

    public static function acfFieldKey( string $field_name ): string {
        $field_name = sanitize_key( $field_name );
        if ( "" === $field_name || ! function_exists( "acf_get_field_groups" ) || ! function_exists( "acf_get_fields" ) ) {
            return "";
        }

        $groups = acf_get_field_groups();
        if ( ! is_array( $groups ) ) {
            return "";
        }

        foreach ( $groups as $group ) {
            $fields = acf_get_fields( $group );
            $key = self::findFieldKey( is_array( $fields ) ? $fields : [], $field_name );
            if ( "" !== $key ) {
                return $key;
            }
        }

        return "";
    }

    public static function postedAttachmentId( string $field_name ): int {
        $posted = $_POST["acf"] ?? [];
        if ( ! is_array( $posted ) ) {
            return 0;
        }

        $field_key = self::acfFieldKey( $field_name );
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
        $field_key = self::acfFieldKey( $field_name );
        if ( "" !== $field_key && function_exists( "update_field" ) ) {
            update_field( $field_key, $attachment_id, $post_id );
            return;
        }

        update_post_meta( $post_id, $field_name, $attachment_id );
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
