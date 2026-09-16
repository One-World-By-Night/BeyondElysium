<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\World_Object;
use BeyondElysium\REST\Import_Controller;
use BeyondElysium\Services\Character_Exporter;
use BeyondElysium\Services\GEX_Xml_Parser;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-053 (Pass H intake). Import decisions are keyed by name, and each entry in a file
 * looks up its match by name again. Two characters named "John Doe" in one file, set to Overwrite
 * against the chronicle's own John Doe, both landed on the same row: the second overwrote what the
 * first had just written, and the commit reported both as overwritten. Two same-named items did the
 * same. Within one commit, a row already written is never overwritten again - a later same-named
 * entry arrives as its own record, and the preview says so.
 */
class ImportSameNameEntriesThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-same-name';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Same Name' ] );
		Game::create( [ 'slug' => 'thread-same-name-source', 'name' => 'Same Name Source' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	/** A parsed exchange file holding one character per clan, every one named John Doe. */
	private function file_of_john_does( string ...$clans ): array {
		$parsed = null;
		foreach ( $clans as $clan ) {
			$id       = Character::create( [ 'name' => 'John Doe', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => 'thread-same-name-source', 'status' => 'active', 'sheet_data' => [ 'vampire-identity' => [ 'Clan' => $clan ] ] ] );
			$document = GEX_Xml_Parser::parse_string( Character_Exporter::export( $id )['xml'] );
			Character::delete( $id );
			if ( $parsed === null ) {
				$parsed = $document;
			} else {
				$parsed['characters'][] = $document['characters'][0];
			}
		}
		return $parsed;
	}

	private function clans_of_john_does(): array {
		$clans = array_map(
			static fn( $c ) => Character::find( (int) $c->id )->sheet_data['vampire-identity']['Clan'] ?? null,
			array_filter( Character::all_for_game( $this->slug ), static fn( $c ) => $c->name === 'John Doe' )
		);
		sort( $clans );
		return $clans;
	}

	public function test_two_same_named_characters_set_to_overwrite_keep_both_sheets(): void {
		$existing = Character::create( [ 'name' => 'John Doe', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'status' => 'active', 'sheet_data' => [ 'vampire-identity' => [ 'Clan' => 'Tremere' ] ] ] );

		$result = Import_Controller::apply_import( $this->game_id, $this->slug, $this->file_of_john_does( 'Brujah', 'Toreador' ), 'john-does.gex', [ 'duplicates' => [ 'John Doe' => 'overwrite' ] ] );

		$this->assertSame( [ 'Brujah', 'Toreador' ], $this->clans_of_john_does(), 'the first entry overwrote the chronicle\'s John Doe; the second arrived as its own character' );
		$this->assertSame( 'Brujah', Character::find( $existing )->sheet_data['vampire-identity']['Clan'] );
		$this->assertSame( [ 'overwritten', 'created' ], array_column( $result['characters'], 'action' ) );
	}

	public function test_two_same_named_items_set_to_overwrite_keep_both(): void {
		World_Object::create( [ 'game_id' => $this->game_id, 'object_type' => 'item', 'name' => 'Dagger', 'properties' => [ 'level' => 1 ] ] );
		$dagger = static fn( int $level ) => [
			'name' => 'Dagger', 'notes' => '', 'item_type' => 'Weapon', 'item_subtype' => '', 'level' => $level, 'bonus' => 0,
			'damage_type' => 'L', 'damage_amount' => 1, 'concealability' => '', 'powers' => '', 'appearance' => '',
			'temper_list' => [], 'negative_list' => [], 'ability_list' => [], 'availability' => [],
		];
		$parsed          = $this->file_of_john_does( 'Brujah' );
		$parsed['items'] = [ $dagger( 2 ), $dagger( 3 ) ];
		$parsed['characters'] = [];

		Import_Controller::apply_import( $this->game_id, $this->slug, $parsed, 'daggers.gex', [ 'world_objects' => [ 'item:Dagger' => 'overwrite' ] ] );

		$levels = array_map(
			static fn( $o ) => (int) ( $o->properties['level'] ?? 0 ),
			array_filter( World_Object::for_game( $this->game_id, [ 'object_type' => 'item', 'per_page' => 100 ] ), static fn( $o ) => $o->name === 'Dagger' )
		);
		sort( $levels );
		$this->assertSame( [ 2, 3 ], $levels );
	}

	public function test_the_preview_says_a_second_same_named_entry_arrives_as_its_own_record(): void {
		Character::create( [ 'name' => 'John Doe', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'status' => 'active' ] );

		$preview = Import_Controller::build_preview( $this->file_of_john_does( 'Brujah', 'Toreador' ), $this->slug, $this->game_id, [], 'XML' );

		$this->assertCount( 1, array_filter( $preview['warnings'], static fn( $w ) => str_contains( $w, 'John Doe' ) && str_contains( $w, '2' ) ) );
		$this->assertCount( 1, $preview['duplicates'], 'one decision for the name, not two rows the Storyteller cannot tell apart' );
	}
}
