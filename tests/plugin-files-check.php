<?php
/**
 * Standalone regression check: php tests/plugin-files-check.php
 */

require __DIR__ . '/admin-check.php';

class WP_Error {
	public $code;
	public function __construct( $code, $message ) {
		$this->code = $code;
	}
}
function get_plugins() {
	return array( 'example/main.php' => array(), 'single.php' => array() );
}
function validate_file( $file ) {
	return in_array( '..', explode( '/', $file ), true ) || false !== strpos( $file, ':' ) ? 1 : 0;
}
function expect_error( $input, $code ) {
	$result = gvn_wordpress_abilities_read_plugin_file( $input );
	check( $result instanceof WP_Error && $result->code === $code, 'Expected error: ' . $code );
}

$can_manage = true;
$multisite  = false;
$can_edit   = true;
$temp       = sys_get_temp_dir() . '/gvn-files-' . bin2hex( random_bytes( 8 ) );
define( 'WP_PLUGIN_DIR', $temp );
mkdir( $temp );
mkdir( $temp . '/example' );
mkdir( $temp . '/example/vendor' );
$files = array(
	'/example/main.php'        => '<?php throw new Exception("Must never execute");',
	'/example/vendor/code.js' => 'export default true;',
	'/example/LICENSE'        => 'Text without an extension.',
	'/example/binary.dat'     => "\0\1binary",
	'/example/large.txt'      => str_repeat( 'a', 262143 ) . 'ção',
	'/single.php'            => '<?php // standalone',
);
$link = $temp . '/example/outside.php';

try {
	foreach ( $files as $file => $content ) {
		file_put_contents( $temp . $file, $content );
	}
	$input  = array( 'plugin' => 'example/main.php' );
	$result = gvn_wordpress_abilities_read_plugin_file( $input );
	check( $result['content'] === $files['/example/main.php'], 'Read source without executing it.' );
	check( null === $result['next_offset'], 'Small files need one page.' );
	foreach ( array( 'vendor/code.js', 'LICENSE' ) as $file ) {
		$result = gvn_wordpress_abilities_read_plugin_file( $input + array( 'file' => 'example/' . $file ) );
		check( $result['content'] === $files[ '/example/' . $file ], 'Read dependencies and extensionless text.' );
	}
	$page1 = gvn_wordpress_abilities_read_plugin_file( $input + array( 'file' => 'example/large.txt' ) );
	$page2 = gvn_wordpress_abilities_read_plugin_file( $input + array( 'file' => 'example/large.txt', 'offset' => $page1['next_offset'] ) );
	check( $page1['content'] . $page2['content'] === $files['/example/large.txt'], 'Pagination must preserve UTF-8 and the entire file.' );
	check( null === $page2['next_offset'], 'Pagination must finish.' );
	expect_error( $input + array( 'file' => '../wp-config.php' ), 'plugin_file_invalid_path' );
	expect_error( $input + array( 'file' => '/etc/passwd' ), 'plugin_file_invalid_path' );
	expect_error( $input + array( 'file' => "example/\0.php" ), 'plugin_file_invalid_path' );
	expect_error( $input + array( 'file' => 'single.php' ), 'plugin_file_unreadable' );
	expect_error( array( 'plugin' => 'single.php', 'file' => 'example/main.php' ), 'plugin_file_unreadable' );
	expect_error( array( 'plugin' => 'missing/main.php' ), 'plugin_file_not_found' );
	expect_error( $input + array( 'file' => 'example/missing.php' ), 'plugin_file_unreadable' );
	expect_error( $input + array( 'file' => 'example/binary.dat' ), 'plugin_file_not_text' );
	expect_error( $input + array( 'offset' => -1 ), 'plugin_file_invalid_offset' );
	if ( symlink( $temp . '/single.php', $link ) ) {
		expect_error( $input + array( 'file' => 'example/outside.php' ), 'plugin_file_unreadable' );
	}
	$can_edit = false;
	check( ! gvn_wordpress_abilities_can_read_plugin_files(), 'Require edit_plugins.' );
	expect_error( $input, 'plugin_file_forbidden' );
	echo "OK: source reading, dependencies, UTF-8 pagination, paths, symlinks, binary rejection, and permissions.\n";
} finally {
	if ( is_link( $link ) ) {
		unlink( $link );
	}
	foreach ( array_keys( $files ) as $file ) {
		if ( is_file( $temp . $file ) ) {
			unlink( $temp . $file );
		}
	}
	rmdir( $temp . '/example/vendor' );
	rmdir( $temp . '/example' );
	rmdir( $temp );
}
