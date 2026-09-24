<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Change_Engine;
use PHPUnit\Framework\TestCase;

/**
 * `pending_duplicate_key()` decides whether a new submission is the same one resubmitted.
 */
class ChangeEnginePendingKeyTest extends TestCase {

	private function key( string $change_type ): ?string {
		$method = new \ReflectionMethod( Change_Engine::class, 'pending_duplicate_key' );
		$method->setAccessible( true );
		return $method->invoke( null, $change_type, [ 'block_slug' => 'vampire-abilities', 'trait' => [ 'name' => 'Occult' ] ], '' );
	}

	public function test_catalog_records_are_never_keyed(): void {
		$this->assertNull( $this->key( 'catalog_rekey' ) );
		$this->assertNull( $this->key( 'catalog_rekey_revert' ) );
		$this->assertNull( $this->key( 'import_note' ) );
	}
}
