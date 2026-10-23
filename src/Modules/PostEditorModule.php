<?php

namespace smp_text_to_speech\Modules;

use smp_text_to_speech\Plugin;
use smp_text_to_speech\PluginMetadata;

if ( ! defined( "ABSPATH" ) ) {
    exit;
}

/**
 * The "Listen to this article" editor box (audio field, generation, upload).
 * While the feature is off the box is not added, and the site's own audio
 * field (some sites define article_audio in their own field group) is hidden.
 */
final class PostEditorModule {
    public function register(): void {
        if ( ! Plugin::article_audio_enabled() ) {
            add_filter( "acf/prepare_field/name=" . Plugin::audio_field_name(), "__return_false" );
            return;
        }
        add_action( "add_meta_boxes", [ Plugin::class, "register_post_metabox" ] );
        add_action( "save_post", [ Plugin::class, "save_embedded_acf_audio" ], 20, 2 );
        Plugin::register_ajax(
            [
                "extract_post_content" => [ "callback" => [ Plugin::class, "ajax_extract_post_content" ], "capability" => "edit_posts" ],
                "generate_audio"       => [ "callback" => [ Plugin::class, "ajax_generate_audio" ], "capability" => "edit_posts" ],
                "generation_status"    => [ "callback" => [ Plugin::class, "ajax_generation_status" ], "capability" => "edit_posts" ],
                "save_manual_audio"    => [ "callback" => [ Plugin::class, "ajax_save_manual_audio" ], "capability" => "edit_posts" ],
            ]
        );
    }
}
