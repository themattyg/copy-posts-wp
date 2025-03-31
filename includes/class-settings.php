<?php
/**
 * Plugin Options Class
 * Build a settings page for the plugin.
 *
 * @package WordPress
 * @subpackage Copy Posts WP
 * @since 1.0.0
 */

	if ( ! defined( 'ABSPATH' ) ) {
		exit;
	}

	class MGCPWP_Settings {

		/**
		 * Initialize the settings page.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'admin_menu', [ __CLASS__, 'add_settings_page' ] );
			add_action( 'admin_init', [ __CLASS__, 'register_settings' ] );
		}

		/**
		 * Add the settings page to the WordPress admin menu.
		 *
		 * @return void
		 */
		public static function add_settings_page(): void {
			add_menu_page(
				'Post Sync Settings',
				'Post Sync',
				'manage_options',
				'mgcpwp-settings',
				[ __CLASS__, 'settings_page' ],
				'dashicons-update',
				80
			);
		}

		/**
		 * Register the settings for the plugin.
		 *
		 * @return void
		 */
		public static function register_settings(): void {
			register_setting( 'mgcpwp_settings_group', 'mgcpwp_settings' );
			add_settings_section( 'mgcpwp_main_settings', 'Sync Settings', '', 'mgcpwp-settings' );
			add_settings_field( 'external_site_url', 'External Site URL', [
				__CLASS__,
				'field_callback'
			], 'mgcpwp-settings', 'mgcpwp_main_settings', [ 'field' => 'external_site_url' ] );
			add_settings_field( 'post_type', 'Post Type', [
				__CLASS__,
				'field_callback'
			], 'mgcpwp-settings', 'mgcpwp_main_settings', [ 'field' => 'post_type' ] );
			add_settings_field( 'category', 'Category ID(s)', [
				__CLASS__,
				'field_callback'
			], 'mgcpwp-settings', 'mgcpwp_main_settings', [ 'field' => 'category' ] );
			add_settings_field( 'tag', 'Tag ID(s)', [
				__CLASS__,
				'field_callback'
			], 'mgcpwp-settings', 'mgcpwp_main_settings', [ 'field' => 'tag' ] );
		}

		/**
		 * Callback function for the settings fields.
		 *
		 * @param array $args The arguments passed to the field.
		 *
		 * @return void
		 */
		public static function field_callback( array $args ): void {
			$options = get_option( 'mgcpwp_settings' );
			$field   = $args['field'];
			$value   = isset( $options[ $field ] ) ? esc_attr( $options[ $field ] ) : '';
			echo "<input type='text' name='mgcpwp_settings[$field]' value='$value' class='regular-text' />";
		}

		/**
		 * Render the settings page.
		 *
		 * @return void
		 */
		public static function settings_page(): void {
			?>
            <div class="wrap">
                <h1>Copy Posts WP Settings</h1>
                <form method="post" action="options.php">
					<?php
						settings_fields( 'mgcpwp_settings_group' );
						do_settings_sections( 'mgcpwp-settings' );
						submit_button();
					?>
                </form>
                <a href="<?php echo esc_url( admin_url( 'admin-post.php?action=wpjps_manual_sync' ) ); ?>"
                   class="button button-primary">Manual Sync</a>
            </div>
			<?php
		}
	}
