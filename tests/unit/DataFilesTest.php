<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * The runtime data files in data/ are copies of Grapevine originals in GV301Source/.
 *
 * GV301Source/ is a ~26MB reference archive excluded from the deployed artifact; data/ is
 * the small subset the plugin reads at runtime. Two copies means they can drift, so this
 * asserts they stay byte-identical.
 *
 * Found the hard way: the first dist artifact excluded GV301Source and production silently
 * fell back to hardcoded schema blocks.
 *
 * @see data/README.md
 * @see BE_PROCESS/workflow-0.2.1.md Step 5
 */
class DataFilesTest extends TestCase {

	/**
	 * data/ filename => GV301Source/ path it was copied from.
	 *
	 * @return array<string,array{0:string,1:string}>
	 */
	public function runtime_data_files(): array {
		return [
			'GVM menu XML' => [ 'Grapevine Menus XML.gvm', 'GV301Source/Code/Grapevine Menus XML.gvm' ],
			'query keys'   => [ 'qkdata.gvd', 'GV301Source/Code/qkdata.gvd' ],
			// BE_PROCESS/0.99.2-workflow.md, "mage-rotes ships as an empty catalog": the
			// data and the reader both already existed, nothing had ever joined them.
			'mage rotes'   => [ 'Rotes.gex', 'GV301Source/Code/Rotes.gex' ],
		];
	}

	/**
	 * @dataProvider runtime_data_files
	 */
	public function test_runtime_copy_exists( string $name, string $origin ): void {
		$this->assertFileExists(
			BE_PLUGIN_PATH . '/data/' . $name,
			"data/{$name} is missing. It ships in the deployed artifact; GV301Source/ does not."
		);
	}

	/**
	 * @dataProvider runtime_data_files
	 */
	public function test_runtime_copy_matches_the_original( string $name, string $origin ): void {
		$copy     = BE_PLUGIN_PATH . '/data/' . $name;
		$original = BE_PLUGIN_ROOT . '/' . $origin;

		if ( ! file_exists( $original ) ) {
			$this->markTestSkipped( "Reference archive {$origin} is not present in this checkout." );
		}

		$this->assertSame(
			hash_file( 'sha256', $original ),
			hash_file( 'sha256', $copy ),
			"data/{$name} has drifted from {$origin}. Re-copy it; do not edit data/ by hand."
		);
	}

	/**
	 * The deployed artifact must not depend on the reference archive.
	 *
	 * Asserted structurally rather than by reading `.distignore`, which is how this
	 * was checked before the Step 10h restructure. The artifact is now a copy of the
	 * `beyond-elysium/` subfolder, so what ships is decided by where a file lives, not
	 * by an exclusion rule that someone has to remember to write. `data/` ships because
	 * it is inside the plugin; `GV301Source/` cannot ship because it is not.
	 */
	public function test_the_plugin_carries_its_runtime_data_and_not_the_archive(): void {
		$this->assertDirectoryExists(
			BE_PLUGIN_PATH . '/data',
			'data/ must live inside the shippable plugin - the seeder reads it at runtime.'
		);

		$this->assertDirectoryDoesNotExist(
			BE_PLUGIN_PATH . '/GV301Source',
			'The reference archive must stay outside the shippable plugin, never inside it.'
		);

		$this->assertDirectoryExists(
			BE_PLUGIN_ROOT . '/GV301Source',
			'The reference archive should still exist at the repo root; only its location proves the exclusion.'
		);
	}
}
