<?php
/**
 * Read-only access to installed plugin source files.
 *
 * @package GVN_WordPress_Abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Require the native plugin source access capability as well as admin access.
 *
 * @return bool Access granted.
 */
function gvn_wordpress_abilities_can_read_plugin_files() {
	return gvn_wordpress_abilities_can_manage() && current_user_can( 'edit_plugins' );
}

/**
 * Read a bounded text file belonging to an installed plugin, never execute it.
 *
 * @param array $input Plugin main file and optional target file.
 * @return array|WP_Error File contents or an error.
 */
function gvn_wordpress_abilities_read_plugin_file( $input ) {
	if ( ! gvn_wordpress_abilities_can_read_plugin_files() ) {
		return new WP_Error( 'plugin_file_forbidden', __( 'Você não tem permissão para ler arquivos de plugins.', 'gvn-wordpress-abilities' ) );
	}

	$plugin = $input['plugin'] ?? '';
	$file   = $input['file'] ?? $plugin;
	foreach ( array( $plugin, $file ) as $path ) {
		if ( ! is_string( $path ) || '' === $path || false !== strpos( $path, "\0" ) || 0 !== validate_file( $path ) || false !== strpos( $path, '\\' ) || '/' === $path[0] ) {
			return new WP_Error( 'plugin_file_invalid_path', __( 'Informe um caminho relativo válido da pasta de plugins.', 'gvn-wordpress-abilities' ) );
		}
	}

	$offset = $input['offset'] ?? 0;
	if ( ! is_int( $offset ) || $offset < 0 ) {
		return new WP_Error( 'plugin_file_invalid_offset', __( 'O deslocamento deve ser um número inteiro não negativo.', 'gvn-wordpress-abilities' ) );
	}

	if ( ! function_exists( 'get_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	$plugins = get_plugins();
	if ( ! isset( $plugins[ $plugin ] ) ) {
		return new WP_Error( 'plugin_file_not_found', __( 'Arquivo não encontrado neste plugin instalado.', 'gvn-wordpress-abilities' ) );
	}

	$root   = realpath( WP_PLUGIN_DIR );
	$target = realpath( WP_PLUGIN_DIR . '/' . $file );
	$folder = realpath( dirname( WP_PLUGIN_DIR . '/' . $plugin ) );
	// Resolve symlinks and enforce directory boundaries, not just string prefixes.
	if ( false === $root || false === $target || false === $folder || 0 !== strpos( $target, $root . DIRECTORY_SEPARATOR ) || 0 !== strpos( $target, $folder . DIRECTORY_SEPARATOR ) || ( $folder === $root && $file !== $plugin ) || ! is_file( $target ) || ! is_readable( $target ) ) {
		return new WP_Error( 'plugin_file_unreadable', __( 'O arquivo não é legível ou está fora da pasta permitida.', 'gvn-wordpress-abilities' ) );
	}

	// Bounded read: never load an unbounded file, including if it grows during reading.
	// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local read with explicit error handling and a size limit.
	$content = @file_get_contents( $target, false, null, $offset, 262145 );
	if ( false === $content ) {
		return new WP_Error( 'plugin_file_read_failed', __( 'Não foi possível ler o arquivo.', 'gvn-wordpress-abilities' ) );
	}
	$has_more = strlen( $content ) > 262144;
	if ( $has_more ) {
		$content = substr( $content, 0, 262144 );
		// Do not split a UTF-8 character at the page boundary.
		for ( $trim = 0; $trim < 3 && 1 !== preg_match( '//u', $content ); ++$trim ) {
			$content = substr( $content, 0, -1 );
		}
	}
	if ( false !== strpos( $content, "\0" ) || 1 !== preg_match( '//u', $content ) ) {
		return new WP_Error( 'plugin_file_not_text', __( 'O arquivo precisa conter texto UTF-8, não conteúdo binário.', 'gvn-wordpress-abilities' ) );
	}

	return array(
		'plugin'      => $plugin,
		'file'        => $file,
		'offset'      => $offset,
		'bytes'       => strlen( $content ),
		'next_offset' => $has_more ? $offset + strlen( $content ) : null,
		'content'     => $content,
	);
}
