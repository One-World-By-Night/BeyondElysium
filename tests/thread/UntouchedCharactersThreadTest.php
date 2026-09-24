<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Plot;
use BeyondElysium\Services\Untouched_Characters;
use WP_UnitTestCase;

/**
 * "Untouched since import" (1.3.3, the second path): a character exactly as its file left it, with
 * nothing of the site's own attached, is the one that could be deleted and re-imported instead of
 * re-keyed. Each rule that can disqualify one is proved on its own, against a character that would
 * otherwise qualify - a rule that is not load-bearing shows up as a character that still counts.
 */
class UntouchedCharactersThreadTest extends WP_UnitTestCase {

	private string $game = 'untouched-chronicle';

	public function setUp(): void {
		parent::setUp();
		global $wpdb;
		// The persistent test database can hold characters from other runs; only this test's own count.
		$wpdb->query( 'DELETE FROM ' . Manager::table( 'characters' ) );
		Game::create( [ 'slug' => $this->game, 'name' => 'Untouched' ] );
	}

	private function imported( string $name = 'Imported', ?int $player = null ): int {
		$id = (int) Character::create( [
			'name' => $name, 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->game,
			'status' => 'active', 'wp_user_id' => $player, 'sheet_data' => [ 'vampire-abilities' => [ [ 'name' => 'Brawl', 'count' => 2 ] ] ],
		] );
		$this->change( $id, 'import_note', [ 'source_file' => 'imported.gex' ] );
		return $id;
	}

	/** The moment of the character's newest change, plus `$seconds` - in the same clock the site writes both columns in. */
	private function after_last_change( int $character_id, int $seconds ): string {
		$latest = (string) Manager::get_var( 'SELECT MAX(submitted_at) FROM ' . Manager::table( 'character_changes' ) . ' WHERE character_id = %d', $character_id );
		return gmdate( 'Y-m-d H:i:s', strtotime( $latest . ' UTC' ) + $seconds );
	}

	private function change( int $character_id, string $type, array $data = [] ): int {
		return (int) Change::create( [
			'character_id' => $character_id, 'change_type' => $type, 'category' => 'x', 'change_data' => $data,
			'xp_cost' => 0, 'status' => 'approved', 'submitted_by' => 1,
		] );
	}

	/** Inserts one row into `$table` that names `$character_id`, filling every other required column with a harmless value. */
	private function attach( string $table, string $column, $value ): void {
		global $wpdb;
		$name    = Manager::table( $table );
		$columns = $wpdb->get_results( $wpdb->prepare(
			'SELECT COLUMN_NAME, DATA_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s',
			$name
		) );
		$row = [ $column => $value ];
		foreach ( $columns as $c ) {
			if ( isset( $row[ $c->COLUMN_NAME ] ) || str_contains( (string) $c->EXTRA, 'auto_increment' ) || $c->IS_NULLABLE === 'YES' || $c->COLUMN_DEFAULT !== null ) {
				continue;
			}
			$row[ $c->COLUMN_NAME ] = in_array( $c->DATA_TYPE, [ 'int', 'bigint', 'tinyint', 'smallint', 'decimal', 'float', 'double' ], true )
				? 1
				: ( in_array( $c->DATA_TYPE, [ 'datetime', 'timestamp' ], true ) ? '2026-01-01 00:00:00' : ( $c->DATA_TYPE === 'json' ? '[]' : 'x' ) );
		}
		$this->assertNotFalse( $wpdb->insert( $name, $row ), "could not attach a row to {$table}: " . $wpdb->last_error );
	}

	public function test_a_character_exactly_as_imported_counts_and_says_whether_a_player_is_assigned(): void {
		$alone  = $this->imported( 'Alone' );
		$owned  = $this->imported( 'Owned', self::factory()->user->create() );

		$found = Untouched_Characters::find();
		ksort( $found );

		$this->assertSame( [ $alone => false, $owned => true ], $found );
	}

	public function test_the_cutovers_own_records_do_not_disqualify_it(): void {
		$id = $this->imported();
		$this->change( $id, 'catalog_rekey', [ 'counts' => [] ] );
		$this->change( $id, 'catalog_rekey_revert', [] );

		$this->assertArrayHasKey( $id, Untouched_Characters::find() );
	}

	public function test_it_can_be_limited_to_one_chronicle(): void {
		$here = $this->imported( 'Here' );
		Game::create( [ 'slug' => 'elsewhere', 'name' => 'Elsewhere' ] );
		$there = (int) Character::create( [ 'name' => 'There', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => 'elsewhere', 'status' => 'active', 'sheet_data' => [] ] );
		$this->change( $there, 'import_note', [ 'source_file' => 'there.gex' ] );

		$this->assertSame( [ $here => false ], Untouched_Characters::find( $this->game ) );
		$this->assertSame( [ $there => false ], Untouched_Characters::find( 'elsewhere' ) );
		$this->assertCount( 2, Untouched_Characters::find() );
	}

	public function test_a_character_nobody_imported_is_not_counted(): void {
		$id = (int) Character::create( [ 'name' => 'Hand Built', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->game, 'status' => 'active', 'sheet_data' => [] ] );

		$this->assertArrayNotHasKey( $id, Untouched_Characters::find(), 'no import note: there is no file to go back to' );
	}

	public function test_a_hand_built_character_that_was_only_cut_over_is_not_counted(): void {
		$id = (int) Character::create( [ 'name' => 'Hand Built', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->game, 'status' => 'active', 'sheet_data' => [] ] );
		$this->change( $id, 'catalog_rekey', [ 'counts' => [] ] );

		$this->assertArrayNotHasKey( $id, Untouched_Characters::find(), 'a cutover record is not an import: there is still no file to go back to' );
	}

	/** @return array<string,array{0:string}> */
	public static function edits(): array {
		return [
			'a trait added'      => [ 'add_trait' ],
			'a trait removed'    => [ 'remove_trait' ],
			'a trait changed'    => [ 'modify_trait' ],
			'a resource changed' => [ 'modify_resource' ],
			'an identity edit'   => [ 'modify_identity' ],
			'xp awarded'         => [ 'xp_earn' ],
			'xp adjusted'        => [ 'xp_adjust' ],
		];
	}

	/** @dataProvider edits */
	public function test_any_edit_disqualifies_it( string $type ): void {
		$id = $this->imported();
		$this->change( $id, $type, [ 'amount' => 1 ] );

		$this->assertArrayNotHasKey( $id, Untouched_Characters::find() );
	}

	public function test_a_direct_write_after_the_import_disqualifies_it(): void {
		global $wpdb;
		$id = $this->imported();
		// The 45-character repair that fixed real sheets wrote no change row; the row's own timestamp is all it left.
		$wpdb->update( Manager::table( 'characters' ), [ 'updated_at' => $this->after_last_change( $id, 86400 ) ], [ 'id' => $id ] );

		$this->assertArrayNotHasKey( $id, Untouched_Characters::find() );
	}

	public function test_a_write_in_the_import_itself_does_not(): void {
		global $wpdb;
		$id = $this->imported();
		$wpdb->update( Manager::table( 'characters' ), [ 'updated_at' => $this->after_last_change( $id, 30 ) ], [ 'id' => $id ] );

		$this->assertArrayHasKey( $id, Untouched_Characters::find(), 'the import writes the row and records the change a moment apart' );
	}

	/** @return array<string,array{0:string,1:string}> table, column that names the character by id */
	public static function attachments_by_id(): array {
		return [
			'a sheet style'          => [ 'character_sheet_styles', 'character_id' ],
			'a submission'           => [ 'character_submissions', 'character_id' ],
			'attendance'             => [ 'attendance', 'character_id' ],
			'a casting'              => [ 'npc_castings', 'character_id' ],
			'a revealed secret'      => [ 'secret_reveals', 'character_id' ],
			'an item event'          => [ 'item_events', 'character_id' ],
			'an item handed over'    => [ 'item_events', 'from_character_id' ],
			'an item attestation'    => [ 'item_attestations', 'character_id' ],
			'an after-game report'   => [ 'after_game_reports', 'character_id' ],
			'a faction membership'   => [ 'faction_members', 'character_id' ],
			'a court position'       => [ 'positions', 'character_id' ],
			'a position history row' => [ 'position_history', 'character_id' ],
		];
	}

	/** @dataProvider attachments_by_id */
	public function test_a_row_that_names_it_disqualifies_it( string $table, string $column ): void {
		$id    = $this->imported();
		$other = $this->imported( 'Bystander' );
		$this->attach( $table, $column, $id );

		$found = Untouched_Characters::find();

		$this->assertArrayNotHasKey( $id, $found, "{$table}.{$column}" );
		$this->assertArrayHasKey( $other, $found, 'and only the one it names' );
	}

	public function test_a_code_printed_on_its_sheet_or_a_transfer_disqualifies_it(): void {
		$printed = $this->imported( 'Printed' );
		$moving  = $this->imported( 'Moving' );
		$plain   = $this->imported( 'Plain' );
		$this->attach( 'character_attestations', 'character_uuid', Character::find( $printed )->uuid );
		$this->attach( 'character_transfers', 'character_uuid', Character::find( $moving )->uuid );

		$found = Untouched_Characters::find();

		$this->assertSame( [ $plain ], array_keys( $found ) );
	}

	public function test_the_plot_every_character_is_given_does_not_disqualify_it_while_it_is_empty(): void {
		$id = $this->imported();

		$this->assertNotNull( Character::plot_id( $id ), 'precondition: every chronicle character is given a plot' );
		$this->assertArrayHasKey( $id, Untouched_Characters::find() );
	}

	public function test_a_post_in_its_own_plot_disqualifies_it(): void {
		$posted = $this->imported( 'Posted' );
		$quiet  = $this->imported( 'Quiet' );
		$this->attach( 'plot_entries', 'plot_id', Character::plot_id( $posted ) );

		$this->assertSame( [ $quiet ], array_keys( Untouched_Characters::find() ) );
	}

	public function test_an_action_round_disqualifies_it(): void {
		$played = $this->imported( 'Played' );
		$quiet  = $this->imported( 'Quiet' );
		$game   = Game::find_by_slug( $this->game );
		$round  = Plot::create( [ 'game_id' => (int) $game->id, 'title' => 'Round', 'initiated_by' => 'st', 'game_date' => '2026-01-01', 'audience' => 'restricted' ] );
		Connection::create( [ 'game_id' => (int) $game->id, 'source_type' => 'plot', 'source_id' => $round, 'target_type' => 'character', 'target_id' => $played, 'label' => 'apr_actor', 'created_by' => 1 ] );

		$this->assertSame( [ $quiet ], array_keys( Untouched_Characters::find() ) );
	}

	public function test_an_item_connected_with_the_import_does_not_disqualify_it_but_one_connected_later_does(): void {
		global $wpdb;
		$with_import = $this->imported( 'With Import' );
		$later       = $this->imported( 'Later' );
		$row         = fn( int $id, string $when ) => [ 'game_id' => 1, 'source_type' => 'character', 'source_id' => $id, 'target_type' => 'world_object', 'target_id' => 999, 'created_by' => 1, 'created_at' => $when ];
		$wpdb->insert( Manager::table( 'connections' ), $row( $with_import, $this->after_last_change( $with_import, 5 ) ) );
		$wpdb->insert( Manager::table( 'connections' ), $row( $later, $this->after_last_change( $later, 86400 ) ) );

		$this->assertSame( [ $with_import ], array_keys( Untouched_Characters::find() ) );
	}

	public function test_a_connection_to_it_from_anything_but_its_own_plot_disqualifies_it(): void {
		global $wpdb;
		$other_label = $this->imported( 'Other Label' );
		$from_object = $this->imported( 'From Object' );
		$plain       = $this->imported( 'Plain' );
		$game        = Game::find_by_slug( $this->game );
		$plot        = Plot::create( [ 'game_id' => (int) $game->id, 'title' => 'Some Plot', 'initiated_by' => 'st', 'audience' => 'restricted' ] );
		Connection::create( [ 'game_id' => (int) $game->id, 'source_type' => 'plot', 'source_id' => $plot, 'target_type' => 'character', 'target_id' => $other_label, 'label' => 'involved', 'created_by' => 1 ] );
		$wpdb->insert( Manager::table( 'connections' ), [ 'game_id' => (int) $game->id, 'source_type' => 'world_object', 'source_id' => 999, 'target_type' => 'character', 'target_id' => $from_object, 'created_by' => 1, 'created_at' => current_time( 'mysql' ) ] );

		$this->assertSame( [ $plain ], array_keys( Untouched_Characters::find() ) );
	}

	public function test_a_rumor_aimed_at_it_disqualifies_it_whether_the_id_is_a_number_or_a_string(): void {
		$by_number = $this->imported( 'By Number' );
		$by_string = $this->imported( 'By String' );
		$plain     = $this->imported( 'Plain' );
		$this->attach( 'plot_entries', 'audience_character_ids', wp_json_encode( [ $by_number ] ) );
		$this->attach( 'plot_entries', 'audience_character_ids', wp_json_encode( [ (string) $by_string ] ) );

		$this->assertSame( [ $plain ], array_keys( Untouched_Characters::find() ) );
	}
}
