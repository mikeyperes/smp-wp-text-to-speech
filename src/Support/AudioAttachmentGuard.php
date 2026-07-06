<?php

namespace Smp\TextToSpeech\Support;

final class AudioAttachmentGuard {
    const MANAGED_META_KEY = "_smp_tts_managed_audio_post_id";

    public static function markManaged( int $attachment_id, int $post_id, string $request_id = "" ): void {
        update_post_meta( $attachment_id, self::MANAGED_META_KEY, $post_id );
        if ( "" !== $request_id ) {
            update_post_meta( $attachment_id, "_smp_tts_managed_request_id", sanitize_key( $request_id ) );
        }
    }

    public static function canDeleteReplacement( int $previous_attachment_id, int $new_attachment_id, int $post_id ): bool {
        if ( ! $previous_attachment_id || $previous_attachment_id === $new_attachment_id || ! $post_id ) {
            return false;
        }

        if ( "attachment" !== get_post_type( $previous_attachment_id ) ) {
            return false;
        }

        if ( 0 !== strpos( (string) get_post_mime_type( $previous_attachment_id ), "audio/" ) ) {
            return false;
        }

        return (int) get_post_meta( $previous_attachment_id, self::MANAGED_META_KEY, true ) === $post_id;
    }
}
