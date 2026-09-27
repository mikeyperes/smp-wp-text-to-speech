<?php
/** Native Fields (no ACF) resolve selectors, derive keys and return values as ACF does. */

declare(strict_types=1);

$GLOBALS['options'] = [];
function add_action( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
function add_filter( $hook, $callback, $priority = 10, $args = 1 ) { return true; }
function apply_filters( $hook, $value, ...$args ) { return $value; }
function do_action( $hook, ...$args ) {}
function did_action( $hook ) { return 1; }
function doing_action( $hook = null ) { return false; }
function is_admin() { return false; }
function esc_attr( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_html( $v ) { return htmlspecialchars( (string) $v, ENT_QUOTES ); }
function esc_url( $v ) { return (string) $v; }
function wp_kses_post( $v ) { return (string) $v; }
function wpautop( $v ) { return (string) $v; }
function get_option( $name, $default = false ) { return array_key_exists( $name, $GLOBALS['options'] ) ? $GLOBALS['options'][ $name ] : $default; }
function update_option( $name, $value, $autoload = null ) { $GLOBALS['options'][ $name ] = $value; return true; }
function delete_option( $name ) { $existed = array_key_exists( $name, $GLOBALS['options'] ); unset( $GLOBALS['options'][ $name ] ); return $existed; }

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'Hexa\\PluginCore\\Fields\\';
	if ( str_starts_with( $class, $prefix ) ) {
		require_once dirname( __DIR__ ) . '/src/Fields/' . substr( $class, strlen( $prefix ) ) . '.php';
	}
} );

use Hexa\PluginCore\Fields\Acf;
use Hexa\PluginCore\Fields\Field;
use Hexa\PluginCore\Fields\FieldGroups;

$fail = static function ( string $message ): void {
	fwrite( STDERR, 'FAIL: ' . $message . PHP_EOL );
	exit( 1 );
};

Acf::active() && $fail( 'The test must run without ACF.' );

FieldGroups::add( [
	'title'    => 'Publication Profile',
	'fields'   => [ [ 'name' => 'mission', 'type' => 'textarea' ], [ 'name' => 'listed', 'type' => 'true_false' ] ],
	'location' => [ [ [ 'param' => 'options_page', 'operator' => '==', 'value' => '*' ] ] ],
] );
null !== FieldGroups::get_group( 'group_publication_profile' ) || $fail( 'A key-less group takes group_<slug of title>, as ACF does.' );
null !== FieldGroups::get_field( 'field_mission' ) || $fail( 'A key-less field takes field_<name>, as ACF does.' );

null === Field::get( 'mission', 'option' ) || $fail( 'A never-saved name returns null, as get_field() does.' );
false === Field::object( 'mission', 'option' ) || $fail( 'A never-saved name has no field object, as get_field_object() does.' );
false === Field::delete( 'mission', 'option' ) || $fail( 'delete_field() on a never-saved name returns false.' );
Field::update( 'mission', 'Report the news.', 'option' ) || $fail( 'update_field() matches a registered name.' );
'field_mission' === get_option( '_options_mission' ) || $fail( 'update_field() stores the `_name` reference.' );
'Report the news.' === Field::get( 'mission', 'option' ) || $fail( 'A saved name resolves through its reference.' );
Field::update( 'listed', 1, 'option' );
true === Field::get( 'listed', 'option' ) || $fail( 'Referenced fields are formatted (true_false returns bool).' );
'Report the news.' === Field::get( 'field_mission', 'option' ) || $fail( 'A key selector resolves directly.' );
update_option( 'options_raw_only', 'raw' );
'raw' === Field::get( 'raw_only', 'option' ) || $fail( 'An unknown name returns the raw stored value.' );
Field::delete( 'mission', 'option' ) || $fail( 'delete_field() erases a referenced field.' );
null === Field::get( 'mission', 'option' ) || $fail( 'A deleted field reads as never saved.' );

ob_start();
\Hexa\PluginCore\Fields\Form::field( [ 'key' => 'field_audio_url', 'name' => 'audio_url', 'label' => 'Audio', 'type' => 'text', 'value' => 'https://example.test/a.mp3' ] );
$html = (string) ob_get_clean();
str_contains( $html, 'name="acf[field_audio_url]"' ) && str_contains( $html, 'https://example.test/a.mp3' ) || $fail( 'Form::field() renders one field under acf[<key>] with its value, as acf_render_field_wrap() does.' );

echo "PASS: native Fields resolve, derive keys and return values as ACF does.\n";
