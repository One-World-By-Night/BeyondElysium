<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Item_Catalog;
use PHPUnit\Framework\TestCase;

/**
 * The declared item catalog loader: load, search by name/book/type, and resolve a single entry by its `book_ref`,
 * against synthetic fixtures and the real shipped Dark Epics file.
 */
class ItemCatalogTest extends TestCase {

	/** @var string[] */
	private array $tmp_dirs = [];

	protected function tearDown(): void {
		foreach ( $this->tmp_dirs as $dir ) {
			self::remove_dir( $dir );
		}
		$this->tmp_dirs = [];
		Item_Catalog::reset_cache();
		parent::tearDown();
	}

	private static function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	/**
	 * Builds a temp `data/catalog/items`-shaped tree from `['book-slug' => [...items...]]`.
	 *
	 * @param array<string,array<int,array<string,mixed>>> $books
	 */
	private function build_catalog( array $books ): string {
		$root = sys_get_temp_dir() . '/be-item-catalog-test-' . uniqid();
		@mkdir( $root, 0777, true );
		foreach ( $books as $slug => $items ) {
			$path = "{$root}/{$slug}.json";
			$data = [
				'format'     => 1,
				'kind'       => 'item_catalog',
				'slug'       => $slug,
				'name'       => ucfirst( $slug ),
				'provenance' => [ 'sources' => [ 'test fixture' ] ],
				'items'      => $items,
			];
			file_put_contents( $path, (string) json_encode( $data ) );
		}
		$this->tmp_dirs[] = $root;
		return $root;
	}

	private function item( string $key, string $name, array $overrides = [] ): array {
		return array_merge( [
			'key'         => $key,
			'name'        => $name,
			'object_type' => 'item',
			'properties'  => [ 'item_type' => 'Melee' ],
			'source'      => [ 'book' => 'Test', 'code' => 'WW00000', 'page' => 1 ],
		], $overrides );
	}

	public function test_a_fresh_root_with_no_files_loads_empty(): void {
		$root = sys_get_temp_dir() . '/be-item-catalog-test-never-created-' . uniqid();
		$result = Item_Catalog::load( $root );
		$this->assertSame( [], $result['items'] );
		$this->assertSame( [], $result['books'] );
	}

	public function test_items_carry_their_own_book_name_slug_and_ref(): void {
		$root  = $this->build_catalog( [ 'test-book' => [ $this->item( 'broken-bottle', 'Broken Bottle' ) ] ] );
		$items = Item_Catalog::load( $root )['items'];
		$this->assertCount( 1, $items );
		$this->assertSame( 'Test-book', $items[0]['book'] );
		$this->assertSame( 'test-book', $items[0]['book_slug'] );
		$this->assertSame( 'test-book:broken-bottle', $items[0]['book_ref'] );
	}

	public function test_an_invalid_file_is_skipped_not_fatal(): void {
		$root = $this->build_catalog( [ 'good-book' => [ $this->item( 'knife', 'Knife' ) ] ] );
		file_put_contents( "{$root}/bad-book.json", '{ not valid json' );
		$result = Item_Catalog::load( $root );
		$this->assertCount( 1, $result['items'] );
		$this->assertNotEmpty( $result['errors'] );
	}

	public function test_search_by_name_is_case_insensitive_substring(): void {
		$root = $this->build_catalog( [ 'test-book' => [
			$this->item( 'broken-bottle', 'Broken Bottle' ),
			$this->item( 'knife', 'Knife/Dagger' ),
		] ] );
		$found = Item_Catalog::search( 'bottle', '', '', $root );
		$this->assertCount( 1, $found );
		$this->assertSame( 'Broken Bottle', $found[0]['name'] );
	}

	public function test_search_by_book_slug(): void {
		$root = $this->build_catalog( [
			'book-a' => [ $this->item( 'a1', 'Item A1' ) ],
			'book-b' => [ $this->item( 'b1', 'Item B1' ) ],
		] );
		$found = Item_Catalog::search( '', 'book-b', '', $root );
		$this->assertCount( 1, $found );
		$this->assertSame( 'Item B1', $found[0]['name'] );
	}

	public function test_search_by_item_type(): void {
		$root = $this->build_catalog( [ 'test-book' => [
			$this->item( 'sword', 'Sword', [ 'properties' => [ 'item_type' => 'Melee' ] ] ),
			$this->item( 'bow', 'Bow', [ 'properties' => [ 'item_type' => 'Ranged' ] ] ),
		] ] );
		$found = Item_Catalog::search( '', '', 'Ranged', $root );
		$this->assertCount( 1, $found );
		$this->assertSame( 'Bow', $found[0]['name'] );
	}

	public function test_find_resolves_a_real_book_ref(): void {
		$root  = $this->build_catalog( [ 'test-book' => [ $this->item( 'broken-bottle', 'Broken Bottle' ) ] ] );
		$found = Item_Catalog::find( 'test-book:broken-bottle', $root );
		$this->assertNotNull( $found );
		$this->assertSame( 'Broken Bottle', $found['name'] );
	}

	public function test_find_returns_null_for_an_unknown_ref(): void {
		$root = $this->build_catalog( [ 'test-book' => [ $this->item( 'broken-bottle', 'Broken Bottle' ) ] ] );
		$this->assertNull( Item_Catalog::find( 'test-book:no-such-key', $root ) );
	}

	public function test_the_real_shipped_catalog_loads_and_resolves(): void {
		Item_Catalog::reset_cache();
		$result = Item_Catalog::load();
		$this->assertSame( [], $result['errors'] );
		$this->assertCount( 174, $result['items'], 'Dark Epics (43), Laws of the Night Revised (9), Laws of the Hunt (2), Laws of the East (10), Laws of the Reckoning (16), Laws of the Resurrection (5), Changing Breeds (17), Changing Breeds II (39), Changing Breeds III (13), Changing Breeds IV (20)' );
		$this->assertContains( 'Dark Epics', array_column( $result['books'], 'name' ) );
		$this->assertContains( 'Laws of the Night Revised Edition', array_column( $result['books'], 'name' ) );
		$this->assertContains( "Mind's Eye Theatre: Changing Breeds II", array_column( $result['books'], 'name' ) );
		$this->assertContains( 'Changing Breeds 4', array_column( $result['books'], 'name' ) );

		$bottle = Item_Catalog::find( 'dark-epics:broken-bottle' );
		$this->assertNotNull( $bottle );
		$this->assertSame( 'Broken Bottle', $bottle['name'] );
		$this->assertSame( 1, $bottle['properties']['bonus'] );

		$stake = Item_Catalog::find( 'laws-of-the-night-revised:wooden-stake' );
		$this->assertNotNull( $stake );
		$this->assertSame( 'Wooden Stake', $stake['name'] );

		$fetish = Item_Catalog::find( 'changing-breeds-2:mace-of-the-winds' );
		$this->assertNotNull( $fetish );
		$this->assertSame( 'Mace of the Winds', $fetish['name'] );
		$this->assertSame( 3, $fetish['properties']['bonus'] );

		$bindhi = Item_Catalog::find( 'changing-breeds-4:indomitable-eye' );
		$this->assertNotNull( $bindhi );
		$this->assertSame( 'Indomitable Eye', $bindhi['name'] );
		$this->assertSame( 5, $bindhi['properties']['level'] );
	}
}
