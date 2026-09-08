<?php

/**
 * Core Personal Data export / erase integration.
 *
 * Wires Bonnie into WordPress's Tools -> Export/Erase Personal Data flows so a
 * subject-access or erasure request by email includes captured Contact Form 7
 * submissions. Cheap to provide, and it materially strengthens the compliance
 * story.
 *
 * @link       https://beforebonnie.com
 * @since      1.0.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/includes
 */

/**
 * Privacy integration.
 *
 * @since      1.0.0
 * @package    Bonnie
 * @subpackage Bonnie/includes
 * @author     Red Pocket <studio@redpocket.hk>
 */
class Bonnie_Privacy {

	/**
	 * Rows processed per export/erase page.
	 *
	 * @var int
	 */
	const PAGE_SIZE = 50;

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
	 * Register the exporter.
	 *
	 * @since 1.0.0
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public function register_exporter( $exporters ) {
		$exporters['bonnie'] = array(
			'exporter_friendly_name' => __( 'Bonnie (Contact Form 7 submissions)', 'the-bonnie-situation' ),
			'callback'               => array( $this, 'export' ),
		);
		return $exporters;
	}

	/**
	 * Register the eraser.
	 *
	 * @since 1.0.0
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public function register_eraser( $erasers ) {
		$erasers['bonnie'] = array(
			'eraser_friendly_name' => __( 'Bonnie (Contact Form 7 submissions)', 'the-bonnie-situation' ),
			'callback'             => array( $this, 'erase' ),
		);
		return $erasers;
	}

	/**
	 * Export captured submissions for an email address.
	 *
	 * @since 1.0.0
	 * @param string $email Subject email.
	 * @param int    $page  1-based page.
	 * @return array { data, done }
	 */
	public function export( $email, $page = 1 ) {
		$page   = max( 1, (int) $page );
		$offset = ( $page - 1 ) * self::PAGE_SIZE;
		$export = array();

		$ids = $this->store->ids_by_email( $email, self::PAGE_SIZE, $offset );

		foreach ( $ids as $id ) {
			$sub = $this->store->get_submission( $id );
			if ( ! $sub ) {
				continue;
			}

			$data = array(
				array(
					'name'  => __( 'Date', 'the-bonnie-situation' ),
					'value' => $sub->created_at,
				),
				array(
					'name'  => __( 'Form', 'the-bonnie-situation' ),
					'value' => '' !== $sub->form_title ? $sub->form_title : '#' . $sub->form_id,
				),
				array(
					'name'  => __( 'Subject', 'the-bonnie-situation' ),
					'value' => $sub->subject,
				),
			);

			foreach ( $this->store->get_submission_meta( $id ) as $meta ) {
				$data[] = array(
					'name'  => $meta->meta_key,
					'value' => $meta->meta_value,
				);
			}

			$export[] = array(
				'group_id'    => 'bonnie-submissions',
				'group_label' => __( 'Contact form submissions (Bonnie)', 'the-bonnie-situation' ),
				'item_id'     => 'bonnie-' . (int) $sub->id,
				'data'        => $data,
			);
		}

		return array(
			'data' => $export,
			'done' => count( $ids ) < self::PAGE_SIZE,
		);
	}

	/**
	 * Erase captured submissions for an email address.
	 *
	 * @since 1.0.0
	 * @param string $email Subject email.
	 * @param int    $page  1-based page (unused; each pass clears a batch).
	 * @return array { items_removed, items_retained, messages, done }
	 */
	public function erase( $email, $page = 1 ) {
		$removed = false;

		try {
			// Each pass deletes a batch, so we always read from offset 0.
			$ids = $this->store->ids_by_email( $email, self::PAGE_SIZE, 0 );

			if ( ! empty( $ids ) ) {
				$this->store->delete_submissions( $ids );
				$removed = true;
			}

			$done = count( $ids ) < self::PAGE_SIZE;
		} catch ( \Throwable $e ) {
			$this->logger->error( 'privacy_erase_failed', $e );
			$done = true;
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(),
			'done'           => $done,
		);
	}

}
