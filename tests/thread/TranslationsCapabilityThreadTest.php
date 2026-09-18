<?php

namespace BeyondElysium\Tests\Thread;

use WP_UnitTestCase;

/**
 * B6 (1.2.0 releases/1.2.0-design-workflow.md §5.7): `be_manage_translations` registered and
 * granted to administrator/editor only - deliberately not folded into `be_manage_schemas`, so
 * this pins that the two capabilities are genuinely independent, not that one implies the
 * other. Site-wide, not chronicle-scoped (Decision 106) - unlike WorldObjectsCapabilityThreadTest's
 * `/my/capabilities` pattern, this is a plain WordPress role grant with nothing to resolve per
 * chronicle. T13's own REST-403 coverage lands with B7, once Translations_Controller exists to
 * test against.
 *
 * @see BE_PROCESS/releases/1.2.0-design-workflow.md §5.7
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
	 * The whole reason this is its own capability (§5.7): a schema-catalog editor must not
	 * automatically be able to translate, and vice versa. Proven by construction - two
	 * distinct entries in Capabilities::all() - rather than by simulating a role-capability
	 * override: WordPress's remove_cap() only overrides a capability directly granted to a
	 * user, not one a user merely inherits from their role, so it cannot actually simulate
	 * "an editor without be_manage_translations" the way a first attempt at this test assumed.
	 */
	public function test_be_manage_schemas_and_be_manage_translations_are_distinct_capabilities(): void {
		$all = \BeyondElysium\Core\Capabilities::all();
		$this->assertContains( 'be_manage_schemas', $all );
		$this->assertContains( 'be_manage_translations', $all );
		$this->assertNotSame( 'be_manage_schemas', 'be_manage_translations' );
	}
}
