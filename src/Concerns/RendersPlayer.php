<?php

namespace smp_text_to_speech\Concerns;

use Smp\TextToSpeech\Support\AcfAudioFieldResolver;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

trait RendersPlayer {
    /**
     * Head enqueue only on a single article that has audio. Any other page
     * that shows a player (a template shortcode) loads the same files from
     * player_markup() with the first player, never on every page.
     */
    public static function enqueue_frontend_assets() {
        if ( is_admin() || ! self::article_audio_enabled() || ! is_singular( [ "post", "press-release" ] ) ) {
            return;
        }
        if ( "" === self::player_audio_url( (int) get_queried_object_id() ) ) {
            return;
        }
        self::enqueue_player_assets();
    }

    private static function enqueue_player_assets(): void {
        wp_enqueue_style( "smp-tts-frontend", plugin_dir_url( \smp_text_to_speech\PluginMetadata::main_file() ) . "assets/frontend-1.3.24.css", [], self::VERSION );
        wp_enqueue_script( "smp-tts-frontend-player", plugin_dir_url( \smp_text_to_speech\PluginMetadata::main_file() ) . "assets/frontend.js", [], self::VERSION, true );
    }

    private static function player_audio_url( int $post_id ): string {
        if ( $post_id <= 0 ) {
            return "";
        }
        $url = get_post_meta( $post_id, "_hexa_tts_audio_url", true );
        if ( ! $url ) {
            $url = self::resolve_audio_url( get_post_meta( $post_id, AcfAudioFieldResolver::fieldName( self::get_settings() ), true ) );
        }

        return is_string( $url ) ? $url : "";
    }

    public static function maybe_insert_player( $content ) {
        $settings = self::get_settings();
        if ( ! in_the_loop() || ! is_main_query() || ! self::should_auto_insert_player( get_the_ID(), $settings ) || "above_article" !== ( $settings["auto_player_placement"] ?? "above_article" ) ) {
            return $content;
        }
        $player = self::player_html( get_the_ID() );
        return "" === $player ? $content : $player . $content;
    }

    public static function maybe_insert_player_around_featured_image( $html, $post_id, $post_thumbnail_id, $size, $attr ) {
        static $injected = [];
        $settings = self::get_settings();
        $placement = $settings["auto_player_placement"] ?? "above_article";
        if ( ! in_array( $placement, [ "before_featured_image", "after_featured_image" ], true ) || ! self::should_auto_insert_player( $post_id, $settings ) ) {
            return $html;
        }
        if ( ! empty( $injected[ $post_id ][ $placement ] ) ) {
            return $html;
        }
        $player = self::player_html( $post_id );
        if ( "" === $player ) {
            return $html;
        }
        $injected[ $post_id ][ $placement ] = true;
        return "before_featured_image" === $placement ? $player . $html : $html . $player;
    }

    private static function should_auto_insert_player( $post_id, array $settings ): bool {
        if ( empty( $settings["auto_insert_player"] ) || "manual" === ( $settings["auto_player_placement"] ?? "above_article" ) ) {
            return false;
        }
        if ( ! is_singular( [ "post", "press-release" ] ) ) {
            return false;
        }
        $queried = (int) get_queried_object_id();
        return $post_id && ( 0 === $queried || (int) $post_id === $queried );
    }

    public static function render_player_shortcode( $atts = [] ) {
        if ( ! self::article_audio_enabled() ) {
            return "";
        }
        $settings = self::get_settings();
        $atts = shortcode_atts( [
            "post_id" => get_the_ID(),
            "label" => $settings["player_label"] ?? "Listen to this article",
            "show_meta" => ! empty( $settings["show_player_meta"] ) ? "1" : "0",
            "preload" => "metadata",
            "class" => "",
            "template" => $settings["player_template"] ?? "clean_card",
            "size" => $settings["player_size"] ?? "default",
            "color" => $settings["primary_color"] ?? "#3657e3",
            "controls" => "",
            "enhanced_controls" => ! empty( $settings["enable_player_controls"] ) ? "1" : "0",
        ], $atts, "smp_tts_player" );
        return self::player_html( absint( $atts["post_id"] ), $atts );
    }

    private static function player_html( $post_id, array $args = [] ) {
        $url = self::player_audio_url( (int) $post_id );
        if ( "" === $url ) {
            return "";
        }
        return self::player_markup( $url, $args, $post_id );
    }

    private static function player_markup( string $url, array $args = [], int $post_id = 0 ): string {
        $settings = self::get_settings();
        $templates = self::template_options();
        $sizes = self::size_options();
        $template = sanitize_key( $args["template"] ?? ( $settings["player_template"] ?? "clean_card" ) );
        $template = isset( $templates[ $template ] ) ? $template : "clean_card";
        $size = sanitize_key( $args["size"] ?? ( $settings["player_size"] ?? "default" ) );
        $size = isset( $sizes[ $size ] ) ? $size : "default";
        $label = isset( $args["label"] ) ? (string) $args["label"] : ( $settings["player_label"] ?? "Listen to this article" );
        $raw_preload = isset( $args["preload"] ) && "" !== (string) $args["preload"] ? (string) $args["preload"] : "metadata";
        $preload = in_array( $raw_preload, [ "none", "metadata", "auto" ], true ) ? $raw_preload : "metadata";
        $extra_class = sanitize_html_class( (string) ( $args["class"] ?? "" ) );
        $color = self::sanitize_color( $args["color"] ?? ( $settings["primary_color"] ?? "#3657e3" ) );
        $enhanced_arg = "" !== (string) ( $args["controls"] ?? "" ) ? $args["controls"] : ( $args["enhanced_controls"] ?? ( $settings["enable_player_controls"] ?? 1 ) );
        $enhanced = self::truthy_setting( $enhanced_arg );
        $transcript = $post_id > 0 ? self::audio_transcript_for_post( $post_id ) : "";
        $player_key = $post_id > 0 ? "post-" . absint( $post_id ) : substr( hash( "sha256", $url ), 0, 16 );
        $styled = ! in_array( $template, [ "unstyled", "unstyled_controls" ], true );
        $custom_player = in_array( $template, [ "narration_card", "unstyled_controls" ], true );
        if ( ! is_admin() ) {
            self::enqueue_player_assets();
        }
        $classes = trim( "hexa-tts-player hexa-tts-player--" . $template . " hexa-tts-player--size-" . $size . ( $enhanced ? " hexa-tts-player--enhanced" : " hexa-tts-player--native" ) . " " . $extra_class );
        ob_start();
        ?>
        <aside class="<?php echo esc_attr( $classes ); ?>" data-hexa-tts-enhanced="<?php echo esc_attr( $enhanced ? "1" : "0" ); ?>" data-hexa-tts-custom="<?php echo esc_attr( $custom_player ? "1" : "0" ); ?>" data-hexa-tts-skin="<?php echo esc_attr( $styled ? "styled" : "unstyled" ); ?>" data-hexa-tts-key="<?php echo esc_attr( $player_key ); ?>" aria-label="Article audio narration"<?php if ( $styled ) : ?> style="--smp-tts-primary: <?php echo esc_attr( $color ); ?>;"<?php endif; ?>>
            <?php if ( $custom_player ) : ?>
                <div class="hexa-tts-narration__eyebrow">Listen</div>
                <h2 class="hexa-tts-player__label hexa-tts-narration__title"><?php echo esc_html( $label ); ?></h2>
                <div class="hexa-tts-narration__meta"><span>AI narration</span><span aria-hidden="true">/</span><span data-hexa-tts-duration-label>Loading duration</span></div>
                <audio hidden preload="<?php echo esc_attr( $preload ); ?>" src="<?php echo esc_url( $url ); ?>"></audio>
                <div class="hexa-tts-narration__main">
                    <button type="button" class="hexa-tts-narration__play" data-hexa-tts-play aria-label="Play article narration"><span aria-hidden="true"></span></button>
                    <div class="hexa-tts-narration__track">
                        <input type="range" class="hexa-tts-narration__timeline" data-hexa-tts-timeline min="0" max="100" value="0" step="0.1" aria-label="Audio progress" aria-valuetext="0:00 of 0:00">
                        <div class="hexa-tts-narration__time"><span data-hexa-tts-current>0:00</span><span data-hexa-tts-duration>0:00</span></div>
                    </div>
                </div>
                <div class="hexa-tts-narration__volume" aria-label="Volume controls">
                    <button type="button" class="hexa-tts-player__button hexa-tts-narration__mute" data-hexa-tts-mute aria-label="Mute audio" aria-pressed="false">Mute</button>
                    <input type="range" class="hexa-tts-narration__volume-range" data-hexa-tts-volume min="0" max="1" value="1" step="0.05" aria-label="Volume" aria-valuetext="100%">
                </div>
            <?php else : ?>
                <div class="hexa-tts-player__label"><?php echo esc_html( $label ); ?></div>
                <audio controls preload="<?php echo esc_attr( $preload ); ?>" src="<?php echo esc_url( $url ); ?>"></audio>
            <?php endif; ?>
            <?php if ( $enhanced ) : ?>
                <div class="hexa-tts-player__controls" aria-label="Audio playback controls">
                    <div class="hexa-tts-player__skip">
                        <button type="button" class="hexa-tts-player__button" data-hexa-tts-skip="-10">Back 10s</button>
                        <button type="button" class="hexa-tts-player__button" data-hexa-tts-skip="30">Forward 30s</button>
                    </div>
                    <div class="hexa-tts-player__speed" aria-label="Playback speed">
                        <?php foreach ( [ "0.75", "1", "1.25", "1.5", "2" ] as $speed ) : ?>
                            <button type="button" class="hexa-tts-player__button <?php echo "1" === $speed ? "is-active" : ""; ?>" data-hexa-tts-speed="<?php echo esc_attr( $speed ); ?>" aria-pressed="<?php echo esc_attr( "1" === $speed ? "true" : "false" ); ?>"><?php echo esc_html( $speed . "x" ); ?></button>
                        <?php endforeach; ?>
                    </div>
                    <?php if ( "" !== $transcript ) : ?>
                        <button type="button" class="hexa-tts-player__button hexa-tts-player__transcript-toggle" data-hexa-tts-transcript-toggle aria-expanded="false">Transcript</button>
                    <?php endif; ?>
                    <?php if ( ! $custom_player ) : ?>
                        <span class="hexa-tts-player__status" aria-live="polite"></span>
                    <?php endif; ?>
                </div>
                <?php if ( "" !== $transcript ) : ?>
                    <div class="hexa-tts-player__transcript" data-hexa-tts-transcript hidden><?php echo wp_kses_post( nl2br( esc_html( $transcript ) ) ); ?></div>
                <?php endif; ?>
            <?php endif; ?>
            <?php if ( $custom_player ) : ?>
                <span class="hexa-tts-player__status hexa-tts-narration__status" aria-live="polite">Audio ready</span>
            <?php endif; ?>
        </aside>
        <?php
        return ob_get_clean();
    }

    private static function truthy_setting( $value ): bool {
        return ! in_array( strtolower( trim( (string) $value ) ), [ "0", "false", "no", "off" ], true );
    }
}
