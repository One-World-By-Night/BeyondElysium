<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The chronicle's default approval policy: a Storyteller sets it through the chronicle-setup route and keeps every
 * other setting, it returns to pending by default, a narrator cannot set it, the general chronicle settings route is
 * not the Storyteller's to use, and a missing choice is refused.
 */
class DefaultApprovalPolicyThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-default-approval-policy';
	private int $hst;
	private int $narrator;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Default Approval Policy' ] );
		Game::update( $this->slug, [ 'settings' => [ 'apr' => [ 'personal_actions' => 3 ], 'require_new_character_approval' => true ] ] );

		$this->hst      = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->narrator = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $game_id, $this->hst, 'hst' );
		Game_Member::set_role( $game_id, $this->narrator, 'narrator' );
	}

	private function send( int $user, string $method, string $route, array $body = [] ): \WP_REST_Response {
		wp_set_current_user( $user );
		$request = new WP_REST_Request( $method, "/be/v1/{$route}" );
		if ( $body ) {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_the_chronicle_settings_route_is_not_the_storytellers_to_use(): void {
		$response = $this->send( $this->hst, 'PUT', "games/{$this->slug}", [ 'settings' => [ 'auto_approve' => true ] ] );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_a_storyteller_sets_the_default_policy_and_keeps_every_other_setting(): void {
		$response = $this->send( $this->hst, 'PUT', "{$this->slug}/approval-rules/default", [ 'auto_approve' => true ] );

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( [ 'auto_approve' => true ], $response->get_data() );

		$settings = Game::find_by_slug( $this->slug )->settings;
		$this->assertTrue( $settings->auto_approve );
		$this->assertSame( 3, (int) $settings->apr->personal_actions );
		$this->assertTrue( $settings->require_new_character_approval );

		$this->assertSame( [ 'auto_approve' => true ], $this->send( $this->hst, 'GET', "{$this->slug}/approval-rules/default" )->get_data() );
	}

	public function test_back_to_pending_by_default(): void {
		$this->send( $this->hst, 'PUT', "{$this->slug}/approval-rules/default", [ 'auto_approve' => true ] );
		$response = $this->send( $this->hst, 'PUT', "{$this->slug}/approval-rules/default", [ 'auto_approve' => false ] );

		$this->assertSame( [ 'auto_approve' => false ], $response->get_data() );
		$this->assertFalse( Game::find_by_slug( $this->slug )->settings->auto_approve );
	}

	public function test_a_narrator_cannot_set_it(): void {
		$response = $this->send( $this->narrator, 'PUT', "{$this->slug}/approval-rules/default", [ 'auto_approve' => true ] );

		$this->assertSame( 403, $response->get_status() );
		$this->assertObjectNotHasProperty( 'auto_approve', Game::find_by_slug( $this->slug )->settings );
	}

	public function test_a_missing_choice_is_refused(): void {
		$response = $this->send( $this->hst, 'PUT', "{$this->slug}/approval-rules/default", [] );

		$this->assertSame( 400, $response->get_status() );
	}
}
