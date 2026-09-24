<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Change_Engine;
use PHPUnit\Framework\TestCase;

/**
 * `pending_duplicate_key()` decides whether a new submission is the same one resubmitted. The
 * catalog cutover's records are written already approved and are never submitted, so they must
 * never be keyed - and the check has to be by type, not by the payload happening to lack a block,
 * so these carry the same `block_slug` and `trait` an ordinary add would.
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
