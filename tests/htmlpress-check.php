<?php
/**
 * Standalone integration contract check: php tests/htmlpress-check.php
 * Uses an HTMLPress API double; never writes to a WordPress installation.
 */

require __DIR__ . '/admin-check.php';

class WP_Error {
	public $code;
	public function __construct( $code, $message ) {
		$this->code = $code;
	}
}
function is_wp_error( $value ) {
	return $value instanceof WP_Error;
}
function sanitize_text_field( $value ) {
	return trim( strip_tags( $value ) );
}
function sanitize_title( $value ) {
	return strtolower( trim( $value ) );
}
function get_post( $id ) {
	return $GLOBALS['posts'][ $id ] ?? null;
}
function get_post_meta( $id, $key, $single ) {
	return $GLOBALS['meta'][ $id ][ $key ] ?? '';
}
class WP_Query {
	public $posts;
	public $max_num_pages = 2;
	public function __construct( $args ) {
		$GLOBALS['query_args'] = $args;
		$this->posts = array_values( $GLOBALS['posts'] );
	}
}
class HTMLPress {
	const GROUP = '_htmlpress_group';
	const LANG  = '_htmlpress_lang';
	const HTML  = '_htmlpress_html';
	public static $allowed = true;
	public static $calls = array();
	public static $failure;
	public static function can_manage() {
		return self::$allowed;
	}
	public static function is_managed( $id ) {
		return '' !== get_post_meta( $id, self::GROUP, true );
	}
	public static function group( $id ) {
		return self::is_managed( $id ) ? array( 'pt' => get_post( 10 ), 'en' => get_post( 11 ) ) : array();
	}
	public static function public_slug( $id ) {
		return 'sobre';
	}
	public static function url( $id ) {
		return 'https://example.org/' . $id . '/';
	}
	public static function save_group( $id, $title, $slug, $documents ) {
		self::$calls[] = func_get_args();
		return self::$failure ?? array( 'pt' => 10, 'en' => 11 );
	}
}

$can_manage = true;
$multisite  = false;
$posts      = array();
$meta       = array();
foreach ( array( 10 => 'pt', 11 => 'en' ) as $id => $language ) {
	$posts[ $id ] = (object) array( 'ID' => $id, 'post_type' => 'page', 'post_status' => 'publish', 'post_title' => 'Sobre' );
	$meta[ $id ]  = array( HTMLPress::GROUP => 'group-a', HTMLPress::LANG => $language, HTMLPress::HTML => '<html><head></head><body>' . $language . '</body></html>' );
}

function htmlpress_error( $input, $code ) {
	$before = count( HTMLPress::$calls );
	$result = gvn_wordpress_abilities_manage_htmlpress( $input );
	check( is_wp_error( $result ) && $code === $result->code, 'Expected error: ' . $code );
	check( count( HTMLPress::$calls ) === $before, 'Rejected input must not call the writer.' );
}

$definition = gvn_wordpress_abilities_htmlpress_definition();
check( false === $definition['meta']['annotations']['readonly'], 'Writing must never be advertised as read-only.' );
check( true === $definition['meta']['annotations']['destructive'] && false === $definition['meta']['annotations']['idempotent'], 'Declare overwrites and non-idempotent creation.' );
$read = gvn_wordpress_abilities_manage_htmlpress( array( 'action' => 'read', 'id' => 11 ) );
check( $read['documents']['pt'] === $meta[10][ HTMLPress::HTML ] && 64 === strlen( $read['revision'] ), 'Read original group HTML and revision.' );
$list = gvn_wordpress_abilities_manage_htmlpress( array( 'action' => 'list' ) );
check( 2 === count( $list['pages'] ) && 2 === $list['next_page'], 'List summaries with pagination.' );
check( ! isset( $list['pages'][0]['documents'] ) && 20 === $query_args['posts_per_page'], 'Bound listing and do not expose HTML in summaries.' );

$html = '<html><head><script>const x = "\\\\value";</script></head><body>ação</body></html>';
$edit = array( 'action' => 'save', 'id' => 10, 'expected_revision' => $read['revision'], 'documents' => array( 'pt' => $html ) );
$saved = gvn_wordpress_abilities_manage_htmlpress( $edit );
check( ! is_wp_error( $saved ), 'Edit must succeed.' );
check( array( 10, 'Sobre', 'sobre', array( 'pt' => $html ) ) === end( HTMLPress::$calls ), 'Delegate exact bytes and only requested languages; preserve title/slug.' );
htmlpress_error( array( 'action' => 'save', 'id' => 10 ), 'gvn_htmlpress_revision_conflict' );
$meta[11][ HTMLPress::HTML ] .= 'changed';
htmlpress_error( $edit, 'gvn_htmlpress_revision_conflict' );
$meta[11][ HTMLPress::HTML ] = $read['documents']['en'];
htmlpress_error( array( 'action' => 'save', 'title' => 'Title', 'slug' => 'title' ), 'gvn_htmlpress_invalid_input' );
htmlpress_error( array_merge( $edit, array( 'documents' => array( 'fr' => $html ) ) ), 'gvn_htmlpress_invalid_input' );
htmlpress_error( array_merge( $edit, array( 'documents' => array( 'pt' => array() ) ) ), 'gvn_htmlpress_invalid_input' );
htmlpress_error( array_merge( $edit, array( 'documents' => array( 'pt' => str_repeat( 'a', 2097153 ) ) ) ), 'gvn_htmlpress_invalid_input' );
htmlpress_error( array( 'action' => 'read', 'id' => 0 ), 'gvn_htmlpress_invalid_page' );
$posts[10]->post_status = 'trash';
htmlpress_error( $edit, 'gvn_htmlpress_invalid_page' );
$posts[10]->post_status = 'publish';

$created = gvn_wordpress_abilities_manage_htmlpress( array( 'action' => 'save', 'title' => 'New', 'slug' => 'new', 'documents' => array( 'pt' => $html, 'es' => $html ) ) );
check( ! is_wp_error( $created ) && 0 === end( HTMLPress::$calls )[0], 'Create through the native API with optional translations.' );
HTMLPress::$failure = new WP_Error( 'htmlpress_invalid_document', 'Native validation error.' );
check( HTMLPress::$failure === gvn_wordpress_abilities_manage_htmlpress( $edit ), 'Propagate native validation/save errors unchanged.' );
HTMLPress::$failure = null;
HTMLPress::$allowed = false;
htmlpress_error( $edit, 'gvn_htmlpress_forbidden' );
HTMLPress::$allowed = true;
$can_manage = false;
htmlpress_error( $edit, 'gvn_htmlpress_forbidden' );
$can_manage = true;
$multisite  = true;
$super      = false;
htmlpress_error( $edit, 'gvn_htmlpress_forbidden' );
echo "OK: HTMLPress contract, reads, listing, exact HTML delegation, create/edit, revisions, errors and permissions.\n";
