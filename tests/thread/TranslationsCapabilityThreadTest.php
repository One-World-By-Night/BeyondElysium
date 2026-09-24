<?php

namespace BeyondElysium\Tests\Thread;

use WP_UnitTestCase;

/**
 * `be_manage_translations` registered and granted to administrator/editor only.
 */
class TranslationsCapabilityThreadTest extends WP_UnitTestCase {

	public function test_administrator_and_editor_have_it(): void {
		foreach ( [ 'administrator', 'editor' ] as $role ) {
			$user = self::factory()->user->create( [ 'role' => $role ] );
			$this->assertTrue( user_can( $user, 'be_manage_translations' ), "{$role} should manage translations" );
		}
	}

	public function test_author_contributor_and_subscriber_do_not(): void {
		foreach ( [ 'author', 'contributor', 'subscriber' ] as $role ) {
			$user = self::factory()->user->create( [ 'role' => $role ] );
			$this->assertFalse( user_can( $user, 'be_manage_translations' ), "{$role} should not manage translations" );
		}
	}

	/**
	 * The whole reason this is its own capability: a schema-catalog editor must not automatically be able to translate,
	 * and vice versa.
	 */
	public function test_be_manage_schemas_and_be_manage_translations_are_distinct_capabilities(): void {
		$all = \BeyondElysium\Core\Capabilities::all();
		$this->assertContains( 'be_manage_schemas', $all );
		$this->assertContains( 'be_manage_translations', $all );
		$this->assertNotSame( 'be_manage_schemas', 'be_manage_translations' );
	}
}
