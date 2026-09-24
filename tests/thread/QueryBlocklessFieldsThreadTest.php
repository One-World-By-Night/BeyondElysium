<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Query_Engine;
use WP_UnitTestCase;

/**
 * Query fields that name no block because every creature type keeps them on its own block: Willpower, Rank, House,
 * Breed, Auspice, Pack, Totem, Faction.
 */
class QueryBlocklessFieldsThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-blockless-fields';

	public function setUp(): void {
		parent::setUp();
		Game::create( [ 'slug' => $this->slug, 'name' => 'Blockless Fields' ] );
		$this->character( 'Ahroun Wolf', 'werewolf', [
			'werewolf-identity'  => [ 'Tribe' => 'Get of Fenris', 'Auspice' => 'Ahroun', 'Rank' => 3 ],
			'werewolf-resources' => [ 'Willpower' => [ 'permanent' => 7, 'temporary' => 7 ] ],
		] );
		$this->character( 'Corax Scout', 'fera', [
			'fera-identity'      => [ 'Fera Type' => 'Corax', 'Auspice' => 'Theurge', 'Rank' => 1 ],
			'werewolf-resources' => [ 'Willpower' => [ 'permanent' => 4, 'temporary' => 2 ] ],
		] );
		$this->character( 'Tired Vampire', 'vampire', [
			'vampire-resources' => [ 'Willpower' => [ 'permanent' => 2, 'temporary' => 2 ] ],
		] );
	}

	private function character( string $name, string $stack, array $sheet ): void {
		Character::create( [ 'name' => $name, 'stack_slug' => $stack, 'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'status' => 'active', 'sheet_data' => $sheet ] );
	}

	/** @return string[] */
	private function names( string $field, string $operator, $value, array $paging = [] ): array {
		$result = Query_Engine::execute( $this->slug, [ [ 'field' => $field, 'operator' => $operator, 'find' => $value, 'value' => $value ] ], 'AND', $paging );
		$names  = array_map( static fn( $row ) => $row->name, $result['results'] );
		if ( empty( $paging['sort'] ) ) {
			sort( $names );
		}
		return $names;
	}

	public function test_willpower_reads_each_creature_types_own_resources_block(): void {
		$this->assertSame( [ 'Ahroun Wolf', 'Corax Scout' ], $this->names( 'willpower', 'at_least', 4 ) );
		$this->assertSame( [ 'Corax Scout', 'Tired Vampire' ], $this->names( 'tempwillpower', 'no_more', 2 ) );
	}

	public function test_an_identity_field_several_types_share_matches_on_each_of_them(): void {
		$this->assertSame( [ 'Ahroun Wolf' ], $this->names( 'auspice', 'equals', 'Ahroun' ) );
		$this->assertSame( [ 'Corax Scout' ], $this->names( 'rank', 'equals', '1' ), 'Rank is a text field in Grapevine\'s own query definitions' );
	}

	public function test_a_creature_type_without_the_field_is_simply_not_matched(): void {
		$this->assertSame( [ 'Ahroun Wolf', 'Corax Scout' ], $this->names( 'auspice', 'contains', 'u' ) );
	}

	public function test_results_sort_by_a_blockless_field(): void {
		$this->assertSame(
			[ 'Ahroun Wolf', 'Corax Scout', 'Tired Vampire' ],
			$this->names( 'willpower', 'at_least', 0, [ 'sort' => [ 'field' => 'willpower', 'direction' => 'desc' ] ] )
		);
	}
}
