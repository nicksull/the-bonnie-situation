<?php

/**
 * Importer for submissions stored by other Contact Form 7 storage plugins.
 *
 * Reads Flamingo and CFDB7 data straight from the database (neither plugin has
 * to be active) and writes it through the same assembly, privacy rules and
 * store filters as a live capture, so imported entries are indistinguishable
 * from captured ones — including add-on encryption and the address book.
 *
 * Imports run in small batches against a per-source cursor (the highest source
 * id imported so far), so a re-run only picks up what is new and never
 * duplicates. Entries already outside their retention window are skipped:
 * importing them would only hand them to the next daily purge. Source data is
 * never modified or deleted.
 *
 * @link       https://beforebonnie.com
 * @since      1.2.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/includes
 */

/**
 * Importer.
 *
 * @since      1.2.0
 * @package    Bonnie
 * @subpackage Bonnie/includes
 * @author     Red Pocket <studio@redpocket.hk>
 */
class Bonnie_Importer {

	/**
	 * Option key holding the per-source import cursor and tallies.
	 *
	 * @var string
	 */
	const OPTION_STATE = 'bonnie_import_state';

	/**
	 * Upper bound on records per batch request.
	 *
	 * @var int
	 */
	const MAX_BATCH = 200;

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
	 * Row assembler shared with live capture.
	 *
	 * @var Bonnie_Capture
	 */
	protected $capture;

	/**
	 * Source adapters keyed by id.
	 *
	 * @var Bonnie_Import_Source[]
	 */
	protected $sources;

	/**
	 * Constructor.
	 *
	 * @since 1.2.0
	 * @param Bonnie_Store   $store   Data-access layer.
	 * @param Bonnie_Logger  $logger  Logger.
	 * @param Bonnie_Capture $capture Row assembler.
	 */
	public function __construct( Bonnie_Store $store, Bonnie_Logger $logger, Bonnie_Capture $capture ) {
		$this->store   = $store;
		$this->logger  = $logger;
		$this->capture = $capture;
		$this->sources = array(
			'flamingo' => new Bonnie_Import_Flamingo(),
			'cfdb7'    => new Bonnie_Import_CFDB7(),
		);
	}

	/**
	 * A source adapter by id.
	 *
	 * @since  1.2.0
	 * @param  string $id Source id.
	 * @return Bonnie_Import_Source|null
	 */
	public function source( $id ) {
		return isset( $this->sources[ $id ] ) ? $this->sources[ $id ] : null;
	}

	/**
	 * Status of every source that has data on this site.
	 *
	 * @since  1.2.0
	 * @return array[] One entry per source: id, label, total, remaining, imported, skipped.
	 */
	public function status() {
		$out = array();
		foreach ( $this->sources as $id => $source ) {
			if ( ! $source->available() ) {
				continue;
			}
			$total = $source->count();
			if ( $total < 1 ) {
				continue;
			}
			$state = $this->state( $id );
			$out[] = array(
				'id'        => $id,
				'label'     => $source->label(),
				'total'     => $total,
				'remaining' => $source->count( $state['cursor'] ),
				'imported'  => $state['imported'],
				'skipped'   => $state['skipped'],
			);
		}
		return $out;
	}

	/**
	 * Import the next batch from a source.
	 *
	 * @since  1.2.0
	 * @param  string $id    Source id.
	 * @param  int    $limit Records to read this batch.
	 * @return array|WP_Error { imported, skipped, remaining, done } for this batch.
	 */
	public function run_batch( $id, $limit = 50 ) {
		$source = $this->source( $id );
		if ( ! $source || ! $source->available() ) {
			return new WP_Error( 'bonnie_import_source', __( 'There is nothing to import from that source.', 'the-bonnie-situation' ), array( 'status' => 400 ) );
		}

		$limit    = min( self::MAX_BATCH, max( 1, (int) $limit ) );
		$state    = $this->state( $id );
		$imported = 0;
		$skipped  = 0;

		foreach ( $source->fetch( $state['cursor'], $limit ) as $record ) {
			if ( $this->import_record( $record ) ) {
				++$imported;
				++$state['imported'];
			} else {
				++$skipped;
				++$state['skipped'];
			}

			// Advance per record so an interrupted batch never re-imports.
			$state['cursor'] = (int) $record['source_id'];
			update_option( self::OPTION_STATE . '_' . $id, $state, false );
		}

		$remaining = $source->count( $state['cursor'] );

		return array(
			'imported'  => $imported,
			'skipped'   => $skipped,
			'remaining' => $remaining,
			'done'      => 0 === $remaining,
		);
	}

	/**
	 * Store one normalised source record, unless policy says it should not exist.
	 *
	 * @since  1.2.0
	 * @param  array $record Normalised record (see Bonnie_Import_Source::fetch()).
	 * @return bool Whether it was stored.
	 */
	protected function import_record( array $record ) {
		try {
			$status = 'spam' === $record['status'] ? 'spam' : 'mail_sent';
			if ( 'spam' === $status && empty( Bonnie_Store::get_setting( 'capture_spam' ) ) ) {
				return false;
			}

			if ( $this->is_expired( (int) $record['form_id'], $record['created_at'] ) ) {
				return false;
			}

			$form_id      = (int) $record['form_id'];
			$contact_form = ( $form_id && function_exists( 'wpcf7_contact_form' ) ) ? wpcf7_contact_form( $form_id ) : null;
			$form_title   = $contact_form ? (string) $contact_form->title() : (string) $record['form_title'];
			$posted       = (array) $record['fields'];

			$row  = $this->capture->assemble_row( $form_id, $form_title, $posted, $status, (array) $record['environment'], $record['created_at'], $contact_form );
			$meta = $this->capture->build_meta( $posted );

			/** This filter is documented in includes/class-bonnie-capture.php */
			$row = apply_filters( 'bonnie_before_store', $row, $posted, $contact_form );

			/** This filter is documented in includes/class-bonnie-capture.php */
			$meta = apply_filters( 'bonnie_before_store_meta', $meta, $posted, $contact_form );

			$submission_id = $this->store->save( $row, $meta );

			/** This action is documented in includes/class-bonnie-capture.php */
			do_action( 'bonnie_after_store', $submission_id, $row, $posted );

			return true;
		} catch ( \Throwable $e ) {
			$this->logger->error( 'import_failed', $e );
			return false;
		}
	}

	/**
	 * Whether an entry is already past the retention window for its form.
	 *
	 * @since  1.2.0
	 * @param  int    $form_id    Form id.
	 * @param  string $created_at MySQL datetime, site timezone.
	 * @return bool
	 */
	protected function is_expired( $form_id, $created_at ) {
		$policy = Bonnie_Store::effective_retention( $form_id );
		if ( empty( $policy['policy'] ) || 'none' === $policy['policy'] ) {
			return false;
		}
		$now_ts = current_time( 'timestamp' ); // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp.Requested
		$cutoff = gmdate( 'Y-m-d H:i:s', $now_ts - max( 1, (int) $policy['days'] ) * DAY_IN_SECONDS );
		return $created_at < $cutoff;
	}

	/**
	 * Saved cursor and tallies for a source.
	 *
	 * @since  1.2.0
	 * @param  string $id Source id.
	 * @return array { cursor, imported, skipped }
	 */
	protected function state( $id ) {
		$state = get_option( self::OPTION_STATE . '_' . $id, array() );
		return array_merge(
			array(
				'cursor'   => 0,
				'imported' => 0,
				'skipped'  => 0,
			),
			is_array( $state ) ? $state : array()
		);
	}

}
