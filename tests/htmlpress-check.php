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
	const TYPE  = '_htmlpress_type';
	public static $allowed = true;
	public static $php_allowed = true;
	public static $calls = array();
	public static $failure;
	public static function can_manage() {
		return self::$allowed;
	}
	public static function can_upload_php() {
		return self::$php_allowed;
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
	public static function save_group( $id, $title, $slug, $documents, $before_commit = null, $types = array() ) {
		self::$calls[] = func_get_args();
		if ( in_array( 'php', $types, true ) && ! self::can_upload_php() ) {
			return new WP_Error( 'htmlpress_php_forbidden', 'PHP upload denied.' );
		}
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
check( 'html' === $read['pages']['pt']['type'] && array( 'en' => 'html', 'pt' => 'html' ) === $read['types'], 'Legacy pages default to HTML and expose formats by language.' );
$list = gvn_wordpress_abilities_manage_htmlpress( array( 'action' => 'list' ) );
check( 2 === count( $list['pages'] ) && 2 === $list['next_page'], 'List summaries with pagination.' );
check( ! isset( $list['pages'][0]['documents'] ) && 20 === $query_args['posts_per_page'], 'Bound listing and do not expose HTML in summaries.' );
check( 'html' === $list['pages'][0]['type'], 'List summaries include the document type.' );

$html = '<html><head><script>const x = "\\\\value";</script></head><body>ação</body></html>';
$edit = array( 'action' => 'save', 'id' => 10, 'expected_revision' => $read['revision'], 'documents' => array( 'pt' => $html ) );
$saved = gvn_wordpress_abilities_manage_htmlpress( $edit );
check( ! is_wp_error( $saved ), 'Edit must succeed.' );
check( array( 10, 'Sobre', 'sobre', array( 'pt' => $html ), null, array( 'pt' => 'html' ) ) === end( HTMLPress::$calls ), 'Delegate exact bytes and only requested languages; preserve title/slug.' );
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
$php = '<?php $path = "\\\\template"; ?><html><head></head><body><?= esc_html($path) ?></body></html>';
$mixed = array( 'action' => 'save', 'title' => 'Mixed', 'slug' => 'mixed', 'documents' => array( 'pt' => $php, 'en' => $html ), 'types' => array( 'pt' => 'php' ) );
$result = gvn_wordpress_abilities_manage_htmlpress( $mixed );
check( ! is_wp_error( $result ) && array( 0, 'Mixed', 'mixed', $mixed['documents'], null, array( 'pt' => 'php', 'en' => 'html' ) ) === end( HTMLPress::$calls ), 'Create mixed PHP/HTML groups with exact source and explicit formats.' );
$meta[10][ HTMLPress::TYPE ] = 'php';
$meta[10][ HTMLPress::HTML ] = $php;
$php_read = gvn_wordpress_abilities_manage_htmlpress( array( 'action' => 'read', 'id' => 10 ) );
check( 'php' === $php_read['types']['pt'] && $php === $php_read['documents']['pt'], 'Read PHP source without executing it.' );
$php_edit = array( 'action' => 'save', 'id' => 10, 'expected_revision' => $php_read['revision'], 'documents' => array( 'pt' => $php ) );
check( ! is_wp_error( gvn_wordpress_abilities_manage_htmlpress( $php_edit ) ) && array( 'pt' => 'php' ) === end( HTMLPress::$calls )[5], 'Omitted format preserves PHP on edit.' );
$meta[10][ HTMLPress::TYPE ] = 'html';
htmlpress_error( $php_edit, 'gvn_htmlpress_revision_conflict' );
$meta[10][ HTMLPress::TYPE ] = 'php';
htmlpress_error( array_merge( $php_edit, array( 'types' => 'php' ) ), 'gvn_htmlpress_invalid_input' );
htmlpress_error( array_merge( $php_edit, array( 'types' => array( 'pt' => 'PHP' ) ) ), 'gvn_htmlpress_invalid_input' );
htmlpress_error( array_merge( $php_edit, array( 'types' => array( 'pt' => null ) ) ), 'gvn_htmlpress_invalid_input' );
htmlpress_error( array_merge( $php_edit, array( 'types' => array( 'fr' => 'php' ) ) ), 'gvn_htmlpress_invalid_input' );
htmlpress_error( array_merge( $php_edit, array( 'types' => array( 'en' => 'php' ) ) ), 'gvn_htmlpress_invalid_input' );
$converted = gvn_wordpress_abilities_manage_htmlpress( array_merge( $php_edit, array( 'documents' => array( 'pt' => $html ), 'types' => array( 'pt' => 'html' ) ) ) );
check( ! is_wp_error( $converted ) && array( 'pt' => 'html' ) === end( HTMLPress::$calls )[5], 'Explicit format supports conversion back to HTML.' );
HTMLPress::$php_allowed = false;
$denied = gvn_wordpress_abilities_manage_htmlpress( $php_edit );
check( is_wp_error( $denied ) && 'htmlpress_php_forbidden' === $denied->code, 'Preserve native PHP permission checks and error.' );
$title_only = gvn_wordpress_abilities_manage_htmlpress( array( 'action' => 'save', 'id' => 10, 'expected_revision' => $php_read['revision'], 'title' => 'Renamed' ) );
check( ! is_wp_error( $title_only ) && array() === end( HTMLPress::$calls )[5], 'Metadata-only edits do not resubmit PHP or require PHP upload rights.' );
$html_only = gvn_wordpress_abilities_manage_htmlpress( array( 'action' => 'save', 'id' => 10, 'expected_revision' => $php_read['revision'], 'documents' => array( 'en' => $html ) ) );
check( ! is_wp_error( $html_only ) && array( 'en' => 'html' ) === end( HTMLPress::$calls )[5], 'Editing HTML leaves omitted PHP untouched even without PHP upload rights.' );
HTMLPress::$php_allowed = true;
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
