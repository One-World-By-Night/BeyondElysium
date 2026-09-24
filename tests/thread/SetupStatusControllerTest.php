<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Models\Submission;
use BeyondElysium\Models\Template;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `Setup_Status_Controller`'s row computation.
 */
class SetupStatusControllerTest extends WP_UnitTestCase {

	private $admin_id;
	private $editor_id;
	private $subscriber_id;
	private $game_slug = 'setup-status-controller-test';

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->admin_id      = self::factory()->user->create( [ 'role' => 'administrator' ] );
		$this->editor_id     = self::factory()->user->create( [ 'role' => 'editor' ] );
		$this->subscriber_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );

		Game::create( [ 'slug' => $this->game_slug, 'name' => 'Setup Status Controller Test', 'created_by' => $this->admin_id ] );
	}

	private function dispatch( int $as_user ): \WP_REST_Response {
		wp_set_current_user( $as_user );
		$request = new WP_REST_Request( 'GET', '/be/v1/' . $this->game_slug . '/setup-status' );
		$request->set_url_params( [ 'game_slug' => $this->game_slug ] );
		return rest_get_server()->dispatch( $request );
	}

	private function row( array $items, string $id ): ?array {
		foreach ( $items as $item ) {
			if ( $item['id'] === $id ) {
				return $item;
			}
		}
		return null;
	}

	public function test_a_freshly_created_chronicle_shows_four_attention_rows(): void {
		$response = $this->dispatch( $this->admin_id );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'attention', $this->row( $data['items'], 'enabled_stacks' )['status'] );
		$this->assertSame( 'attention', $this->row( $data['items'], 'storytellers' )['status'] );
		$this->assertSame( 'attention', $this->row( $data['items'], 'require_new_character_approval' )['status'] );
		$this->assertSame( 'attention', $this->row( $data['items'], 'characters' )['status'] );
	}

	public function test_setting_enabled_stacks_turns_the_row_green(): void {
		Game::update( $this->game_slug, [ 'settings' => [ 'enabled_stacks' => [ 'vampire' ] ] ] );

		$response = $this->dispatch( $this->admin_id );
		$row      = $this->row( $response->get_data()['items'], 'enabled_stacks' );

		$this->assertSame( 'ok', $row['status'] );
	}

	public function test_assigning_an_hst_turns_the_storytellers_row_green(): void {
		$game = Game::find_by_slug( $this->game_slug );
		Game_Member::set_role( (int) $game->id, $this->editor_id, 'hst' );

		$response = $this->dispatch( $this->admin_id );
		$row      = $this->row( $response->get_data()['items'], 'storytellers' );

		$this->assertSame( 'ok', $row['status'] );
	}

	public function test_front_end_pages_row_is_attention_before_provisioning(): void {
		$response = $this->dispatch( $this->admin_id );
		$row      = $this->row( $response->get_data()['items'], 'front_end_pages' );

		$this->assertSame( 'attention', $row['status'] );
	}

	public function test_provisioning_the_fixed_pages_turns_the_front_end_pages_row_green_for_every_chronicle(): void {
		\BeyondElysium\Core\Page_Provisioner::maybe_provision();

		$response = $this->dispatch( $this->admin_id );
		$row      = $this->row( $response->get_data()['items'], 'front_end_pages' );

		$this->assertSame( 'ok', $row['status'] );
	}

	public function test_a_character_turns_the_characters_row_green(): void {
		Character::create( [
			'name'       => 'Setup Status Test Character',
			'owner_slug' => $this->game_slug,
			'stack_slug' => 'vampire',
			'created_by' => $this->admin_id,
		] );

		$response = $this->dispatch( $this->admin_id );
		$row      = $this->row( $response->get_data()['items'], 'characters' );

		$this->assertSame( 'ok', $row['status'] );
	}

	public function test_an_hst_sees_actionable_true_on_creature_types_and_new_character_approval(): void {
		$game = Game::find_by_slug( $this->game_slug );
		Game_Member::set_role( (int) $game->id, $this->editor_id, 'hst' );

		$response = $this->dispatch( $this->editor_id );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status(), 'be_view_characters is granted broadly - the editor must reach the route at all' );
		$this->assertSame( 'attention', $this->row( $data['items'], 'enabled_stacks' )['status'], 'status is unrelated to actionable' );
		$this->assertTrue( $this->row( $data['items'], 'enabled_stacks' )['actionable'] );
		$this->assertTrue( $this->row( $data['items'], 'require_new_character_approval' )['actionable'] );
		// Assigning Storytellers (Chronicle Access) stays administrator-only.
		$this->assertFalse( $this->row( $data['items'], 'storytellers' )['actionable'] );
	}

	/**
	 * @dataProvider non_staff_chronicle_roles
	 */
	public function test_a_member_who_is_not_staff_is_refused_the_checklist( string $chronicle_role ): void {
		$game      = Game::find_by_slug( $this->game_slug );
		$member_id = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( (int) $game->id, $member_id, $chronicle_role );

		$response = $this->dispatch( $member_id );

		$this->assertSame( 403, $response->get_status() );
		$this->assertArrayNotHasKey( 'items', (array) $response->get_data(), 'no row leaks in the refusal' );
	}

	/** @return array<string,array{0:string}> */
	public static function non_staff_chronicle_roles(): array {
		return [
			'a player'   => [ 'player' ],
			'a narrator' => [ 'narrator' ],
			'a Harpy'    => [ 'boons' ],
		];
	}

	/**
	 * The AST half of the same ruling: an AST does not hold be_manage_chronicle_setup either.
	 */
	public function test_an_ast_sees_actionable_false_on_creature_types_and_new_character_approval(): void {
		$game   = Game::find_by_slug( $this->game_slug );
		$ast_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( (int) $game->id, $ast_id, 'ast' );

		$response = $this->dispatch( $ast_id );
		$data     = $response->get_data();

		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $this->row( $data['items'], 'enabled_stacks' )['actionable'] );
		$this->assertFalse( $this->row( $data['items'], 'require_new_character_approval' )['actionable'] );
	}

	public function test_a_subscriber_with_no_membership_is_denied_outright(): void {
		$response = $this->dispatch( $this->subscriber_id );

		$this->assertSame( 403, $response->get_status() );
	}

	public function test_an_administrator_sees_actionable_true_on_the_administrator_only_rows(): void {
		$response = $this->dispatch( $this->admin_id );
		$row      = $this->row( $response->get_data()['items'], 'enabled_stacks' );

		$this->assertTrue( $row['actionable'] );
	}

	public function test_the_demo_chronicle_gets_its_own_row_and_others_do_not(): void {
		$response_demo = $this->dispatch_for_game( $this->admin_id, 'be-demo' );
		$this->assertNotNull( $this->row( $response_demo->get_data()['items'], 'demo_chronicle' ) );

		$response_real = $this->dispatch( $this->admin_id );
		$this->assertNull( $this->row( $response_real->get_data()['items'], 'demo_chronicle' ) );
	}

	private function dispatch_for_game( int $as_user, string $game_slug ): \WP_REST_Response {
		wp_set_current_user( $as_user );
		$request = new WP_REST_Request( 'GET', '/be/v1/' . $game_slug . '/setup-status' );
		$request->set_url_params( [ 'game_slug' => $game_slug ] );
		return rest_get_server()->dispatch( $request );
	}

	public function test_status_is_never_cached_across_requests(): void {
		$before = $this->row( $this->dispatch( $this->admin_id )->get_data()['items'], 'enabled_stacks' );
		$this->assertSame( 'attention', $before['status'] );

		Game::update( $this->game_slug, [ 'settings' => [ 'enabled_stacks' => [ 'mage' ] ] ] );

		$after = $this->row( $this->dispatch( $this->admin_id )->get_data()['items'], 'enabled_stacks' );
		$this->assertSame( 'ok', $after['status'], 'a cached response here would still read attention' );
	}

	/** @return array{0:string,1:string} A global trait_list block's slug and its first item's name. */
	private function a_ruleable_item(): array {
		foreach ( Schema_Block::all_for_game_by_types( [ 'trait_list' ], '' ) as $block ) {
			$first = $block->definition->items[0]->name ?? null;
			if ( $first !== null ) {
				return [ $block->slug, $first ];
			}
		}
		$this->fail( 'the test database has no trait_list block with an item' );
	}

	private function status_of( string $id ): string {
		return $this->row( $this->dispatch( $this->admin_id )->get_data()['items'], $id )['status'];
	}

	private function detail_of( string $id ): string {
		return $this->row( $this->dispatch( $this->admin_id )->get_data()['items'], $id )['detail'];
	}

	/**
	 * These four rows were `info` whatever the chronicle did.
	 */
	public function test_the_four_optional_rows_read_info_until_the_chronicle_sets_something(): void {
		foreach ( [ 'approval_rules', 'catalog_customisation', 'sheet_templates', 'downtime_and_rumors' ] as $id ) {
			$this->assertSame( 'info', $this->status_of( $id ), $id );
		}
	}

	public function test_saving_downtime_and_rumor_settings_turns_that_row_green(): void {
		Game::update( $this->game_slug, [ 'settings' => [ 'apr' => [ 'personal_actions' => 7 ] ] ] );

		$this->assertSame( 'ok', $this->status_of( 'downtime_and_rumors' ) );
		$this->assertSame( 'This chronicle has its own downtime and rumor settings.', $this->detail_of( 'downtime_and_rumors' ) );
	}

	public function test_an_empty_downtime_settings_object_is_not_a_choice(): void {
		Game::update( $this->game_slug, [ 'settings' => [ 'apr' => [] ] ] );

		$this->assertSame( 'info', $this->status_of( 'downtime_and_rumors' ) );
	}

	public function test_overriding_a_sheet_template_turns_that_row_green_and_removing_it_turns_it_back(): void {
		$game   = Game::find_by_slug( $this->game_slug );
		$global = Template::resolve( 'vampire', 'sheet_full', null );
		$this->assertNotNull( $global, 'the test database has a global vampire sheet_full template' );

		$id = Template::create( [
			'game_id'       => (int) $game->id,
			'stack_slug'    => 'vampire',
			'name'          => 'Setup status template',
			'template_type' => 'sheet_full',
			'layout'        => json_decode( (string) wp_json_encode( $global->layout ), true ),
		] );
		$this->assertGreaterThan( 0, $id );
		$this->assertSame( 'ok', $this->status_of( 'sheet_templates' ) );

		Template::delete( $id );

		$this->assertSame( 'info', $this->status_of( 'sheet_templates' ), 'the page is a mirror, not a memory' );
	}

	public function test_forking_a_block_turns_the_catalog_row_green_and_removing_the_fork_turns_it_back(): void {
		[ $slug ] = $this->a_ruleable_item();
		$this->assertNotNull( Schema_Block::find_or_create_fork_for_game( $slug, $this->game_slug ) );
		$this->assertSame( 'ok', $this->status_of( 'catalog_customisation' ) );

		Schema_Block::delete( $slug, $this->game_slug );

		$this->assertSame( 'info', $this->status_of( 'catalog_customisation' ) );
	}

	public function test_choosing_a_default_approval_policy_turns_the_approval_row_green_either_way(): void {
		foreach ( [ [ true, 'approved automatically' ], [ false, 'waits for Storyteller approval' ] ] as [ $auto, $wording ] ) {
			Game::update( $this->game_slug, [ 'settings' => [ 'auto_approve' => $auto ] ] );

			$this->assertSame( 'ok', $this->status_of( 'approval_rules' ), 'auto_approve ' . var_export( $auto, true ) );
			$this->assertStringContainsString( $wording, $this->detail_of( 'approval_rules' ) );
		}
	}

	public function test_a_rule_the_chronicle_created_turns_the_approval_row_green_and_is_counted(): void {
		[ $slug, $item ] = $this->a_ruleable_item();

		wp_set_current_user( $this->admin_id );
		$request = new WP_REST_Request( 'POST', '/be/v1/' . $this->game_slug . '/approval-rules' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( wp_json_encode( [ 'block_slug' => $slug, 'target_type' => 'item', 'target_name' => $item, 'approval' => 'auto', 'reason' => 'setup status test' ] ) );
		$this->assertSame( 201, rest_get_server()->dispatch( $request )->get_status() );

		$this->assertSame( 'ok', $this->status_of( 'approval_rules' ) );
		$this->assertStringContainsString( '1 approval rule(s) set', $this->detail_of( 'approval_rules' ) );
	}

	public function test_a_fork_with_no_rule_in_it_is_not_an_approval_rule(): void {
		[ $slug ] = $this->a_ruleable_item();
		Schema_Block::find_or_create_fork_for_game( $slug, $this->game_slug );

		$this->assertSame( 'info', $this->status_of( 'approval_rules' ), 'a customised block is not a rule' );
		$this->assertSame( 'ok', $this->status_of( 'catalog_customisation' ) );
	}

	public function test_the_five_setting_rows_read_info_until_the_chronicle_sets_something(): void {
		foreach ( [ 'plot_features', 'branding', 'faction_restrictions', 'purchase_lists', 'grapevine_files' ] as $id ) {
			$this->assertSame( 'info', $this->status_of( $id ), $id );
		}
	}

	public function test_opening_a_purchase_list_turns_that_row_green_and_names_what_is_open(): void {
		Game::update( $this->game_slug, [ 'settings' => [ 'purchase_scope' => [ 'abilities' => true, 'backgrounds' => false, 'merits_flaws' => true ] ] ] );

		$this->assertSame( 'ok', $this->status_of( 'purchase_lists' ) );
		$this->assertSame( 'Open to every creature type: Abilities, Merits and Flaws.', $this->detail_of( 'purchase_lists' ) );

		Game::update( $this->game_slug, [ 'settings' => [ 'purchase_scope' => [ 'abilities' => false, 'backgrounds' => false, 'merits_flaws' => false ] ] ] );

		$this->assertSame( 'info', $this->status_of( 'purchase_lists' ), 'every switch off is the default again' );
	}

	public function test_turning_on_plot_features_turns_that_row_green(): void {
		Game::update( $this->game_slug, [ 'settings' => [ 'plots' => [ 'expanded_enabled' => true ] ] ] );
		$this->assertSame( 'ok', $this->status_of( 'plot_features' ) );

		Game::update( $this->game_slug, [ 'settings' => [ 'plots' => [ 'expanded_enabled' => false ] ] ] );
		$this->assertSame( 'info', $this->status_of( 'plot_features' ) );
	}

	public function test_a_chronicle_accent_color_turns_the_branding_row_green(): void {
		Game::update( $this->game_slug, [ 'settings' => [ 'accent_color' => '#123456' ] ] );
		$this->assertSame( 'ok', $this->status_of( 'branding' ) );
		$this->assertStringContainsString( '#123456', $this->detail_of( 'branding' ) );

		Game::update( $this->game_slug, [ 'settings' => [ 'accent_color' => '' ] ] );
		$this->assertSame( 'info', $this->status_of( 'branding' ), 'an emptied override is the site default again' );
	}

	public function test_a_stored_list_of_allowed_values_turns_the_sub_faction_row_green(): void {
		Game::update( $this->game_slug, [ 'settings' => [ 'enabled_factions' => [ 'vampire' => [ 'Sect' => [ 'Camarilla' ] ] ] ] ] );
		$this->assertSame( 'ok', $this->status_of( 'faction_restrictions' ) );
		$this->assertStringContainsString( '1 field(s)', $this->detail_of( 'faction_restrictions' ) );

		Game::update( $this->game_slug, [ 'settings' => [ 'enabled_factions' => [ 'vampire' => [ 'Sect' => [] ] ] ] ] );
		$this->assertSame( 'info', $this->status_of( 'faction_restrictions' ), 'an empty list is no choice' );
	}

	public function test_a_file_a_player_sent_turns_the_grapevine_row_green(): void {
		$game = Game::find_by_slug( $this->game_slug );
		Submission::create( [
			'game_id'        => (int) $game->id,
			'submitted_by'   => $this->subscriber_id,
			'arrival'        => 'new',
			'character_name' => 'Setup Status Sender',
			'stack_slug'     => 'vampire',
			'source_file'    => 'sender.gex',
			'format'         => 'gex',
			'file_hash'      => str_repeat( 'a', 64 ),
			'parsed'         => '{}',
		] );

		$this->assertSame( 'ok', $this->status_of( 'grapevine_files' ) );
		$this->assertStringContainsString( '1 player file(s)', $this->detail_of( 'grapevine_files' ) );
	}

	public function test_the_summary_counts_every_row_and_the_rows_there_are_to_do(): void {
		$summary = $this->dispatch( $this->admin_id )->get_data()['summary'];

		$this->assertSame( 14, $summary['total'] );
		$this->assertSame( $summary['total'], $summary['attention'] + $summary['ok'] + $summary['info'] );

		Game::update( $this->game_slug, [ 'settings' => [ 'accent_color' => '#123456' ] ] );
		$after = $this->dispatch( $this->admin_id )->get_data()['summary'];
		$this->assertSame( $summary['ok'] + 1, $after['ok'] );
		$this->assertSame( 14, $after['total'] );
	}

	public function test_the_demo_chronicles_own_row_is_not_something_to_do(): void {
		$data = $this->dispatch_for_game( $this->admin_id, 'be-demo' )->get_data();

		$this->assertCount( 15, $data['items'] );
		$this->assertSame( 14, $data['summary']['total'], 'the demo row asks for nothing' );
		$this->assertSame( 15, $data['summary']['attention'] + $data['summary']['ok'] + $data['summary']['info'] );
	}

	public function test_an_hst_can_act_on_the_setting_rows_a_site_administrator_does_not_have_to_be_for(): void {
		$game = Game::find_by_slug( $this->game_slug );
		Game_Member::set_role( (int) $game->id, $this->editor_id, 'hst' );

		$items = $this->dispatch( $this->editor_id )->get_data()['items'];

		foreach ( [ 'purchase_lists', 'faction_restrictions', 'branding', 'grapevine_files' ] as $id ) {
			$this->assertTrue( $this->row( $items, $id )['actionable'], $id );
		}
		$this->assertFalse( $this->row( $items, 'plot_features' )['actionable'], 'Plot features stay a site administrator\'s alone' );
	}

	public function test_an_ast_reads_the_setting_rows_and_can_only_copy_the_grapevine_link(): void {
		$game   = Game::find_by_slug( $this->game_slug );
		$ast_id = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( (int) $game->id, $ast_id, 'ast' );

		$items = $this->dispatch( $ast_id )->get_data()['items'];

		foreach ( [ 'purchase_lists', 'faction_restrictions', 'branding', 'plot_features' ] as $id ) {
			$this->assertFalse( $this->row( $items, $id )['actionable'], $id );
		}
		$this->assertTrue( $this->row( $items, 'grapevine_files' )['actionable'] );
	}
}
