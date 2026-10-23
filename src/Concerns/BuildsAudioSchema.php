<?php

namespace smp_text_to_speech\Concerns;

use Hexa\PluginCore\CoreBootstrap\CoreBootstrap;
use Hexa\PluginCore\CorePackageUpdates\CorePackageAjaxController;
use Hexa\PluginCore\CorePackageUpdates\CorePackageStatus;
use Hexa\PluginCore\CoreRuntime\PluginContext;
use Hexa\PluginCore\PluginUpdates\GitHubPluginUpdater;
use Hexa\PluginCore\PluginUpdates\PluginUpdateStatus;
use Hexa\PluginCore\PluginUpdates\UpdaterAjaxController;
use Hexa\PluginCore\WpAdminTabs\CoreTabConfig;
use Hexa\PluginCore\WpAdminTabs\CoreTabModule;
use Hexa\PluginCore\WpAdminTabs\HostTabsRenderer;
use Hexa\PluginCore\WpAdminTabs\TabDefinition;
use Hexa\PluginCore\WpAdminTabs\TabRegistry;
use Smp\TextToSpeech\Admin\SettingsNavigation;
use Smp\TextToSpeech\Support\AcfAudioFieldResolver;
use Smp\TextToSpeech\Support\AudioAttachmentGuard;
use Smp\TextToSpeech\Support\PostVisibility;
use Smp\TextToSpeech\Support\Text;
use WP_Error;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

trait BuildsAudioSchema {
    /**
     * The AudioObject for the article being viewed, added to HexaWP Core's
     * shared schema block.
     *
     * @return list<array<string,mixed>>
     */
    public static function current_schema_nodes(): array {
        if ( is_admin() || ! self::article_audio_enabled() || ! is_singular( [ "post", "press-release" ] ) ) {
            return [];
        }

        $audio = self::audio_schema_entity( (int) get_queried_object_id() );
        return $audio ? [ $audio ] : [];
    }

    /**
     * Links the audio to the page's article when another plugin printed one.
     *
     * @param list<array<string,mixed>> $nodes
     * @return list<array<string,mixed>>
     */
    public static function link_audio_to_article( $nodes ) {
        if ( ! is_array( $nodes ) || ! is_singular() ) {
            return $nodes;
        }
        $permalink = (string) get_permalink( get_queried_object_id() );
        $audio_id = $permalink . "#audio";
        $has_audio = false;
        foreach ( $nodes as $node ) {
            $has_audio = $has_audio || ( is_array( $node ) && $audio_id === ( $node["@id"] ?? "" ) );
        }
        if ( ! $has_audio ) {
            return $nodes;
        }
        foreach ( $nodes as &$node ) {
            if ( is_array( $node ) && \Hexa\PluginCore\SchemaTools\SchemaIds::article( $permalink ) === ( $node["@id"] ?? "" ) ) {
                $node["audio"] = [ "@id" => $audio_id ];
                $node["hasPart"] = self::append_schema_reference( $node["hasPart"] ?? [], $audio_id );
            }
        }
        unset( $node );
        return $nodes;
    }

    public static function generate_audio_schema_array( int $post_id ): array {
        $entity = self::audio_schema_entity( $post_id );
        if ( empty( $entity ) ) {
            return [];
        }

        return [ "@context" => "https://schema.org", "@graph" => [ $entity ] ];
    }

    private static function audio_schema_entity( int $post_id ): array {
        $post = get_post( $post_id );
        if ( ! $post || ! in_array( $post->post_type, [ "post", "press-release" ], true ) ) {
            return [];
        }

        $settings = self::get_settings();
        $acf_field = AcfAudioFieldResolver::fieldName( $settings );
        $attachment_id = self::current_audio_attachment_id( $post_id, $settings );
        $url = (string) get_post_meta( $post_id, "_hexa_tts_audio_url", true );
        if ( "" === $url && $attachment_id ) {
            $url = (string) wp_get_attachment_url( $attachment_id );
        }
        if ( "" === $url ) {
            $url = self::resolve_audio_url( get_post_meta( $post_id, $acf_field, true ) );
        }
        if ( "" === $url ) {
            return [];
        }

        $permalink = get_permalink( $post );
        $metadata = $attachment_id ? wp_get_attachment_metadata( $attachment_id ) : [];
        $metadata = is_array( $metadata ) ? $metadata : [];
        $seconds = isset( $metadata["length"] ) ? absint( $metadata["length"] ) : 0;
        $duration = self::schema_duration_from_seconds( $seconds );
        $mime = $attachment_id ? (string) get_post_mime_type( $attachment_id ) : "";
        if ( "" === $mime && preg_match( "/\\.mp3(?:\\?|$)/i", $url ) ) {
            $mime = "audio/mpeg";
        }

        $generated = (string) get_post_meta( $post_id, "_hexa_tts_generated_at", true );
        if ( "" === $generated && $attachment_id ) {
            $generated = (string) get_post_field( "post_date", $attachment_id );
        }
        $upload_date = "" !== $generated ? mysql2date( DATE_W3C, $generated, false ) : "";
        $transcript = self::audio_transcript_for_post( $post_id );

        return \Hexa\PluginCore\SchemaTools\SchemaNodes::node( "AudioObject", [
            "name" => "Audio version of " . get_the_title( $post ),
            "description" => "Audio narration for " . get_the_title( $post ) . ".",
            "url" => esc_url_raw( $url ),
            "contentUrl" => esc_url_raw( $url ),
            "encodingFormat" => $mime,
            "duration" => $duration,
            "uploadDate" => $upload_date,
            "inLanguage" => \Hexa\PluginCore\SchemaTools\SchemaSources::language(),
            "isPartOf" => [ "@id" => \Hexa\PluginCore\SchemaTools\SchemaIds::article( (string) $permalink ) ],
            "transcript" => $transcript,
        ], $permalink . "#audio" );
    }

    private static function append_schema_reference( $value, string $id ): array {
        $items = [];
        if ( is_array( $value ) && isset( $value["@id"] ) ) {
            $items[] = $value;
        } elseif ( is_array( $value ) ) {
            $items = $value;
        }

        foreach ( $items as $item ) {
            if ( is_array( $item ) && (string) ( $item["@id"] ?? "" ) === $id ) {
                return $items;
            }
        }

        $items[] = [ "@id" => $id ];
        return array_values( $items );
    }

    private static function audio_transcript_for_post( int $post_id ): string {
        foreach ( [ "_hexa_tts_transcript", "_hexa_tts_narration_text" ] as $key ) {
            $value = trim( (string) get_post_meta( $post_id, $key, true ) );
            if ( "" !== $value ) {
                return wp_strip_all_tags( $value );
            }
        }

        return "";
    }

    private static function schema_duration_from_seconds( int $seconds ): string {
        return \Hexa\PluginCore\SchemaTools\SchemaGraph::duration_from_seconds( $seconds );
    }

    private static function schema_types( array $schema ): array {
        return \Hexa\PluginCore\SchemaTools\SchemaGraph::types( $schema );
    }

    private static function schema_integrity_report( int $post_id ): array {
        $schema = $post_id > 0 ? self::generate_audio_schema_array( $post_id ) : [];
        $types = self::schema_types( $schema );
        $entity = isset( $schema["@graph"][0] ) && is_array( $schema["@graph"][0] ) ? $schema["@graph"][0] : [];
        $attachment_id = $post_id > 0 ? self::current_audio_attachment_id( $post_id, self::get_settings() ) : 0;
        $metadata = $attachment_id ? wp_get_attachment_metadata( $attachment_id ) : [];
        $metadata = is_array( $metadata ) ? $metadata : [];

        return [
            "types" => $types,
            "checks" => [
                [ "label" => "Published audio post found", "status" => $post_id > 0 ? "green" : "red" ],
                [ "label" => "AudioObject schema generated", "status" => in_array( "AudioObject", $types, true ) ? "green" : "red" ],
                [ "label" => "contentUrl points to an audio file", "status" => ! empty( $entity["contentUrl"] ) ? "green" : "red" ],
                [ "label" => "encodingFormat is present", "status" => ! empty( $entity["encodingFormat"] ) ? "green" : "yellow" ],
                [ "label" => "duration is present from attachment metadata", "status" => ! empty( $entity["duration"] ) ? "green" : ( ! empty( $metadata ) ? "yellow" : "red" ) ],
                [ "label" => "AudioObject links to article page", "status" => ! empty( $entity["isPartOf"] ) ? "green" : "red" ],
                [ "label" => "Schema validator URL can be opened", "status" => $post_id > 0 && get_permalink( $post_id ) ? "green" : "yellow" ],
            ],
        ];
    }
}
