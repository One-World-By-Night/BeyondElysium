<?php

namespace BeyondElysium\REST;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the plugin's credits and in-memoriam content.
 *
 * Backed by two plain WordPress options rather than a database table - this
 * is small, site-wide, rarely-changed text, not per-chronicle data. Read is
 * open to any logged-in viewer (the same floor as be_view_characters); write
 * is restricted to a site administrator.
 */
class Credits_Controller extends Base_Controller {

	protected $rest_base = 'credits';

	const CREDITS_OPTION  = 'be_credits_text';
	const MEMORIAM_OPTION = 'be_in_memoriam';
	const DEFAULT_CREDITS = 'Beyond Elysium was created by Greg Hacke for One World by Night.';

	/** The starting in-memoriam list, seeded once; freely editable from there. */
	const DEFAULT_MEMORIAM = [
		[ 'name' => 'Arielle M.', 'note' => '' ],
		[ 'name' => 'Stephen Page', 'note' => '' ],
		[ 'name' => 'Scott Little', 'note' => '' ],
		[ 'name' => 'Jamison', 'note' => '' ],
		[ 'name' => 'Travis Dunn', 'note' => '' ],
		[ 'name' => 'Carl Gosline', 'note' => '' ],
		[ 'name' => 'Gary "House" Williams', 'note' => '' ],
		[ 'name' => 'Ash White', 'note' => '' ],
		[ 'name' => 'Sarah Gabbey', 'note' => '' ],
		[ 'name' => 'J. T. Nielsen', 'note' => '' ],
	];

	/**
	 * Registers the credits routes.
	 *
	 * Adds a GET route open to any logged-in viewer and a PUT route
	 * restricted to be_manage_games, both operating on the same
	 * credits-text-plus-memoriam-list payload shape.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->rest_base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
			[
				'methods'             => 'PUT',
				'callback'            => [ $this, 'update_item' ],
				'permission_callback' => $this->permission( 'be_manage_games' ),
			],
		] );
	}

	/**
	 * Returns the current credits text and in-memoriam list.
	 *
	 * Reads both options, seeding sensible defaults (including the
	 * permanent Arielle entry) the first time either is read before
	 * ever being set.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_item( $request ) {
		return $this->success( [
			'credits_text' => get_option( self::CREDITS_OPTION, self::DEFAULT_CREDITS ),
			'in_memoriam'  => self::memoriam_list(),
		] );
	}

	/**
	 * Updates the credits text and/or in-memoriam list.
	 *
	 * Accepts either field independently; a field left out of the
	 * request body is left unchanged. The saved list fully replaces
	 * whatever was there before, name-by-name edits and removals
	 * included.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function update_item( $request ) {
		$credits_text = $request->get_param( 'credits_text' );
		if ( is_string( $credits_text ) ) {
			update_option( self::CREDITS_OPTION, sanitize_textarea_field( $credits_text ) );
		}

		$in_memoriam = $request->get_param( 'in_memoriam' );
		if ( is_array( $in_memoriam ) ) {
			$clean = [];
			foreach ( $in_memoriam as $entry ) {
				if ( ! is_array( $entry ) || empty( $entry['name'] ) ) {
					continue;
				}
				$clean[] = [
					'name' => sanitize_text_field( $entry['name'] ),
					'note' => sanitize_text_field( $entry['note'] ?? '' ),
				];
			}
			update_option( self::MEMORIAM_OPTION, $clean );
		}

		return $this->success( [
			'credits_text' => get_option( self::CREDITS_OPTION, self::DEFAULT_CREDITS ),
			'in_memoriam'  => self::memoriam_list(),
		] );
	}

	/**
	 * Returns the stored in-memoriam list, seeding it with
	 * DEFAULT_MEMORIAM the first time it is ever read before anyone
	 * has saved their own list.
	 *
	 * @return array
	 */
	private static function memoriam_list(): array {
		$stored = get_option( self::MEMORIAM_OPTION, null );
		if ( is_array( $stored ) ) {
			return $stored;
		}

		update_option( self::MEMORIAM_OPTION, self::DEFAULT_MEMORIAM );
		return self::DEFAULT_MEMORIAM;
	}
}
