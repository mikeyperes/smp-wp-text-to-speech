<?php

declare(strict_types=1);

$root = dirname( __DIR__ );
$plugin = file_get_contents( $root . "/smp-wp-text-to-speech.php" );
$css = file_get_contents( $root . "/assets/frontend-1.3.24.css" );
$js = file_get_contents( $root . "/assets/frontend.js" );

if ( ! is_string( $plugin ) || ! is_string( $css ) || ! is_string( $js ) ) {
    fwrite( STDERR, "FAIL: Unstyled player sources could not be read.\n" );
    exit( 1 );
}

$required_plugin_tokens = [
    '* Version: 1.3.25',
    'const VERSION = "1.3.25";',
    '"unstyled" => [ "label" => "No design (unstyled)"',
    '"unstyled_controls" => [ "label" => "No design with custom controls"',
    '$styled = ! in_array( $template, [ "unstyled", "unstyled_controls" ], true );',
    'data-hexa-tts-skin=',
    '$styled ? "styled" : "unstyled"',
    '<?php if ( $styled ) : ?> style="--smp-tts-primary:',
];
foreach ( $required_plugin_tokens as $token ) {
    if ( ! str_contains( $plugin, $token ) ) {
        fwrite( STDERR, "FAIL: Unstyled player support is missing: {$token}.\n" );
        exit( 1 );
    }
}

$scoped_skin_selectors = [
    ':where(.hexa-tts-player[data-hexa-tts-skin="styled"]) {',
    ':where(.hexa-tts-player[data-hexa-tts-skin="styled"]) .hexa-tts-player__label',
    ':where(.hexa-tts-player[data-hexa-tts-skin="styled"]) audio',
    ':where(.hexa-tts-player[data-hexa-tts-skin="styled"]) .hexa-tts-player__controls',
    ':where(.hexa-tts-player[data-hexa-tts-skin="styled"]) .hexa-tts-player__button',
    ':where(.hexa-tts-player[data-hexa-tts-skin="styled"]) .hexa-tts-player__transcript',
];
foreach ( $scoped_skin_selectors as $selector ) {
    if ( ! str_contains( $css, $selector ) ) {
        fwrite( STDERR, "FAIL: A shared player skin rule is not scoped: {$selector}.\n" );
        exit( 1 );
    }
}

if ( preg_match( '/(?<!:where\()\.hexa-tts-player\[data-hexa-tts-skin="styled"\]/', $css ) ) {
    fwrite( STDERR, "FAIL: A shared styled-player selector can outrank a named template.\n" );
    exit( 1 );
}

$forbidden_unstyled_css = [
    '.hexa-tts-player--unstyled',
    '.hexa-tts-player--unstyled_controls',
    ".hexa-tts-player {",
    "\n.hexa-tts-player__label {",
    "\n.hexa-tts-player__controls {",
    "\n.hexa-tts-player__button {",
];
foreach ( $forbidden_unstyled_css as $selector ) {
    if ( str_contains( $css, $selector ) ) {
        fwrite( STDERR, "FAIL: Plugin styling can still reach the unstyled player: {$selector}.\n" );
        exit( 1 );
    }
}

if ( preg_match( '/^\.hexa-tts-narration__(?!status)/m', $css ) ) {
    fwrite( STDERR, "FAIL: Custom-control presentation is not scoped to a styled template.\n" );
    exit( 1 );
}

foreach ( [ 'data-hexa-tts-enhanced', 'data-hexa-tts-skip', 'data-hexa-tts-speed', 'data-hexa-tts-transcript-toggle', 'aria-live="polite"' ] as $token ) {
    if ( ! str_contains( $plugin, $token ) ) {
        fwrite( STDERR, "FAIL: Functional player markup was removed: {$token}.\n" );
        exit( 1 );
    }
}

foreach ( [ "ROOT_SELECTOR", "data-hexa-tts-speed", "data-hexa-tts-skip", "data-hexa-tts-transcript-toggle" ] as $token ) {
    if ( ! str_contains( $js, $token ) ) {
        fwrite( STDERR, "FAIL: Functional player behavior was removed: {$token}.\n" );
        exit( 1 );
    }
}

echo "PASS: Unstyled player keeps functional markup and behavior without plugin-owned presentation.\n";
