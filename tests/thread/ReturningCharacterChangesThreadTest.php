<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Transfer;
use BeyondElysium\Services\Character_Exporter;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * When a character returns to a chronicle that already holds its sheet, the reviewing Storyteller is shown what
 * differs before overwriting it.
 */
class ReturningCharacterChangesThreadTest extends WP_UnitTestCase {

	private string $home_slug = 'thread-return-home';
	private string $host_slug = 'thread-return-host';

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		foreach ( [ $this->home_slug => 'Return Home', $this->host_slug => 'Return Host' ] as $slug => $name ) {
			$wpdb->insert( $wpdb->prefix . 'be_games', [
				'slug' => $slug, 'name' => $name, 'settings' => '{}',
				'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			] );
		}

		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		add_filter( 'pre_http_request', [ $this, 'loopback' ], 10, 3 );
	}

	public function tearDown(): void {
		remove_filter( 'pre_http_request', [ $this, 'loopback' ], 10 );
		parent::tearDown();
	}

	/**
	 * Answers the host's verify callback through real REST dispatch.
	 */
	public function loopback( $preempt, $args, $url ) {
		if ( strpos( $url, '/verify/' ) === false ) {
			return $preempt;
		}
		$response = rest_get_server()->dispatch( new WP_REST_Request( 'GET', '/be/v1/verify/' . rawurldecode( substr( $url, strrpos( $url, '/' ) + 1 ) ) ) );
		return [
			'response' => [ 'code' => $response->get_status(), 'message' => '' ],
			'body'     => wp_json_encode( $response->get_data() ),
			'headers'  => [], 'cookies' => [], 'filename' => null,
		];
	}

	/**
	 * The sheet as the host last saw it.
	 */
	private function sheet_when_it_left(): array {
		return [
			'vampire-identity'    => [ 'Clan' => 'Nosferatu', 'Sect' => 'Sabbat', 'Generation' => 8, 'Title' => 'Pack Priest' ],
			'vampire-abilities'       => [ [ 'name' => 'Streetwise', 'count' => 4 ], [ 'name' => 'Stealth', 'count' => 3 ] ],
			'vampire-merits'          => [ [ 'name' => 'Danger Sense', 'count' => 2 ] ],
			'vampire-disciplines' => [ [ 'name' => 'Animalism', 'level' => 2 ], [ 'name' => 'Obfuscate', 'level' => 3 ] ],
		];
	}

	/**
	 * The same sheet after more play at home.
	 */
	private function sheet_coming_back(): array {
		return [
			'vampire-identity'    => [ 'Clan' => 'Nosferatu', 'Sect' => 'Sabbat', 'Generation' => 8, 'Title' => 'Bishop' ],
			'vampire-abilities'       => [ [ 'name' => 'Streetwise', 'count' => 5 ], [ 'name' => 'Stealth', 'count' => 3 ], [ 'name' => 'Brawl', 'count' => 2 ] ],
			'vampire-merits'          => [],
			'vampire-disciplines' => [ [ 'name' => 'Animalism', 'level' => 3 ], [ 'name' => 'Obfuscate', 'level' => 3 ], [ 'name' => 'Potence', 'level' => 1 ] ],
		];
	}

	private function character( string $owner_slug, array $sheet, array $xp, ?string $uuid = null ): int {
		$id = Character::create( array_filter( [
			'name' => 'Wandering Nosferatu', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle',
			'owner_slug' => $owner_slug, 'status' => 'active', 'sheet_data' => $sheet, 'uuid' => $uuid,
		] ) );
		Character::update_xp( $id, $xp[0], $xp[1] );
		return $id;
	}

	/**
	 * Home sends `$coming_back`.
	 *
	 * @return array<string,mixed> The review's one duplicate.
	 */
	private function review_of_a_return( array $coming_back, array $xp_back, array $host_copy, array $xp_host ): array {
		$home     = $this->character( $this->home_slug, $coming_back, $xp_back );
		$uuid     = Character::find( $home )->uuid;
		$document = Character_Exporter::export( $home, [ 'as_transfer' => true ] );
		preg_match( '/code=([A-Za-z0-9-]+)/', $document['xml'], $m );
		Character::delete( $home );
		$this->character( $this->host_slug, $host_copy, $xp_host, $uuid );

		return $this->offer_and_review( $document['xml'], $m[1], $uuid );
	}

	private function offer_and_review( string $xml, string $code, string $uuid ): array {
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->host_slug}/transfers/inbound" );
		foreach ( [ 'payload' => $xml, 'short_code' => $code, 'home_site' => home_url(), 'home_slug' => $this->home_slug, 'home_chronicle' => 'Return Home', 'character_uuid' => $uuid ] as $key => $value ) {
			$request->set_param( $key, $value );
		}
		$offered = rest_get_server()->dispatch( $request );
		$this->assertSame( 202, $offered->get_status(), wp_json_encode( $offered->get_data() ) );

		$transfer = Transfer::find_open( $uuid, 'inbound' );
		$review   = rest_get_server()->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->host_slug}/transfers/{$transfer->id}/review" ) );
		$this->assertSame( 200, $review->get_status(), wp_json_encode( $review->get_data() ) );

		$duplicates = $review->get_data()['preview']['duplicates'];
		$this->assertCount( 1, $duplicates );
		return $duplicates[0];
	}

	public function test_a_returning_character_shows_exactly_what_changed_since_it_was_here(): void {
		$duplicate = $this->review_of_a_return( $this->sheet_coming_back(), [ 52, 3 ], $this->sheet_when_it_left(), [ 40, 6 ] );

		$this->assertSame( 'uuid', $duplicate['matched_by'] );
		$this->assertEqualsCanonicalizing( [
			[ 'section' => 'Details', 'entry' => 'Title', 'here' => 'Pack Priest', 'arriving' => 'Bishop' ],
			[ 'section' => 'Experience', 'entry' => 'Earned', 'here' => '40', 'arriving' => '52' ],
			[ 'section' => 'Experience', 'entry' => 'Unspent', 'here' => '6', 'arriving' => '3' ],
			[ 'section' => 'Abilities', 'entry' => 'Streetwise', 'here' => '4', 'arriving' => '5' ],
			[ 'section' => 'Abilities', 'entry' => 'Brawl', 'here' => null, 'arriving' => '2' ],
			[ 'section' => 'Merits', 'entry' => 'Danger Sense', 'here' => '2', 'arriving' => null ],
			[ 'section' => 'Disciplines', 'entry' => 'Animalism', 'here' => '2', 'arriving' => '3' ],
			[ 'section' => 'Disciplines', 'entry' => 'Potence', 'here' => null, 'arriving' => '1' ],
		], $duplicate['changes'] );
	}

	public function test_a_character_coming_back_unchanged_shows_no_changes(): void {
		$duplicate = $this->review_of_a_return( $this->sheet_when_it_left(), [ 40, 6 ], $this->sheet_when_it_left(), [ 40, 6 ] );

		$this->assertSame( [], $duplicate['changes'] );
	}

	public function test_a_character_whose_sheet_belongs_to_another_chronicle_is_not_compared(): void {
		$home     = $this->character( $this->home_slug, $this->sheet_coming_back(), [ 52, 3 ] );
		$uuid     = Character::find( $home )->uuid;
		$document = Character_Exporter::export( $home, [ 'as_transfer' => true ] );
		preg_match( '/code=([A-Za-z0-9-]+)/', $document['xml'], $m );

		$duplicate = $this->offer_and_review( $document['xml'], $m[1], $uuid );

		$this->assertSame( 'uuid_elsewhere', $duplicate['matched_by'] );
		$this->assertArrayNotHasKey( 'changes', $duplicate, "another chronicle's sheet is not this chronicle's to read" );
	}
}
