<?php
/**
 * Standalone regression check: php tests/admin-check.php
 *
 * No WordPress installation or database required.
 */

define( 'ABSPATH', __DIR__ );

$hooks      = array();
$options    = array();
$registered = array();
$settings   = array();
$can_manage = true;
$multisite  = false;
$super      = false;

function add_action( $hook, $callback ) {
	$GLOBALS['hooks'][ $hook ][] = $callback;
}
function __( $text, $domain ) {
	return $text;
}
function get_option( $name, $default ) {
	return $GLOBALS['options'][ $name ] ?? $default;
}
function current_user_can( $capability ) {
	return $GLOBALS['can_manage'];
}
function is_multisite() {
	return $GLOBALS['multisite'];
}
function is_super_admin() {
	return $GLOBALS['super'];
}
function wp_register_ability( $name, $args ) {
	$GLOBALS['registered'][ $name ] = $args;
}
function register_setting( $group, $name, $args ) {
	$GLOBALS['settings'][ $name ] = $args;
}
function check( $condition, $message ) {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}
function run_hook( $name ) {
	foreach ( $GLOBALS['hooks'][ $name ] as $callback ) {
		$callback();
	}
}

require dirname( __DIR__ ) . '/gvn-wordpress-abilities.php';

$names = array_keys( gvn_wordpress_abilities_catalog() );
run_hook( 'wp_abilities_api_init' );
check( array_keys( $registered ) === $names, 'Both abilities must be enabled by default.' );
foreach ( $registered as $args ) {
	check( is_callable( $args['execute_callback'] ), 'Existing callbacks must remain valid.' );
	check( call_user_func( $args['permission_callback'] ), 'Administrators must retain access.' );
}

foreach ( array( array( $names[0] ), $names, array() ) as $disabled ) {
	$options['gvn_wordpress_abilities_disabled'] = $disabled;
	$registered = array();
	run_hook( 'wp_abilities_api_init' );
	check( array_keys( $registered ) === array_values( array_diff( $names, $disabled ) ), 'Disable/re-enable must affect registration.' );
	check( count( gvn_wordpress_abilities_catalog() ) === 2, 'Disabled abilities must remain in the admin catalog.' );
}

check( gvn_wordpress_abilities_sanitize_disabled( array( '', 'other/plugin', $names[0], $names[0], array() ) ) === array( $names[0] ), 'Ignore unknown, duplicate, and malformed values.' );
check( gvn_wordpress_abilities_sanitize_disabled( array( '' ) ) === array(), 'Unchecking all must re-enable all abilities.' );
check( gvn_wordpress_abilities_sanitize_disabled( 'invalid' ) === array(), 'Reject non-array input.' );

run_hook( 'admin_init' );
check( isset( $settings['gvn_wordpress_abilities_disabled'] ), 'Administrators must be able to save.' );

$multisite = true;
$settings  = array();
run_hook( 'admin_init' );
check( ! gvn_wordpress_abilities_can_manage() && array() === $settings, 'Multisite site administrators must not manage or save abilities.' );
$super = true;
run_hook( 'admin_init' );
check( gvn_wordpress_abilities_can_manage() && isset( $settings['gvn_wordpress_abilities_disabled'] ), 'Multisite superadministrators must have access.' );
$can_manage = false;
check( ! gvn_wordpress_abilities_can_manage(), 'Users without manage_options must be denied.' );

echo "OK: defaults, disable/re-enable, catalog, sanitization, and permissions.\n";
