<?php

/**
 * Import source: Flamingo inbound messages.
 *
 * Messages are `flamingo_inbound` posts. Each field is a `_field_{key}` meta,
 * with the original field names (in order) in `_fields`; IP, user agent and
 * page URL live in the `_meta` array. Flamingo does not store the CF7 form id
 * on a message: the form post holds `_flamingo['channel']`, the id of the
 * message's `flamingo_inbound_channel` term, so the form is resolved through
 * that term. Everything is read with SQL so Flamingo does not need to be
 * active (its post type and taxonomy are then unregistered).
 *
 * @link       https://beforebonnie.com
 * @since      1.2.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/includes
 */

/**
 * Flamingo import source.
 *
 * @since      1.2.0
 * @package    Bonnie
 * @subpackage Bonnie/includes
 * @author     Red Pocket <studio@redpocket.hk>
 */
class Bonnie_Import_Flamingo extends Bonnie_Import_Source {

	// Reads core tables directly by design (see class docblock); values are bound.
	// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching

	/**
	 * Channel term id => [ form id, form title ], built once per request.
	 *
	 * @var array|null
	 */
	protected $channels = null;

	/**
	 * {@inheritdoc}
	 */
	public function label() {
		return 'Flamingo';
	}

	/**
	 * {@inheritdoc}
	 */
	public function available() {
		return $this->count() > 0;
	}

	/**
	 * {@inheritdoc}
	 */
	public function count( $after_id = 0 ) {
		global $wpdb;
		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'flamingo_inbound' AND post_status IN ('publish','flamingo-spam') AND ID > %d",
				(int) $after_id
			)
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function fetch( $after_id, $limit ) {
		global $wpdb;

		$posts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT ID, post_date, post_status FROM {$wpdb->posts} WHERE post_type = 'flamingo_inbound' AND post_status IN ('publish','flamingo-spam') AND ID > %d ORDER BY ID ASC LIMIT %d",
				(int) $after_id,
				(int) $limit
			)
		);
		if ( empty( $posts ) ) {
			return array();
		}

		$ids = array_map( 'intval', wp_list_pluck( $posts, 'ID' ) );
		update_meta_cache( 'post', $ids );
		$terms = $this->channel_terms( $ids );

		$records = array();
		foreach ( $posts as $post ) {
			$id   = (int) $post->ID;
			$form = $this->resolve_form( isset( $terms[ $id ] ) ? $terms[ $id ] : array() );
			$meta = self::unserialize_safe( get_post_meta( $id, '_meta', true ) );
			$meta = is_array( $meta ) ? $meta : array();

			$records[] = array(
				'source_id'   => $id,
				'form_id'     => $form[0],
				'form_title'  => $form[1],
				'status'      => 'flamingo-spam' === $post->post_status ? 'spam' : 'active',
				'created_at'  => (string) $post->post_date,
				'fields'      => $this->fields( $id ),
				'environment' => array(
					'remote_ip'  => isset( $meta['remote_ip'] ) ? (string) $meta['remote_ip'] : '',
					'user_agent' => isset( $meta['user_agent'] ) ? (string) $meta['user_agent'] : '',
					'url'        => isset( $meta['url'] ) ? (string) $meta['url'] : '',
				),
			);
		}

		return $records;
	}

	/**
	 * A message's fields, keyed by their original names, in form order.
	 *
	 * @since  1.2.0
	 * @param  int $id Message post id.
	 * @return array
	 */
	protected function fields( $id ) {
		$names = self::unserialize_safe( get_post_meta( $id, '_fields', true ) );
		$names = is_array( $names ) ? array_keys( $names ) : array();

		$fields = array();
		foreach ( $names as $name ) {
			$value = get_post_meta( $id, '_field_' . sanitize_key( $name ), true );
			$value = self::unserialize_safe( $value );
			if ( is_array( $value ) || is_scalar( $value ) ) {
				$fields[ (string) $name ] = $value;
			}
		}
		return $fields;
	}

	/**
	 * Channel term ids for a set of messages.
	 *
	 * @since  1.2.0
	 * @param  int[] $ids Message post ids.
	 * @return array post id => term id[]
	 */
	protected function channel_terms( array $ids ) {
		global $wpdb;
		$in   = implode( ',', array_map( 'intval', $ids ) );
		$rows = $wpdb->get_results(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- $in is a list of intval()ed ids.
			"SELECT tr.object_id, tt.term_id FROM {$wpdb->term_relationships} tr INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tt.taxonomy = 'flamingo_inbound_channel' AND tr.object_id IN ({$in})"
		);

		$out = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->object_id ][] = (int) $row->term_id;
		}
		return $out;
	}

	/**
	 * Resolve a message's channel terms to a CF7 form.
	 *
	 * @since  1.2.0
	 * @param  int[] $term_ids Channel term ids on the message.
	 * @return array [ int form id (0 = unknown), string form title ]
	 */
	protected function resolve_form( array $term_ids ) {
		$channels = $this->channels();
		foreach ( $term_ids as $term_id ) {
			if ( isset( $channels[ $term_id ] ) ) {
				return $channels[ $term_id ];
			}
		}
		return array( 0, '' );
	}

	/**
	 * Map channel term ids to CF7 forms.
	 *
	 * Primary link: the form's `_flamingo['channel']` meta. Fallback: a channel
	 * term whose slug is the form's post_name. Channels whose form has been
	 * deleted keep their term name as the title, with form id 0.
	 *
	 * @since  1.2.0
	 * @return array term id => [ form id, form title ]
	 */
	protected function channels() {
		global $wpdb;

		if ( null !== $this->channels ) {
			return $this->channels;
		}

		$this->channels = array();

		$terms = $wpdb->get_results(
			"SELECT t.term_id, t.name, t.slug, tt.parent FROM {$wpdb->terms} t INNER JOIN {$wpdb->term_taxonomy} tt ON tt.term_id = t.term_id WHERE tt.taxonomy = 'flamingo_inbound_channel'"
		);
		$by_slug = array();
		foreach ( (array) $terms as $term ) {
			// The 'contact-form-7' parent groups the per-form channels; it names no form.
			if ( 'contact-form-7' === $term->slug && 0 === (int) $term->parent ) {
				continue;
			}
			$this->channels[ (int) $term->term_id ] = array( 0, (string) $term->name );
			$by_slug[ (string) $term->slug ]        = (int) $term->term_id;
		}

		$forms = $wpdb->get_results(
			"SELECT ID, post_title, post_name FROM {$wpdb->posts} WHERE post_type = 'wpcf7_contact_form'"
		);
		foreach ( (array) $forms as $form ) {
			$link    = self::unserialize_safe( get_post_meta( (int) $form->ID, '_flamingo', true ) );
			$term_id = ( is_array( $link ) && ! empty( $link['channel'] ) ) ? (int) $link['channel'] : 0;
			if ( ! $term_id && isset( $by_slug[ (string) $form->post_name ] ) ) {
				$term_id = $by_slug[ (string) $form->post_name ];
			}
			if ( $term_id && isset( $this->channels[ $term_id ] ) ) {
				$this->channels[ $term_id ] = array( (int) $form->ID, (string) $form->post_title );
			}
		}

		return $this->channels;
	}

}
