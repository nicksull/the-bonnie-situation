<?php

/**
 * Settings page.
 *
 * Registers the Settings subpage and hosts the React settings app (built from
 * src/settings.js). Values are saved through the /bonnie/v1/settings REST route,
 * not the Settings API.
 *
 * @link       https://beforebonnie.com
 * @since      1.0.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/admin
 */

/**
 * Settings page.
 *
 * @since      1.0.0
 * @package    Bonnie
 * @subpackage Bonnie/admin
 * @author     Red Pocket <studio@redpocket.hk>
 */
class Bonnie_Settings {

	/**
	 * Admin page slug.
	 *
	 * @var string
	 */
	const PAGE = 'bonnie-settings';

	/**
	 * Parent menu slug (the top-level Bonnie menu, owned by the submissions page).
	 *
	 * @var string
	 */
	const PARENT = 'bonnie';

	/**
	 * Register the Settings subpage under the Bonnie menu.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function add_menu() {
		add_submenu_page(
			self::PARENT,
			__( 'Privacy & Retention', 'the-bonnie-situation' ),
			__( 'Settings', 'the-bonnie-situation' ),
			bonnie_capability(),
			self::PAGE,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue the settings app on its screen.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( self::PARENT . '_page_' . self::PAGE !== $hook ) {
			return;
		}

		$asset_file = BONNIE_PLUGIN_DIR . 'build/settings.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return;
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'bonnie-settings-app',
			BONNIE_PLUGIN_URL . 'build/settings.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		wp_enqueue_style( 'wp-components' );

		wp_localize_script(
			'bonnie-settings-app',
			'bonnieSettings',
			array(
				'restUrl'    => esc_url_raw( rest_url() ),
				'nonce'      => wp_create_nonce( 'wp_rest' ),
				'settings'   => Bonnie_Store::get_settings(),
				'upgradeUrl' => bonnie_upgrade_url(),
			)
		);
	}

	/**
	 * Render the settings screen (React mount point).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( bonnie_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'the-bonnie-situation' ) );
		}
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Settings', 'the-bonnie-situation' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Clean it up before Bonnie gets home. Configure how Contact Form 7 submissions are stored and when they are automatically removed.', 'the-bonnie-situation' ); ?>
			</p>
			<div id="bonnie-settings-app" class="bonnie-settings-app">
				<p><?php esc_html_e( 'Loading settings…', 'the-bonnie-situation' ); ?></p>
			</div>
		</div>
		<?php
	}

}
