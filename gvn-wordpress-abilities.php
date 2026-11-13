<?php
/**
 * Plugin Name: GVN WordPress Abilities
 * Description: Expõe apenas a estrutura das tabelas WordPress ao MCP, sem ler registros nem executar SQL informado pelo usuário.
 * Version: 1.1.0
 * Author: Giovani
 * Author URI: https://projetoalfa.org
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Text Domain: gvn-wordpress-abilities
 */

defined( 'ABSPATH' ) || exit;

/**
 * List only tables belonging to the current site's prefix.
 *
 * @return array|WP_Error Table names or an error.
 */
function gvn_wordpress_abilities_tables() {
	global $wpdb;
	$tables = $wpdb->get_col(
		$wpdb->prepare( 'SHOW FULL TABLES LIKE %s', $wpdb->esc_like( $wpdb->prefix ) . '%' )
	);
	if ( $wpdb->last_error ) {
		return new WP_Error( 'schema_query_failed', 'Não foi possível consultar as tabelas.' );
	}
	return array_values( array_filter( $tables, static function ( $table ) use ( $wpdb ) {
		return 0 === strpos( $table, $wpdb->prefix );
	} ) );
}

/**
 * Read column and index metadata for one existing site table.
 *
 * @param array $input Ability input.
 * @return array|WP_Error Metadata or an error.
 */
function gvn_wordpress_abilities_describe( $input ) {
	global $wpdb;
	$tables = gvn_wordpress_abilities_tables();
	if ( is_wp_error( $tables ) ) {
		return $tables;
	}
	$table = isset( $input['table'] ) ? $input['table'] : '';
	if ( ! is_string( $table ) || ! in_array( $table, $tables, true ) ) {
		return new WP_Error( 'schema_invalid_table', 'Tabela não encontrada neste site.' );
	}
	$identifier = '`' . str_replace( '`', '``', $table ) . '`';
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Identifier is validated against the table list and escaped.
	$columns = $wpdb->get_results( "SHOW COLUMNS FROM {$identifier}", ARRAY_A );
	if ( $wpdb->last_error ) {
		return new WP_Error( 'schema_query_failed', 'Não foi possível consultar as colunas.' );
	}
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Same validated identifier.
	$indexes = $wpdb->get_results( "SHOW INDEX FROM {$identifier}", ARRAY_A );
	if ( $wpdb->last_error ) {
		return new WP_Error( 'schema_query_failed', 'Não foi possível consultar os índices.' );
	}
	return array(
		'table'   => $table,
		'columns' => array_map(
			static function ( $column ) {
				return array_intersect_key( $column, array_flip( array( 'Field', 'Type', 'Null', 'Key', 'Extra' ) ) );
			},
			$columns
		),
		'indexes' => array_map(
			static function ( $index ) {
				return array_intersect_key( $index, array_flip( array( 'Key_name', 'Non_unique', 'Seq_in_index', 'Column_name', 'Index_type' ) ) );
			},
			$indexes
		),
	);
}

add_action(
	'wp_abilities_api_init',
	static function () {
		$common = array(
			'category'            => 'site',
			'permission_callback' => static function () {
				return current_user_can( 'manage_options' ) && ( ! is_multisite() || is_super_admin() );
			},
			'meta'                => array(
				'mcp'         => array( 'public' => true ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		);
		wp_register_ability(
			'gvn-wordpress-abilities/list-tables',
			array_merge(
				$common,
				array(
					'label'            => 'Listar tabelas do site',
					'description'      => 'Lista nomes de tabelas do prefixo do site atual, sem consultar registros.',
					'input_schema'     => array( 'type' => 'object', 'properties' => new stdClass() ),
					'execute_callback' => 'gvn_wordpress_abilities_tables',
				)
			)
		);
		wp_register_ability(
			'gvn-wordpress-abilities/describe-table',
			array_merge(
				$common,
				array(
					'label'            => 'Descrever tabela do site',
					'description'      => 'Retorna colunas e índices de uma tabela do site, sem consultar registros.',
					'input_schema'     => array(
						'type'       => 'object',
						'properties' => array( 'table' => array( 'type' => 'string', 'description' => 'Nome exato da tabela.' ) ),
						'required'   => array( 'table' ),
					),
					'execute_callback' => 'gvn_wordpress_abilities_describe',
				)
			)
		);
	}
);
