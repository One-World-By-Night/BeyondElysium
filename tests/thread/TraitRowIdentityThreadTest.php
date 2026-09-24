<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Through the real change route: a held row's identity is its `name` alone unless the item.
 */
class TraitRowIdentityThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-row-identity';
	private int $player;
	private int $character;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->slug, 'name' => $this->slug,
			'settings' => wp_json_encode( [ 'auto_approve' => true ] ),
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
		] );
		$game_id = (int) Game::find_by_slug( $this->slug )->id;

		// A specialization labels one holding: no multiples anywhere on this block.
		Schema_Block::create( [
			'slug' => 'tri-abilities', 'name' => 'Abilities', 'section_type' => 'trait_list', 'is_system' => 0,
			'definition' => [ 'has_specializations' => true, 'items' => [ [ 'name' => 'Brawl', 'cost' => '1' ] ] ],
		] );
		// Retainers states its own flag; Generation says nothing and takes the block default.
		Schema_Block::create( [
			'slug' => 'tri-backgrounds', 'name' => 'Backgrounds', 'section_type' => 'trait_list', 'is_system' => 0,
			'definition' => [ 'has_specializations' => true, 'items' => [
				[ 'name' => 'Retainers', 'cost' => '1', 'allow_multiples' => true ],
				[ 'name' => 'Generation', 'cost' => '1' ],
			] ],
		] );
		Schema_Block::create( [
			'slug' => 'tri-plain-backgrounds', 'name' => 'Plain Backgrounds', 'section_type' => 'trait_list', 'is_system' => 0,
			'definition' => [ 'items' => [
				[ 'name' => 'Retainers', 'cost' => '1', 'allow_multiples' => true ],
				[ 'name' => 'Generation', 'cost' => '1' ],
			] ],
		] );

		// The same two items under a block-wide default of true, and one item overriding it.
		Schema_Block::create( [
			'slug' => 'tri-studies', 'name' => 'Studies', 'section_type' => 'trait_list', 'is_system' => 0,
			'definition' => [ 'has_specializations' => true, 'allow_multiples' => true, 'items' => [
				[ 'name' => 'Lore', 'cost' => '1' ],
				[ 'name' => 'Brawl', 'cost' => '1', 'allow_multiples' => false ],
			] ],
		] );

		// An atomic block is exempt by declaration: a repeat really is a second entry.
		Schema_Block::create( [
			'slug' => 'tri-merits', 'name' => 'Merits', 'section_type' => 'trait_list', 'is_system' => 0,
			'definition' => [ 'atomic' => true, 'items' => [ [ 'name' => 'Catlike Balance', 'cost' => '1' ] ] ],
		] );

		Schema_Block::create( [
			'slug' => 'tri-disciplines', 'name' => 'Disciplines', 'section_type' => 'tiered_power', 'is_system' => 0,
			'definition' => [ 'powers' => [ [ 'name' => 'Celerity', 'levels' => [
				[ 'level' => 1, 'power_name' => 'Alacrity', 'cost' => '3' ],
				[ 'level' => null, 'power_name' => 'Precision', 'tier' => 'elder', 'cost' => '12' ],
				[ 'level' => null, 'power_name' => 'Zephyr', 'tier' => 'elder', 'cost' => '12' ],
			] ] ] ],
		] );

		Creature_Stack::create( [
			'slug' => 'tri-stack', 'name' => 'Row Identity Creature', 'is_system' => 0, 'created_by' => 1,
			'stack_definition' => [ 'sections' => [
				[ 'block_slug' => 'tri-abilities' ], [ 'block_slug' => 'tri-backgrounds' ],
				[ 'block_slug' => 'tri-plain-backgrounds' ],
				[ 'block_slug' => 'tri-studies' ], [ 'block_slug' => 'tri-merits' ],
				[ 'block_slug' => 'tri-disciplines' ],
			] ],
		] );

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $game_id, $this->player, 'player' );

		$this->character = Character::create( [
			'name' => 'Row Identity Tester', 'stack_slug' => 'tri-stack',
			'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'wp_user_id' => $this->player,
			'sheet_data' => [],
		] );
		Character::update_xp( $this->character, 200, 200 );
	}

	private function submit( array $change ) {
		wp_set_current_user( $this->player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->slug}/characters/{$this->character}/changes" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( wp_json_encode( array_merge( [ 'category' => 'test' ], $change ) ) );
		return rest_get_server()->dispatch( $request );
	}

	private function add( string $block, array $trait ) {
		return $this->submit( [
			'change_type' => 'add_trait',
			'change_data' => [ 'block_slug' => $block, 'trait' => $trait ],
		] );
	}

	/** @return array<int,array<string,mixed>> The character's stored rows for one block. */
	private function rows( string $block ): array {
		$sheet = Character::find( $this->character )->sheet_data;
		return is_array( $sheet[ $block ] ?? null ) ? $sheet[ $block ] : [];
	}

	public function test_a_second_focus_label_cannot_buy_a_second_brawl(): void {
		$this->assertSame( 201, $this->add( 'tri-abilities', [ 'name' => 'Brawl', 'count' => 5, 'specialization' => 'Wrestling' ] )->get_status() );

		$second = $this->add( 'tri-abilities', [ 'name' => 'Brawl', 'count' => 2, 'specialization' => 'Boxing' ] );

		$this->assertSame( 400, $second->get_status() );
		$this->assertSame( 'trait_already_held', $second->as_error()->get_error_code() );
		$this->assertCount( 1, $this->rows( 'tri-abilities' ) );
	}

	public function test_two_retainers_are_two_real_purchases(): void {
		$this->assertSame( 201, $this->add( 'tri-backgrounds', [ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'John Doe' ] )->get_status() );

		$second = $this->add( 'tri-backgrounds', [ 'name' => 'Retainers', 'count' => 2, 'specialization' => 'Sue Smith' ] );

		$this->assertSame( 201, $second->get_status() );
		$this->assertCount( 2, $this->rows( 'tri-backgrounds' ) );
	}

	/**
	 * Guard: the server path was always correct.
	 */
	public function test_two_labelled_retainers_on_a_plain_backgrounds_block_are_two_rows(): void {
		$this->assertSame( 201, $this->add( 'tri-plain-backgrounds', [ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'Bob' ] )->get_status() );

		$second = $this->add( 'tri-plain-backgrounds', [ 'name' => 'Retainers', 'count' => 2, 'specialization' => 'Sue' ] );

		$this->assertSame( 201, $second->get_status() );
		$rows = $this->rows( 'tri-plain-backgrounds' );
		$this->assertCount( 2, $rows );
		$this->assertSame( [ 'Bob', 'Sue' ], array_map( static fn( $row ) => $row['specialization'], $rows ) );
	}

	public function test_the_same_retainer_twice_is_still_one_holding(): void {
		$this->assertSame( 201, $this->add( 'tri-backgrounds', [ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'John Doe' ] )->get_status() );

		$again = $this->add( 'tri-backgrounds', [ 'name' => 'Retainers', 'count' => 1, 'specialization' => 'John Doe' ] );

		$this->assertSame( 400, $again->get_status() );
		$this->assertSame( 'trait_already_held', $again->as_error()->get_error_code() );
		$this->assertCount( 1, $this->rows( 'tri-backgrounds' ) );
	}

	public function test_an_item_with_no_flag_of_its_own_follows_the_block_default(): void {
		// Generation sits on a block whose default is false: one holding, label or no label.
		$this->assertSame( 201, $this->add( 'tri-backgrounds', [ 'name' => 'Generation', 'count' => 2, 'specialization' => '8th' ] )->get_status() );
		$narrow = $this->add( 'tri-backgrounds', [ 'name' => 'Generation', 'count' => 1, 'specialization' => '7th' ] );
		$this->assertSame( 400, $narrow->get_status() );
		$this->assertSame( 'trait_already_held', $narrow->as_error()->get_error_code() );

		// Lore sits on a block whose default is true: two fields of study, two holdings.
		$this->assertSame( 201, $this->add( 'tri-studies', [ 'name' => 'Lore', 'count' => 2, 'specialization' => 'Camarilla' ] )->get_status() );
		$this->assertSame( 201, $this->add( 'tri-studies', [ 'name' => 'Lore', 'count' => 1, 'specialization' => 'Sabbat' ] )->get_status() );
		$this->assertCount( 2, $this->rows( 'tri-studies' ) );
	}

	public function test_an_item_overrides_a_permissive_block(): void {
		$this->assertSame( 201, $this->add( 'tri-studies', [ 'name' => 'Brawl', 'count' => 3, 'specialization' => 'Wrestling' ] )->get_status() );

		$second = $this->add( 'tri-studies', [ 'name' => 'Brawl', 'count' => 1, 'specialization' => 'Boxing' ] );

		$this->assertSame( 400, $second->get_status() );
		$this->assertSame( 'trait_already_held', $second->as_error()->get_error_code() );
	}

	public function test_relabelling_one_retainer_onto_another_is_refused(): void {
		$this->add( 'tri-backgrounds', [ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'John Doe' ] );
		$this->add( 'tri-backgrounds', [ 'name' => 'Retainers', 'count' => 2, 'specialization' => 'Sue Smith' ] );
		$this->assertCount( 2, $this->rows( 'tri-backgrounds' ) );

		// `previous` is what says WHICH holding is being relabelled.
		$collide = $this->submit( [
			'change_type' => 'modify_trait',
			'change_data' => [
				'block_slug' => 'tri-backgrounds',
				'trait'      => [ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'Sue Smith' ],
				'previous'   => [ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'John Doe' ],
			],
		] );

		$this->assertSame( 400, $collide->get_status() );
		$this->assertSame( 'trait_already_held', $collide->as_error()->get_error_code() );
		$labels = array_map( static fn( $row ) => $row['specialization'] ?? '', $this->rows( 'tri-backgrounds' ) );
		$this->assertSame( [ 'John Doe', 'Sue Smith' ], $labels );
	}

	/**
	 * Consumer 3: a modify lands on the holding it names.
	 */
	public function test_raising_one_retainer_does_not_raise_the_other(): void {
		$this->add( 'tri-backgrounds', [ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'John Doe' ] );
		$this->add( 'tri-backgrounds', [ 'name' => 'Retainers', 'count' => 2, 'specialization' => 'Sue Smith' ] );

		$raise = $this->submit( [
			'change_type' => 'modify_trait',
			'change_data' => [ 'block_slug' => 'tri-backgrounds', 'trait' => [ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'Sue Smith' ] ],
		] );

		$this->assertSame( 201, $raise->get_status() );
		$rows = $this->rows( 'tri-backgrounds' );
		$this->assertCount( 2, $rows );
		$this->assertSame( [ 3, 3 ], array_map( static fn( $row ) => (int) $row['count'], $rows ) );
		$this->assertSame( [ 'John Doe', 'Sue Smith' ], array_map( static fn( $row ) => $row['specialization'], $rows ) );
	}

	/**
	 * Consumer 3: removing one labelled holding leaves the other standing.
	 */
	public function test_removing_one_retainer_leaves_the_other(): void {
		$this->add( 'tri-backgrounds', [ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'John Doe' ] );
		$this->add( 'tri-backgrounds', [ 'name' => 'Retainers', 'count' => 2, 'specialization' => 'Sue Smith' ] );

		$remove = $this->submit( [
			'change_type' => 'remove_trait',
			'change_data' => [ 'block_slug' => 'tri-backgrounds', 'trait' => [ 'name' => 'Retainers', 'specialization' => 'John Doe' ] ],
		] );

		$this->assertSame( 201, $remove->get_status() );
		$rows = $this->rows( 'tri-backgrounds' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Sue Smith', $rows[0]['specialization'] );
	}

	/**
	 * The same rule on the tiered_power path, where a family holds several named picks.
	 */
	public function test_removing_one_elder_pick_leaves_the_family_s_other_picks(): void {
		$this->add( 'tri-disciplines', [ 'name' => 'Celerity', 'power_name' => 'Precision' ] );
		$this->add( 'tri-disciplines', [ 'name' => 'Celerity', 'power_name' => 'Zephyr' ] );
		$this->assertCount( 2, $this->rows( 'tri-disciplines' ) );

		$remove = $this->submit( [
			'change_type' => 'remove_trait',
			'change_data' => [ 'block_slug' => 'tri-disciplines', 'trait' => [ 'name' => 'Celerity', 'power_name' => 'Precision' ] ],
		] );

		$this->assertSame( 201, $remove->get_status() );
		$rows = $this->rows( 'tri-disciplines' );
		$this->assertCount( 1, $rows );
		$this->assertSame( 'Zephyr', $rows[0]['power_name'] );
	}

	public function test_an_ordinary_rating_change_is_untouched(): void {
		$this->add( 'tri-abilities', [ 'name' => 'Brawl', 'count' => 2, 'specialization' => 'Wrestling' ] );

		$raise = $this->submit( [
			'change_type' => 'modify_trait',
			'change_data' => [ 'block_slug' => 'tri-abilities', 'trait' => [ 'name' => 'Brawl', 'count' => 4, 'specialization' => 'Wrestling' ] ],
		] );

		$this->assertSame( 201, $raise->get_status() );
		$this->assertSame( 4, (int) $this->rows( 'tri-abilities' )[0]['count'] );
	}

	public function test_relabelling_the_one_retainer_you_hold_is_allowed(): void {
		$this->add( 'tri-backgrounds', [ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'John Doe' ] );

		$relabel = $this->submit( [
			'change_type' => 'modify_trait',
			'change_data' => [
				'block_slug' => 'tri-backgrounds',
				'trait'      => [ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'Jack Doe' ],
				'previous'   => [ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'John Doe' ],
			],
		] );

		$this->assertSame( 201, $relabel->get_status() );
		$this->assertCount( 1, $this->rows( 'tri-backgrounds' ) );
		$this->assertSame( 'Jack Doe', $this->rows( 'tri-backgrounds' )[0]['specialization'] );
	}

	/**
	 * A change that could never apply is refused.
	 */
	public function test_changing_a_holding_you_do_not_have_is_refused(): void {
		$this->add( 'tri-backgrounds', [ 'name' => 'Retainers', 'count' => 3, 'specialization' => 'John Doe' ] );

		$phantom = $this->submit( [
			'change_type' => 'modify_trait',
			'change_data' => [ 'block_slug' => 'tri-backgrounds', 'trait' => [ 'name' => 'Retainers', 'count' => 9, 'specialization' => 'Nobody At All' ] ],
		] );

		$this->assertSame( 400, $phantom->get_status() );
		$this->assertSame( 'trait_not_held', $phantom->as_error()->get_error_code() );
		$this->assertSame( 3, (int) $this->rows( 'tri-backgrounds' )[0]['count'] );
	}

	public function test_an_atomic_block_still_appends(): void {
		$this->assertSame( 201, $this->add( 'tri-merits', [ 'name' => 'Catlike Balance', 'count' => 1 ] )->get_status() );
		$this->assertSame( 201, $this->add( 'tri-merits', [ 'name' => 'Catlike Balance', 'count' => 1 ] )->get_status() );

		$this->assertCount( 2, $this->rows( 'tri-merits' ) );
	}
}
