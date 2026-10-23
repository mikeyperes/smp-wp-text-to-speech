<?php

namespace smp_text_to_speech\Concerns;

use Smp\TextToSpeech\Support\AcfAudioFieldResolver;
use Smp\TextToSpeech\Support\Text;
use WP_Error;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

trait RunsDiagnostics {
    public static function ajax_validate_central_api() {
        $result = self::api_request( "/status", [] );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ "message" => $result->get_error_message() ] );
        }
        $settings = self::get_settings();
        $settings["last_status"] = $result;
        update_option( self::OPTION, $settings, false );
        wp_send_json_success( $result );
    }

    public static function ajax_check_credits() {
        $post_id = absint( $_POST["post_id"] ?? 0 );
        if ( ! self::can_run_diagnostics( $post_id ) ) {
            wp_send_json_error( [ "message" => "You do not have permission to check text-to-speech credits." ], 403 );
        }

        $result = self::api_request( "/credits", [], 120 );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ "message" => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    public static function ajax_check_health() {
        $post_id = absint( $_POST["post_id"] ?? 0 );
        if ( ! self::can_run_diagnostics( $post_id ) ) {
            wp_send_json_error( [ "message" => "You do not have permission to run text-to-speech health checks." ], 403 );
        }

        $result = self::api_request( "/health", [], 120 );
        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ "message" => $result->get_error_message() ] );
        }

        $checks = array_merge( self::local_health_checks( $post_id ), is_array( $result["checks"] ?? null ) ? $result["checks"] : [] );
        $healthy = true;
        foreach ( $checks as $check ) {
            if ( "pass" !== ( $check["status"] ?? "" ) ) {
                $healthy = false;
                break;
            }
        }

        $result["checks"] = $checks;
        $result["healthy"] = $healthy;
        $result["message"] = $healthy
            ? "The text-to-speech integration passed every health check."
            : "One or more text-to-speech health checks need attention.";
        wp_send_json_success( $result );
    }

    private static function can_run_diagnostics( int $post_id ): bool {
        return $post_id > 0 ? current_user_can( "edit_post", $post_id ) : current_user_can( "manage_options" );
    }

    private static function local_health_checks( int $post_id ): array {
        $settings = self::get_settings();
        $upload = wp_upload_dir();
        $upload_dir = (string) ( $upload["basedir"] ?? "" );
        $upload_ready = empty( $upload["error"] ) && "" !== $upload_dir && is_dir( $upload_dir ) && is_writable( $upload_dir );
        $acf_field = AcfAudioFieldResolver::fieldName( $settings );
        $checks = [
            [
                "id" => "wordpress_api_key",
                "label" => "WordPress API key",
                "status" => "" !== self::api_key() ? "pass" : "fail",
                "message" => "" !== self::api_key() ? "A Publish Scale site key is configured." : "The Publish Scale site key is missing.",
            ],
            [
                "id" => "media_storage",
                "label" => "Media Library storage",
                "status" => $upload_ready ? "pass" : "fail",
                "message" => $upload_ready ? "The WordPress uploads directory is writable." : "The WordPress uploads directory is not writable.",
            ],
            [
                "id" => "audio_field",
                "label" => "Audio field configuration",
                "status" => "" !== $acf_field ? "pass" : "fail",
                "message" => "" !== $acf_field ? "Generated audio will sync to " . $acf_field . "." : "No audio field is configured.",
            ],
        ];

        if ( $post_id > 0 ) {
            $post = get_post( $post_id );
            $supported = $post && in_array( get_post_type( $post_id ), [ "post", "press-release" ], true );
            $checks[] = [
                "id" => "article_post",
                "label" => "Article post",
                "status" => $supported ? "pass" : "fail",
                "message" => $supported ? "This article is supported by the integration." : "This post is not a supported article type.",
            ];

            $extract = $supported ? self::extract_post_text( $post_id ) : new WP_Error( "hexa_tts_unsupported_post", "Unsupported post type." );
            $characters = is_wp_error( $extract ) ? 0 : absint( $extract["characters"] ?? 0 );
            $checks[] = [
                "id" => "narration_text",
                "label" => "Narration text",
                "status" => $characters > 0 ? "pass" : "fail",
                "message" => $characters > 0
                    ? number_format_i18n( $characters ) . " narratable characters are available."
                    : ( is_wp_error( $extract ) ? $extract->get_error_message() : "No narratable article text was found." ),
            ];
        }

        return $checks;
    }

    public static function ajax_fetch_source_api_key() {
        try {
            $challenge = bin2hex( random_bytes( 32 ) );
        } catch ( \Throwable $exception ) {
            $challenge = hash( "sha256", wp_generate_password( 64, true, true ) . microtime( true ) . wp_rand() );
        }

        $transient = self::key_claim_transient_name( $challenge );
        if ( "" === $transient ) {
            wp_send_json_error( [ "message" => "Could not initialize secure domain verification." ], 500 );
        }

        $proof = hash_hmac( "sha256", $challenge, self::secret_key() );
        set_transient( $transient, $proof, 5 * MINUTE_IN_SECONDS );

        $response = wp_remote_post(
            self::API_BASE . "/site-key/fetch",
            [
                "timeout" => 20,
                "redirection" => 0,
                "sslverify" => true,
                "headers" => [
                    "Accept" => "application/json",
                    "Content-Type" => "application/json",
                    "Cache-Control" => "no-store",
                ],
                "body" => wp_json_encode(
                    [
                        "site_url" => home_url( "/" ),
                        "challenge_url" => rest_url( self::REST_NS . "/key-claim" ),
                        "challenge" => $challenge,
                    ]
                ),
            ]
        );

        delete_transient( $transient );

        if ( is_wp_error( $response ) ) {
            wp_send_json_error( [ "message" => $response->get_error_message() ], 502 );
        }

        $code = (int) wp_remote_retrieve_response_code( $response );
        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( $code < 200 || $code >= 300 || ! is_array( $body ) || empty( $body["success"] ) ) {
            $message = is_array( $body ) && ! empty( $body["message"] )
                ? sanitize_text_field( (string) $body["message"] )
                : "The source rejected this site's API-key claim.";
            wp_send_json_error( [ "message" => $message ], $code >= 400 && $code <= 599 ? $code : 422 );
        }

        $api_key = self::sanitize_secret( $body["api_key"] ?? "" );
        if ( strlen( $api_key ) < 20 || 0 !== strpos( $api_key, "pstts_" ) ) {
            wp_send_json_error( [ "message" => "The source returned an invalid site API key." ], 502 );
        }

        $existing = self::get_settings();
        $settings = $existing;
        $settings["api_key"] = self::encrypt_secret( $api_key );
        update_option( self::OPTION, $settings, false );

        $status = self::api_request( "/status", [], 20 );
        if ( is_wp_error( $status ) ) {
            update_option( self::OPTION, $existing, false );
            wp_send_json_error(
                [ "message" => "The assigned key was retrieved but failed validation, so the prior setting was restored: " . $status->get_error_message() ],
                502
            );
        }

        $settings["last_status"] = $status;
        update_option( self::OPTION, $settings, false );

        $site_domain = sanitize_text_field( (string) ( $status["site"]["domain"] ?? wp_parse_url( home_url( "/" ), PHP_URL_HOST ) ) );
        $provider_count = is_array( $status["available_apis"] ?? null ) ? count( $status["available_apis"] ) : 0;
        wp_send_json_success(
            [
                "message" => "Assigned API key fetched, encrypted, saved, and verified.",
                "details" => "Domain: " . $site_domain . " · Available APIs: " . $provider_count . " · Key: " . self::mask_secret( $api_key ),
                "masked_key" => self::mask_secret( $api_key ),
                "site" => $status["site"] ?? [],
                "status" => $status,
            ]
        );
    }

    private static function render_diagnostics_panel( int $post_id = 0 ): void {
        ?>
        <section class="hexa-tts-diagnostics" data-post-id="<?php echo esc_attr( $post_id ); ?>">
            <div class="hexa-tts-diagnostics-head">
                <div>
                    <h3>Text-to-speech diagnostics</h3>
                    <p>Check current provider credit availability or run the full integration checklist.</p>
                </div>
                <div class="hexa-tts-diagnostics-actions">
                    <button type="button" class="button button-secondary hexa-tts-check-credits">Check credits</button>
                    <button type="button" class="button button-secondary hexa-tts-check-health">Check health</button>
                </div>
            </div>
            <div class="hexa-tts-credit-result" aria-live="polite"></div>
            <ul class="hexa-tts-health-checklist" aria-live="polite" hidden></ul>
            <p class="hexa-tts-diagnostics-note">Credit and provider-health checks send one small production-sized synthesis probe because UnrealSpeech does not provide a read-only balance endpoint.</p>
        </section>
        <?php
    }
}
