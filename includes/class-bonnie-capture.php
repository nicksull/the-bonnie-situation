<?php

/**
 * Contact Form 7 capture handler.
 *
 * Hooks late on wpcf7_submit so it never delays or blocks the user's email, and
 * wraps the write so a storage failure degrades silently (logged) rather than
 * breaking the form.
 *
 * @link       https://beforebonnie.com
 * @since      1.0.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/includes
 */

/**
 * Capture handler.
 *
 * @since      1.0.0
 * @package    Bonnie
 * @subpackage Bonnie/includes
 * @author     Red Pocket <studio@redpocket.hk>
 */
class Bonnie_Capture {

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
	 * Capture a CF7 submission. Bound to wpcf7_submit (priority 20, 2 args).
	 *
	 * @since 1.0.0
	 * @param WPCF7_ContactForm $contact_form The submitted form.
	 * @param array             $result       CF7 result array (status, etc.).
	 * @return void
	 */
	public function capture( $contact_form, $result ) {
		try {
			if ( ! class_exists( 'WPCF7_Submission' ) ) {
				return;
			}

			$submission = WPCF7_Submission::get_instance();
			if ( ! $submission ) {
				return;
			}

			$status = ( is_array( $result ) && isset( $result['status'] ) ) ? $result['status'] : '';

			$capture = $this->should_capture( $status );

			/**
			 * Filter whether a given submission should be captured.
			 *
			 * @since 1.0.0
			 * @param bool              $capture      Decision so far.
			 * @param WPCF7_ContactForm $contact_form The form.
			 * @param array             $result       CF7 result array.
			 */
			$capture = apply_filters( 'bonnie_should_capture', $capture, $contact_form, $result );
			if ( ! $capture ) {
				return;
			}

			$posted = $submission->get_posted_data();
			if ( ! is_array( $posted ) ) {
				$posted = array();
			}

			$row  = $this->build_submission( $contact_form, $submission, $posted, $status );
			$meta = $this->build_meta( $posted );

			/**
			 * Filter the assembled submission row before it is written.
			 *
			 * @since 1.0.0
			 * @param array             $row          Submission column values.
			 * @param array             $posted       Raw posted field data.
			 * @param WPCF7_ContactForm $contact_form The form.
			 */
			$row = apply_filters( 'bonnie_before_store', $row, $posted, $contact_form );

			/**
			 * Filter the submission meta before it is written. The write-side
			 * counterpart to `bonnie_read_submission_meta`, where an add-on
			 * encrypts meta values for encryption-at-rest.
			 *
			 * @since 1.1.0
			 * @param array             $meta         meta_key => meta_value pairs.
			 * @param array             $posted       Raw posted field data.
			 * @param WPCF7_ContactForm $contact_form The form.
			 */
			$meta = apply_filters( 'bonnie_before_store_meta', $meta, $posted, $contact_form );

			$submission_id = $this->store->save( $row, $meta );

			/**
			 * Fires after a submission has been stored.
			 *
			 * @since 1.0.0
			 * @param int   $submission_id New submission id.
			 * @param array $row           Stored submission column values.
			 * @param array $posted        Raw posted field data.
			 */
			do_action( 'bonnie_after_store', $submission_id, $row, $posted );
		} catch ( \Throwable $e ) {
			// Never re-throw into CF7's submission flow.
			$this->logger->error( 'capture_failed', $e );
		}
	}

	/**
	 * Decide whether a submission with the given CF7 status should be stored.
	 *
	 * @since 1.0.0
	 * @param string $status CF7 result status.
	 * @return bool
	 */
	protected function should_capture( $status ) {
		$settings = Bonnie_Store::get_settings();

		switch ( $status ) {
			case 'mail_sent':
				return true;
			case 'mail_failed':
				return ! empty( $settings['store_on_mail_failed'] );
			case 'spam':
				return ! empty( $settings['capture_spam'] );
			case 'validation_failed':
			case 'acceptance_missing':
				return ! empty( $settings['capture_validation'] );
			default:
				return false;
		}
	}

	/**
	 * Build the submission column values, honouring suppression settings.
	 *
	 * @since 1.0.0
	 * @param WPCF7_ContactForm $contact_form The form.
	 * @param WPCF7_Submission  $submission   The submission instance.
	 * @param array             $posted       Raw posted field data.
	 * @param string            $status       CF7 result status.
	 * @return array
	 */
	protected function build_submission( $contact_form, $submission, array $posted, $status ) {
		$form_id  = (int) $contact_form->id();
		$settings = Bonnie_Store::effective_capture( $form_id );

		// A per-form field mapping, when set, takes priority over auto-detection.
		$email_keys   = $this->candidates( $settings['map_email'], array( 'your-email', 'email', 'e-mail', 'your-mail' ) );
		$name_keys    = $this->candidates( $settings['map_name'], array( 'your-name', 'name', 'full-name', 'fullname', 'your-fullname' ) );
		$subject_keys = $this->candidates( $settings['map_subject'], array( 'your-subject', 'subject' ) );

		$email   = $this->detect_field( $posted, $email_keys, 'email' );
		$name    = $this->detect_field( $posted, $name_keys, 'name' );
		$subject = $this->detect_field( $posted, $subject_keys, 'subject' );

		$remote_ip = null;
		if ( $settings['store_ip'] ) {
			$raw_ip = (string) $submission->get_meta( 'remote_ip' );

			/**
			 * Filter the raw remote IP string before it is packed for storage.
			 *
			 * The read counterpart to storing an address: an add-on returns a
			 * masked address here to implement IP anonymisation. A no-op in free,
			 * which stores the address as received (only when IP storage is on).
			 *
			 * @since 1.0.0
			 * @param string            $raw_ip   Raw IP string as received.
			 * @param array             $settings Effective capture settings.
			 * @param WPCF7_ContactForm $contact_form The form.
			 */
			$raw_ip    = (string) apply_filters( 'bonnie_capture_remote_ip', $raw_ip, $settings, $contact_form );
			$remote_ip = $this->pack_ip( $raw_ip );
		}

		$user_agent = null;
		if ( $settings['store_user_agent'] ) {
			$ua         = (string) $submission->get_meta( 'user_agent' );
			$user_agent = '' !== $ua ? mb_substr( $ua, 0, 255 ) : null;
		}

		$referer = null;
		if ( $settings['store_referer'] ) {
			$url     = (string) $submission->get_meta( 'url' );
			$referer = '' !== $url ? esc_url_raw( $url ) : null;
		}

		$consent = $this->collect_consent( $contact_form, $posted );

		$email = sanitize_email( $email );

		return array(
			'form_id'       => $form_id,
			'form_title'    => (string) $contact_form->title(),
			'status'        => ( 'spam' === $status ) ? 'spam' : 'active',
			'channel'       => 'inbound',
			'subject'       => '' !== $subject ? mb_substr( $subject, 0, 255 ) : '',
			'from_name'     => '' !== $name ? mb_substr( $name, 0, 255 ) : '',
			'from_email'    => $email ? $email : '',
			'remote_ip'     => $remote_ip,
			'user_agent'    => $user_agent,
			'referer_url'   => $referer,
			'consent_flags' => ! empty( $consent ) ? wp_json_encode( $consent ) : null,
			'created_at'    => current_time( 'mysql' ),
		);
	}

	/**
	 * Collect the meta fields to persist (everything but CF7 internals).
	 *
	 * @since 1.0.0
	 * @param array $posted Raw posted field data.
	 * @return array meta_key => meta_value.
	 */
	protected function build_meta( array $posted ) {
		$meta = array();
		foreach ( $posted as $key => $value ) {
			if ( 0 === strpos( (string) $key, '_wpcf7' ) ) {
				continue; // CF7 internal field.
			}
			$meta[ $key ] = $value;
		}

		/**
		 * Filter which posted keys are persisted as meta. Return an array of
		 * keys to whitelist, or null to keep the default (all non-internal keys).
		 *
		 * @since 1.0.0
		 * @param array|null $keys   Whitelist of keys, or null.
		 * @param array      $posted Raw posted field data.
		 */
		$allowed = apply_filters( 'bonnie_capture_meta_keys', null, $posted );
		if ( is_array( $allowed ) ) {
			$meta = array_intersect_key( $meta, array_flip( $allowed ) );
		}

		return $meta;
	}

	/**
	 * Find the first non-empty value among candidate keys, with a fuzzy fallback.
	 *
	 * @since 1.0.0
	 * @param array  $posted     Raw posted field data.
	 * @param array  $candidates Ordered candidate field keys.
	 * @param string $type       One of 'email', 'name', 'subject', or '' for none.
	 * @return string
	 */
	protected function detect_field( array $posted, array $candidates, $type = '' ) {
		foreach ( $candidates as $key ) {
			if ( isset( $posted[ $key ] ) ) {
				$value = $this->flatten( $posted[ $key ] );
				if ( '' !== $value ) {
					return $value;
				}
			}
		}

		// Fuzzy fallback by field name / value shape.
		foreach ( $posted as $key => $value ) {
			$value = $this->flatten( $value );
			if ( '' === $value ) {
				continue;
			}
			if ( 'email' === $type && is_email( $value ) ) {
				return $value;
			}
			if ( 'name' === $type && false !== stripos( (string) $key, 'name' ) ) {
				return $value;
			}
			if ( 'subject' === $type && false !== stripos( (string) $key, 'subject' ) ) {
				return $value;
			}
		}

		return '';
	}

	/**
	 * Prepend a mapped field key (if set) to the auto-detection candidates.
	 *
	 * @since 1.0.0
	 * @param string $mapped   Per-form mapped field key, or ''.
	 * @param array  $defaults Default candidate keys.
	 * @return array
	 */
	protected function candidates( $mapped, array $defaults ) {
		$mapped = trim( (string) $mapped );
		return '' !== $mapped ? array_merge( array( $mapped ), $defaults ) : $defaults;
	}

	/**
	 * Reduce a posted value (possibly an array) to a trimmed scalar string.
	 *
	 * @since 1.0.0
	 * @param mixed $value Posted value.
	 * @return string
	 */
	protected function flatten( $value ) {
		if ( is_array( $value ) ) {
			$value = reset( $value );
		}
		return trim( (string) $value );
	}

	/**
	 * Snapshot acceptance/consent checkbox values from the form.
	 *
	 * @since 1.0.0
	 * @param WPCF7_ContactForm $contact_form The form.
	 * @param array             $posted       Raw posted field data.
	 * @return array name => value for acceptance fields.
	 */
	protected function collect_consent( $contact_form, array $posted ) {
		$flags = array();

		if ( ! method_exists( $contact_form, 'scan_form_tags' ) ) {
			return $flags;
		}

		foreach ( $contact_form->scan_form_tags() as $tag ) {
			$basetype = is_object( $tag ) && isset( $tag->basetype ) ? (string) $tag->basetype : '';
			if ( false === strpos( $basetype, 'acceptance' ) ) {
				continue;
			}
			$name = is_object( $tag ) && isset( $tag->name ) ? $tag->name : '';
			if ( '' !== $name ) {
				$flags[ $name ] = isset( $posted[ $name ] ) ? $posted[ $name ] : '';
			}
		}

		return $flags;
	}

	/**
	 * Pack an IP string to binary for storage.
	 *
	 * @since 1.0.0
	 * @param string $ip Raw (or add-on-masked) IP string.
	 * @return string|null Packed binary, or null if unavailable/invalid.
	 */
	protected function pack_ip( $ip ) {
		$ip = trim( (string) $ip );
		if ( '' === $ip ) {
			return null;
		}
		$packed = @inet_pton( $ip ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false !== $packed ? $packed : null;
	}

}
