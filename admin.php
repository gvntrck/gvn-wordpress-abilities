<?php
/**
 * Native WordPress admin screen for this plugin's abilities.
 *
 * @package GVN_WordPress_Abilities
 */

defined( 'ABSPATH' ) || exit;

add_action(
	'admin_enqueue_scripts',
	static function ( $hook ) {
		if ( 'settings_page_gvn-wordpress-abilities' === $hook ) {
			wp_enqueue_style( 'gvn-wordpress-abilities-admin', plugins_url( 'admin.css', __FILE__ ), array(), '1.2.1' );
		}
	}
);

add_action(
	'admin_menu',
	static function () {
		if ( gvn_wordpress_abilities_can_manage() ) {
			add_options_page(
				__( 'GVN WordPress Abilities', 'gvn-wordpress-abilities' ),
				__( 'GVN Abilities', 'gvn-wordpress-abilities' ),
				'manage_options',
				'gvn-wordpress-abilities',
				'gvn_wordpress_abilities_admin_page'
			);
		}
	}
);

add_action(
	'admin_init',
	static function () {
		// Do not allow site administrators to save these options on multisite.
		if ( gvn_wordpress_abilities_can_manage() ) {
			register_setting(
				'gvn_wordpress_abilities',
				'gvn_wordpress_abilities_disabled',
				array(
					'type'              => 'array',
					'sanitize_callback' => 'gvn_wordpress_abilities_sanitize_disabled',
					'default'           => array(),
				)
			);
		}
	}
);

/**
 * Accept only disabled names belonging to this plugin.
 *
 * @param mixed $value Submitted option.
 * @return array Valid disabled ability names.
 */
function gvn_wordpress_abilities_sanitize_disabled( $value ) {
	if ( ! is_array( $value ) ) {
		return array();
	}

	return array_values(
		array_filter(
			array_keys( gvn_wordpress_abilities_catalog() ),
			static function ( $name ) use ( $value ) {
				return in_array( $name, $value, true );
			}
		)
	);
}

/**
 * Render all plugin abilities, even those no longer registered.
 */
function gvn_wordpress_abilities_admin_page() {
	if ( ! gvn_wordpress_abilities_can_manage() ) {
		wp_die( esc_html__( 'Você não tem permissão para gerenciar estas habilidades.', 'gvn-wordpress-abilities' ) );
	}

	$disabled = gvn_wordpress_abilities_disabled();
	?>
	<div class="wrap">
		<h1><?php esc_html_e( 'GVN WordPress Abilities', 'gvn-wordpress-abilities' ); ?></h1>
		<p><?php esc_html_e( 'Habilidades permitem que clientes MCP consultem informações do site. Este plugin expõe apenas a estrutura das tabelas, nunca seus registros.', 'gvn-wordpress-abilities' ); ?></p>
		<?php settings_errors(); ?>
		<?php if ( ! function_exists( 'wp_register_ability' ) ) : ?>
			<div class="notice notice-warning"><p><?php esc_html_e( 'A API de habilidades não está disponível. Use WordPress 6.9 ou superior para executar as habilidades.', 'gvn-wordpress-abilities' ); ?></p></div>
		<?php endif; ?>
		<form action="<?php echo esc_url( admin_url( 'options.php' ) ); ?>" method="post">
			<?php settings_fields( 'gvn_wordpress_abilities' ); ?>
			<input type="hidden" name="gvn_wordpress_abilities_disabled[]" value="">
			<p id="gvn-abilities-help"><?php esc_html_e( 'Marque as habilidades que deseja desativar e salve. Desativadas, elas deixam de ser registradas e não podem ser executadas pelo MCP. As permissões de administrador continuam obrigatórias para as ativas.', 'gvn-wordpress-abilities' ); ?></p>
			<table class="widefat striped gvn-abilities-table">
				<caption class="screen-reader-text"><?php esc_html_e( 'Habilidades deste plugin', 'gvn-wordpress-abilities' ); ?></caption>
				<thead>
					<tr>
						<th scope="col"><?php esc_html_e( 'Habilidade e descrição', 'gvn-wordpress-abilities' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Estado salvo', 'gvn-wordpress-abilities' ); ?></th>
						<th scope="col"><?php esc_html_e( 'Desativar', 'gvn-wordpress-abilities' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( gvn_wordpress_abilities_catalog() as $name => $ability ) : ?>
						<?php $is_disabled = in_array( $name, $disabled, true ); ?>
						<tr>
							<th scope="row">
								<strong><?php echo esc_html( $ability['label'] ); ?></strong>
								<p><code><?php echo esc_html( $name ); ?></code></p>
								<p><?php echo esc_html( $ability['description'] ); ?></p>
							</th>
							<td><?php echo $is_disabled ? esc_html__( 'Desativada', 'gvn-wordpress-abilities' ) : esc_html__( 'Ativa', 'gvn-wordpress-abilities' ); ?></td>
							<td>
								<label>
									<input type="checkbox" name="gvn_wordpress_abilities_disabled[]" value="<?php echo esc_attr( $name ); ?>" <?php checked( $is_disabled ); ?> aria-describedby="gvn-abilities-help">
									<span class="screen-reader-text"><?php echo esc_html( $ability['label'] ); ?>: </span>
									<?php esc_html_e( 'Desativar', 'gvn-wordpress-abilities' ); ?>
								</label>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>
			<?php submit_button( __( 'Salvar alterações', 'gvn-wordpress-abilities' ) ); ?>
		</form>
	</div>
	<?php
}
