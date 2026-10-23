<?php
/**
 * The plugin's PHP source as one string: the main file plus every file in
 * src/. Static tests search it for required code without caring which
 * module or concern holds it.
 */
function smp_tts_test_plugin_source( string $root ): string {
    $files = [ $root . '/smp-wp-text-to-speech.php' ];
    $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root . '/src', FilesystemIterator::SKIP_DOTS ) );
    foreach ( $iterator as $file ) {
        if ( 'php' === $file->getExtension() ) {
            $files[] = $file->getPathname();
        }
    }
    sort( $files );

    return implode( "\n", array_map( static fn( string $path ): string => (string) file_get_contents( $path ), $files ) );
}
