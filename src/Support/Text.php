<?php

namespace Smp\TextToSpeech\Support;

final class Text {
    public static function length( string $text ): int {
        return function_exists( "mb_strlen" ) ? mb_strlen( $text, "UTF-8" ) : strlen( $text );
    }

    public static function slice( string $text, int $start, ?int $length = null ): string {
        if ( function_exists( "mb_substr" ) ) {
            return null === $length ? mb_substr( $text, $start, null, "UTF-8" ) : mb_substr( $text, $start, $length, "UTF-8" );
        }

        return null === $length ? substr( $text, $start ) : substr( $text, $start, $length );
    }

    public static function truncateWithDots( string $text, int $max ): string {
        if ( $max < 4 || self::length( $text ) <= $max ) {
            return $text;
        }

        return rtrim( self::slice( $text, 0, max( 1, $max - 3 ) ) ) . "...";
    }
}
