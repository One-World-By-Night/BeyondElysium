<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The book is what its files say: a reseed writes each catalog block and creature type exactly as declared, and a
 * house rule lives in a chronicle's copy, which a reseed keeps.
 */
class BookReadOnlyThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-book-read-only';

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
		Game::create( [ 'slug' => $this->slug, 'name' => 'Book Read Only' ] );
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function stored( string $slug, string $game_slug = '' ): array {
		global $wpdb;
		return json_decode( (string) $wpdb->get_var( $wpdb->prepare(
			"SELECT definition FROM {$wpdb->prefix}be_schema_blocks WHERE slug = %s AND game_slug = %s",
			$slug,
			$game_slug
		) ), true );
	}

	/**
	 * @param array<string,mixed> $definition
	 * @return array<string,array<string,mixed>>
	 */
	private static function by_name( array $definition, string $list ): array {
		return array_column( $definition[ $list ], null, 'name' );
	}

	public function test_a_reseed_writes_the_book_as_its_files_say(): void {
		$merits = self::stored( 'vampire-merits' );
		$first  = $merits['items'][0]['name'];
		$merits['items'][0]['approval']    = 'st';
		$merits['items'][0]['description'] = [ 'description' => '<p>A site note.</p>' ];
		$merits['items'][]                 = [ 'name' => 'Thread Site Merit', 'cost' => '1', 'admin_added' => true ];
		Schema_Block::update( 'vampire-merits', [ 'definition' => $merits ] );

		$disciplines                              = self::stored( 'vampire-disciplines' );
		$disciplines['_meta']['costs']['elder']   = 10;
		$disciplines['_meta']['_admin_set']       = [ 'costs.elder' ];
		Schema_Block::update( 'vampire-disciplines', [ 'definition' => $disciplines ] );

		$stack               = json_decode( (string) wp_json_encode( Creature_Stack::find_by_slug( 'vampire' )->stack_definition ), true );
		$stack['sections'][] = [ 'block_slug' => 'mage-spheres', 'label' => 'Spheres', 'display_order' => 99, 'admin_added' => true ];
		Creature_Stack::update( 'vampire', [ 'stack_definition' => $stack ] );

		Seeder::seed_schema_blocks();
		Seeder::seed_creature_stacks();

		$items = self::by_name( self::stored( 'vampire-merits' ), 'items' );
		$this->assertNotSame( 'st', $items[ $first ]['approval'] ?? null );
		$this->assertNotSame( '<p>A site note.</p>', $items[ $first ]['description']['description'] ?? null );
		$this->assertArrayNotHasKey( 'Thread Site Merit', $items );
		$this->assertSame( 12, self::stored( 'vampire-disciplines' )['_meta']['costs']['elder'] );
		$this->assertArrayNotHasKey( '_admin_set', self::stored( 'vampire-disciplines' )['_meta'] );
		$this->assertNotContains( 'mage-spheres', array_column( (array) Creature_Stack::find_by_slug( 'vampire' )->stack_definition->sections, 'block_slug' ) );
	}

	public function test_a_chronicles_descriptions_and_approvals_survive_a_reseed(): void {
		$definition = json_decode( (string) wp_json_encode( Schema_Block::find_for_game( 'vampire-disciplines', $this->slug )->definition ), true );
		foreach ( $definition['powers'] as $p => $power ) {
			if ( $power['name'] === 'Animalism' ) {
				$definition['powers'][ $p ]['description']                  = [ 'description' => '<p>Our Animalism.</p>' ];
				$definition['powers'][ $p ]['levels'][0]['description']     = [ 'description' => '<p>Our first dot.</p>' ];
				$definition['powers'][ $p ]['levels'][0]['approval']        = 'st';
			}
		}
		$request = new WP_REST_Request( 'PUT', "/be/v1/{$this->slug}/schema-blocks/vampire-disciplines" );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( [ 'definition' => $definition ] ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );

		Seeder::seed_schema_blocks();

		$animalism = self::by_name( self::stored( 'vampire-disciplines', $this->slug ), 'powers' )['Animalism'];
		$this->assertSame( '<p>Our Animalism.</p>', $animalism['description']['description'] ?? null );
		$this->assertSame( '<p>Our first dot.</p>', $animalism['levels'][0]['description']['description'] ?? null );
		$this->assertSame( 'st', $animalism['levels'][0]['approval'] ?? null );
		$book = self::by_name( self::stored( 'vampire-disciplines' ), 'powers' )['Animalism'];
		$this->assertNotSame( '<p>Our Animalism.</p>', $book['description']['description'] ?? null, 'the book keeps its own' );
	}
}
