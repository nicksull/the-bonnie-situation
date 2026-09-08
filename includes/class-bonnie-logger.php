<?php

/**
 * Minimal error logger.
 *
 * Capture must never break Contact Form 7, so failures are swallowed and routed
 * here instead of being re-thrown. Output only happens when WP_DEBUG is on.
 *
 * @link       https://beforebonnie.com
 * @since      1.0.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/includes
 */

/**
 * Logger.
 *
 * @since      1.0.0
 * @package    Bonnie
 * @subpackage Bonnie/includes
 * @author     Red Pocket <studio@redpocket.hk>
 */
class Bonnie_Logger {

	/**
	 * Record a non-fatal error.
	 *
	 * @since  1.0.0
	 * @param  string                    $context Short machine-readable context tag.
	 * @param  Throwable|string|mixed    $detail  Optional exception or detail to append.
	 * @return void
	 */
	public function error( $context, $detail = null ) {
		if ( ! ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ) {
			return;
		}

		$message = '[Bonnie] ' . $context;

		if ( $detail instanceof \Throwable ) {
			$message .= ': ' . $detail->getMessage() . ' @ ' . $detail->getFile() . ':' . $detail->getLine();
		} elseif ( null !== $detail ) {
			$message .= ': ' . ( is_scalar( $detail ) ? (string) $detail : wp_json_encode( $detail ) );
		}

		// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
		error_log( $message );
	}

}
