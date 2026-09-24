<?php

namespace BeyondElysium\REST;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the plugin's credits and in-memoriam content.
 */
class Credits_Controller extends Base_Controller {

	protected $rest_base = 'credits';

	const CREDITS_OPTION  = 'be_credits_text';
	const MEMORIAM_OPTION = 'be_in_memoriam';
	const DEFAULT_CREDITS = 'Beyond Elysium was created by Greg Hacke for One World by Night.';

	/**
	 * The starting in-memoriam list, seeded once.
	 */
	// Alphabetical by first name.
	const DEFAULT_MEMORIAM = [
		[ 'name' => 'Arielle M.', 'note' => '' ],
		[ 'name' => 'Ash White', 'note' => '' ],
		[ 'name' => 'Carl Gosline', 'note' => '' ],
		[ 'name' => 'Douglas Alexander', 'note' => '' ],
		[ 'name' => 'Gary "House" Williams', 'note' => '' ],
		[ 'name' => 'J. T. Nielsen', 'note' => '' ],
		[ 'name' => 'Jamison', 'note' => '' ],
		[ 'name' => 'Sarah Gabbey', 'note' => '' ],
		[ 'name' => 'Scott Little', 'note' => '' ],
		[ 'name' => 'Stephen Page', 'note' => '' ],
		[ 'name' => 'Tim "Ando" Anderson', 'note' => '' ],
		[ 'name' => 'Travis Dunn', 'note' => '' ],
	];

	/**
	 * Registers the credits routes.
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
	 * Returns the stored in-memoriam list, seeding it with DEFAULT_MEMORIAM the first time it is ever read before anyone
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
