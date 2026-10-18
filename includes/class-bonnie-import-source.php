<?php

/**
 * Base class for an import source (another plugin's stored CF7 submissions).
 *
 * @link       https://beforebonnie.com
 * @since      1.2.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/includes
 */

/**
 * Import source.
 *
 * Adapters read the other plugin's data directly from the database, ordered by
 * a stable, increasing source id, and normalise each entry for the importer.
 *
 * @since      1.2.0
 * @package    Bonnie
 * @subpackage Bonnie/includes
 * @author     Red Pocket <studio@redpocket.hk>
 */
abstract class Bonnie_Import_Source {

	/**
	 * Human-readable name of the source plugin.
	 *
	 * @since  1.2.0
	 * @return string
	 */
	abstract public function label();

	/**
	 * Whether the source's storage exists on this site (active or not).
	 *
	 * @since  1.2.0
	 * @return bool
	 */
	abstract public function available();

	/**
	 * Number of importable entries with a source id above the cursor.
	 *
	 * @since  1.2.0
	 * @param  int $after_id Cursor (0 = all).
	 * @return int
	 */
	abstract public function count( $after_id = 0 );

	/**
	 * The next entries above the cursor, in ascending source-id order.
	 *
	 * Each entry is normalised to:
	 * - source_id   int     Stable id in the source.
	 * - form_id     int     CF7 form id, or 0 if it cannot be resolved.
	 * - form_title  string  Fallback title when the form no longer exists.
	 * - status      string  'active' | 'spam'.
	 * - created_at  string  MySQL datetime, site timezone.
	 * - fields      array   Field name => value, as CF7 posted it.
	 * - environment array   Raw remote_ip, user_agent and url strings (may be '').
	 *
	 * @since  1.2.0
	 * @param  int $after_id Cursor.
	 * @param  int $limit    Max entries.
	 * @return array[]
	 */
	abstract public function fetch( $after_id, $limit );

	/**
	 * Decode a serialised value without instantiating objects.
	 *
	 * @since  1.2.0
	 * @param  mixed $value Raw stored value.
	 * @return mixed
	 */
	protected static function unserialize_safe( $value ) {
		if ( ! is_string( $value ) || ! is_serialized( $value ) ) {
			return $value;
		}
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_unserialize
		$decoded = @unserialize( trim( $value ), array( 'allowed_classes' => false ) ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		return false === $decoded ? $value : $decoded;
	}

}
