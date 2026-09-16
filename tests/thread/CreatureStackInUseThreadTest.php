<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/PdfSigningTestFixture.php';

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Creature_Stack;
use BeyondElysium\Models\Game;
use BeyondElysium\Tests\Support\PdfSigningTestFixture;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.0.0-review F-088 (Pass H intake `t1-audit-stats-setup`, `t2-rendering`). An administrator
 * could delete a custom creature type its characters still used. The characters stayed, and
 * afterwards the Point Audit answered "Character not found in this game." for a character that
 * was right there, and the signed sheet came back a blank page - or, in a batch, quietly one
 * character short.
 */
class CreatureStackInUseThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-stack-in-use';
	private int $admin;
	private int $orphan;
	private int $vampire;

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		PdfSigningTestFixture::ensure();
	}

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $this->admin );
		Game::create( [ 'slug' => $this->slug, 'name' => 'Stack In Use', 'created_by' => $this->admin ] );

		Creature_Stack::create( [
			'slug'             => 'thread-custom-type',
			'name'             => 'Thread Custom Type',
			'stack_definition' => [ 'sections' => [ [ 'block_slug' => 'met-abilities', 'label' => 'Abilities', 'display_order' => 1 ] ] ],
			'is_system'        => 0,
		] );

		$this->orphan  = (int) Character::create( [ 'name' => 'Custom One', 'owner_slug' => $this->slug, 'stack_slug' => 'thread-custom-type', 'created_by' => $this->admin ] );
		$this->vampire = (int) Character::create( [ 'name' => 'Plain Vampire', 'owner_slug' => $this->slug, 'stack_slug' => 'vampire', 'created_by' => $this->admin ] );
	}

	private function delete_stack( string $stack_slug ): \WP_REST_Response {
		$request = new WP_REST_Request( 'DELETE', '/be/v1/creature-stacks/' . $stack_slug );
		return rest_get_server()->dispatch( $request );
	}

	/** A stack deleted before anything refused it, as it would be on a site that already did. */
	private function remove_stack_row( string $stack_slug ): void {
		global $wpdb;
		$wpdb->delete( $wpdb->prefix . 'be_creature_stacks', [ 'slug' => $stack_slug ] );
	}

	public function test_a_creature_type_characters_still_use_is_not_deleted(): void {
		Character::create( [ 'name' => 'Custom Two', 'owner_slug' => 'another-chronicle', 'stack_slug' => 'thread-custom-type', 'created_by' => $this->admin ] );

		$response = $this->delete_stack( 'thread-custom-type' );

		$this->assertSame( 409, $response->get_status() );
		$this->assertSame( 'creature_stack_in_use', $response->as_error()->get_error_code() );
		$this->assertSame( 2, $response->as_error()->get_error_data()['count'] );
		$this->assertStringContainsString( '2 characters', $response->as_error()->get_error_message() );
		$this->assertNotNull( Creature_Stack::find_by_slug( 'thread-custom-type' ) );
	}

	public function test_a_creature_type_no_character_uses_is_still_deleted(): void {
		Character::delete( $this->orphan );

		$this->assertSame( 204, $this->delete_stack( 'thread-custom-type' )->get_status() );
		$this->assertNull( Creature_Stack::find_by_slug( 'thread-custom-type' ) );
	}

	public function test_the_point_audit_says_the_creature_type_is_gone_not_the_character(): void {
		$this->remove_stack_row( 'thread-custom-type' );

		$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/characters/{$this->orphan}/point-audit" );
		$request->set_url_params( [ 'game_slug' => $this->slug, 'id' => (string) $this->orphan ] );
		$response = rest_get_server()->dispatch( $request );

		$this->assertSame( 'creature_stack_not_found', $response->as_error()->get_error_code() );
		$this->assertSame( 404, $response->get_status() );
	}

	/**
	 * @return array<string,\WP_REST_Response>
	 */
	private function sheet_requests(): array {
		$responses = [];
		foreach ( [ 'alone' => (string) $this->orphan, 'in a batch' => "{$this->vampire},{$this->orphan}" ] as $case => $ids ) {
			$request = new WP_REST_Request( 'GET', "/be/v1/{$this->slug}/sheets/pdf" );
			$request->set_url_params( [ 'game_slug' => $this->slug ] );
			$request->set_param( 'character_ids', $ids );
			$responses[ $case ] = rest_get_server()->dispatch( $request );
		}
		return $responses;
	}

	public function test_a_signed_sheet_names_the_character_whose_creature_type_is_gone(): void {
		$this->remove_stack_row( 'thread-custom-type' );

		foreach ( $this->sheet_requests() as $case => $response ) {
			$this->assertTrue( $response->is_error(), "{$case}: a sheet was printed without the character" );
			$this->assertSame( 'creature_stack_not_found', $response->as_error()->get_error_code(), $case );
			$this->assertStringContainsString( 'Custom One', $response->as_error()->get_error_message(), $case );
		}
	}
}
