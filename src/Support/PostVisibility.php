<?php

namespace Smp\TextToSpeech\Support;

final class PostVisibility {
    public static function canExposeSchema( int $post_id ): bool {
        $post = get_post( $post_id );
        if ( ! $post ) {
            return false;
        }

        if ( function_exists( "is_post_publicly_viewable" ) && is_post_publicly_viewable( $post ) ) {
            return true;
        }

        if ( "publish" === get_post_status( $post_id ) && function_exists( "is_post_type_viewable" ) && is_post_type_viewable( get_post_type( $post_id ) ) ) {
            return true;
        }

        return current_user_can( "read_post", $post_id );
    }
}
