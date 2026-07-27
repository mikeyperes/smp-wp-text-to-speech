<?php

declare(strict_types=1);

$root = dirname( __DIR__ );
$plugin = file_get_contents( $root . "/smp-wp-text-to-speech.php" );
$css = file_get_contents( $root . "/assets/frontend-1.3.20.css" );
$js = file_get_contents( $root . "/assets/frontend.js" );

if ( ! is_string( $plugin ) || ! is_string( $css ) || ! is_string( $js ) ) {
    fwrite( STDERR, "FAIL: Narration Card sources could not be read.\n" );
    exit( 1 );
}

$required_template_labels = [
    '"clean_card" => [ "label" => "Top-accent article card"',
    '"editorial_bar" => [ "label" => "Left-accent editorial card"',
    '"compact_pill" => [ "label" => "Rounded compact pill"',
    '"narration_card" => [ "label" => "Dark narration card with timeline"',
];
foreach ( $required_template_labels as $label ) {
    if ( ! str_contains( $plugin, $label ) ) {
        fwrite( STDERR, "FAIL: A clear player template label is missing: {$label}.\n" );
        exit( 1 );
    }
}

$expected_template_keys = [
    "clean_card",
    "editorial_bar",
    "compact_pill",
    "media_panel",
    "minimal_audio",
    "quiet_card",
    "slim_line",
    "ghost",
    "inline_label",
    "dot",
    "underline",
    "tag",
    "editorial_thin",
    "caption",
    "eyebrow",
    "framed",
    "rule_between",
    "corner",
    "mini",
    "soft_tint",
    "what_to_know",
    "narration_card",
];
foreach ( $expected_template_keys as $key ) {
    if ( ! str_contains( $plugin, '"' . $key . '" => [ "label" =>' ) ) {
        fwrite( STDERR, "FAIL: Existing player template key was removed: {$key}.\n" );
        exit( 1 );
    }
}

foreach ( [ "framed", "rule_between", "corner", "mini", "soft_tint", "what_to_know", "narration_card" ] as $key ) {
    if ( ! str_contains( $css, ".hexa-tts-player--" . $key ) ) {
        fwrite( STDERR, "FAIL: Player template has no distinct frontend selector: {$key}.\n" );
        exit( 1 );
    }
}

$required_markup = [
    '"narration_card" === $template',
    'data-hexa-tts-custom=',
    'data-hexa-tts-play',
    'data-hexa-tts-timeline',
    'data-hexa-tts-current',
    'data-hexa-tts-duration',
    'aria-label="Audio progress"',
];
foreach ( $required_markup as $token ) {
    if ( ! str_contains( $plugin, $token ) ) {
        fwrite( STDERR, "FAIL: Narration Card markup is missing: {$token}.\n" );
        exit( 1 );
    }
}

$required_css = [
    ".hexa-tts-player--narration_card",
    "border-top: 3px solid var(--smp-tts-primary)",
    ".hexa-tts-narration__play",
    ".hexa-tts-narration__timeline",
    ".hexa-tts-narration__time",
    "grid-template-columns: 46px minmax(0, 1fr)",
];
foreach ( $required_css as $token ) {
    if ( ! str_contains( $css, $token ) ) {
        fwrite( STDERR, "FAIL: Narration Card CSS is missing: {$token}.\n" );
        exit( 1 );
    }
}

$required_js = [
    "function setCustomPlayState(root, playing)",
    "function syncCustomProgress(root, audio)",
    "audio.addEventListener('loadedmetadata'",
    "timeline.addEventListener('input'",
    "var playResult = audio.play()",
];
foreach ( $required_js as $token ) {
    if ( ! str_contains( $js, $token ) ) {
        fwrite( STDERR, "FAIL: Narration Card behavior is missing: {$token}.\n" );
        exit( 1 );
    }
}

echo "PASS: Dark Narration Card has a clear label, selected-color accents, custom timeline markup, and functional playback controls.\n";
