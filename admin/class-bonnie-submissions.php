<?php

/**
 * Submissions admin page controller.
 *
 * Owns the top-level Bonnie menu and the submissions screen: it processes the
 * permanent-delete action on admin_init — before any output, so it can redirect
 * — and renders either the DataViews list or a single submission's detail view.
 * Add-ons inject extra actions through the documented hooks (see docs/HOOKS.md).
 *
 * @link       https://beforebonnie.com
 * @since      1.0.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/admin
 */

/**
 * Submissions page controller.
 *
 * @since      1.0.0
 * @package    Bonnie
 * @subpackage Bonnie/admin
 * @author     Red Pocket <studio@redpocket.hk>
 */
class Bonnie_Submissions_Page {

	/**
	 * Admin page slug (the top-level menu).
	 *
	 * @var string
	 */
	const PAGE = 'bonnie';

	/**
	 * Data-access layer.
	 *
	 * @var Bonnie_Store
	 */
	protected $store;

	/**
	 * Logger.
	 *
	 * @var Bonnie_Logger
	 */
	protected $logger;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Bonnie_Store  $store  Data-access layer.
	 * @param Bonnie_Logger $logger Logger.
	 */
	public function __construct( Bonnie_Store $store, Bonnie_Logger $logger ) {
		$this->store  = $store;
		$this->logger = $logger;
	}

	/**
	 * Register the top-level menu and the Submissions subpage.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function add_menu() {
		add_menu_page(
			__( 'Bonnie', 'the-bonnie-situation' ),
			__( 'Bonnie', 'the-bonnie-situation' ),
			bonnie_capability(),
			self::PAGE,
			array( $this, 'render' ),
			'dashicons-businesswoman',
			81
		);

		add_submenu_page(
			self::PAGE,
			__( 'Submissions', 'the-bonnie-situation' ),
			__( 'Submissions', 'the-bonnie-situation' ),
			bonnie_capability(),
			self::PAGE,
			array( $this, 'render' )
		);
	}

	/**
	 * Process the permanent-delete action before any output. Hooked on admin_init.
	 *
	 * Add-ons register their own admin_init handlers for the actions they add
	 * (export, trash, restore); this handler only owns permanent delete.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function handle_actions() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$page = isset( $_REQUEST['page'] ) ? sanitize_key( $_REQUEST['page'] ) : '';
		if ( self::PAGE !== $page ) {
			return;
		}
		$action = $this->resolve_action();
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( 'delete' !== $action ) {
			return; // Not ours (add-ons handle their own actions).
		}

		if ( ! current_user_can( bonnie_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'the-bonnie-situation' ) );
		}

		// Nonce is verified below via check_admin_referer(); ids are cast with
		// absint() at access, so the raw request value is never trusted.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$is_bulk = isset( $_REQUEST['submission'] ) && is_array( $_REQUEST['submission'] );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$raw_ids = isset( $_REQUEST['submission'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['submission'] ) ) : array();
		$ids     = array_filter( $raw_ids );

		if ( empty( $ids ) ) {
			return;
		}

		// Verify the appropriate nonce: a bulk nonce, or the per-row nonce
		// embedded in the detail-view action link.
		if ( $is_bulk ) {
			check_admin_referer( 'bulk-submissions' );
		} else {
			check_admin_referer( 'bonnie_action_' . (int) reset( $ids ) );
		}

		$this->run_delete( $ids );
		$this->redirect_with_notice( 'delete', count( $ids ) );
	}

	/**
	 * Resolve the requested action from the top or bottom bulk dropdown / link.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	protected function resolve_action() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';
		if ( '' !== $action && '-1' !== $action ) {
			return $action;
		}
		$action2 = isset( $_REQUEST['action2'] ) ? sanitize_key( $_REQUEST['action2'] ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		return ( '' !== $action2 && '-1' !== $action2 ) ? $action2 : '';
	}

	/**
	 * Permanently delete a set of submissions.
	 *
	 * @since 1.0.0
	 * @param int[] $ids Submission ids.
	 * @return void
	 */
	protected function run_delete( array $ids ) {
		try {
			$this->store->delete_submissions( $ids );
		} catch ( \Throwable $e ) {
			$this->logger->error( 'admin_action_failed', $e );
		}
	}

	/**
	 * Redirect back to the list with a result notice.
	 *
	 * @since 1.0.0
	 * @param string $action Action performed.
	 * @param int    $count  Affected count.
	 * @return void
	 */
	protected function redirect_with_notice( $action, $count ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$status = isset( $_REQUEST['status'] ) ? sanitize_key( $_REQUEST['status'] ) : 'active';

		$url = add_query_arg(
			array(
				'page'          => self::PAGE,
				'status'        => $status,
				'bonnie_notice' => $action,
				'bonnie_count'  => (int) $count,
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Render the page (list or detail).
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function render() {
		if ( ! current_user_can( bonnie_capability() ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'the-bonnie-situation' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$submission = isset( $_REQUEST['submission'] ) && ! is_array( $_REQUEST['submission'] ) ? (int) $_REQUEST['submission'] : 0;

		if ( 'view' === $action && $submission > 0 ) {
			$this->render_detail( $submission );
			return;
		}

		$this->render_list();
	}

	/**
	 * Render the submissions list (DataViews React app mount point).
	 *
	 * Filtering, sorting, search, pagination and row actions are handled
	 * client-side by the DataViews app against the bonnie/v1 REST routes.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	protected function render_list() {
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Submissions', 'the-bonnie-situation' ); ?></h1>
			<?php
			/**
			 * Fires in the submissions list header, after the page title. Add-ons
			 * add page-title actions (e.g. Pro's Export buttons) here.
			 *
			 * @since 1.0.0
			 */
			do_action( 'bonnie_submissions_list_header' );
			?>
			<hr class="wp-header-end" />
			<?php $this->print_notice(); ?>
			<div id="bonnie-submissions-app" class="bonnie-submissions-app">
				<p><?php esc_html_e( 'Loading submissions…', 'the-bonnie-situation' ); ?></p>
			</div>
		</div>
		<?php
	}

	/**
	 * Enqueue the DataViews app on the submissions list screen.
	 *
	 * @since 1.0.0
	 * @param string $hook Current admin page hook suffix.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE !== $hook ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$action = isset( $_REQUEST['action'] ) ? sanitize_key( $_REQUEST['action'] ) : '';
		if ( 'view' === $action ) {
			return; // Detail view is server-rendered.
		}

		$asset_file = BONNIE_PLUGIN_DIR . 'build/index.asset.php';
		if ( ! file_exists( $asset_file ) ) {
			return; // Build artifact missing; fall back to the empty mount point.
		}

		$asset = require $asset_file;

		wp_enqueue_script(
			'bonnie-submissions-app',
			BONNIE_PLUGIN_URL . 'build/index.js',
			$asset['dependencies'],
			$asset['version'],
			true
		);

		// DataViews is bundled (JS only), so ship its stylesheet ourselves; it
		// builds on wp-components' base styles and CSS variables.
		wp_enqueue_style( 'wp-components' );
		wp_enqueue_style(
			'bonnie-dataviews',
			plugin_dir_url( __FILE__ ) . 'css/bonnie-dataviews.css',
			array( 'wp-components' ),
			$asset['version']
		);

		$forms = array();
		foreach ( $this->store->get_forms() as $form ) {
			$forms[] = array(
				'value' => (string) $form->form_id,
				'label' => '' !== $form->form_title ? $form->form_title : '#' . $form->form_id,
			);
		}

		wp_localize_script(
			'bonnie-submissions-app',
			'bonnieData',
			array(
				'restUrl' => esc_url_raw( rest_url() ),
				'nonce'   => wp_create_nonce( 'wp_rest' ),
				'forms'   => $forms,
				'storeIp' => ! empty( Bonnie_Store::get_setting( 'store_ip' ) ),
			)
		);
	}

	/**
	 * Render a single submission's detail view.
	 *
	 * @since 1.0.0
	 * @param int $id Submission id.
	 * @return void
	 */
	protected function render_detail( $id ) {
		$sub = $this->store->get_submission( $id );

		echo '<div class="wrap">';

		if ( ! $sub ) {
			echo '<h1>' . esc_html__( 'Submission', 'the-bonnie-situation' ) . '</h1>';
			echo '<p>' . esc_html__( 'That submission no longer exists.', 'the-bonnie-situation' ) . '</p>';
			echo '<p><a href="' . esc_url( add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) ) ) . '">' . esc_html__( '&larr; Back to submissions', 'the-bonnie-situation' ) . '</a></p>';
			echo '</div>';
			return;
		}

		$store_ip = ! empty( Bonnie_Store::get_setting( 'store_ip' ) );
		$meta     = $this->store->get_submission_meta( $id );
		$back     = add_query_arg( array( 'page' => self::PAGE ), admin_url( 'admin.php' ) );

		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Submission', 'the-bonnie-situation' ) . ' #' . (int) $sub->id . '</h1>';
		echo ' <a class="page-title-action" href="' . esc_url( $back ) . '">' . esc_html__( 'Back to submissions', 'the-bonnie-situation' ) . '</a>';
		echo '<hr class="wp-header-end" />';

		$rows = array(
			__( 'Date', 'the-bonnie-situation' )    => $sub->created_at,
			__( 'Form', 'the-bonnie-situation' )    => ( '' !== $sub->form_title ? $sub->form_title : '#' . $sub->form_id ),
			__( 'Status', 'the-bonnie-situation' )  => ucfirst( $sub->status ),
			__( 'Name', 'the-bonnie-situation' )    => $sub->from_name,
			__( 'Email', 'the-bonnie-situation' )   => $sub->from_email,
			__( 'Subject', 'the-bonnie-situation' ) => $sub->subject,
		);
		if ( $store_ip ) {
			$rows[ __( 'IP', 'the-bonnie-situation' ) ]      = $this->store->ip_to_string( $sub->remote_ip );
		}
		if ( ! empty( $sub->referer_url ) ) {
			$rows[ __( 'Referrer', 'the-bonnie-situation' ) ] = $sub->referer_url;
		}

		echo '<h2>' . esc_html__( 'Overview', 'the-bonnie-situation' ) . '</h2>';
		echo '<table class="widefat striped" style="max-width:760px"><tbody>';
		foreach ( $rows as $label => $value ) {
			echo '<tr><th scope="row" style="width:160px">' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		echo '</tbody></table>';

		echo '<h2>' . esc_html__( 'Fields', 'the-bonnie-situation' ) . '</h2>';
		if ( empty( $meta ) ) {
			echo '<p>' . esc_html__( 'No stored fields.', 'the-bonnie-situation' ) . '</p>';
		} else {
			echo '<table class="widefat striped" style="max-width:760px"><tbody>';
			foreach ( $meta as $row ) {
				echo '<tr><th scope="row" style="width:200px">' . esc_html( $row->meta_key ) . '</th><td>' . nl2br( esc_html( $row->meta_value ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}

		// Free core offers a single permanent delete. Add-ons inject their own
		// actions (e.g. the Pro trash workflow) through the hook below.
		echo '<p style="margin-top:16px">';
		echo '<a class="button button-link-delete" href="' . esc_url( $this->detail_action_url( 'delete', $sub->id ) ) . '">' . esc_html__( 'Delete permanently', 'the-bonnie-situation' ) . '</a>';

		/**
		 * Fires in the submission detail action bar. Add-ons echo extra buttons
		 * (e.g. Pro's Move to Trash / Restore) here.
		 *
		 * @since 1.0.0
		 * @param object $sub The submission row being viewed.
		 */
		do_action( 'bonnie_submission_detail_actions', $sub );
		echo '</p>';

		echo '</div>';
	}

	/**
	 * Nonced single-action URL used from the detail view.
	 *
	 * Public so add-ons can build correctly-nonced links for the actions they
	 * add to the detail view.
	 *
	 * @since 1.0.0
	 * @param string $action Action key.
	 * @param int    $id     Submission id.
	 * @return string
	 */
	public function detail_action_url( $action, $id ) {
		$url = add_query_arg(
			array(
				'page'       => self::PAGE,
				'action'     => $action,
				'submission' => (int) $id,
			),
			admin_url( 'admin.php' )
		);
		return wp_nonce_url( $url, 'bonnie_action_' . (int) $id );
	}

	/**
	 * Print the post-action admin notice, if any.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	protected function print_notice() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		if ( empty( $_GET['bonnie_notice'] ) ) {
			return;
		}
		$notice = sanitize_key( $_GET['bonnie_notice'] );
		$count  = isset( $_GET['bonnie_count'] ) ? (int) $_GET['bonnie_count'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		$messages = array(
			/* translators: %d: number of submissions. */
			'trash'   => _n( '%d submission moved to trash.', '%d submissions moved to trash.', $count, 'the-bonnie-situation' ),
			/* translators: %d: number of submissions. */
			'restore' => _n( '%d submission restored.', '%d submissions restored.', $count, 'the-bonnie-situation' ),
			/* translators: %d: number of submissions. */
			'delete'  => _n( '%d submission permanently deleted.', '%d submissions permanently deleted.', $count, 'the-bonnie-situation' ),
		);

		/**
		 * Filter the map of post-action admin notices. Add-ons register messages
		 * for the actions they add (e.g. export).
		 *
		 * @since 1.0.0
		 * @param array $messages notice-key => message string.
		 * @param int   $count    Affected count (for pluralisation context).
		 */
		$messages = apply_filters( 'bonnie_admin_notices', $messages, $count );

		if ( ! isset( $messages[ $notice ] ) ) {
			return;
		}

		printf(
			'<div class="notice notice-success is-dismissible"><p>%s</p></div>',
			esc_html( sprintf( $messages[ $notice ], $count ) )
		);
	}
}
