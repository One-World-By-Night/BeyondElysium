<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * A Blood Magic row is told apart by its path and its tradition, so a change reaches the row it names even when another
 * row holds the same path under a different tradition or spelling.
 */
class TraditionAddressingThreadTest extends WP_UnitTestCase {

	private const GAME = 'thread-tradition-addressing';

	private int $manager;
	private int $player;
	private int $character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$game_id = (int) Game::create( [ 'slug' => self::GAME, 'name' => 'Thread Tradition Addressing' ] );
		Game::update( self::GAME, [ 'settings' => [ 'auto_approve' => true ] ] );
		$this->manager = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->player  = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $this->player, 'player' );

		$this->character = Character::create( [
			'name' => 'Addressing Test', 'stack_slug' => 'vampire',
			'owner_type' => 'chronicle', 'owner_slug' => self::GAME, 'wp_user_id' => $this->player,
			'sheet_data' => [ 'vampire-blood-magic' => [
				[ 'name' => 'Awakening of the Steel', 'tier' => '***', 'level' => 5, 'custom' => true, 'tradition' => 'Dur An Ki' ],
				[ 'name' => 'Awakening of the Steel', 'level' => 5, 'tradition' => 'Dur-An-Ki' ],
				[ 'name' => 'Alchemy', 'tier' => '***', 'level' => 2, 'custom' => true, 'tradition' => 'Sadhana' ],
				[ 'name' => 'Alchemy', 'level' => 2, 'tradition' => 'Sadhanna' ],
				[ 'name' => 'Path of Blood', 'level' => 3, 'tradition' => 'Thaumaturgy (Camarilla)' ],
				[ 'name' => 'Path of Blood', 'level' => 2, 'tradition' => 'Thaumaturgy (Anarch)' ],
			] ],
		] );
	}

	private function submit( string $type, array $trait, ?array $previous = null, int $as = 0 ) {
		wp_set_current_user( $as ?: $this->manager );
		$data = [ 'block_slug' => 'vampire-blood-magic', 'trait' => $trait ];
		if ( $previous !== null ) {
			$data['previous'] = $previous;
		}
		$request = new WP_REST_Request( 'POST', '/be/v1/' . self::GAME . '/characters/' . $this->character . '/changes' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'category' => 'vampire-blood-magic', 'change_type' => $type, 'change_data' => $data ] ) );
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function rows(): array {
		return Character::find( $this->character )->sheet_data['vampire-blood-magic'];
	}

	/**
	 * "name (tradition) level" for every held row.
	 *
	 * @return string[]
	 */
	private function held(): array {
		return array_map( static fn( $r ) => "{$r['name']} ({$r['tradition']}) {$r['level']}", $this->rows() );
	}

	public function test_a_removal_that_names_a_tradition_removes_only_that_rows(): void {
		$response = $this->submit( 'remove_trait', [ 'name' => 'Awakening of the Steel', 'tradition' => 'Dur-An-Ki' ] );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertNotContains( 'Awakening of the Steel (Dur-An-Ki) 5', $this->held() );
		$this->assertContains( 'Awakening of the Steel (Dur An Ki) 5', $this->held(), 'the row in the other spelling stays' );
		$this->assertCount( 5, $this->rows() );
	}

	public function test_a_removal_that_names_no_tradition_still_removes_every_row_of_that_name(): void {
		$response = $this->submit( 'remove_trait', [ 'name' => 'Path of Blood' ] );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( [ 'Awakening of the Steel (Dur An Ki) 5', 'Awakening of the Steel (Dur-An-Ki) 5', 'Alchemy (Sadhana) 2', 'Alchemy (Sadhanna) 2' ], $this->held() );
	}

	public function test_a_change_reaches_the_row_in_the_tradition_it_names(): void {
		$response = $this->submit(
			'modify_trait',
			[ 'name' => 'Path of Blood', 'level' => 4, 'tradition' => 'Thaumaturgy (Anarch)' ],
			[ 'name' => 'Path of Blood', 'level' => 2, 'tradition' => 'Thaumaturgy (Anarch)' ]
		);

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$held = $this->held();
		$this->assertContains( 'Path of Blood (Thaumaturgy (Anarch)) 4', $held );
		$this->assertContains( 'Path of Blood (Thaumaturgy (Camarilla)) 3', $held, 'the other tradition\'s row is untouched' );
	}

	public function test_a_change_that_would_leave_two_identical_rows_is_refused(): void {
		$response = $this->submit(
			'modify_trait',
			[ 'name' => 'Alchemy', 'level' => 2, 'tradition' => 'Sadhana' ],
			[ 'name' => 'Alchemy', 'level' => 2, 'tradition' => 'Sadhanna' ]
		);

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'already_held', $response->as_error()->get_error_code() );
		$this->assertContains( 'Alchemy (Sadhanna) 2', $this->held(), 'nothing changed' );
	}

	public function test_a_stale_spelling_with_no_twin_is_folded_to_the_catalogs_spelling_by_the_change(): void {
		$this->submit( 'remove_trait', [ 'name' => 'Awakening of the Steel', 'tradition' => 'Dur An Ki' ] );

		$response = $this->submit(
			'modify_trait',
			[ 'name' => 'Awakening of the Steel', 'level' => 4, 'tradition' => 'Dur-An-Ki' ],
			[ 'name' => 'Awakening of the Steel', 'level' => 5, 'tradition' => 'Dur-An-Ki' ]
		);

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertContains( 'Awakening of the Steel (Dur An Ki) 4', $this->held() );
		$this->assertNotContains( 'Awakening of the Steel (Dur-An-Ki) 5', $this->held() );
	}

	public function test_a_level_change_on_the_row_in_the_catalogs_spelling_is_not_blocked_by_a_stale_twin(): void {
		$response = $this->submit(
			'modify_trait',
			[ 'name' => 'Awakening of the Steel', 'level' => 4, 'tradition' => 'Dur An Ki' ],
			[ 'name' => 'Awakening of the Steel', 'level' => 5, 'tradition' => 'Dur An Ki' ]
		);

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$held = $this->held();
		$this->assertContains( 'Awakening of the Steel (Dur An Ki) 4', $held );
		$this->assertContains( 'Awakening of the Steel (Dur-An-Ki) 5', $held, 'the stale row is left for the Storyteller to remove' );
	}

	public function test_a_row_in_a_tradition_the_catalog_does_not_list_can_still_be_raised(): void {
		$sheet = Character::find( $this->character )->sheet_data;
		$sheet['vampire-blood-magic'][] = [ 'name' => 'Ash Path', 'level' => 2, 'tradition' => 'Necromancy (Mortis)' ];
		Character::update_sheet_data( $this->character, $sheet );

		$response = $this->submit(
			'modify_trait',
			[ 'name' => 'Ash Path', 'level' => 3, 'tradition' => 'Necromancy (Mortis)' ],
			[ 'name' => 'Ash Path', 'level' => 2, 'tradition' => 'Necromancy (Mortis)' ]
		);

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertContains( 'Ash Path (Necromancy (Mortis)) 3', $this->held(), 'the stored tradition is left as it was' );
	}

	public function test_a_tradition_the_catalog_does_not_list_is_still_refused_when_it_is_new(): void {
		$added = $this->submit( 'add_trait', [ 'name' => 'Path of Fire', 'level' => 1, 'tradition' => 'Koldunic Sorcery' ] );
		$this->assertSame( 400, $added->get_status() );
		$this->assertSame( 'unknown_tradition', $added->as_error()->get_error_code() );

		$moved = $this->submit(
			'modify_trait',
			[ 'name' => 'Path of Blood', 'level' => 2, 'tradition' => 'Koldunic Sorcery' ],
			[ 'name' => 'Path of Blood', 'level' => 2, 'tradition' => 'Thaumaturgy (Anarch)' ]
		);
		$this->assertSame( 400, $moved->get_status() );
		$this->assertSame( 'unknown_tradition', $moved->as_error()->get_error_code() );
	}

	public function test_two_waiting_changes_to_one_path_in_two_traditions_do_not_overwrite_each_other(): void {
		Game::update( self::GAME, [ 'settings' => [ 'auto_approve' => false ] ] );
		Character::update_xp( $this->character, 100, 100 );

		$camarilla = $this->submit(
			'modify_trait',
			[ 'name' => 'Path of Blood', 'level' => 4, 'tradition' => 'Thaumaturgy (Camarilla)' ],
			[ 'name' => 'Path of Blood', 'level' => 3, 'tradition' => 'Thaumaturgy (Camarilla)' ],
			$this->player
		);
		$anarch = $this->submit(
			'modify_trait',
			[ 'name' => 'Path of Blood', 'level' => 3, 'tradition' => 'Thaumaturgy (Anarch)' ],
			[ 'name' => 'Path of Blood', 'level' => 2, 'tradition' => 'Thaumaturgy (Anarch)' ],
			$this->player
		);

		$this->assertSame( 201, $camarilla->get_status(), wp_json_encode( $camarilla->get_data() ) );
		$this->assertSame( 201, $anarch->get_status(), wp_json_encode( $anarch->get_data() ) );
		$this->assertNotSame( $camarilla->get_data()->id, $anarch->get_data()->id, 'the second is its own waiting change' );
	}
}
