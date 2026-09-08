<?php

/**
 * REST API for the DataViews admin.
 *
 * Exposes read + batch-action routes under the bonnie/v1 namespace, used by the
 * React submissions screen. All routes are gated behind the Bonnie capability
 * and the standard REST cookie nonce.
 *
 * @link       https://beforebonnie.com
 * @since      1.0.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/includes
 */

/**
 * REST controller.
 *
 * @since      1.0.0
 * @package    Bonnie
 * @subpackage Bonnie/includes
 * @author     Red Pocket <studio@redpocket.hk>
 */
class Bonnie_REST {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const NAMESPACE = 'bonnie/v1';

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
	 * Register routes. Hooked on rest_api_init.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/submissions',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_submissions' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'status'    => array( 'type' => 'string', 'default' => 'active' ),
					'form_id'   => array( 'type' => 'integer', 'default' => 0 ),
					'search'    => array( 'type' => 'string', 'default' => '' ),
					'orderby'   => array( 'type' => 'string', 'default' => 'created_at' ),
					'order'     => array( 'type' => 'string', 'default' => 'desc' ),
					'page'      => array( 'type' => 'integer', 'default' => 1 ),
					'per_page'  => array( 'type' => 'integer', 'default' => 20 ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/submissions/batch',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'batch' ),
				'permission_callback' => array( $this, 'check_permission' ),
				'args'                => array(
					'action' => array( 'type' => 'string', 'required' => true ),
					'ids'    => array( 'type' => 'array', 'required' => true ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/settings',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'update_settings' ),
					'permission_callback' => array( $this, 'check_permission' ),
				),
			)
		);
	}

	/**
	 * Return the current settings.
	 *
	 * @since 1.0.0
	 * @return WP_REST_Response
	 */
	public function get_settings() {
		return new WP_REST_Response( Bonnie_Store::get_settings() );
	}

	/**
	 * Sanitise and persist submitted settings.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function update_settings( WP_REST_Request $request ) {
		$clean = Bonnie_Store::sanitize_settings( $request->get_json_params() );
		update_option( Bonnie_Store::OPTION_SETTINGS, $clean );
		return new WP_REST_Response( $clean );
	}

	/**
	 * Permission check for all routes.
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function check_permission() {
		return current_user_can( bonnie_capability() );
	}

	/**
	 * List submissions for the current view.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public function get_submissions( WP_REST_Request $request ) {
		$per_page = min( 100, max( 1, (int) $request['per_page'] ) );
		$page     = max( 1, (int) $request['page'] );

		$args = array(
			'status'   => sanitize_key( $request['status'] ),
			'form_id'  => (int) $request['form_id'],
			'search'   => sanitize_text_field( (string) $request['search'] ),
			'orderby'  => sanitize_key( $request['orderby'] ),
			'order'    => sanitize_key( $request['order'] ),
			'per_page' => $per_page,
			'paged'    => $page,
		);

		$result   = $this->store->query_submissions( $args );
		$store_ip = ! empty( Bonnie_Store::get_setting( 'store_ip' ) );
		$format   = get_option( 'date_format' ) . ' ' . get_option( 'time_format' );

		$items = array();
		foreach ( $result['items'] as $row ) {
			$items[] = array(
				'id'                 => (int) $row->id,
				'created_at'         => $row->created_at,
				'created_at_display' => mysql2date( $format, $row->created_at ),
				'form_id'            => (int) $row->form_id,
				'form_title'         => '' !== $row->form_title ? $row->form_title : '#' . $row->form_id,
				'from_name'          => $row->from_name,
				'from_email'         => $row->from_email,
				'subject'            => $row->subject,
				'status'             => $row->status,
				'remote_ip'          => $store_ip ? $this->store->ip_to_string( $row->remote_ip ) : null,
				'view_url'           => admin_url( 'admin.php?page=bonnie&action=view&submission=' . (int) $row->id ),
			);
		}

		$total = (int) $result['total'];

		return new WP_REST_Response(
			array(
				'items'      => $items,
				'total'      => $total,
				'totalPages' => (int) ceil( $total / $per_page ),
			)
		);
	}

	/**
	 * Apply a batch action to a set of submissions.
	 *
	 * Core handles permanent `delete`. Add-ons register additional actions (e.g.
	 * the Pro trash workflow) by whitelisting them on `bonnie_rest_batch_actions`
	 * and handling them on `bonnie_rest_batch`.
	 *
	 * @since 1.0.0
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function batch( WP_REST_Request $request ) {
		$action = sanitize_key( $request['action'] );
		$ids    = array_filter( array_map( 'intval', (array) $request['ids'] ) );

		if ( empty( $ids ) ) {
			return new WP_Error( 'bonnie_no_ids', __( 'No submissions selected.', 'the-bonnie-situation' ), array( 'status' => 400 ) );
		}

		/**
		 * Filter the set of batch actions the endpoint accepts.
		 *
		 * @since 1.0.0
		 * @param string[] $actions Allowed action keys. Core provides 'delete'.
		 */
		$allowed = (array) apply_filters( 'bonnie_rest_batch_actions', array( 'delete' ) );

		if ( ! in_array( $action, $allowed, true ) ) {
			return new WP_Error( 'bonnie_bad_action', __( 'Unknown action.', 'the-bonnie-situation' ), array( 'status' => 400 ) );
		}

		if ( 'delete' === $action ) {
			try {
				$updated = $this->store->delete_submissions( $ids );
			} catch ( \Throwable $e ) {
				$this->logger->error( 'rest_batch_failed', $e );
				return new WP_Error( 'bonnie_batch_failed', __( 'The action could not be completed.', 'the-bonnie-situation' ), array( 'status' => 500 ) );
			}

			return new WP_REST_Response(
				array(
					'action'  => $action,
					'updated' => (int) $updated,
				)
			);
		}

		/**
		 * Handle an add-on batch action. Return an array with an `updated` count,
		 * a WP_Error, or null to signal the action was not handled.
		 *
		 * @since 1.0.0
		 * @param array|WP_Error|null $result Handling result (null = unhandled).
		 * @param string              $action Action key.
		 * @param int[]               $ids    Submission ids.
		 */
		$result = apply_filters( 'bonnie_rest_batch', null, $action, $ids );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		if ( is_array( $result ) ) {
			return new WP_REST_Response(
				array(
					'action'  => $action,
					'updated' => (int) ( isset( $result['updated'] ) ? $result['updated'] : 0 ),
				)
			);
		}

		return new WP_Error( 'bonnie_bad_action', __( 'Unknown action.', 'the-bonnie-situation' ), array( 'status' => 400 ) );
	}

}
