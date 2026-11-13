<?php
/**
 * Plugin Name: GVN WordPress Abilities
 * Description: Habilidades MCP para consultar estruturas, ler arquivos de plugins e gerenciar páginas HTMLPress.
 * Version: 1.5.0
 * Author: Giovani
 * Author URI: https://projetoalfa.org
 * Requires at least: 6.9
 * Requires PHP: 7.4
 * Requires Plugins: mcp-adapter
 * Text Domain: gvn-wordpress-abilities
 */

defined( 'ABSPATH' ) || exit;



require 'plugin-update-checker/plugin-update-checker.php';
use YahnisElsts\PluginUpdateChecker\v6\PucFactory;

$myUpdateChecker = PucFactory::buildUpdateChecker(
	'https://github.com/gvntrck/gvn-wordpress-abilities',
	__FILE__,
	'gvn-wordpress-abilities'
);

//Set the branch that contains the stable release.
$myUpdateChecker->setBranch('master');








require_once __DIR__ . '/plugin-files.php';
require_once __DIR__ . '/htmlpress.php';

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

/**
 * Whether the current user may manage or execute abilities.
 *
 * @return bool Access granted.
 */
function gvn_wordpress_abilities_can_manage() {
	return current_user_can( 'manage_options' ) && ( ! is_multisite() || is_super_admin() );
}

/**
 * Catalog shared by registration and admin, including disabled abilities.
 *
 * @return array Ability definitions.
 */
function gvn_wordpress_abilities_catalog() {
	return array(
		'gvn-wordpress-abilities/manage-htmlpress' => gvn_wordpress_abilities_htmlpress_definition(),
		'gvn-wordpress-abilities/list-tables' => array(
			'label'            => __( 'Listar tabelas do site', 'gvn-wordpress-abilities' ),
			'description'      => __( 'Lista nomes de tabelas do prefixo do site atual, sem consultar registros.', 'gvn-wordpress-abilities' ),
			'input_schema'     => array( 'type' => 'object', 'properties' => new stdClass() ),
			'execute_callback' => 'gvn_wordpress_abilities_tables',
		),
		'gvn-wordpress-abilities/describe-table' => array(
			'label'            => __( 'Descrever tabela do site', 'gvn-wordpress-abilities' ),
			'description'      => __( 'Retorna colunas e índices de uma tabela do site, sem consultar registros.', 'gvn-wordpress-abilities' ),
			'input_schema'     => array(
				'type'       => 'object',
				'properties' => array( 'table' => array( 'type' => 'string', 'description' => __( 'Nome exato da tabela.', 'gvn-wordpress-abilities' ) ) ),
				'required'   => array( 'table' ),
			),
			'execute_callback' => 'gvn_wordpress_abilities_describe',
		),
		'gvn-wordpress-abilities/read-plugin-file' => array(
			'label'               => __( 'Ler arquivo de plugin', 'gvn-wordpress-abilities' ),
			'description'         => __( 'Lê códigos e textos de qualquer plugin instalado, incluindo vendor e node_modules, sem executar ou editar. Retorna até 256 KiB por chamada; continue usando next_offset até ser null. Exige edit_plugins. Pode enviar informações sensíveis ao cliente MCP.', 'gvn-wordpress-abilities' ),
			'input_schema'        => array(
				'type'                 => 'object',
				'properties'           => array(
					'plugin' => array( 'type' => 'string', 'description' => __( 'Arquivo principal relativo à pasta de plugins, por exemplo: meu-plugin/meu-plugin.php.', 'gvn-wordpress-abilities' ) ),
					'file'   => array( 'type' => 'string', 'description' => __( 'Arquivo a ler, relativo à pasta de plugins, por exemplo: meu-plugin/includes/example.php. Se omitido, lê o arquivo principal.', 'gvn-wordpress-abilities' ) ),
					'offset' => array( 'type' => 'integer', 'minimum' => 0, 'default' => 0, 'description' => __( 'Deslocamento em bytes. Para continuar, use next_offset da resposta anterior.', 'gvn-wordpress-abilities' ) ),
				),
				'required'             => array( 'plugin' ),
				'additionalProperties' => false,
			),
			'execute_callback'    => 'gvn_wordpress_abilities_read_plugin_file',
			'permission_callback' => 'gvn_wordpress_abilities_can_read_plugin_files',
		),
	);
}

/**
 * Retrieve disabled names; an absent option preserves the original behavior.
 *
 * @return array Disabled ability names.
 */
function gvn_wordpress_abilities_disabled() {
	return (array) get_option( 'gvn_wordpress_abilities_disabled', array() );
}

add_action(
	'wp_abilities_api_init',
	static function () {
		$common = array(
			'category'            => 'site',
			'permission_callback' => 'gvn_wordpress_abilities_can_manage',
			'meta'                => array(
				'mcp'         => array( 'public' => true ),
				'annotations' => array( 'readonly' => true, 'destructive' => false, 'idempotent' => true ),
			),
		);
		$disabled = gvn_wordpress_abilities_disabled();
		foreach ( gvn_wordpress_abilities_catalog() as $name => $args ) {
			if ( ! in_array( $name, $disabled, true ) ) {
				wp_register_ability( $name, array_merge( $common, $args ) );
			}
		}
	}
);

require_once __DIR__ . '/admin.php';
