<?php
/**
 * Plugin Name: HexaTextToSpeech
 * Plugin URI: https://code.hexawebsystems.com/manual-ai-reports/6/view
 * Description: In-house WordPress text-to-speech workflow with provider settings, AJAX key validation, post editor extraction, and one-click audio generation.
 * Version: 1.0
 * Author: Hexa Web Systems
 * Text Domain: smp-wordpress-text-to-speech
 * Requires at least: 6.0
 * Requires PHP: 7.4
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

final class HexaTextToSpeech {
    const VERSION = '1.0';
    const OPTION = 'hexa_tts_settings';
    const NONCE_ACTION = 'hexa_tts_admin_nonce';
    const SETTINGS_SLUG = 'hexa-text-to-speech';
    const AUDIO_DIR = 'hexa-text-to-speech';

    public static function init() {
        add_action( 'admin_menu', [ __CLASS__, 'register_admin_menu' ] );
        add_action( 'admin_enqueue_scripts', [ __CLASS__, 'enqueue_admin_assets' ] );
        add_action( 'admin_post_hexa_tts_save_settings', [ __CLASS__, 'handle_save_settings' ] );
        add_action( 'add_meta_boxes', [ __CLASS__, 'register_post_metabox' ] );
        add_action( 'wp_ajax_hexa_tts_validate_provider', [ __CLASS__, 'ajax_validate_provider' ] );
        add_action( 'wp_ajax_hexa_tts_extract_post_content', [ __CLASS__, 'ajax_extract_post_content' ] );
        add_action( 'wp_ajax_hexa_tts_generate_audio', [ __CLASS__, 'ajax_generate_audio' ] );
        add_filter( 'the_content', [ __CLASS__, 'maybe_insert_player' ], 12 );
        add_shortcode( 'hexa_tts_player', [ __CLASS__, 'render_player_shortcode' ] );
        register_activation_hook( __FILE__, [ __CLASS__, 'activate' ] );
    }

    public static function activate() {
        if ( ! get_option( self::OPTION ) ) {
            add_option( self::OPTION, self::default_settings(), '', false );
        }
    }

    public static function register_admin_menu() {
        add_options_page(
            'HexaTextToSpeech',
            'HexaTextToSpeech',
            'manage_options',
            self::SETTINGS_SLUG,
            [ __CLASS__, 'render_settings_page' ]
        );
    }

    public static function enqueue_admin_assets( $hook ) {
        $screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
        $is_settings = 'settings_page_' . self::SETTINGS_SLUG === $hook;
        $is_post = $screen && in_array( $screen->base, [ 'post' ], true );

        if ( ! $is_settings && ! $is_post ) {
            return;
        }

        wp_enqueue_style(
            'hexa-tts-admin',
            plugin_dir_url( __FILE__ ) . 'assets/admin.css',
            [],
            self::VERSION
        );

        wp_enqueue_script(
            'hexa-tts-admin',
            plugin_dir_url( __FILE__ ) . 'assets/admin.js',
            [ 'jquery' ],
            self::VERSION,
            true
        );

        $settings = self::get_settings();
        wp_localize_script(
            'hexa-tts-admin',
            'hexaTts',
            [
                'ajaxUrl' => admin_url( 'admin-ajax.php' ),
                'nonce' => wp_create_nonce( self::NONCE_ACTION ),
                'defaultProvider' => $settings['default_provider'],
                'defaultProfile' => $settings['default_profile'],
                'providers' => self::provider_choices(),
                'profiles' => self::profile_choices(),
            ]
        );
    }

    public static function provider_definitions() {
        return [
            'kokoro' => [
                'label' => 'Kokoro-82M Local Service',
                'summary' => 'Recommended in-house default. Use a private Kokoro HTTP service and keep per-character vendor cost at zero after compute.',
                'price' => 'Self-hosted compute only; hosted reference pricing was about $0.65 / 1M characters.',
                'docs' => [
                    [ 'Kokoro model card', 'https://huggingface.co/hexgrad/Kokoro-82M' ],
                    [ 'Kokoro GitHub', 'https://github.com/hexgrad/kokoro' ],
                ],
                'fields' => [
                    'service_url' => [ 'label' => 'Service URL', 'type' => 'url', 'default' => 'http://127.0.0.1:8880/synthesize', 'help' => 'Full synthesize endpoint or base URL. The plugin appends /synthesize when needed.' ],
                    'api_key' => [ 'label' => 'Bearer token', 'type' => 'password', 'secret' => true, 'default' => '', 'help' => 'Optional. Use if your local service requires Authorization: Bearer.' ],
                    'voice' => [ 'label' => 'Default voice', 'type' => 'text', 'default' => 'af_heart', 'help' => 'Example Kokoro voice. Adjust to the voices installed in your local service.' ],
                    'model' => [ 'label' => 'Model', 'type' => 'text', 'default' => 'kokoro-82m', 'help' => 'Internal model label sent to the service.' ],
                ],
                'instructions' => [
                    'Deploy Kokoro behind an internal HTTP endpoint, ideally on the same server or private network.',
                    'Expose GET /health and POST /synthesize if possible. POST /synthesize should accept JSON with text, voice, language, speed, and format.',
                    'Return audio bytes directly, or JSON with audio_base64 and mime_type.',
                ],
            ],
            'piper' => [
                'label' => 'Piper Local Service',
                'summary' => 'Fast local neural TTS fallback. Good for offline/local narration when simplicity matters more than premium realism.',
                'price' => 'Self-hosted compute only.',
                'docs' => [
                    [ 'Piper GitHub', 'https://github.com/rhasspy/piper' ],
                    [ 'Piper samples', 'https://rhasspy.github.io/piper-samples/' ],
                ],
                'fields' => [
                    'service_url' => [ 'label' => 'Service URL', 'type' => 'url', 'default' => 'http://127.0.0.1:5002/synthesize', 'help' => 'Full synthesize endpoint or base URL. The plugin appends /synthesize when needed.' ],
                    'api_key' => [ 'label' => 'Bearer token', 'type' => 'password', 'secret' => true, 'default' => '', 'help' => 'Optional bearer token for your Piper wrapper.' ],
                    'voice' => [ 'label' => 'Default voice', 'type' => 'text', 'default' => 'en_US-lessac-medium', 'help' => 'Installed Piper voice/model name.' ],
                    'model' => [ 'label' => 'Model', 'type' => 'text', 'default' => 'piper', 'help' => 'Internal model label sent to the service.' ],
                ],
                'instructions' => [
                    'Run Piper through a small HTTP wrapper because WordPress/PHP should not load the model directly.',
                    'Expose GET /health and POST /synthesize with the same contract as Kokoro.',
                    'Use this as a local fallback if Kokoro voice quality or hardware fit is not acceptable.',
                ],
            ],
            'amazon_polly' => [
                'label' => 'Amazon Polly',
                'summary' => 'Reliable cloud fallback with Standard, Neural, Generative, and Long-Form voices.',
                'price' => 'Standard $4 / 1M chars, Neural $16 / 1M chars, Generative $30 / 1M chars.',
                'docs' => [
                    [ 'Amazon Polly pricing', 'https://aws.amazon.com/polly/pricing/' ],
                    [ 'ListVoices API', 'https://docs.aws.amazon.com/polly/latest/dg/API_ListVoices.html' ],
                    [ 'AWS IAM access keys', 'https://docs.aws.amazon.com/IAM/latest/UserGuide/id_credentials_access-keys.html' ],
                ],
                'fields' => [
                    'access_key_id' => [ 'label' => 'Access key ID', 'type' => 'password', 'secret' => true, 'default' => '', 'help' => 'IAM access key with least-privilege polly:ListVoices and polly:SynthesizeSpeech.' ],
                    'secret_access_key' => [ 'label' => 'Secret access key', 'type' => 'password', 'secret' => true, 'default' => '', 'help' => 'IAM secret access key. Stored encrypted when OpenSSL is available.' ],
                    'region' => [ 'label' => 'AWS region', 'type' => 'text', 'default' => 'us-east-1', 'help' => 'Example: us-east-1.' ],
                    'voice' => [ 'label' => 'Voice ID', 'type' => 'text', 'default' => 'Joanna', 'help' => 'Example: Joanna, Matthew, Ruth, Stephen.' ],
                    'engine' => [ 'label' => 'Engine', 'type' => 'select', 'default' => 'neural', 'options' => [ 'standard' => 'standard', 'neural' => 'neural', 'generative' => 'generative', 'long-form' => 'long-form' ], 'help' => 'Voice support varies by region and voice.' ],
                ],
                'instructions' => [
                    'Create or reuse an AWS account, then create an IAM user or role with Polly permissions.',
                    'Minimum permissions for this plugin: polly:ListVoices for validation and polly:SynthesizeSpeech for generation.',
                    'Copy Access key ID and Secret access key into the fields above, then choose the region where Polly is enabled.',
                ],
            ],
            'google_tts' => [
                'label' => 'Google Cloud Text-to-Speech',
                'summary' => 'Cloud fallback with Standard/WaveNet/Neural2/Chirp voice families.',
                'price' => 'Standard/WaveNet $4 / 1M chars, Neural2 $16 / 1M chars, Chirp 3 HD $30 / 1M chars in the captured pricing table.',
                'docs' => [
                    [ 'Google TTS pricing', 'https://cloud.google.com/text-to-speech/pricing' ],
                    [ 'voices.list reference', 'https://cloud.google.com/text-to-speech/docs/reference/rest/v1/voices/list' ],
                    [ 'Google API credentials', 'https://console.cloud.google.com/apis/credentials' ],
                ],
                'fields' => [
                    'api_key' => [ 'label' => 'API key', 'type' => 'password', 'secret' => true, 'default' => '', 'help' => 'API key for a Google Cloud project with Text-to-Speech API enabled.' ],
                    'language' => [ 'label' => 'Language code', 'type' => 'text', 'default' => 'en-US', 'help' => 'BCP-47 language code, e.g. en-US.' ],
                    'voice' => [ 'label' => 'Voice name', 'type' => 'text', 'default' => 'en-US-Neural2-J', 'help' => 'Exact Google voice name.' ],
                    'speaking_rate' => [ 'label' => 'Speaking rate', 'type' => 'number', 'default' => '1.0', 'help' => 'Google accepts rates like 0.9, 1.0, 1.1.' ],
                ],
                'instructions' => [
                    'Open Google Cloud Console, create/select a project, and enable Cloud Text-to-Speech API.',
                    'Create an API key under APIs & Services > Credentials.',
                    'Restrict the key to Cloud Text-to-Speech API and your server IPs when possible.',
                ],
            ],
            'elevenlabs' => [
                'label' => 'ElevenLabs',
                'summary' => 'Premium/manual voice option. Strong voice quality, usually not the cheapest automatic bulk article provider.',
                'price' => 'Credit based. Free tier 10k credits/month; paid plans scale by credits.',
                'docs' => [
                    [ 'ElevenLabs authentication', 'https://elevenlabs.io/docs/api-reference/authentication' ],
                    [ 'ElevenLabs pricing', 'https://elevenlabs.io/pricing' ],
                    [ 'ElevenLabs API keys', 'https://elevenlabs.io/app/settings/api-keys' ],
                ],
                'fields' => [
                    'api_key' => [ 'label' => 'API key', 'type' => 'password', 'secret' => true, 'default' => '', 'help' => 'Sent as xi-api-key.' ],
                    'voice' => [ 'label' => 'Voice ID', 'type' => 'text', 'default' => '21m00Tcm4TlvDq8ikWAM', 'help' => 'Voice ID from ElevenLabs voice library.' ],
                    'model' => [ 'label' => 'Model ID', 'type' => 'text', 'default' => 'eleven_multilingual_v2', 'help' => 'Example: eleven_multilingual_v2 or a lower-cost Flash/Turbo model if enabled.' ],
                    'stability' => [ 'label' => 'Stability', 'type' => 'number', 'default' => '0.45', 'help' => 'Voice setting from 0 to 1.' ],
                    'similarity_boost' => [ 'label' => 'Similarity boost', 'type' => 'number', 'default' => '0.75', 'help' => 'Voice setting from 0 to 1.' ],
                ],
                'instructions' => [
                    'Create an ElevenLabs account and open Settings > API Keys.',
                    'Create a key with Text to Speech access and an appropriate credit limit.',
                    'Copy the key and the desired Voice ID into this page. Keep bulk auto-generation disabled unless cost is approved.',
                ],
            ],
            'deepgram' => [
                'label' => 'Deepgram Aura',
                'summary' => 'Low-latency TTS option. Good for real-time voice products and also usable for article audio.',
                'price' => 'Aura-1 $15 / 1M chars; Aura-2 $30 / 1M chars on captured pricing.',
                'docs' => [
                    [ 'Deepgram authentication', 'https://developers.deepgram.com/docs/authenticating' ],
                    [ 'Deepgram TTS request', 'https://developers.deepgram.com/reference/text-to-speech-api' ],
                    [ 'Deepgram console', 'https://console.deepgram.com/' ],
                ],
                'fields' => [
                    'api_key' => [ 'label' => 'API key', 'type' => 'password', 'secret' => true, 'default' => '', 'help' => 'Sent as Authorization: Token.' ],
                    'model' => [ 'label' => 'Model', 'type' => 'text', 'default' => 'aura-2-thalia-en', 'help' => 'Example: aura-2-thalia-en.' ],
                    'voice' => [ 'label' => 'Voice/profile note', 'type' => 'text', 'default' => 'thalia', 'help' => 'Deepgram voice is normally embedded in the model name.' ],
                    'speed' => [ 'label' => 'Speed', 'type' => 'number', 'default' => '1.0', 'help' => 'Supported speed range depends on model.' ],
                ],
                'instructions' => [
                    'Create a Deepgram account and open the Console.',
                    'Create an API key for the project.',
                    'The validator calls /v1/auth/token, which Deepgram documents as a key validation endpoint.',
                ],
            ],
            'cartesia' => [
                'label' => 'Cartesia Sonic',
                'summary' => 'Modern voice API with Sonic models. Useful for realtime/premium tests and article generation.',
                'price' => 'Plan/minute oriented pricing; not the first default for bulk cached articles.',
                'docs' => [
                    [ 'Cartesia List Voices', 'https://docs.cartesia.ai/api-reference/voices/list' ],
                    [ 'Cartesia TTS bytes', 'https://docs.cartesia.ai/api-reference/tts/bytes' ],
                    [ 'Cartesia API keys', 'https://play.cartesia.ai/keys' ],
                ],
                'fields' => [
                    'api_key' => [ 'label' => 'API key', 'type' => 'password', 'secret' => true, 'default' => '', 'help' => 'Cartesia API key, sent as Authorization: Bearer.' ],
                    'version' => [ 'label' => 'Cartesia-Version', 'type' => 'text', 'default' => '2026-03-01', 'help' => 'Required version header.' ],
                    'model' => [ 'label' => 'Model ID', 'type' => 'text', 'default' => 'sonic-3.5', 'help' => 'Example: sonic-3.5.' ],
                    'voice' => [ 'label' => 'Voice ID', 'type' => 'text', 'default' => '', 'help' => 'Required voice UUID from Cartesia voices.' ],
                    'speed' => [ 'label' => 'Speed', 'type' => 'number', 'default' => '1.0', 'help' => 'Generation config speed.' ],
                ],
                'instructions' => [
                    'Open Cartesia Playground > API Keys and create a key.',
                    'Use List Voices to find the voice UUID you want.',
                    'Keep Cartesia-Version set to the current documented API version unless upgrading deliberately.',
                ],
            ],
        ];
    }

    public static function default_settings() {
        $providers = [];
        foreach ( self::provider_definitions() as $provider_id => $provider ) {
            foreach ( $provider['fields'] as $field_id => $field ) {
                $providers[ $provider_id ][ $field_id ] = $field['default'] ?? '';
            }
        }

        return [
            'default_provider' => 'kokoro',
            'default_profile' => 'default',
            'auto_insert_player' => 1,
            'include_title' => 1,
            'max_characters' => 20000,
            'providers' => $providers,
            'profiles' => [
                'default' => [
                    'label' => 'Default Article Narration',
                    'provider' => 'kokoro',
                    'voice' => 'af_heart',
                    'model' => 'kokoro-82m',
                    'language' => 'en-US',
                    'speed' => '1.0',
                ],
                'local' => [
                    'label' => 'Local Low-Cost',
                    'provider' => 'kokoro',
                    'voice' => 'af_heart',
                    'model' => 'kokoro-82m',
                    'language' => 'en-US',
                    'speed' => '1.0',
                ],
                'premium' => [
                    'label' => 'Premium Manual',
                    'provider' => 'elevenlabs',
                    'voice' => '21m00Tcm4TlvDq8ikWAM',
                    'model' => 'eleven_multilingual_v2',
                    'language' => 'en-US',
                    'speed' => '1.0',
                ],
            ],
        ];
    }

    public static function get_settings() {
        $stored = get_option( self::OPTION, [] );
        return self::merge_settings( self::default_settings(), is_array( $stored ) ? $stored : [] );
    }

    private static function merge_settings( array $defaults, array $stored ) {
        foreach ( $stored as $key => $value ) {
            if ( is_array( $value ) && isset( $defaults[ $key ] ) && is_array( $defaults[ $key ] ) ) {
                $defaults[ $key ] = self::merge_settings( $defaults[ $key ], $value );
            } else {
                $defaults[ $key ] = $value;
            }
        }

        return $defaults;
    }

    public static function provider_choices() {
        $choices = [];
        foreach ( self::provider_definitions() as $id => $provider ) {
            $choices[ $id ] = $provider['label'];
        }
        return $choices;
    }

    public static function profile_choices() {
        $settings = self::get_settings();
        $choices = [];
        foreach ( $settings['profiles'] as $id => $profile ) {
            $choices[ $id ] = $profile['label'] ?: $id;
        }
        return $choices;
    }

    public static function render_settings_page() {
        if ( ! current_user_can( 'manage_options' ) ) {
            return;
        }

        $settings = self::get_settings();
        $providers = self::provider_definitions();
        $saved = isset( $_GET['hexa_tts_saved'] ) && '1' === $_GET['hexa_tts_saved'];
        ?>
        <div class="wrap hexa-tts-wrap">
            <div class="hexa-tts-page-head">
                <div>
                    <h1>HexaTextToSpeech</h1>
                    <p>Provider credentials, validation, defaults, and editor workflow for WordPress article narration.</p>
                </div>
                <a class="button button-secondary" href="https://code.hexawebsystems.com/manual-ai-reports/6/view" target="_blank" rel="noopener noreferrer">Open research report</a>
            </div>

            <?php if ( $saved ) : ?>
                <div class="notice notice-success is-dismissible"><p>HexaTextToSpeech settings saved.</p></div>
            <?php endif; ?>

            <form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="hexa-tts-settings-form">
                <?php wp_nonce_field( self::NONCE_ACTION, 'hexa_tts_nonce' ); ?>
                <input type="hidden" name="action" value="hexa_tts_save_settings">

                <section class="hexa-tts-panel">
                    <div class="hexa-tts-panel-head">
                        <h2>Defaults</h2>
                        <p>These defaults power the one-click process in post.php.</p>
                    </div>
                    <div class="hexa-tts-grid hexa-tts-grid-4">
                        <label>
                            <span>Default service</span>
                            <select name="hexa_tts[default_provider]">
                                <?php foreach ( self::provider_choices() as $provider_id => $label ) : ?>
                                    <option value="<?php echo esc_attr( $provider_id ); ?>" <?php selected( $settings['default_provider'], $provider_id ); ?>><?php echo esc_html( $label ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Default profile</span>
                            <select name="hexa_tts[default_profile]">
                                <?php foreach ( $settings['profiles'] as $profile_id => $profile ) : ?>
                                    <option value="<?php echo esc_attr( $profile_id ); ?>" <?php selected( $settings['default_profile'], $profile_id ); ?>><?php echo esc_html( $profile['label'] ); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </label>
                        <label>
                            <span>Max characters per single generation</span>
                            <input type="number" name="hexa_tts[max_characters]" value="<?php echo esc_attr( $settings['max_characters'] ); ?>" min="500" step="500">
                        </label>
                        <label class="hexa-tts-check-row">
                            <input type="checkbox" name="hexa_tts[auto_insert_player]" value="1" <?php checked( ! empty( $settings['auto_insert_player'] ) ); ?>>
                            <span>Auto-insert player on posts</span>
                        </label>
                        <label class="hexa-tts-check-row">
                            <input type="checkbox" name="hexa_tts[include_title]" value="1" <?php checked( ! empty( $settings['include_title'] ) ); ?>>
                            <span>Include post title in extracted narration</span>
                        </label>
                    </div>
                </section>

                <section class="hexa-tts-panel">
                    <div class="hexa-tts-panel-head">
                        <h2>Profiles</h2>
                        <p>Profiles let the editor choose a working preset without changing global API settings.</p>
                    </div>
                    <div class="hexa-tts-profile-grid">
                        <?php foreach ( $settings['profiles'] as $profile_id => $profile ) : ?>
                            <div class="hexa-tts-profile-card">
                                <h3><?php echo esc_html( ucfirst( $profile_id ) ); ?></h3>
                                <label>
                                    <span>Label</span>
                                    <input type="text" name="hexa_tts[profiles][<?php echo esc_attr( $profile_id ); ?>][label]" value="<?php echo esc_attr( $profile['label'] ); ?>">
                                </label>
                                <label>
                                    <span>Provider</span>
                                    <select name="hexa_tts[profiles][<?php echo esc_attr( $profile_id ); ?>][provider]">
                                        <?php foreach ( self::provider_choices() as $provider_id => $label ) : ?>
                                            <option value="<?php echo esc_attr( $provider_id ); ?>" <?php selected( $profile['provider'], $provider_id ); ?>><?php echo esc_html( $label ); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </label>
                                <label><span>Voice</span><input type="text" name="hexa_tts[profiles][<?php echo esc_attr( $profile_id ); ?>][voice]" value="<?php echo esc_attr( $profile['voice'] ); ?>"></label>
                                <label><span>Model</span><input type="text" name="hexa_tts[profiles][<?php echo esc_attr( $profile_id ); ?>][model]" value="<?php echo esc_attr( $profile['model'] ); ?>"></label>
                                <label><span>Language</span><input type="text" name="hexa_tts[profiles][<?php echo esc_attr( $profile_id ); ?>][language]" value="<?php echo esc_attr( $profile['language'] ); ?>"></label>
                                <label><span>Speed</span><input type="number" step="0.05" name="hexa_tts[profiles][<?php echo esc_attr( $profile_id ); ?>][speed]" value="<?php echo esc_attr( $profile['speed'] ); ?>"></label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>

                <section class="hexa-tts-panel">
                    <div class="hexa-tts-panel-head">
                        <h2>API Services</h2>
                        <p>Each provider has saved credentials, masked status, AJAX validation, and setup instructions.</p>
                    </div>

                    <div class="hexa-tts-provider-stack">
                        <?php foreach ( $providers as $provider_id => $provider ) : ?>
                            <?php $values = self::get_provider_settings( $provider_id, false ); ?>
                            <article class="hexa-tts-provider-card" data-provider-card="<?php echo esc_attr( $provider_id ); ?>">
                                <div class="hexa-tts-provider-title">
                                    <div>
                                        <h3><?php echo esc_html( $provider['label'] ); ?></h3>
                                        <p><?php echo esc_html( $provider['summary'] ); ?></p>
                                    </div>
                                    <button type="button" class="button hexa-tts-test-provider" data-provider="<?php echo esc_attr( $provider_id ); ?>">Test credentials</button>
                                </div>
                                <div class="hexa-tts-price"><?php echo esc_html( $provider['price'] ); ?></div>
                                <div class="hexa-tts-provider-fields">
                                    <?php foreach ( $provider['fields'] as $field_id => $field ) : ?>
                                        <?php
                                        $raw_value = $values[ $field_id ] ?? '';
                                        $display_value = ! empty( $field['secret'] ) ? '' : $raw_value;
                                        $mask = ! empty( $field['secret'] ) ? self::mask_secret( self::decrypt_secret( $raw_value ) ) : '';
                                        ?>
                                        <label>
                                            <span><?php echo esc_html( $field['label'] ); ?></span>
                                            <?php if ( 'select' === ( $field['type'] ?? 'text' ) ) : ?>
                                                <select name="hexa_tts[providers][<?php echo esc_attr( $provider_id ); ?>][<?php echo esc_attr( $field_id ); ?>]" data-field="<?php echo esc_attr( $field_id ); ?>">
                                                    <?php foreach ( $field['options'] as $option_value => $option_label ) : ?>
                                                        <option value="<?php echo esc_attr( $option_value ); ?>" <?php selected( $raw_value, $option_value ); ?>><?php echo esc_html( $option_label ); ?></option>
                                                    <?php endforeach; ?>
                                                </select>
                                            <?php else : ?>
                                                <input
                                                    type="<?php echo esc_attr( $field['type'] ?? 'text' ); ?>"
                                                    name="hexa_tts[providers][<?php echo esc_attr( $provider_id ); ?>][<?php echo esc_attr( $field_id ); ?>]"
                                                    data-field="<?php echo esc_attr( $field_id ); ?>"
                                                    value="<?php echo esc_attr( $display_value ); ?>"
                                                    placeholder="<?php echo esc_attr( $mask ? 'Saved: ' . $mask : ( $field['default'] ?? '' ) ); ?>"
                                                    <?php echo 'number' === ( $field['type'] ?? '' ) ? 'step="0.05"' : ''; ?>
                                                >
                                            <?php endif; ?>
                                            <?php if ( $mask ) : ?>
                                                <small class="hexa-tts-saved-secret">Saved credential: <?php echo esc_html( $mask ); ?></small>
                                            <?php endif; ?>
                                            <small><?php echo esc_html( $field['help'] ?? '' ); ?></small>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                                <div class="hexa-tts-test-result" data-provider-result="<?php echo esc_attr( $provider_id ); ?>" aria-live="polite"></div>
                                <details class="hexa-tts-instructions">
                                    <summary>Setup instructions and links</summary>
                                    <ol>
                                        <?php foreach ( $provider['instructions'] as $instruction ) : ?>
                                            <li><?php echo esc_html( $instruction ); ?></li>
                                        <?php endforeach; ?>
                                    </ol>
                                    <div class="hexa-tts-doc-links">
                                        <?php foreach ( $provider['docs'] as $doc ) : ?>
                                            <a href="<?php echo esc_url( $doc[1] ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( $doc[0] ); ?></a>
                                        <?php endforeach; ?>
                                    </div>
                                </details>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </section>

                <p class="submit hexa-tts-submit">
                    <button type="submit" class="button button-primary button-hero">Save HexaTextToSpeech settings</button>
                </p>
            </form>
        </div>
        <?php
    }

    public static function handle_save_settings() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_die( 'Unauthorized.' );
        }

        check_admin_referer( self::NONCE_ACTION, 'hexa_tts_nonce' );
        $incoming = isset( $_POST['hexa_tts'] ) && is_array( $_POST['hexa_tts'] ) ? wp_unslash( $_POST['hexa_tts'] ) : [];
        $settings = self::sanitize_settings( $incoming, self::get_settings() );
        update_option( self::OPTION, $settings, false );

        wp_safe_redirect( add_query_arg( [ 'page' => self::SETTINGS_SLUG, 'hexa_tts_saved' => '1' ], admin_url( 'options-general.php' ) ) );
        exit;
    }

    private static function sanitize_settings( array $incoming, array $existing ) {
        $providers = self::provider_definitions();
        $clean = self::default_settings();
        $clean['default_provider'] = self::valid_provider_id( $incoming['default_provider'] ?? $existing['default_provider'] );
        $clean['default_profile'] = sanitize_key( $incoming['default_profile'] ?? $existing['default_profile'] );
        $clean['auto_insert_player'] = empty( $incoming['auto_insert_player'] ) ? 0 : 1;
        $clean['include_title'] = empty( $incoming['include_title'] ) ? 0 : 1;
        $clean['max_characters'] = max( 500, absint( $incoming['max_characters'] ?? $existing['max_characters'] ) );

        foreach ( $providers as $provider_id => $provider ) {
            foreach ( $provider['fields'] as $field_id => $field ) {
                $current = $existing['providers'][ $provider_id ][ $field_id ] ?? '';
                $value = $incoming['providers'][ $provider_id ][ $field_id ] ?? '';

                if ( ! empty( $field['secret'] ) ) {
                    $value = self::sanitize_secret( $value );
                    $clean['providers'][ $provider_id ][ $field_id ] = '' === $value ? $current : self::encrypt_secret( $value );
                    continue;
                }

                if ( 'url' === ( $field['type'] ?? '' ) ) {
                    $clean['providers'][ $provider_id ][ $field_id ] = esc_url_raw( trim( (string) $value ) );
                } elseif ( 'number' === ( $field['type'] ?? '' ) ) {
                    $clean['providers'][ $provider_id ][ $field_id ] = (string) floatval( $value );
                } else {
                    $clean['providers'][ $provider_id ][ $field_id ] = sanitize_text_field( (string) $value );
                }
            }
        }

        $profiles = is_array( $incoming['profiles'] ?? null ) ? $incoming['profiles'] : [];
        foreach ( $clean['profiles'] as $profile_id => $profile ) {
            $posted = $profiles[ $profile_id ] ?? [];
            $clean['profiles'][ $profile_id ] = [
                'label' => sanitize_text_field( $posted['label'] ?? $profile['label'] ),
                'provider' => self::valid_provider_id( $posted['provider'] ?? $profile['provider'] ),
                'voice' => sanitize_text_field( $posted['voice'] ?? $profile['voice'] ),
                'model' => sanitize_text_field( $posted['model'] ?? $profile['model'] ),
                'language' => sanitize_text_field( $posted['language'] ?? $profile['language'] ),
                'speed' => (string) floatval( $posted['speed'] ?? $profile['speed'] ),
            ];
        }

        if ( ! isset( $clean['profiles'][ $clean['default_profile'] ] ) ) {
            $clean['default_profile'] = 'default';
        }

        return $clean;
    }

    private static function valid_provider_id( $provider_id ) {
        $provider_id = sanitize_key( (string) $provider_id );
        return isset( self::provider_definitions()[ $provider_id ] ) ? $provider_id : 'kokoro';
    }

    private static function sanitize_secret( $value ) {
        $value = trim( (string) $value );
        return preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
    }

    private static function secret_key() {
        $material = ( defined( 'AUTH_KEY' ) ? AUTH_KEY : '' ) . ( defined( 'SECURE_AUTH_KEY' ) ? SECURE_AUTH_KEY : '' ) . ( defined( 'LOGGED_IN_KEY' ) ? LOGGED_IN_KEY : '' );
        if ( '' === $material ) {
            $material = wp_salt( 'auth' );
        }
        return hash( 'sha256', $material, true );
    }

    private static function encrypt_secret( $value ) {
        if ( '' === $value || ! function_exists( 'openssl_encrypt' ) ) {
            return $value;
        }

        $iv = function_exists( 'random_bytes' ) ? random_bytes( 16 ) : substr( hash( 'sha256', wp_rand() . microtime( true ), true ), 0, 16 );
        $cipher = openssl_encrypt( $value, 'AES-256-CBC', self::secret_key(), OPENSSL_RAW_DATA, $iv );

        if ( false === $cipher ) {
            return $value;
        }

        return 'enc:' . base64_encode( $iv . $cipher );
    }

    private static function decrypt_secret( $value ) {
        if ( ! is_string( $value ) || 0 !== strpos( $value, 'enc:' ) || ! function_exists( 'openssl_decrypt' ) ) {
            return (string) $value;
        }

        $raw = base64_decode( substr( $value, 4 ), true );
        if ( false === $raw || strlen( $raw ) < 17 ) {
            return '';
        }

        $iv = substr( $raw, 0, 16 );
        $cipher = substr( $raw, 16 );
        $plain = openssl_decrypt( $cipher, 'AES-256-CBC', self::secret_key(), OPENSSL_RAW_DATA, $iv );
        return false === $plain ? '' : $plain;
    }

    private static function mask_secret( $value ) {
        $value = (string) $value;
        if ( '' === $value ) {
            return '';
        }
        $last = substr( $value, -4 );
        return str_repeat( '*', min( 8, max( 4, strlen( $value ) - 4 ) ) ) . $last;
    }

    private static function get_provider_settings( $provider_id, $decrypt = true ) {
        $settings = self::get_settings();
        $values = $settings['providers'][ $provider_id ] ?? [];
        if ( ! $decrypt ) {
            return $values;
        }

        $defs = self::provider_definitions()[ $provider_id ]['fields'] ?? [];
        foreach ( $defs as $field_id => $field ) {
            if ( ! empty( $field['secret'] ) ) {
                $values[ $field_id ] = self::decrypt_secret( $values[ $field_id ] ?? '' );
            }
        }
        return $values;
    }

    private static function provider_values_for_request( $provider_id, $posted_fields = [] ) {
        $values = self::get_provider_settings( $provider_id, true );
        $defs = self::provider_definitions()[ $provider_id ]['fields'] ?? [];
        $posted_fields = is_array( $posted_fields ) ? wp_unslash( $posted_fields ) : [];

        foreach ( $defs as $field_id => $field ) {
            if ( ! array_key_exists( $field_id, $posted_fields ) ) {
                continue;
            }
            $posted = ! empty( $field['secret'] ) ? self::sanitize_secret( $posted_fields[ $field_id ] ) : sanitize_text_field( (string) $posted_fields[ $field_id ] );
            if ( '' !== $posted ) {
                $values[ $field_id ] = $posted;
            }
        }

        return $values;
    }

    public static function ajax_validate_provider() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( [ 'message' => 'You do not have permission to validate provider credentials.' ], 403 );
        }
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );

        $provider_id = self::valid_provider_id( $_POST['provider'] ?? '' );
        $fields = self::provider_values_for_request( $provider_id, $_POST['fields'] ?? [] );
        $result = self::validate_provider( $provider_id, $fields );

        if ( is_wp_error( $result ) ) {
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    private static function validate_provider( $provider_id, array $fields ) {
        switch ( $provider_id ) {
            case 'kokoro':
            case 'piper':
                return self::validate_local_service( $fields );
            case 'amazon_polly':
                return self::validate_amazon_polly( $fields );
            case 'google_tts':
                return self::validate_google_tts( $fields );
            case 'elevenlabs':
                return self::validate_elevenlabs( $fields );
            case 'deepgram':
                return self::validate_deepgram( $fields );
            case 'cartesia':
                return self::validate_cartesia( $fields );
        }

        return new WP_Error( 'hexa_tts_unknown_provider', 'Unknown provider.' );
    }

    private static function validate_local_service( array $fields ) {
        $base = self::base_service_url( $fields['service_url'] ?? '' );
        if ( '' === $base ) {
            return new WP_Error( 'hexa_tts_missing_url', 'Missing local service URL.' );
        }

        $headers = [];
        if ( ! empty( $fields['api_key'] ) ) {
            $headers['Authorization'] = 'Bearer ' . $fields['api_key'];
        }

        foreach ( [ '/health', '/voices', '' ] as $path ) {
            $response = wp_remote_get( rtrim( $base, '/' ) . $path, [ 'timeout' => 12, 'headers' => $headers ] );
            if ( is_wp_error( $response ) ) {
                continue;
            }
            $code = wp_remote_retrieve_response_code( $response );
            if ( $code >= 200 && $code < 300 ) {
                return [
                    'message' => 'Local service responded successfully at ' . rtrim( $base, '/' ) . $path . '.',
                    'details' => 'HTTP ' . $code,
                ];
            }
        }

        return new WP_Error( 'hexa_tts_local_unreachable', 'Local TTS service did not respond successfully. Confirm the URL and service health endpoint.' );
    }

    private static function validate_amazon_polly( array $fields ) {
        foreach ( [ 'access_key_id', 'secret_access_key', 'region' ] as $required ) {
            if ( empty( $fields[ $required ] ) ) {
                return new WP_Error( 'hexa_tts_missing_aws', 'Missing AWS ' . str_replace( '_', ' ', $required ) . '.' );
            }
        }

        $response = self::aws_polly_request(
            'GET',
            $fields['region'],
            '/v1/voices',
            [ 'LanguageCode' => 'en-US' ],
            '',
            $fields['access_key_id'],
            $fields['secret_access_key']
        );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $count = isset( $body['Voices'] ) && is_array( $body['Voices'] ) ? count( $body['Voices'] ) : 0;
        return [
            'message' => 'Amazon Polly credentials validated.',
            'details' => $count ? $count . ' English voice(s) returned.' : 'ListVoices returned successfully.',
        ];
    }

    private static function validate_google_tts( array $fields ) {
        if ( empty( $fields['api_key'] ) ) {
            return new WP_Error( 'hexa_tts_missing_google', 'Missing Google API key.' );
        }

        $url = add_query_arg(
            [
                'key' => $fields['api_key'],
                'languageCode' => $fields['language'] ?: 'en-US',
            ],
            'https://texttospeech.googleapis.com/v1/voices'
        );
        $response = wp_remote_get( $url, [ 'timeout' => 15 ] );
        $error = self::response_error( $response );
        if ( $error ) {
            return $error;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        $count = isset( $body['voices'] ) && is_array( $body['voices'] ) ? count( $body['voices'] ) : 0;
        return [
            'message' => 'Google Cloud Text-to-Speech API key validated.',
            'details' => $count ? $count . ' voice(s) returned.' : 'voices.list returned successfully.',
        ];
    }

    private static function validate_elevenlabs( array $fields ) {
        if ( empty( $fields['api_key'] ) ) {
            return new WP_Error( 'hexa_tts_missing_elevenlabs', 'Missing ElevenLabs API key.' );
        }

        $response = wp_remote_get(
            'https://api.elevenlabs.io/v1/models',
            [
                'timeout' => 15,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'xi-api-key' => $fields['api_key'],
                ],
            ]
        );
        $error = self::response_error( $response );
        if ( $error ) {
            return $error;
        }

        $body = json_decode( wp_remote_retrieve_body( $response ), true );
        return [
            'message' => 'ElevenLabs API key validated.',
            'details' => is_array( $body ) ? count( $body ) . ' model record(s) returned.' : 'Models endpoint returned successfully.',
        ];
    }

    private static function validate_deepgram( array $fields ) {
        if ( empty( $fields['api_key'] ) ) {
            return new WP_Error( 'hexa_tts_missing_deepgram', 'Missing Deepgram API key.' );
        }

        $response = wp_remote_get(
            'https://api.deepgram.com/v1/auth/token',
            [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Token ' . $fields['api_key'],
                ],
            ]
        );
        $error = self::response_error( $response );
        if ( $error ) {
            return $error;
        }

        return [
            'message' => 'Deepgram API key validated.',
            'details' => 'The documented /v1/auth/token validation endpoint returned successfully.',
        ];
    }

    private static function validate_cartesia( array $fields ) {
        if ( empty( $fields['api_key'] ) ) {
            return new WP_Error( 'hexa_tts_missing_cartesia', 'Missing Cartesia API key.' );
        }

        $response = wp_remote_get(
            add_query_arg( [ 'limit' => 1 ], 'https://api.cartesia.ai/voices' ),
            [
                'timeout' => 15,
                'headers' => [
                    'Authorization' => 'Bearer ' . $fields['api_key'],
                    'Cartesia-Version' => $fields['version'] ?: '2026-03-01',
                ],
            ]
        );
        $error = self::response_error( $response );
        if ( $error ) {
            return $error;
        }

        return [
            'message' => 'Cartesia API key validated.',
            'details' => 'Voices endpoint returned successfully.',
        ];
    }

    private static function response_error( $response ) {
        if ( is_wp_error( $response ) ) {
            return $response;
        }

        $code = wp_remote_retrieve_response_code( $response );
        if ( $code >= 200 && $code < 300 ) {
            return null;
        }

        $body = trim( wp_remote_retrieve_body( $response ) );
        $message = 'Provider returned HTTP ' . $code . '.';
        if ( '' !== $body ) {
            $decoded = json_decode( $body, true );
            if ( is_array( $decoded ) ) {
                $message .= ' ' . wp_json_encode( $decoded );
            } else {
                $message .= ' ' . substr( wp_strip_all_tags( $body ), 0, 300 );
            }
        }

        return new WP_Error( 'hexa_tts_provider_http_error', $message );
    }

    public static function register_post_metabox() {
        $post_types = get_post_types( [ 'public' => true ], 'names' );
        foreach ( $post_types as $post_type ) {
            add_meta_box(
                'hexa-tts-post-box',
                'HexaTextToSpeech',
                [ __CLASS__, 'render_post_metabox' ],
                $post_type,
                'normal',
                'high'
            );
        }
    }

    public static function render_post_metabox( $post ) {
        $settings = self::get_settings();
        $audio_url = get_post_meta( $post->ID, '_hexa_tts_audio_url', true );
        $status = get_post_meta( $post->ID, '_hexa_tts_status', true );
        $provider = get_post_meta( $post->ID, '_hexa_tts_provider', true ) ?: $settings['default_provider'];
        $profile = get_post_meta( $post->ID, '_hexa_tts_profile', true ) ?: $settings['default_profile'];
        ?>
        <div class="hexa-tts-postbox" data-post-id="<?php echo esc_attr( $post->ID ); ?>">
            <div class="hexa-tts-postbox-top">
                <div>
                    <strong>Status:</strong>
                    <span class="hexa-tts-post-status"><?php echo esc_html( $status ?: 'Not generated' ); ?></span>
                </div>
                <?php if ( $audio_url ) : ?>
                    <a href="<?php echo esc_url( $audio_url ); ?>" target="_blank" rel="noopener noreferrer">Open current audio</a>
                <?php endif; ?>
            </div>

            <div class="hexa-tts-editor-grid">
                <label>
                    <span>Profile</span>
                    <select class="hexa-tts-post-profile">
                        <?php foreach ( $settings['profiles'] as $profile_id => $profile_data ) : ?>
                            <option value="<?php echo esc_attr( $profile_id ); ?>" <?php selected( $profile, $profile_id ); ?>><?php echo esc_html( $profile_data['label'] ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label>
                    <span>Service override</span>
                    <select class="hexa-tts-post-provider">
                        <?php foreach ( self::provider_choices() as $provider_id => $label ) : ?>
                            <option value="<?php echo esc_attr( $provider_id ); ?>" <?php selected( $provider, $provider_id ); ?>><?php echo esc_html( $label ); ?></option>
                        <?php endforeach; ?>
                    </select>
                </label>
                <label><span>Voice override</span><input type="text" class="hexa-tts-post-voice" placeholder="Use profile/provider default"></label>
                <label><span>Model override</span><input type="text" class="hexa-tts-post-model" placeholder="Use profile/provider default"></label>
                <label><span>Language override</span><input type="text" class="hexa-tts-post-language" placeholder="Use profile/provider default"></label>
                <label><span>Speed override</span><input type="number" step="0.05" class="hexa-tts-post-speed" placeholder="1.0"></label>
            </div>

            <div class="hexa-tts-post-actions">
                <button type="button" class="button hexa-tts-extract-post">Extract content</button>
                <button type="button" class="button button-primary hexa-tts-generate-post">One-click generate audio</button>
            </div>

            <div class="hexa-tts-post-feedback" aria-live="polite"></div>
            <textarea class="hexa-tts-extracted-preview" readonly placeholder="Extracted narration text preview appears here after AJAX extraction."></textarea>

            <?php if ( $audio_url ) : ?>
                <audio controls preload="none" src="<?php echo esc_url( $audio_url ); ?>"></audio>
            <?php endif; ?>
        </div>
        <?php
    }

    public static function ajax_extract_post_content() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'You do not have permission to extract this post.' ], 403 );
        }

        $extract = self::extract_post_text( $post_id );
        if ( is_wp_error( $extract ) ) {
            wp_send_json_error( [ 'message' => $extract->get_error_message() ] );
        }

        wp_send_json_success( $extract );
    }

    public static function ajax_generate_audio() {
        check_ajax_referer( self::NONCE_ACTION, 'nonce' );
        $post_id = absint( $_POST['post_id'] ?? 0 );
        if ( ! $post_id || ! current_user_can( 'edit_post', $post_id ) ) {
            wp_send_json_error( [ 'message' => 'You do not have permission to generate audio for this post.' ], 403 );
        }

        $settings = self::get_settings();
        $profile_id = sanitize_key( $_POST['profile'] ?? $settings['default_profile'] );
        $profile = $settings['profiles'][ $profile_id ] ?? $settings['profiles']['default'];
        $provider_id = self::valid_provider_id( $_POST['provider'] ?? $profile['provider'] ?? $settings['default_provider'] );
        $overrides = [
            'voice' => sanitize_text_field( wp_unslash( $_POST['voice'] ?? '' ) ),
            'model' => sanitize_text_field( wp_unslash( $_POST['model'] ?? '' ) ),
            'language' => sanitize_text_field( wp_unslash( $_POST['language'] ?? '' ) ),
            'speed' => sanitize_text_field( wp_unslash( $_POST['speed'] ?? '' ) ),
        ];

        $extract = self::extract_post_text( $post_id );
        if ( is_wp_error( $extract ) ) {
            update_post_meta( $post_id, '_hexa_tts_status', 'Extraction failed' );
            wp_send_json_error( [ 'message' => $extract->get_error_message() ] );
        }

        if ( strlen( $extract['text'] ) > absint( $settings['max_characters'] ) ) {
            update_post_meta( $post_id, '_hexa_tts_status', 'Too long' );
            wp_send_json_error( [ 'message' => 'Extracted text is ' . strlen( $extract['text'] ) . ' characters, above the single-generation limit of ' . absint( $settings['max_characters'] ) . '. Increase the limit or add chunking before generation.' ] );
        }

        update_post_meta( $post_id, '_hexa_tts_status', 'Generating' );
        $result = self::generate_audio_for_post( $post_id, $provider_id, $profile_id, $profile, $overrides, $extract );

        if ( is_wp_error( $result ) ) {
            update_post_meta( $post_id, '_hexa_tts_status', 'Failed' );
            update_post_meta( $post_id, '_hexa_tts_error', $result->get_error_message() );
            wp_send_json_error( [ 'message' => $result->get_error_message() ] );
        }

        wp_send_json_success( $result );
    }

    private static function extract_post_text( $post_id ) {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return new WP_Error( 'hexa_tts_missing_post', 'Post not found.' );
        }

        $settings = self::get_settings();
        $parts = [];
        if ( ! empty( $settings['include_title'] ) ) {
            $parts[] = get_the_title( $post );
        }

        $content = $post->post_content;
        if ( function_exists( 'do_blocks' ) ) {
            $content = do_blocks( $content );
        }
        $content = strip_shortcodes( $content );
        $content = preg_replace( '#<(script|style|noscript)[^>]*>.*?</\1>#is', ' ', $content );
        $content = preg_replace( '#<(h[1-6]|p|li|blockquote|br)[^>]*>#i', "\n", $content );
        $text = wp_strip_all_tags( $content, true );
        $text = html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, get_bloginfo( 'charset' ) ?: 'UTF-8' );
        $text = preg_replace( '/\[[^\]]+\]/', ' ', $text );
        $text = preg_replace( '/[ \t]+/', ' ', $text );
        $text = preg_replace( "/\n{3,}/", "\n\n", $text );
        $text = trim( $text );

        if ( '' !== $text ) {
            $parts[] = $text;
        }

        $final = trim( implode( "\n\n", array_filter( $parts ) ) );
        if ( '' === $final ) {
            return new WP_Error( 'hexa_tts_empty_text', 'No usable text was extracted from this post.' );
        }

        return [
            'text' => $final,
            'characters' => strlen( $final ),
            'words' => str_word_count( wp_strip_all_tags( $final ) ),
            'hash' => hash( 'sha256', $final ),
            'preview' => function_exists( 'mb_substr' ) ? mb_substr( $final, 0, 2500 ) : substr( $final, 0, 2500 ),
        ];
    }

    private static function generate_audio_for_post( $post_id, $provider_id, $profile_id, array $profile, array $overrides, array $extract ) {
        $provider_settings = self::get_provider_settings( $provider_id, true );
        $voice = $overrides['voice'] ?: ( $profile['voice'] ?: ( $provider_settings['voice'] ?? '' ) );
        $model = $overrides['model'] ?: ( $profile['model'] ?: ( $provider_settings['model'] ?? '' ) );
        $language = $overrides['language'] ?: ( $profile['language'] ?: ( $provider_settings['language'] ?? 'en-US' ) );
        $speed = $overrides['speed'] ?: ( $profile['speed'] ?: ( $provider_settings['speed'] ?? $provider_settings['speaking_rate'] ?? '1.0' ) );

        $audio = self::synthesize( $provider_id, $extract['text'], $provider_settings, [
            'voice' => $voice,
            'model' => $model,
            'language' => $language,
            'speed' => $speed,
        ] );

        if ( is_wp_error( $audio ) ) {
            return $audio;
        }

        $upload = wp_upload_dir();
        if ( ! empty( $upload['error'] ) ) {
            return new WP_Error( 'hexa_tts_upload_dir', $upload['error'] );
        }

        $dir = trailingslashit( $upload['basedir'] ) . self::AUDIO_DIR;
        if ( ! wp_mkdir_p( $dir ) ) {
            return new WP_Error( 'hexa_tts_mkdir_failed', 'Unable to create audio output directory.' );
        }

        $extension = $audio['extension'] ?: 'mp3';
        $filename = sanitize_file_name( $post_id . '-' . substr( $extract['hash'], 0, 16 ) . '-' . $provider_id . '.' . $extension );
        $path = trailingslashit( $dir ) . $filename;
        $bytes = file_put_contents( $path, $audio['bytes'] );
        if ( false === $bytes ) {
            return new WP_Error( 'hexa_tts_write_failed', 'Unable to write generated audio file.' );
        }

        $url = trailingslashit( $upload['baseurl'] ) . self::AUDIO_DIR . '/' . $filename;
        update_post_meta( $post_id, '_hexa_tts_audio_url', esc_url_raw( $url ) );
        update_post_meta( $post_id, '_hexa_tts_audio_path', $path );
        update_post_meta( $post_id, '_hexa_tts_status', 'Ready' );
        update_post_meta( $post_id, '_hexa_tts_provider', $provider_id );
        update_post_meta( $post_id, '_hexa_tts_profile', $profile_id );
        update_post_meta( $post_id, '_hexa_tts_voice', $voice );
        update_post_meta( $post_id, '_hexa_tts_model', $model );
        update_post_meta( $post_id, '_hexa_tts_language', $language );
        update_post_meta( $post_id, '_hexa_tts_speed', $speed );
        update_post_meta( $post_id, '_hexa_tts_text_hash', $extract['hash'] );
        update_post_meta( $post_id, '_hexa_tts_character_count', $extract['characters'] );
        update_post_meta( $post_id, '_hexa_tts_generated_at', current_time( 'mysql' ) );

        return [
            'message' => 'Audio generated and saved.',
            'audio_url' => $url,
            'bytes' => $bytes,
            'provider' => $provider_id,
            'profile' => $profile_id,
            'characters' => $extract['characters'],
        ];
    }

    private static function synthesize( $provider_id, $text, array $settings, array $runtime ) {
        switch ( $provider_id ) {
            case 'kokoro':
            case 'piper':
                return self::synthesize_local_service( $text, $settings, $runtime );
            case 'amazon_polly':
                return self::synthesize_amazon_polly( $text, $settings, $runtime );
            case 'google_tts':
                return self::synthesize_google_tts( $text, $settings, $runtime );
            case 'elevenlabs':
                return self::synthesize_elevenlabs( $text, $settings, $runtime );
            case 'deepgram':
                return self::synthesize_deepgram( $text, $settings, $runtime );
            case 'cartesia':
                return self::synthesize_cartesia( $text, $settings, $runtime );
        }

        return new WP_Error( 'hexa_tts_unknown_provider', 'Unknown provider.' );
    }

    private static function synthesize_local_service( $text, array $settings, array $runtime ) {
        $endpoint = self::synthesize_service_url( $settings['service_url'] ?? '' );
        if ( '' === $endpoint ) {
            return new WP_Error( 'hexa_tts_missing_local_endpoint', 'Missing local synthesize service URL.' );
        }

        $headers = [ 'Content-Type' => 'application/json' ];
        if ( ! empty( $settings['api_key'] ) ) {
            $headers['Authorization'] = 'Bearer ' . $settings['api_key'];
        }

        $response = wp_remote_post(
            $endpoint,
            [
                'timeout' => 120,
                'headers' => $headers,
                'body' => wp_json_encode( [
                    'text' => $text,
                    'voice' => $runtime['voice'],
                    'model' => $runtime['model'],
                    'language' => $runtime['language'],
                    'speed' => floatval( $runtime['speed'] ),
                    'format' => 'mp3',
                ] ),
            ]
        );
        $error = self::response_error( $response );
        if ( $error ) {
            return $error;
        }

        return self::audio_from_response( $response, 'mp3' );
    }

    private static function synthesize_amazon_polly( $text, array $settings, array $runtime ) {
        foreach ( [ 'access_key_id', 'secret_access_key', 'region' ] as $required ) {
            if ( empty( $settings[ $required ] ) ) {
                return new WP_Error( 'hexa_tts_missing_aws', 'Missing AWS ' . str_replace( '_', ' ', $required ) . '.' );
            }
        }

        $body = wp_json_encode( [
            'Engine' => $settings['engine'] ?: 'neural',
            'OutputFormat' => 'mp3',
            'Text' => $text,
            'TextType' => 'text',
            'VoiceId' => $runtime['voice'] ?: ( $settings['voice'] ?? 'Joanna' ),
        ] );

        $response = self::aws_polly_request(
            'POST',
            $settings['region'],
            '/v1/speech',
            [],
            $body,
            $settings['access_key_id'],
            $settings['secret_access_key'],
            [ 'content-type' => 'application/json' ]
        );
        $error = self::response_error( $response );
        if ( $error ) {
            return $error;
        }

        return [ 'bytes' => wp_remote_retrieve_body( $response ), 'extension' => 'mp3', 'mime' => 'audio/mpeg' ];
    }

    private static function synthesize_google_tts( $text, array $settings, array $runtime ) {
        if ( empty( $settings['api_key'] ) ) {
            return new WP_Error( 'hexa_tts_missing_google', 'Missing Google API key.' );
        }

        $language = $runtime['language'] ?: ( $settings['language'] ?? 'en-US' );
        $voice = $runtime['voice'] ?: ( $settings['voice'] ?? '' );
        $body = [
            'input' => [ 'text' => $text ],
            'voice' => array_filter( [ 'languageCode' => $language, 'name' => $voice ] ),
            'audioConfig' => [
                'audioEncoding' => 'MP3',
                'speakingRate' => floatval( $runtime['speed'] ?: ( $settings['speaking_rate'] ?? 1 ) ),
            ],
        ];

        $response = wp_remote_post(
            add_query_arg( [ 'key' => $settings['api_key'] ], 'https://texttospeech.googleapis.com/v1/text:synthesize' ),
            [
                'timeout' => 120,
                'headers' => [ 'Content-Type' => 'application/json' ],
                'body' => wp_json_encode( $body ),
            ]
        );
        $error = self::response_error( $response );
        if ( $error ) {
            return $error;
        }

        $json = json_decode( wp_remote_retrieve_body( $response ), true );
        if ( empty( $json['audioContent'] ) ) {
            return new WP_Error( 'hexa_tts_google_empty_audio', 'Google response did not include audioContent.' );
        }

        return [ 'bytes' => base64_decode( $json['audioContent'] ), 'extension' => 'mp3', 'mime' => 'audio/mpeg' ];
    }

    private static function synthesize_elevenlabs( $text, array $settings, array $runtime ) {
        if ( empty( $settings['api_key'] ) ) {
            return new WP_Error( 'hexa_tts_missing_elevenlabs', 'Missing ElevenLabs API key.' );
        }

        $voice = $runtime['voice'] ?: ( $settings['voice'] ?? '' );
        if ( '' === $voice ) {
            return new WP_Error( 'hexa_tts_missing_voice', 'Missing ElevenLabs voice ID.' );
        }

        $response = wp_remote_post(
            'https://api.elevenlabs.io/v1/text-to-speech/' . rawurlencode( $voice ),
            [
                'timeout' => 120,
                'headers' => [
                    'Content-Type' => 'application/json',
                    'Accept' => 'audio/mpeg',
                    'xi-api-key' => $settings['api_key'],
                ],
                'body' => wp_json_encode( [
                    'text' => $text,
                    'model_id' => $runtime['model'] ?: ( $settings['model'] ?? 'eleven_multilingual_v2' ),
                    'voice_settings' => [
                        'stability' => floatval( $settings['stability'] ?? 0.45 ),
                        'similarity_boost' => floatval( $settings['similarity_boost'] ?? 0.75 ),
                    ],
                ] ),
            ]
        );
        $error = self::response_error( $response );
        if ( $error ) {
            return $error;
        }

        return [ 'bytes' => wp_remote_retrieve_body( $response ), 'extension' => 'mp3', 'mime' => 'audio/mpeg' ];
    }

    private static function synthesize_deepgram( $text, array $settings, array $runtime ) {
        if ( empty( $settings['api_key'] ) ) {
            return new WP_Error( 'hexa_tts_missing_deepgram', 'Missing Deepgram API key.' );
        }

        $url = add_query_arg(
            [
                'model' => $runtime['model'] ?: ( $settings['model'] ?? 'aura-2-thalia-en' ),
                'encoding' => 'mp3',
                'container' => 'mp3',
                'speed' => floatval( $runtime['speed'] ?: ( $settings['speed'] ?? 1 ) ),
            ],
            'https://api.deepgram.com/v1/speak'
        );

        $response = wp_remote_post(
            $url,
            [
                'timeout' => 120,
                'headers' => [
                    'Authorization' => 'Token ' . $settings['api_key'],
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode( [ 'text' => $text ] ),
            ]
        );
        $error = self::response_error( $response );
        if ( $error ) {
            return $error;
        }

        return [ 'bytes' => wp_remote_retrieve_body( $response ), 'extension' => 'mp3', 'mime' => 'audio/mpeg' ];
    }

    private static function synthesize_cartesia( $text, array $settings, array $runtime ) {
        if ( empty( $settings['api_key'] ) ) {
            return new WP_Error( 'hexa_tts_missing_cartesia', 'Missing Cartesia API key.' );
        }
        $voice = $runtime['voice'] ?: ( $settings['voice'] ?? '' );
        if ( '' === $voice ) {
            return new WP_Error( 'hexa_tts_missing_cartesia_voice', 'Missing Cartesia voice ID.' );
        }

        $response = wp_remote_post(
            'https://api.cartesia.ai/tts/bytes',
            [
                'timeout' => 120,
                'headers' => [
                    'Authorization' => 'Bearer ' . $settings['api_key'],
                    'Cartesia-Version' => $settings['version'] ?: '2026-03-01',
                    'Content-Type' => 'application/json',
                ],
                'body' => wp_json_encode( [
                    'model_id' => $runtime['model'] ?: ( $settings['model'] ?? 'sonic-3.5' ),
                    'transcript' => $text,
                    'voice' => [
                        'mode' => 'id',
                        'id' => $voice,
                    ],
                    'output_format' => [
                        'container' => 'mp3',
                    ],
                    'generation_config' => [
                        'speed' => floatval( $runtime['speed'] ?: ( $settings['speed'] ?? 1 ) ),
                    ],
                ] ),
            ]
        );
        $error = self::response_error( $response );
        if ( $error ) {
            return $error;
        }

        return [ 'bytes' => wp_remote_retrieve_body( $response ), 'extension' => 'mp3', 'mime' => 'audio/mpeg' ];
    }

    private static function audio_from_response( $response, $default_extension ) {
        $body = wp_remote_retrieve_body( $response );
        $content_type = wp_remote_retrieve_header( $response, 'content-type' );
        if ( false !== strpos( (string) $content_type, 'application/json' ) ) {
            $json = json_decode( $body, true );
            if ( ! empty( $json['audio_base64'] ) ) {
                return [
                    'bytes' => base64_decode( $json['audio_base64'] ),
                    'extension' => $json['extension'] ?? $default_extension,
                    'mime' => $json['mime_type'] ?? 'audio/mpeg',
                ];
            }
            if ( ! empty( $json['audio_url'] ) ) {
                $download = wp_remote_get( esc_url_raw( $json['audio_url'] ), [ 'timeout' => 120 ] );
                $error = self::response_error( $download );
                if ( $error ) {
                    return $error;
                }
                return [
                    'bytes' => wp_remote_retrieve_body( $download ),
                    'extension' => pathinfo( parse_url( $json['audio_url'], PHP_URL_PATH ), PATHINFO_EXTENSION ) ?: $default_extension,
                    'mime' => wp_remote_retrieve_header( $download, 'content-type' ) ?: 'audio/mpeg',
                ];
            }
            return new WP_Error( 'hexa_tts_local_json_no_audio', 'Local service returned JSON without audio_base64 or audio_url.' );
        }

        return [
            'bytes' => $body,
            'extension' => false !== strpos( (string) $content_type, 'wav' ) ? 'wav' : $default_extension,
            'mime' => $content_type ?: 'audio/mpeg',
        ];
    }

    private static function base_service_url( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return '';
        }
        $parts = wp_parse_url( $url );
        if ( empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
            return '';
        }
        $base = $parts['scheme'] . '://' . $parts['host'];
        if ( ! empty( $parts['port'] ) ) {
            $base .= ':' . $parts['port'];
        }
        $path = $parts['path'] ?? '';
        $path = preg_replace( '#/(synthesize|tts|generate)$#', '', $path );
        return rtrim( $base . $path, '/' );
    }

    private static function synthesize_service_url( $url ) {
        $url = trim( (string) $url );
        if ( '' === $url ) {
            return '';
        }
        if ( preg_match( '#/(synthesize|tts|generate)/?$#', $url ) ) {
            return esc_url_raw( $url );
        }
        return esc_url_raw( rtrim( $url, '/' ) . '/synthesize' );
    }

    private static function aws_polly_request( $method, $region, $path, array $query, $body, $access_key, $secret_key, array $extra_headers = [] ) {
        $service = 'polly';
        $host = 'polly.' . $region . '.amazonaws.com';
        $amzdate = gmdate( 'Ymd\THis\Z' );
        $datestamp = gmdate( 'Ymd' );
        $payload_hash = hash( 'sha256', $body );

        $headers = array_change_key_case( $extra_headers, CASE_LOWER );
        $headers['host'] = $host;
        $headers['x-amz-date'] = $amzdate;
        $headers['x-amz-content-sha256'] = $payload_hash;
        ksort( $headers );

        $canonical_query = self::aws_canonical_query( $query );
        $canonical_headers = '';
        foreach ( $headers as $key => $value ) {
            $canonical_headers .= strtolower( $key ) . ':' . trim( preg_replace( '/\s+/', ' ', $value ) ) . "\n";
        }
        $signed_headers = implode( ';', array_keys( $headers ) );
        $canonical_request = $method . "\n" . $path . "\n" . $canonical_query . "\n" . $canonical_headers . "\n" . $signed_headers . "\n" . $payload_hash;
        $credential_scope = $datestamp . '/' . $region . '/' . $service . '/aws4_request';
        $string_to_sign = "AWS4-HMAC-SHA256\n" . $amzdate . "\n" . $credential_scope . "\n" . hash( 'sha256', $canonical_request );

        $k_date = hash_hmac( 'sha256', $datestamp, 'AWS4' . $secret_key, true );
        $k_region = hash_hmac( 'sha256', $region, $k_date, true );
        $k_service = hash_hmac( 'sha256', $service, $k_region, true );
        $k_signing = hash_hmac( 'sha256', 'aws4_request', $k_service, true );
        $signature = hash_hmac( 'sha256', $string_to_sign, $k_signing );
        $headers['Authorization'] = 'AWS4-HMAC-SHA256 Credential=' . $access_key . '/' . $credential_scope . ', SignedHeaders=' . $signed_headers . ', Signature=' . $signature;

        $url = 'https://' . $host . $path . ( $canonical_query ? '?' . $canonical_query : '' );
        return wp_remote_request(
            $url,
            [
                'method' => $method,
                'timeout' => 120,
                'headers' => $headers,
                'body' => $body,
            ]
        );
    }

    private static function aws_canonical_query( array $query ) {
        ksort( $query );
        $pairs = [];
        foreach ( $query as $key => $value ) {
            $pairs[] = rawurlencode( (string) $key ) . '=' . rawurlencode( (string) $value );
        }
        return implode( '&', $pairs );
    }

    public static function maybe_insert_player( $content ) {
        $settings = self::get_settings();
        if ( empty( $settings['auto_insert_player'] ) || ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
            return $content;
        }

        $player = self::player_html( get_the_ID() );
        if ( '' === $player ) {
            return $content;
        }

        return $player . $content;
    }

    public static function render_player_shortcode( $atts = [] ) {
        $atts = shortcode_atts( [ 'post_id' => get_the_ID() ], $atts, 'hexa_tts_player' );
        return self::player_html( absint( $atts['post_id'] ) );
    }

    private static function player_html( $post_id ) {
        $url = get_post_meta( $post_id, '_hexa_tts_audio_url', true );
        if ( ! $url ) {
            return '';
        }

        $provider = get_post_meta( $post_id, '_hexa_tts_provider', true );
        $generated = get_post_meta( $post_id, '_hexa_tts_generated_at', true );
        ob_start();
        ?>
        <aside class="hexa-tts-player" aria-label="Article audio narration">
            <div class="hexa-tts-player__label">Listen to this article</div>
            <audio controls preload="none" src="<?php echo esc_url( $url ); ?>"></audio>
            <div class="hexa-tts-player__meta">
                <?php if ( $provider ) : ?><span><?php echo esc_html( self::provider_choices()[ $provider ] ?? $provider ); ?></span><?php endif; ?>
                <?php if ( $generated ) : ?><span><?php echo esc_html( mysql2date( 'M j, Y g:i A', $generated ) ); ?></span><?php endif; ?>
            </div>
        </aside>
        <?php
        return ob_get_clean();
    }
}

HexaTextToSpeech::init();
