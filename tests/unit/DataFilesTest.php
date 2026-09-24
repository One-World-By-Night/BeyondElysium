<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The Grapevine data files kept in this repository are byte copies of the reference archive's originals.
 */
class DataFilesTest extends TestCase {

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public function grapevine_copies(): array {
		return [
			'query keys'   => [ 'beyond-elysium/data/qkdata.gvd', 'GV301Source/Code/qkdata.gvd' ],
			'GVM menu XML' => [ 'tools/catalog/source/Grapevine Menus XML.gvm', 'GV301Source/Code/Grapevine Menus XML.gvm' ],
			'mage rotes'   => [ 'tools/catalog/source/Rotes.gex', 'GV301Source/Code/Rotes.gex' ],
		];
	}

	/**
	 * @dataProvider grapevine_copies
	 */
	public function test_the_copy_exists( string $copy, string $origin ): void {
		$this->assertFileExists( be_reference_path( $copy ), "{$copy} is missing." );
	}

	/**
	 * @dataProvider grapevine_copies
	 */
	public function test_the_copy_matches_the_original( string $copy, string $origin ): void {
		$original = be_reference_path( $origin );

		if ( ! file_exists( $original ) ) {
			$this->markTestSkipped( "Reference archive {$origin} is not present in this checkout." );
		}

		$this->assertSame(
			hash_file( 'sha256', $original ),
			hash_file( 'sha256', be_reference_path( $copy ) ),
			"{$copy} has drifted from {$origin}. Re-copy it; do not edit it by hand."
		);
	}

	/**
	 * What ships is decided by where a file lives: the plugin folder carries its runtime data, and neither the reference
	 * archive nor the catalog sources are inside it.
	 */
	public function test_the_plugin_carries_its_runtime_data_and_not_the_archive_or_the_sources(): void {
		$this->assertDirectoryExists( BE_PLUGIN_PATH . '/data', 'data/ must live inside the shippable plugin.' );
		$this->assertDirectoryDoesNotExist( BE_PLUGIN_PATH . '/GV301Source', 'The reference archive must stay outside the shippable plugin.' );
		$this->assertDirectoryExists( BE_REPO_ROOT . '/GV301Source', 'The reference archive should still exist at the repo root.' );

		foreach ( [ 'Grapevine Menus XML.gvm', 'Rotes.gex', 'met-mechanics.csv', 'grimoire-rotes.csv' ] as $source ) {
			$this->assertFileDoesNotExist( BE_PLUGIN_PATH . '/data/' . $source, "{$source} is a catalog source and does not ship in the plugin." );
			$this->assertFileExists( be_reference_path( 'tools/catalog/source/' . $source ), "{$source} is kept with the catalog tools." );
		}
	}

	/**
	 * The Portuguese (Brazil) names the plugin ships load as pairs of name and translation.
	 */
	public function test_the_shipped_portuguese_names_are_a_two_column_file(): void {
		$path = BE_PLUGIN_PATH . '/data/translations/pt_BR.csv';
		$this->assertFileExists( $path );

		$handle = fopen( $path, 'r' );
		$this->assertSame( [ 'name', 'translation' ], fgetcsv( $handle, 0, ',', '"', '\\' ) );
		$rows = 0;
		while ( ( $row = fgetcsv( $handle, 0, ',', '"', '\\' ) ) !== false ) {
			++$rows;
			$this->assertCount( 2, $row, "row {$rows} does not have two columns" );
			$this->assertNotSame( '', trim( (string) $row[0] ), "row {$rows} has no name" );
			$this->assertNotSame( '', trim( (string) $row[1] ), "row {$rows} has no translation" );
		}
		fclose( $handle );

		$this->assertSame( 5227, $rows );
	}
}
