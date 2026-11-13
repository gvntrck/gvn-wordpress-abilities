<?php
/**
 * HTMLPress integration through its public group API, never direct meta writes.
 *
 * @package GVN_WordPress_Abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * Single ability for discovery, reading and writing HTMLPress groups.
 *
 * @return array Ability definition.
 */
function gvn_wordpress_abilities_htmlpress_definition() {
	return array(
		'label'               => __( 'Gerenciar páginas HTMLPress', 'gvn-wordpress-abilities' ),
		'description'         => __( 'Compatibilidade com HTMLPress 1.3.1 (classe HTMLPress, não o homônimo do WordPress.org). action=list lista páginas; read lê um grupo e sua revision; save cria (id=0, title, slug e documents.pt obrigatórios) ou edita (id e expected_revision da leitura obrigatórios). Novas páginas/traduções são PUBLICADAS imediatamente; existentes mantêm status. Título e slug são compartilhados; idiomas omitidos e assets existentes são preservados. HTML completo de até 2 MiB por idioma, incluindo scripts: envie apenas conteúdo confiável. Não gerencia ZIP/assets, exclusão ou status.', 'gvn-wordpress-abilities' ),
		'input_schema'        => array(
			'type'                 => 'object',
			'properties'           => array(
				'action'            => array( 'type' => 'string', 'enum' => array( 'list', 'read', 'save' ) ),
				'id'                => array( 'type' => 'integer', 'minimum' => 0, 'description' => __( 'ID de qualquer página do grupo; zero ou omitido para criar.', 'gvn-wordpress-abilities' ) ),
				'page'              => array( 'type' => 'integer', 'minimum' => 1, 'default' => 1 ),
				'title'             => array( 'type' => 'string', 'description' => __( 'Título compartilhado. Omitir na edição mantém o atual.', 'gvn-wordpress-abilities' ) ),
				'slug'              => array( 'type' => 'string', 'description' => __( 'Slug compartilhado. Mudanças alteram URLs sem redirecionar links antigos. Omitir mantém o atual.', 'gvn-wordpress-abilities' ) ),
				'expected_revision' => array( 'type' => 'string', 'pattern' => '^[a-f0-9]{64}$', 'description' => __( 'revision retornada por read; obrigatória para editar.', 'gvn-wordpress-abilities' ) ),
				'documents'         => array(
					'type'                 => 'object',
					'properties'           => array(
						'pt' => array( 'type' => 'string', 'maxLength' => 2097152 ),
						'en' => array( 'type' => 'string', 'maxLength' => 2097152 ),
						'es' => array( 'type' => 'string', 'maxLength' => 2097152 ),
					),
					'additionalProperties' => false,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		),
		'permission_callback' => 'gvn_wordpress_abilities_can_manage_htmlpress',
		'execute_callback'    => 'gvn_wordpress_abilities_manage_htmlpress',
		'meta'                => array(
			'mcp'         => array( 'public' => true ),
			'annotations' => array( 'readonly' => false, 'destructive' => true, 'idempotent' => false ),
		),
	);
}

/**
 * Check dependency at execution time, independent of plugin loading order.
 *
 * @return bool Whether the installed integration API is available.
 */
function gvn_wordpress_abilities_htmlpress_available() {
	foreach ( array( 'can_manage', 'is_managed', 'group', 'public_slug', 'url', 'save_group' ) as $method ) {
		if ( ! is_callable( array( 'HTMLPress', $method ) ) ) {
			return false;
		}
	}
	return defined( 'HTMLPress::HTML' ) && defined( 'HTMLPress::GROUP' ) && defined( 'HTMLPress::LANG' );
}

/**
 * Require both plugins' access policies, including unfiltered_html.
 *
 * @return bool Access granted.
 */
function gvn_wordpress_abilities_can_manage_htmlpress() {
	return gvn_wordpress_abilities_can_manage()
		&& gvn_wordpress_abilities_htmlpress_available()
		&& HTMLPress::can_manage();
}

/**
 * Return a page summary without sending its potentially large HTML.
 *
 * @param WP_Post $post Managed page.
 * @return array Page summary.
 */
function gvn_wordpress_abilities_htmlpress_page( $post ) {
	return array(
		'id'       => (int) $post->ID,
		'title'    => $post->post_title,
		'status'   => $post->post_status,
		'language' => get_post_meta( $post->ID, HTMLPress::LANG, true ),
		'group'    => get_post_meta( $post->ID, HTMLPress::GROUP, true ),
		'slug'     => HTMLPress::public_slug( $post->ID ),
		'url'      => HTMLPress::url( $post->ID ),
	);
}

/**
 * Read all translations and a fingerprint used to detect stale edits.
 *
 * @param int $id Any page in a group.
 * @return array|WP_Error Group snapshot.
 */
function gvn_wordpress_abilities_htmlpress_read( $id ) {
	$post = $id > 0 ? get_post( $id ) : null;
	if ( ! $post || 'page' !== $post->post_type || 'trash' === $post->post_status || ! HTMLPress::is_managed( $id ) ) {
		return new WP_Error( 'gvn_htmlpress_invalid_page', __( 'Informe uma página HTMLPress fora da lixeira.', 'gvn-wordpress-abilities' ) );
	}
	$group = HTMLPress::group( $id );
	if ( ! $group ) {
		return new WP_Error( 'gvn_htmlpress_invalid_group', __( 'Grupo HTMLPress não encontrado.', 'gvn-wordpress-abilities' ) );
	}
	ksort( $group );
	$pages     = array();
	$documents = array();
	foreach ( $group as $language => $page ) {
		if ( ! current_user_can( 'edit_post', $page->ID ) ) {
			return new WP_Error( 'gvn_htmlpress_forbidden', __( 'Sem permissão para todas as traduções.', 'gvn-wordpress-abilities' ) );
		}
		$pages[ $language ]     = gvn_wordpress_abilities_htmlpress_page( $page );
		$documents[ $language ] = (string) get_post_meta( $page->ID, HTMLPress::HTML, true );
	}
	return array(
		'pages'     => $pages,
		'documents' => $documents,
		'revision'  => hash( 'sha256', serialize( array( $pages, $documents ) ) ),
	);
}

/**
 * Dispatch the integration without bypassing HTMLPress validation or rollback.
 *
 * @param array $input Validated ability input (HTML is not request-slashed).
 * @return array|WP_Error Operation result.
 */
function gvn_wordpress_abilities_manage_htmlpress( $input ) {
	if ( ! gvn_wordpress_abilities_htmlpress_available() ) {
		return new WP_Error( 'gvn_htmlpress_unavailable', __( 'Ative uma versão compatível do HTMLPress (API da versão 1.3.1).', 'gvn-wordpress-abilities' ) );
	}
	if ( ! gvn_wordpress_abilities_can_manage_htmlpress() ) {
		return new WP_Error( 'gvn_htmlpress_forbidden', __( 'Permissão insuficiente para gerenciar HTMLPress.', 'gvn-wordpress-abilities' ) );
	}
	$action = $input['action'] ?? '';
	$id     = $input['id'] ?? 0;
	if ( ! is_int( $id ) || $id < 0 || ! in_array( $action, array( 'list', 'read', 'save' ), true ) ) {
		return new WP_Error( 'gvn_htmlpress_invalid_input', __( 'Ação ou ID inválido.', 'gvn-wordpress-abilities' ) );
	}
	if ( 'list' === $action ) {
		$page = $input['page'] ?? 1;
		if ( ! is_int( $page ) || $page < 1 ) {
			return new WP_Error( 'gvn_htmlpress_invalid_input', __( 'Página de resultados inválida.', 'gvn-wordpress-abilities' ) );
		}
		$query = new WP_Query(
			array(
				'post_type'      => 'page',
				'post_status'    => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page' => 20,
				'paged'          => $page,
				'orderby'        => 'ID',
				'order'          => 'ASC',
				'meta_query'     => array( array( 'key' => HTMLPress::GROUP, 'value' => '', 'compare' => '!=' ) ),
			)
		);
		$pages = array();
		foreach ( $query->posts as $post ) {
			if ( current_user_can( 'edit_post', $post->ID ) ) {
				$pages[] = gvn_wordpress_abilities_htmlpress_page( $post );
			}
		}
		return array( 'pages' => $pages, 'next_page' => $page < $query->max_num_pages ? $page + 1 : null );
	}
	if ( 'read' === $action ) {
		return gvn_wordpress_abilities_htmlpress_read( $id );
	}
	$current = $id ? gvn_wordpress_abilities_htmlpress_read( $id ) : null;
	if ( is_wp_error( $current ) ) {
		return $current;
	}
	if ( $id && ( ! isset( $input['expected_revision'] ) || ! is_string( $input['expected_revision'] ) || ! hash_equals( $current['revision'], $input['expected_revision'] ) ) ) {
		return new WP_Error( 'gvn_htmlpress_revision_conflict', __( 'Leia novamente o grupo e envie sua revision em expected_revision antes de editar.', 'gvn-wordpress-abilities' ) );
	}
	$anchor    = $id ? ( $current['pages']['pt'] ?? reset( $current['pages'] ) ) : array();
	$title     = $input['title'] ?? ( $anchor['title'] ?? '' );
	$slug      = $input['slug'] ?? ( $anchor['slug'] ?? '' );
	$documents = $input['documents'] ?? array();
	if ( ! is_string( $title ) || ! is_string( $slug ) || '' === sanitize_text_field( $title ) || '' === sanitize_title( $slug )
		|| ! is_array( $documents ) || array_diff( array_keys( $documents ), array( 'pt', 'en', 'es' ) )
		|| ( ! $id && ! isset( $documents['pt'] ) ) ) {
		return new WP_Error( 'gvn_htmlpress_invalid_input', __( 'Informe título, slug e documentos válidos; português é obrigatório na criação.', 'gvn-wordpress-abilities' ) );
	}
	foreach ( $documents as $html ) {
		if ( ! is_string( $html ) || strlen( $html ) > 2097152 ) {
			return new WP_Error( 'gvn_htmlpress_invalid_input', __( 'Cada documento deve ser texto HTML de até 2 MiB.', 'gvn-wordpress-abilities' ) );
		}
	}
	// HTMLPress validates full HTML, preserves omitted languages and rolls back failures.
	// Keep existing asset bundles: this operation edits HTML only, not uploaded files.
	$result = HTMLPress::save_group( $id, $title, $slug, $documents );
	if ( is_wp_error( $result ) ) {
		return $result;
	}
	$pages = array();
	foreach ( $result as $language => $post_id ) {
		$pages[ $language ] = gvn_wordpress_abilities_htmlpress_page( get_post( $post_id ) );
	}
	return array( 'pages' => $pages );
}
