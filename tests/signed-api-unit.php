<?php
/** Voice client transport, polling and refused-request behavior without network calls. */
define( 'ABSPATH', __DIR__ );
require __DIR__ . '/../lib/hexa-wordpress-plugin-core/src/ApiKeys/HmacSignature.php';
require __DIR__ . '/../lib/hexa-wordpress-plugin-core/src/ApiKeys/SignedJsonClient.php';
require __DIR__ . '/../src/Concerns/GeneratesAudio.php';

class WP_Error {
    private string $message;
    public function __construct( $code, $message ) { $this->message = $message; }
    public function get_error_message() { return $this->message; }
}
function wp_parse_url( $url, $component = -1 ) { return parse_url( $url, $component ); }
function wp_json_encode( $value ) { return json_encode( $value ); }
function home_url() { return 'https://www.site.test/'; }
function apply_filters( $hook, $value ) { return $value; }
function is_wp_error( $value ) { return $value instanceof WP_Error; }
function sanitize_text_field( $value ) { return $value; }
function absint( $value ) { return abs( (int) $value ); }
function wp_remote_retrieve_response_code( $response ) { return $response['code']; }
function wp_remote_retrieve_body( $response ) { return $response['body']; }
function wp_remote_post( $url, $args ) {
    $GLOBALS['calls'][] = [ $url, $args ];
    return $GLOBALS['answer'] ?? [ 'code' => 200, 'body' => '{"success":true,"request":{"status":"completed","audio_bytes":42}}' ];
}
final class VoiceFixture {
    use \smp_text_to_speech\Concerns\GeneratesAudio;
    const API_BASE = 'https://api.example.test/v1/text-to-speech';
    private static function api_key() { return $GLOBALS['key'] ?? 'fixture-key'; }
    public static function call( $path, $payload ) { return self::api_request( $path, $payload ); }
    public static function poll( $id ) { return self::api_request_status( $id ); }
}
$checks = 0;
function check( bool $ok, string $label ): void {
    global $checks;
    if ( ! $ok ) { throw new RuntimeException( $label ); }
    ++$checks;
}
VoiceFixture::call( '/synthesize', [ 'content' => 'Hello' ] );
[ $url, $args ] = $GLOBALS['calls'][0];
$headers = $args['headers'];
check( VoiceFixture::API_BASE . '/synthesize' === $url, 'Configured endpoint used' );
check( 'site.test' === $headers['X-SMP-Domain'], 'Core normalizes the site identity' );
check( '{"content":"Hello"}' === $args['body'], 'Only the supplied fields are sent' );
check( \Hexa\PluginCore\ApiKeys\HmacSignature::sign( 'POST', '/v1/text-to-speech/synthesize', $headers['X-SMP-Timestamp'], $headers['X-SMP-Nonce'], 'site.test', $args['body'], 'fixture-key' ) === $headers['X-SMP-Signature'], 'Voice call uses the Core signature contract' );
$state = VoiceFixture::poll( 'tts_' . str_repeat( 'a', 32 ) );
check( 'completed' === $state['status'] && 42 === $state['audio_bytes'], 'Polling preserves request status' );
check( '{}' === $GLOBALS['calls'][1][1]['body'], 'Polling uses signed POST with an empty object' );
check( $headers['X-SMP-Nonce'] !== $GLOBALS['calls'][1][1]['headers']['X-SMP-Nonce'], 'Polling creates a fresh nonce' );
$GLOBALS['answer'] = [ 'code' => 403, 'body' => '{"success":false,"message":"Refused"}' ];
check( VoiceFixture::call( '/status', [] ) instanceof WP_Error, 'API refusal is preserved' );
$GLOBALS['key'] = '';
$before = count( $GLOBALS['calls'] );
check( VoiceFixture::call( '/status', [] ) instanceof WP_Error && $before === count( $GLOBALS['calls'] ), 'Missing key never sends' );
echo "Voice signed API: $checks checks passed\n";
