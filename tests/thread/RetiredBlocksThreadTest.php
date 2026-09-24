<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/LegacyInstall.php';

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Change;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\Catalog_Reader;
use BeyondElysium\Services\Retired_Blocks;
use BeyondElysium\Tests\Support\LegacyInstall;
use WP_UnitTestCase;

/**
 * Retired blocks are deleted only when nothing on the install names them. Each kind of reference keeps its block,
 * with a line saying what names it, and the block goes once the reference does.
 */
class RetiredBlocksThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-retired-blocks';
	private string $log;
	private string $previous_log;

	public function setUp(): void {
		parent::setUp();
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}

		LegacyInstall::shared_blocks();
		LegacyInstall::stand_in_blocks();
		Game::create( [ 'slug' => $this->game_slug, 'name' => 'Thread Retired Blocks' ] );

		$this->log          = tempnam( sys_get_temp_dir(), 'be-retired-' );
		$this->previous_log = (string) ini_get( 'error_log' );
		ini_set( 'error_log', $this->log );
	}

	public function tearDown(): void {
		ini_set( 'error_log', $this->previous_log );
		@unlink( $this->log ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		parent::tearDown();
	}

	private function exists( string $slug ): bool {
		return Schema_Block::find_by_slug( $slug ) !== null;
	}

	/** @param array<string,mixed> $sheet */
	private function character( string $stack, array $sheet ): int {
		return (int) Character::create( [
			'name' => 'Retired Blocks Character', 'stack_slug' => $stack, 'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug, 'status' => 'active', 'sheet_data' => $sheet,
		] );
	}

	/**
	 * Runs the removal and returns the lines that kept `$slug`, empty when it was removed.
	 */
	private function kept_because( string $slug ): array {
		return Retired_Blocks::remove_unused()['kept'][ $slug ] ?? [];
	}

	public function test_blocks_nothing_names_are_removed_and_werewolfs_rites_stay(): void {
		$result = Retired_Blocks::remove_unused();

		$this->assertEqualsCanonicalizing( Retired_Blocks::SLUGS, $result['removed'] );
		$this->assertSame( [], $result['kept'] );
		foreach ( Retired_Blocks::SLUGS as $slug ) {
			$this->assertFalse( $this->exists( $slug ), $slug );
		}
		$this->assertTrue( $this->exists( 'werewolf-rites' ), "Werewolf's own Rites list is not retired" );
		$this->assertTrue( $this->exists( 'vampire-abilities' ) );
	}

	public function test_a_second_run_finds_nothing_left_to_do(): void {
		Retired_Blocks::remove_unused();

		$this->assertSame( [ 'removed' => [], 'kept' => [] ], Retired_Blocks::remove_unused() );
	}

	public function test_a_creature_stack_listing_a_block_keeps_it(): void {
		$stack                                    = Creature_Stack::find_by_slug( 'vampire' );
		$definition                               = json_decode( wp_json_encode( $stack->stack_definition ), true );
		$definition['sections'][]                 = [ 'block_slug' => 'met-abilities', 'label' => 'Abilities', 'display_order' => 99, 'required' => false ];
		Creature_Stack::update( 'vampire', [ 'stack_definition' => $definition ] );

		$this->assertSame( [ 'stack vampire' ], $this->kept_because( 'met-abilities' ) );
		$this->assertTrue( $this->exists( 'met-abilities' ) );
		$this->assertFalse( $this->exists( 'met-merits' ), 'the others still go' );
	}

	public function test_a_template_naming_a_block_keeps_it_including_a_chronicles_own(): void {
		$game     = Game::find_by_slug( $this->game_slug );
		$sections = Template::resolve( 'vampire', 'sheet_full', null )->layout['sections'];
		$sections[] = [ 'block_slug' => 'met-merits', 'title' => 'Merits', 'column' => 1, 'order' => 99, 'width' => 'half', 'display' => null, 'collapsed' => false ];
		$id       = Template::create( [
			'game_id' => (int) $game->id, 'stack_slug' => 'vampire', 'name' => 'Own Sheet', 'template_type' => 'sheet_full',
			'layout' => [ 'version' => 1, 'columns' => 6, 'sections' => $sections ],
		] );
		$this->assertGreaterThan( 0, $id );

		$this->assertSame( [ "template {$id}" ], $this->kept_because( 'met-merits' ) );
	}

	public function test_a_character_holding_entries_keeps_the_block_but_an_empty_list_does_not(): void {
		$this->character( 'demon', [ 'demon-lores' => [] ] );
		$this->assertSame( [], $this->kept_because( 'demon-lores' ), 'an empty list holds nothing' );

		LegacyInstall::stand_in_blocks();
		$this->character( 'demon', [ 'demon-lores' => [ [ 'name' => 'Vampire' ] ] ] );
		$this->assertSame( [ '1 character(s) holding entries under it' ], $this->kept_because( 'demon-lores' ) );
	}

	public function test_a_chronicles_own_copy_of_a_block_keeps_the_shared_one(): void {
		Schema_Block::create( [
			'slug' => 'met-flaws', 'game_slug' => $this->game_slug, 'name' => 'Flaws (chronicle)', 'section_type' => 'trait_list',
			'definition' => [ 'items' => [ [ 'name' => 'Nightmares' ] ] ],
		] );

		$this->assertSame( [ '1 chronicle copy(ies)' ], $this->kept_because( 'met-flaws' ) );
		$this->assertTrue( $this->exists( 'met-flaws' ) );
	}

	public function test_another_blocks_definition_naming_a_block_keeps_it(): void {
		Schema_Block::create( [
			'slug' => 'thread-retired-pointer', 'name' => 'Pointer', 'section_type' => 'identity_field',
			'definition' => [ 'fields' => [ [ 'name' => 'Pick', 'field_type' => 'select', 'options_ref' => 'met-abilities' ] ] ],
		] );

		$this->assertSame( [ 'block thread-retired-pointer' ], $this->kept_because( 'met-abilities' ) );
	}

	public function test_a_pending_change_keeps_the_block_but_a_reviewed_one_is_only_history(): void {
		$id = $this->character( 'vampire', [ 'vampire-abilities' => [] ] );
		Change::create( [
			'character_id' => $id, 'change_type' => 'add_trait', 'status' => 'approved',
			'change_data' => [ 'block_slug' => 'met-abilities', 'trait' => [ 'name' => 'Brawl' ] ],
		] );
		$this->assertSame( [], $this->kept_because( 'met-abilities' ), 'a reviewed change is history' );

		LegacyInstall::shared_blocks();
		Change::create( [
			'character_id' => $id, 'change_type' => 'add_trait', 'status' => 'pending',
			'change_data' => [ 'block_slug' => 'met-abilities', 'trait' => [ 'name' => 'Brawl' ] ],
		] );
		$this->assertSame( [ '1 pending change(s)' ], $this->kept_because( 'met-abilities' ) );
	}

	public function test_a_translation_keyed_on_a_block_keeps_it(): void {
		global $wpdb;
		$wpdb->insert( Manager::table( 'translation_strings' ), [ 'source_key' => 'thread-retired-string', 'source_text' => 'Thread retired string' ] );
		$wpdb->insert( Manager::table( 'translations' ), [ 'string_id' => (int) $wpdb->insert_id, 'locale' => 'pt_BR', 'context' => 'met-merits', 'translation' => 'Texto' ] );

		$this->assertSame( [ '1 translation(s) keyed on it' ], $this->kept_because( 'met-merits' ) );
	}

	public function test_every_kept_and_removed_block_is_written_to_the_log(): void {
		$this->character( 'demon', [ 'demon-lores' => [ [ 'name' => 'Vampire' ] ] ] );

		Retired_Blocks::remove_unused();

		$log = (string) file_get_contents( $this->log );
		$this->assertStringContainsString( 'removed the retired block met-abilities', $log );
		$this->assertStringContainsString( 'kept the retired block demon-lores: 1 character(s) holding entries under it', $log );
	}

	public function test_the_sections_of_the_two_unreplaced_blocks_leave_system_templates_and_a_chronicles_own_stay(): void {
		$demon = Template::resolve( 'demon', 'sheet_full', null );
		$this->assertTrue( (bool) $demon->is_system );
		$layout                 = $demon->layout;
		$layout['sections'][]   = [ 'block_slug' => 'demon-lores', 'title' => 'Lores', 'column' => 1, 'order' => 99, 'width' => 'full', 'display' => null, 'collapsed' => false ];
		$this->assertTrue( Template::update( (int) $demon->id, [ 'layout' => $layout ] ) );

		$game = Game::find_by_slug( $this->game_slug );
		$own  = Template::create( [ 'game_id' => (int) $game->id, 'stack_slug' => 'demon', 'name' => 'Own Demon', 'template_type' => 'sheet_full', 'layout' => $layout ] );
		$this->assertGreaterThan( 0, $own );

		$this->assertGreaterThanOrEqual( 1, Retired_Blocks::drop_from_system_templates() );

		$slugs = array_column( Template::find( (int) $demon->id )->layout['sections'], 'block_slug' );
		$this->assertNotContains( 'demon-lores', $slugs );
		$this->assertContains( 'demon-evocations', $slugs, 'the rest of the layout is untouched' );
		$this->assertContains( 'demon-lores', array_column( Template::find( $own )->layout['sections'], 'block_slug' ), "a chronicle's own template is not edited" );
	}
}
