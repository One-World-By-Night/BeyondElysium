<?php

namespace BeyondElysium\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * `tools/bylaws/build.php` refuses an attachment naming a clause or a catalog entry it doesn't have, and writes
 * nothing on a refusal.
 */
class BylawBuildToolTest extends TestCase {

	private string $tool;
	private string $tmp;

	public function setUp(): void {
		parent::setUp();
		$this->tool = dirname( __DIR__, 2 ) . '/tools/bylaws/build.php';
		$this->tmp  = sys_get_temp_dir() . '/be-bylaw-build-test-' . wp_generate_password_fallback();
		mkdir( $this->tmp );
	}

	public function tearDown(): void {
		array_map( 'unlink', glob( $this->tmp . '/*' ) ?: [] );
		rmdir( $this->tmp );
		parent::tearDown();
	}

	private function write_rules(): string {
		$path = $this->tmp . '/rules.json';
		file_put_contents( $path, json_encode( [
			'rules' => [
				[ 'clause_id' => 7838, 'path' => '10.e.v', 'subject' => 'True Faith', 'pc' => 'Coordinator Notify', 'npc' => 'Unregulated', 'coordinators' => [ 'Hunter' ], 'modified' => '2026-01-01' ],
			],
		] ) );
		return $path;
	}

	private function run_build_tool( string $attachments_json ): array {
		$rules_path       = $this->write_rules();
		$attachments_path = $this->tmp . '/attachments.json';
		$output_path      = $this->tmp . '/output.json';
		file_put_contents( $attachments_path, $attachments_json );

		$cmd = sprintf(
			'php %s %s %s %s 2>&1',
			escapeshellarg( $this->tool ),
			escapeshellarg( $rules_path ),
			escapeshellarg( $attachments_path ),
			escapeshellarg( $output_path )
		);
		exec( $cmd, $out, $exit_code );

		return [ 'exit' => $exit_code, 'output' => implode( "\n", $out ), 'wrote_file' => file_exists( $output_path ) ];
	}

	public function test_an_attachment_naming_an_unknown_clause_is_refused_and_writes_nothing(): void {
		$result = $this->run_build_tool( json_encode( [ [ 'clause_id' => 999999, 'family' => 'merits', 'name' => 'True Faith' ] ] ) );

		$this->assertNotSame( 0, $result['exit'] );
		$this->assertFalse( $result['wrote_file'] );
		$this->assertStringContainsString( '999999', $result['output'] );
	}

	public function test_an_attachment_naming_an_entry_the_catalog_does_not_have_is_refused(): void {
		$result = $this->run_build_tool( json_encode( [ [ 'clause_id' => 7838, 'family' => 'merits', 'name' => 'Definitely Not A Real Merit Name' ] ] ) );

		$this->assertNotSame( 0, $result['exit'] );
		$this->assertFalse( $result['wrote_file'] );
		$this->assertStringContainsString( 'Definitely Not A Real Merit Name', $result['output'] );
	}

	public function test_an_attachment_naming_an_unknown_family_is_refused(): void {
		$result = $this->run_build_tool( json_encode( [ [ 'clause_id' => 7838, 'family' => 'not-a-real-family', 'name' => 'True Faith' ] ] ) );

		$this->assertNotSame( 0, $result['exit'] );
		$this->assertFalse( $result['wrote_file'] );
	}

	public function test_a_real_attachment_is_accepted_and_written(): void {
		$result = $this->run_build_tool( json_encode( [ [ 'clause_id' => 7838, 'family' => 'merits', 'name' => 'True Faith' ] ] ) );

		$this->assertSame( 0, $result['exit'] );
		$this->assertTrue( $result['wrote_file'] );
	}
}

if ( ! function_exists( __NAMESPACE__ . '\\wp_generate_password_fallback' ) ) {
	function wp_generate_password_fallback(): string {
		return bin2hex( random_bytes( 6 ) );
	}
}
