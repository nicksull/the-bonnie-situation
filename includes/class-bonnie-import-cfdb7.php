<?php

/**
 * Import source: CFDB7 ("Database Addon for Contact Form 7").
 *
 * One table, `{prefix}db7_forms`: `form_id` is the row id, `form_post_id` the
 * CF7 form id, `form_value` a serialized field array and `form_date` a
 * site-local datetime. Inside `form_value`, `cfdb7_status` is CFDB7's read
 * flag (not a field), uploads appear as `{field}cfdb7_file` holding the stored
 * filename, and quotes, slashes and backslashes are entity-encoded on write.
 * CFDB7 stores no IP, user agent or spam.
 *
 * @link       https://beforebonnie.com
 * @since      1.2.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/includes
 */

/**
 * CFDB7 import source.
 *
 * @since      1.2.0
 * @package    Bonnie
 * @subpackage Bonnie/includes
 * @author     Red Pocket <studio@redpocket.hk>
 */
class Bonnie_Import_CFDB7 extends Bonnie_Import_Source {

	// CFDB7's own table, read directly; the table name derives from the trusted prefix.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared

	/**
	 * Suffix CFDB7 appends to an upload field's key.
	 *
	 * @var string
	 */
	const FILE_SUFFIX = 'cfdb7_file';

	/**
	 * Entities CFDB7 writes in place of characters, and what they decode to.
	 *
	 * @var array
	 */
	const ENTITIES = array(
		'&quot;' => '"',
		'&#039;' => "'",
		'&#047;' => '/',
		'&#092;' => '\\',
	);

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return 'CFDB7';
	}

	/**
	 * Database connection CFDB7 writes to (it can be pointed elsewhere).
	 *
	 * @since  1.2.0
	 * @return wpdb
	 */
	protected function db() {
		global $wpdb;
		// CFDB7's own filter for swapping its connection; honour it so we read where it wrote.
		$db = apply_filters( 'cfdb7_database', $wpdb ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound
		return $db instanceof wpdb ? $db : $wpdb;
	}

	/**
	 * CFDB7's table name.
	 *
	 * @since  1.2.0
	 * @return string
	 */
	protected function table() {
		return $this->db()->prefix . 'db7_forms';
	}

	/**
	 * {@inheritdoc}
	 */
	public function available() {
		$db    = $this->db();
		$table = $this->table();
		return $table === $db->get_var( $db->prepare( 'SHOW TABLES LIKE %s', $db->esc_like( $table ) ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function count( $after_id = 0 ) {
		if ( ! $this->available() ) {
			return 0;
		}
		$db = $this->db();
		return (int) $db->get_var(
			$db->prepare( "SELECT COUNT(*) FROM `{$this->table()}` WHERE form_id > %d", (int) $after_id )
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function fetch( $after_id, $limit ) {
		$db   = $this->db();
		$rows = $db->get_results(
			$db->prepare(
				"SELECT form_id, form_post_id, form_value, form_date FROM `{$this->table()}` WHERE form_id > %d ORDER BY form_id ASC LIMIT %d",
				(int) $after_id,
				(int) $limit
			)
		);

		$records = array();
		foreach ( (array) $rows as $row ) {
			$form_id   = (int) $row->form_post_id;
			$records[] = array(
				'source_id'   => (int) $row->form_id,
				'form_id'     => $form_id,
				'form_title'  => $form_id ? (string) get_the_title( $form_id ) : '',
				'status'      => 'active',
				'created_at'  => (string) $row->form_date,
				'fields'      => $this->fields( $row->form_value ),
				'environment' => array(
					'remote_ip'  => '',
					'user_agent' => '',
					'url'        => '',
				),
			);
		}

		return $records;
	}

	/**
	 * Decode a `form_value` blob to field name => value.
	 *
	 * @since  1.2.0
	 * @param  string $raw Serialized field array.
	 * @return array
	 */
	protected function fields( $raw ) {
		$values = self::unserialize_safe( $raw );
		if ( ! is_array( $values ) ) {
			return array();
		}

		$fields = array();
		foreach ( $values as $key => $value ) {
			$key = (string) $key;
			if ( 'cfdb7_status' === $key ) {
				continue;
			}
			// An upload is keyed "{field}cfdb7_file"; store it under the field name.
			if ( strlen( $key ) > strlen( self::FILE_SUFFIX ) && substr( $key, -strlen( self::FILE_SUFFIX ) ) === self::FILE_SUFFIX ) {
				$key = substr( $key, 0, -strlen( self::FILE_SUFFIX ) );
				if ( '' === $value || isset( $values[ $key ] ) ) {
					continue;
				}
			}
			$fields[ $key ] = $this->decode( $value );
		}
		return $fields;
	}

	/**
	 * Reverse CFDB7's write-time entity encoding.
	 *
	 * @since  1.2.0
	 * @param  mixed $value Stored value (string or array of strings).
	 * @return mixed
	 */
	protected function decode( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( $this, 'decode' ), $value );
		}
		return is_scalar( $value ) ? strtr( (string) $value, self::ENTITIES ) : '';
	}

}
