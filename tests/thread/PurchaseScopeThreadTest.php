<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Catalog_Reader;
use BeyondElysium\Services\Point_Audit;
use BeyondElysium\Services\Purchase_Scope;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * An HST can open a purchase list to every creature type.
 */
class PurchaseScopeThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-purchase-scope';
	private int $player;
	private int $character;

	/**
	 * Family suffix => [ own item names, the name only the other creature type has ].
	 */
	private const LISTS = [
		'abilities'   => [ [ 'Alertness', 'Brawl' ], 'Psb Cosmology', '2' ],
		'backgrounds' => [ [ 'Allies' ], 'Psb Avatar', '1' ],
		'merits'      => [ [ 'Iron Will' ], 'Psb Lucky', '3' ],
		'flaws'       => [ [ 'Nightmares' ], 'Psb Haunted', '1' ],
	];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		Purchase_Scope::reset_cache();

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug,
			'settings' => wp_json_encode( [ 'auto_approve' => true ] ),
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$game_id = (int) Game::find_by_slug( $this->slug )->id;

		foreach ( self::LISTS as $suffix => [ $shared, $only_other, $cost ] ) {
			$own_items   = array_map( static fn( $name ) => [ 'name' => $name, 'cost' => '1' ], $shared );
			$other_items = array_merge( $own_items, [ [ 'name' => $only_other, 'cost' => $cost ] ] );
			if ( $suffix === 'backgrounds' ) {
				$other_items[1]['approval'] = 'st';
				$other_items[]              = [ 'name' => 'Psb Followers', 'cost' => '1', 'allow_multiples' => true ];
			}
			// The first creature type has the shared names alone; the second adds its own.
			$this->block( "psa-{$suffix}", $own_items );
			$this->block( "psb-{$suffix}", $other_items );
		}
		foreach ( [ 'psa', 'psb' ] as $prefix ) {
			Creature_Stack::create( [
				'slug' => "{$prefix}-stack", 'name' => strtoupper( $prefix ), 'is_system' => 0, 'created_by' => 1,
				'stack_definition' => [ 'sections' => array_map( static fn( $suffix ) => [ 'block_slug' => "{$prefix}-{$suffix}" ], array_keys( self::LISTS ) ) ],
			] );
		}

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $this->player, 'player' );
		$this->character = Character::create( [
			'name' => 'Scope Tester', 'stack_slug' => 'psa-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player,
		] );
		Character::update_xp( $this->character, 50, 50 );
	}

	public function tearDown(): void {
		Purchase_Scope::reset_cache();
		wp_set_current_user( 0 );
		parent::tearDown();
	}

	/** @param array<int,array<string,string>> $items */
	private function block( string $slug, array $items ): void {
		Schema_Block::create( [
			'slug' => $slug, 'name' => $slug, 'section_type' => 'trait_list', 'is_system' => 0,
			'definition' => [ 'items' => $items, 'allow_custom' => false ],
		] );
	}

	/** @param array<string,bool> $scope */
	private function open( array $scope ): void {
		Game::update( $this->slug, [ 'settings' => [ 'auto_approve' => true, 'purchase_scope' => $scope ] ] );
		Purchase_Scope::reset_cache();
	}

	/** @return string[] The names a chronicle's first creature type can buy in one family. */
	private function names( string $suffix ): array {
		$blocks = Creature_Stack::resolve( 'psa-stack', $this->slug )['blocks'];
		return array_map( static fn( $item ) => $item->name, (array) $blocks[ "psa-{$suffix}" ]->definition->items );
	}

	private function submit( array $change ) {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character}/changes" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array_merge( [ 'category' => 'test' ], $change ) ) );
		return rest_get_server()->dispatch( $request );
	}

	private function add( string $suffix, string $name ) {
		return $this->submit( [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => "psa-{$suffix}", 'trait' => [ 'name' => $name, 'count' => 1 ] ],
		] );
	}

	public function test_every_area_starts_off_and_each_creature_type_sees_only_its_own_list(): void {
		$this->assertSame( [], Purchase_Scope::open_areas( $this->slug ) );

		foreach ( self::LISTS as $suffix => [ $shared, $only_other ] ) {
			$this->assertSame( $shared, $this->names( $suffix ), $suffix );
			$this->assertNotContains( $only_other, $this->names( $suffix ), $suffix );
		}
	}

	public function test_opening_abilities_widens_abilities_and_nothing_else(): void {
		$this->open( [ 'abilities' => true ] );

		$this->assertContains( 'Psb Cosmology', $this->names( 'abilities' ) );
		foreach ( [ 'backgrounds' => 'Psb Avatar', 'merits' => 'Psb Lucky', 'flaws' => 'Psb Haunted' ] as $suffix => $only_other ) {
			$this->assertNotContains( $only_other, $this->names( $suffix ), "{$suffix} widened without its switch" );
		}
	}

	public function test_merits_and_flaws_are_one_switch_for_both(): void {
		$this->open( [ 'merits_flaws' => true ] );

		$this->assertContains( 'Psb Lucky', $this->names( 'merits' ) );
		$this->assertContains( 'Psb Haunted', $this->names( 'flaws' ) );
		$this->assertNotContains( 'Psb Cosmology', $this->names( 'abilities' ) );
		$this->assertNotContains( 'Psb Avatar', $this->names( 'backgrounds' ) );
	}

	public function test_the_switches_combine_freely(): void {
		$this->open( [ 'abilities' => true, 'backgrounds' => true, 'merits_flaws' => false ] );

		$this->assertContains( 'Psb Cosmology', $this->names( 'abilities' ) );
		$this->assertContains( 'Psb Avatar', $this->names( 'backgrounds' ) );
		$this->assertNotContains( 'Psb Lucky', $this->names( 'merits' ) );
		$this->assertNotContains( 'Psb Haunted', $this->names( 'flaws' ) );
	}

	public function test_a_creature_types_own_entries_come_first_and_a_shared_name_is_listed_once(): void {
		$this->open( [ 'abilities' => true ] );

		$names = $this->names( 'abilities' );

		$this->assertSame( [ 'Alertness', 'Brawl' ], array_slice( $names, 0, 2 ) );
		$this->assertSame( 1, count( array_keys( $names, 'Alertness', true ) ) );
	}

	public function test_a_wider_list_is_never_written_to_a_block(): void {
		$this->open( [ 'abilities' => true, 'backgrounds' => true, 'merits_flaws' => true ] );
		$this->names( 'abilities' );

		$stored = array_map( static fn( $item ) => $item->name, (array) Schema_Block::find_by_slug( 'psa-abilities' )->definition->items );

		$this->assertSame( [ 'Alertness', 'Brawl' ], $stored );
	}

	public function test_a_block_no_creature_type_lists_never_joins_a_purchase_list(): void {
		// What the catalog retired, or an admin left behind, is not anyone's list.
		$this->block( 'psx-abilities', [ [ 'name' => 'Orphan Only', 'cost' => '1' ] ] );
		$this->open( [ 'abilities' => true ] );

		$this->assertNotContains( 'Orphan Only', $this->names( 'abilities' ) );
		$this->assertNotContains( 'psx-abilities', Purchase_Scope::family_slugs( 'abilities' ) );
	}

	public function test_a_purchase_from_another_creature_type_is_refused_until_its_area_is_open_and_then_priced(): void {
		$closed = $this->add( 'abilities', 'Psb Cosmology' );
		$this->assertSame( 400, $closed->get_status() );
		$this->assertSame( 'unknown_trait', $closed->as_error()->get_error_code() );
		$this->assertEquals( 50, Character::find( $this->character )->xp_unspent );

		// Another area being open does not open this one.
		$this->open( [ 'backgrounds' => true ] );
		$this->assertSame( 400, $this->add( 'abilities', 'Psb Cosmology' )->get_status() );

		$this->open( [ 'abilities' => true ] );
		$open = $this->add( 'abilities', 'Psb Cosmology' );

		$this->assertSame( 201, $open->get_status(), wp_json_encode( $open->get_data() ) );
		$character = Character::find( $this->character );
		$this->assertEquals( 48, $character->xp_unspent, 'priced at the other creature type\'s own cost, 2' );
		$this->assertSame( 'Psb Cosmology', $character->sheet_data['psa-abilities'][0]['name'] );
		$this->assertArrayNotHasKey( 'custom', $character->sheet_data['psa-abilities'][0], 'a catalog entry, not a homebrew one' );
	}

	public function test_an_entry_from_another_creature_type_keeps_its_own_approval_rule(): void {
		$this->open( [ 'backgrounds' => true ] );

		$response = $this->add( 'backgrounds', 'Psb Avatar' );

		$this->assertSame( 201, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$this->assertSame( 'pending', $response->get_data()->status, 'its own approval level, not the chronicle\'s auto-approve default' );
		$this->assertEquals( 50, Character::find( $this->character )->xp_unspent );
	}

	public function test_an_entry_from_another_creature_type_that_allows_multiples_can_be_held_under_two_labels(): void {
		Game::update( $this->slug, [ 'settings' => [ 'auto_approve' => false, 'purchase_scope' => [ 'backgrounds' => true ] ] ] );
		Purchase_Scope::reset_cache();
		$submit = fn( string $label ) => $this->submit( [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => 'psa-backgrounds', 'trait' => [ 'name' => 'Psb Followers', 'count' => 1, 'specialization' => $label ] ],
		] );

		$first  = $submit( 'The Court' );
		$second = $submit( 'The Street' );

		$this->assertSame( 201, $first->get_status(), wp_json_encode( $first->get_data() ) );
		$this->assertSame( 201, $second->get_status(), wp_json_encode( $second->get_data() ) );
		$this->assertNotSame( $first->get_data()->id, $second->get_data()->id, 'two purchases, not one resubmitted' );
	}

	public function test_the_point_audit_prices_a_held_entry_from_another_creature_type_only_while_its_area_is_open(): void {
		Character::update_sheet_data( $this->character, [ 'psa-abilities' => [ [ 'name' => 'Psb Cosmology', 'count' => 1 ] ] ] );
		$line = static function ( array $audit ): array {
			foreach ( $audit['lines'] as $candidate ) {
				if ( ( $candidate['label'] ?? '' ) === 'Psb Cosmology' ) {
					return $candidate;
				}
			}
			return [];
		};

		$closed = $line( (array) Point_Audit::for_character( $this->character ) );
		$this->assertNotSame( [], $closed, 'the audit lists the held entry' );
		$this->assertNull( $closed['xp'], 'not in this creature type\'s catalog, so not priced' );

		$this->open( [ 'abilities' => true ] );
		$open = $line( (array) Point_Audit::for_character( $this->character ) );

		$this->assertNotSame( [], $open );
		$this->assertSame( 2, $open['xp'] );
	}

	public function test_on_the_declared_catalog_the_family_is_the_live_per_creature_blocks_only(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		delete_option( Catalog_Cutover::OPTION );
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . \BeyondElysium\Database\Manager::table( 'characters' ) );

		Catalog_Cutover::declare_fresh_install();
		Seeder::seed_creature_stacks();
		Purchase_Scope::reset_cache();

		$family = Purchase_Scope::family_slugs( 'abilities' );
		$this->assertContains( 'vampire-abilities', $family );
		$this->assertContains( 'mage-abilities', $family );
		$this->assertNotContains( 'met-abilities', $family, 'the retired shared list is nobody\'s purchase list' );

		delete_option( Catalog_Cutover::OPTION );
	}
}
