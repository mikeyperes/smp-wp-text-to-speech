<?php

namespace smp_text_to_speech\Concerns;

use Smp\TextToSpeech\Support\AcfAudioFieldResolver;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

trait RendersPostEditor {
    private static function embedded_acf_audio_field( $post_id, $acf_field ) {
        $acf_value = get_post_meta( $post_id, $acf_field, true );
        $attachment_id = AcfAudioFieldResolver::attachmentIdFromValue( $acf_value );
        if ( ! $attachment_id ) {
            $attachment_id = (int) get_post_meta( $post_id, "_hexa_tts_attachment_id", true );
        }
        $field = [
            "key" => AcfAudioFieldResolver::renderFieldKey( $acf_field ),
            "label" => "Article Audio",
            "name" => $acf_field,
            "type" => "file",
            "instructions" => "Upload or select the audio file for this article. Generated TTS audio is saved here automatically.",
            "required" => 0,
            "value" => $attachment_id ?: "",
            "prefix" => "acf",
            "return_format" => "url",
            "library" => "all",
            "mime_types" => "mp3,m4a,wav,aac,ogg",
            "wrapper" => [ "width" => "", "class" => "hexa-tts-embedded-acf-file", "id" => "" ],
        ];
        echo '<div class="hexa-tts-embedded-acf">';
        \Hexa\PluginCore\Fields\Form::field( $field );
        echo '</div>';
    }

    public static function register_post_metabox() {
        foreach ( [ "post", "press-release" ] as $post_type ) {
            if ( post_type_exists( $post_type ) ) {
                add_meta_box( "hexa-tts-post-box", "Listen to this article", [ __CLASS__, "render_post_metabox" ], $post_type, "normal", "high" );
            }
        }
    }

    public static function render_post_metabox( $post ) {
        if ( class_exists( "\\Hexa\\PluginCore\\WpAdminComponents\\CoreUi" ) ) {
            \Hexa\PluginCore\WpAdminComponents\CoreUi::render_assets();
        }
        $settings = self::get_settings();
        $acf_field = AcfAudioFieldResolver::fieldName( $settings );
        $acf_value = get_post_meta( $post->ID, $acf_field, true );
        $audio_url = get_post_meta( $post->ID, "_hexa_tts_audio_url", true );
        $attachment_id = get_post_meta( $post->ID, "_hexa_tts_attachment_id", true );
        if ( ! $audio_url ) {
            $audio_url = self::resolve_audio_url( $acf_value );
        }
        if ( ! $attachment_id && is_numeric( $acf_value ) ) {
            $attachment_id = (int) $acf_value;
        }
        $status = get_post_meta( $post->ID, "_hexa_tts_status", true );
        $api_ready = "" !== self::api_key();
        $provider_label = trim( implode( " / ", array_filter( [ $settings["default_provider"] ?? "", $settings["default_voice"] ?? "" ] ) ) );
        ?>
        <div class="hexa-tts-postbox hexa-tts-postbox-simple hpc-ui" data-post-id="<?php echo esc_attr( $post->ID ); ?>" data-acf-field="<?php echo esc_attr( $acf_field ); ?>" data-has-audio="<?php echo $audio_url ? "1" : "0"; ?>" data-existing-attachment-id="<?php echo esc_attr( absint( $attachment_id ) ); ?>">
            <input type="hidden" class="hexa-tts-post-provider" value="<?php echo esc_attr( $settings["default_provider"] ); ?>">
            <input type="hidden" class="hexa-tts-post-profile" value="<?php echo esc_attr( $settings["default_profile"] ); ?>">
            <input type="hidden" class="hexa-tts-post-voice" value="<?php echo esc_attr( $settings["default_voice"] ); ?>">
            <input type="hidden" class="hexa-tts-post-speed" value="<?php echo esc_attr( $settings["default_speed"] ); ?>">

            <div class="hexa-tts-simple-status">
                <div><span class="hexa-tts-kicker">Connection</span><strong class="hexa-tts-api-state <?php echo $api_ready ? "is-ready" : "is-missing"; ?>"><?php echo $api_ready ? "API connected" : "Missing API key"; ?></strong></div>
                <div><span class="hexa-tts-kicker">Audio</span><strong class="hexa-tts-post-status"><?php echo esc_html( $status ?: ( $audio_url ? "Ready" : "Not generated" ) ); ?></strong></div>
                <div><span class="hexa-tts-kicker">ACF audio file</span><strong><?php echo esc_html( $acf_field ); ?></strong></div>
                <div><span class="hexa-tts-kicker">Credits</span><strong class="hexa-tts-credit-state is-unknown">Not checked</strong></div>
            </div>

            <?php self::render_diagnostics_panel( (int) $post->ID ); ?>

            <div class="hexa-tts-one-click-card">
                <div>
                    <h3>Use article text</h3>
                    <p>This uses the post title and body automatically. No copy, paste, or extra fields are required for the normal workflow.</p>
                    <?php if ( $provider_label ) : ?><p class="hexa-tts-muted">Default voice: <?php echo esc_html( $provider_label ); ?></p><?php endif; ?>
                </div>
                <button type="button" class="hpc-button hexa-tts-generate-post">Generate audio from article</button>
            </div>

            <div class="hexa-tts-acf-card">
                <div class="hexa-tts-acf-card-head">
                    <div>
                        <h3>Article Audio</h3>
                        <p>This is the actual ACF audio file field <code><?php echo esc_html( $acf_field ); ?></code>. Upload/select your own audio here, or generate audio above.</p>
                    </div>
                    <?php if ( $audio_url ) : ?><a href="<?php echo esc_url( $audio_url ); ?>" target="_blank" rel="noopener noreferrer">Open current MP3</a><?php endif; ?>
                </div>
                <?php self::embedded_acf_audio_field( $post->ID, $acf_field ); ?>
            </div>

            <div class="hexa-tts-storage-row">
                <span>Audio storage</span>
                <?php if ( $audio_url ) : ?>
                    <strong class="hexa-tts-storage-state">Saved to Media Library and ACF</strong>
                    <a class="hexa-tts-open-audio" href="<?php echo esc_url( $audio_url ); ?>" target="_blank" rel="noopener noreferrer">Open MP3</a>
                <?php else : ?>
                    <strong class="hexa-tts-storage-state">Will save to Media Library and <?php echo esc_html( $acf_field ); ?></strong>
                <?php endif; ?>
                <?php if ( $acf_value ) : ?><small class="hexa-tts-storage-note">Current <?php echo esc_html( $acf_field ); ?> value is set.</small><?php else : ?><small class="hexa-tts-storage-note">No value is currently stored in <?php echo esc_html( $acf_field ); ?>.</small><?php endif; ?>
            </div>

            <?php if ( $audio_url ) : ?>
                <audio controls preload="metadata" src="<?php echo esc_url( $audio_url ); ?>"></audio>
                <p class="hexa-tts-current-storage">Attachment ID: <?php echo esc_html( $attachment_id ?: "n/a" ); ?> · ACF audio file field: <?php echo esc_html( $acf_field ); ?></p>
            <?php endif; ?>

            <div class="hexa-tts-post-feedback" aria-live="polite"></div>
            <div class="hexa-tts-activity-log" aria-live="polite"></div>

            <details class="hexa-tts-advanced-box">
                <summary>Optional: preview or customize narration text</summary>
                <p>Leave this closed for the normal one-click flow. Open it only when the narration needs manual editing.</p>
                <div class="hexa-tts-post-actions hexa-tts-post-actions-left"><button type="button" class="button hexa-tts-extract-post">Import article text for editing</button></div>
                <div class="hexa-tts-grid hexa-tts-grid-2">
                    <label><span>Add before article</span><textarea class="hexa-tts-post-prepend" rows="2" placeholder="Optional intro"></textarea></label>
                    <label><span>Add after article</span><textarea class="hexa-tts-post-append" rows="2" placeholder="Optional outro"></textarea></label>
                </div>
                <label class="hexa-tts-check-row"><input type="checkbox" class="hexa-tts-post-shorten"><span>Shorten automatically if the article is over the limit</span></label>
                <label><span>Narration text</span><textarea class="hexa-tts-extracted-preview" placeholder="Click Import article text for editing, or leave blank to use the article automatically."></textarea></label>
            </details>
            <details class="hexa-tts-technical-box">
                <summary>Technical details</summary>
                <div class="hexa-tts-tech-grid">
                    <div><span>Shortcode</span><code>[smp_tts_player post_id="<?php echo esc_attr( $post->ID ); ?>" label="Listen to this article" enhanced_controls="1" preload="metadata"]</code><button type="button" class="button hexa-tts-copy" data-copy-text="<?php echo esc_attr( '[smp_tts_player post_id="' . $post->ID . '" label="Listen to this article" enhanced_controls="1" preload="metadata"]' ); ?>">Copy shortcode</button></div>
                    <div><span>Legacy shortcode</span><code>[hexa_tts_player post_id="<?php echo esc_attr( $post->ID ); ?>"]</code><button type="button" class="button hexa-tts-copy" data-copy-text="<?php echo esc_attr( '[hexa_tts_player post_id="' . $post->ID . '"]' ); ?>">Copy legacy</button></div>
                    <div><span>ACF field</span><code><?php echo esc_html( $acf_field ); ?></code></div>
                    <div><span>Attachment ID</span><code><?php echo esc_html( $attachment_id ?: 'none' ); ?></code></div>
                    <div><span>Audio URL</span><code><?php echo esc_html( $audio_url ?: 'none' ); ?></code></div>
                </div>
            </details>
        </div>
        <?php
    }

    public static function save_embedded_acf_audio( $post_id, $post ) {
        if ( wp_is_post_autosave( $post_id ) || wp_is_post_revision( $post_id ) ) {
            return;
        }
        if ( ! $post || ! in_array( $post->post_type, [ "post", "press-release" ], true ) ) {
            return;
        }
        if ( ! current_user_can( "edit_post", $post_id ) ) {
            return;
        }
        $settings = self::get_settings();
        $acf_field = AcfAudioFieldResolver::fieldName( $settings );
        $attachment_id = AcfAudioFieldResolver::postedAttachmentId( $acf_field );
        if ( ! $attachment_id ) {
            return;
        }
        $mime = (string) get_post_mime_type( $attachment_id );
        if ( 0 !== strpos( $mime, "audio/" ) ) {
            return;
        }
        $audio_url = wp_get_attachment_url( $attachment_id );
        if ( ! $audio_url ) {
            return;
        }
        $file_path = get_attached_file( $attachment_id );
        self::sync_audio_attachment( $post_id, $attachment_id, $audio_url, $file_path ?: "", [ "provider" => "manual_acf_upload", "cost_usd" => 0 ] );
        update_post_meta( $post_id, "_hexa_tts_status", "Ready" );
    }

    public static function ajax_save_manual_audio() {
        $post_id = absint( $_POST["post_id"] ?? 0 );
        $attachment_id = absint( $_POST["attachment_id"] ?? 0 );
        if ( ! $post_id || ! current_user_can( "edit_post", $post_id ) ) {
            wp_send_json_error( [ "message" => "You do not have permission to update audio for this post." ], 403 );
        }
        if ( ! $attachment_id ) {
            wp_send_json_error( [ "message" => "No audio attachment was selected." ], 400 );
        }
        $mime = (string) get_post_mime_type( $attachment_id );
        if ( 0 !== strpos( $mime, "audio/" ) ) {
            wp_send_json_error( [ "message" => "Selected file is not an audio attachment." ], 400 );
        }
        $audio_url = wp_get_attachment_url( $attachment_id );
        if ( ! $audio_url ) {
            wp_send_json_error( [ "message" => "Could not resolve selected audio URL." ], 400 );
        }
        $file_path = get_attached_file( $attachment_id );
        $stored = self::sync_audio_attachment( $post_id, $attachment_id, $audio_url, $file_path ?: "", [ "provider" => "manual_upload", "cost_usd" => 0 ] );
        update_post_meta( $post_id, "_hexa_tts_status", "Ready" );
        wp_send_json_success( array_merge( $stored, [ "message" => "Audio file saved to ACF and Media Library." ] ) );
    }

    public static function ajax_extract_post_content() {
        $post_id = absint( $_POST["post_id"] ?? 0 );
        if ( ! $post_id || ! current_user_can( "edit_post", $post_id ) ) {
            wp_send_json_error( [ "message" => "You do not have permission to extract this post." ], 403 );
        }
        $extract = self::extract_post_text( $post_id );
        if ( is_wp_error( $extract ) ) {
            wp_send_json_error( [ "message" => $extract->get_error_message() ] );
        }
        wp_send_json_success( $extract );
    }

    public static function ajax_generation_status() {
        $post_id = absint( $_POST["post_id"] ?? 0 );
        if ( ! $post_id || ! current_user_can( "edit_post", $post_id ) ) {
            wp_send_json_error( [ "message" => "You do not have permission to view generation status for this post." ], 403 );
        }

        $client_request_id = self::sanitize_client_request_id( wp_unslash( (string) ( $_POST["client_request_id"] ?? "" ) ) );
        $api_status = $client_request_id ? self::api_request_status( $client_request_id ) : null;

        wp_send_json_success( [
            "status" => (string) get_post_meta( $post_id, "_hexa_tts_status", true ),
            "log" => self::generation_log( $post_id ),
            "api_status" => $api_status,
            "client_request_id" => $client_request_id,
        ] );
    }
}
