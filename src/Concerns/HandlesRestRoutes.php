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

trait HandlesRestRoutes {
    public static function register_rest_routes(): void {
        register_rest_route(
            self::REST_NS,
            "/schema",
            [
                "methods" => "GET",
                "callback" => [ __CLASS__, "rest_schema" ],
                "permission_callback" => "__return_true",
                "args" => [
                    "post_id" => [ "sanitize_callback" => "absint" ],
                    "url" => [ "sanitize_callback" => "esc_url_raw" ],
                ],
            ]
        );
        register_rest_route(
            self::REST_NS,
            "/key-claim",
            [
                "methods" => "POST",
                "callback" => [ __CLASS__, "rest_key_claim" ],
                "permission_callback" => "__return_true",
                "args" => [
                    "challenge" => [
                        "required" => true,
                        "sanitize_callback" => "sanitize_text_field",
                    ],
                ],
            ]
        );
    }

    public static function rest_key_claim( \WP_REST_Request $request ): \WP_REST_Response {
        $challenge = strtolower( trim( (string) $request->get_param( "challenge" ) ) );
        $transient = self::key_claim_transient_name( $challenge );
        $stored_proof = "" !== $transient ? (string) get_transient( $transient ) : "";
        $expected_proof = "" !== $challenge ? hash_hmac( "sha256", $challenge, self::secret_key() ) : "";

        if ( ! preg_match( "/\\A[a-f0-9]{64}\\z/", $challenge )
            || "" === $stored_proof
            || "" === $expected_proof
            || ! hash_equals( $expected_proof, $stored_proof )
        ) {
            $response = new \WP_REST_Response(
                [ "success" => false, "message" => "No active site-key claim was found." ],
                404
            );
            $response->header( "Cache-Control", "no-store, private, max-age=0" );
            $response->header( "X-Robots-Tag", "noindex, nofollow" );
            return $response;
        }

        delete_transient( $transient );
        $response = new \WP_REST_Response(
            [
                "success" => true,
                "challenge" => $challenge,
                "site_url" => home_url( "/" ),
                "plugin" => self::SETTINGS_SLUG,
                "version" => self::VERSION,
            ],
            200
        );
        $response->header( "Cache-Control", "no-store, private, max-age=0" );
        $response->header( "Pragma", "no-cache" );
        $response->header( "X-Robots-Tag", "noindex, nofollow" );
        return $response;
    }

    public static function rest_schema( \WP_REST_Request $request ): \WP_REST_Response {
        $post_id = absint( $request->get_param( "post_id" ) );
        $url = (string) $request->get_param( "url" );
        $context = "none";

        if ( $post_id <= 0 && "" !== $url ) {
            $post_id = url_to_postid( $url );
        }

        $schema = [];
        if ( $post_id > 0 && get_post( $post_id ) ) {
            if ( ! PostVisibility::canExposeSchema( $post_id ) ) {
                return new \WP_REST_Response(
                    [
                        "plugin" => "smp-wp-text-to-speech",
                        "context" => "restricted",
                        "requested_url" => $url,
                        "post_id" => $post_id,
                        "message" => "AudioObject schema is available only for publicly readable posts.",
                    ],
                    403
                );
            }
            $context = "single";
            $schema = self::generate_audio_schema_array( $post_id );
        }

        return new \WP_REST_Response(
            [
                "plugin" => "smp-wp-text-to-speech",
                "context" => $context,
                "requested_url" => $url,
                "post_id" => $post_id,
                "types" => self::schema_types( $schema ),
                "integrity" => self::schema_integrity_report( $post_id ),
                "schema" => $schema,
            ]
        );
    }

    private static function key_claim_transient_name( string $challenge ): string {
        $challenge = strtolower( trim( $challenge ) );
        if ( ! preg_match( "/\\A[a-f0-9]{64}\\z/", $challenge ) ) {
            return "";
        }
        return "hexa_tts_key_claim_" . substr( hash( "sha256", $challenge ), 0, 40 );
    }
}
