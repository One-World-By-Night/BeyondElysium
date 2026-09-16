<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Change_Validator;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The last two free-text fields that were still plain text when every comparable field had
 * become rich (1.0.1 D1): a chronicle's own `description`, and the generic `textarea`
 * identity-field type an NPC's roleplaying notes use.
 *
 * Both were plain for no reason anyone recorded - the chronicle description was sanitized
 * with `sanitize_textarea_field` while a plot's description one controller over used
 * `wp_kses_post`, and `Change_Validator::text()` ran `strip_tags()` over every identity value
 * including the one field type that is prose rather than a value.
 *
 * Making the chronicle description rich has a hard dependency this test also pins:
 * `St_Visibility::filter_game()` had to move from the byte-offset strip to the HTML-aware one
 * in the same commit, or a marker opening inside a tag and closing outside it leaves the tag
 * dangling.
 *
 * @see BE_PROCESS/releases/1.0.1-design-workflow.md D1
 */
class RichTextHoldoutsThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-rich-holdouts';
	private int $game_id;
	private int $player;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Rich Holdouts',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
	}

	private function admin(): int {
		$admin = self::factory()->user->create( [ 'role' => 'administrator' ] );
		wp_set_current_user( $admin );
		return $admin;
	}

	public function test_a_chronicle_description_keeps_its_formatting(): void {
		$this->admin();

		$request = new WP_REST_Request( 'PUT', "/be/v1/games/{$this->game_slug}" );
		$request->set_body_params( [
			'description' => '<p>A city of <em>old</em> debts.</p><script>alert(1)</script>',
		] );
		rest_get_server()->dispatch( $request );

		$saved = (string) ( (array) rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', "/be/v1/games/{$this->game_slug}" )
		)->get_data() )['description'];

		$this->assertStringContainsString( '<em>old</em>', $saved, 'Formatting must survive.' );
		$this->assertStringNotContainsString( '<script', $saved, 'wp_kses_post must drop scripts.' );
	}

	/**
	 * The dependency D1 names: with `filter_game()` still on the byte-offset strip, cutting a
	 * marker that opens inside `<em>` and closes outside it leaves `<em>` dangling.
	 */
	public function test_stripping_a_marker_out_of_rich_description_leaves_no_dangling_tag(): void {
		global $wpdb;
		$wpdb->update(
			$wpdb->prefix . 'be_games',
			[ 'description' => '<p>Public. <em>Bold claim [ST]and the real</em> reason[/ST] here.</p>' ],
			[ 'id' => $this->game_id ]
		);

		wp_set_current_user( $this->player );
		$seen = (string) ( (array) rest_get_server()->dispatch(
			new WP_REST_Request( 'GET', "/be/v1/games/{$this->game_slug}" )
		)->get_data() )['description'];

		$this->assertStringNotContainsString( 'the real', $seen );
		$this->assertStringNotContainsString( '[ST]', $seen );
		$this->assertStringContainsString( 'Public.', $seen );
		$this->assertSame(
			substr_count( $seen, '<em' ),
			substr_count( $seen, '</em>' ),
			'Every <em> opened must still be closed after the marker is cut out.'
		);
	}

	/** The block set `Change_Validator::validate()` resolves against. */
	private function blocks(): array {
		return [
			'notes-block' => (object) [
				'section_type' => 'identity_field',
				'definition'   => (object) [
					'fields' => [
						(object) [ 'name' => 'Roleplaying Notes', 'field_type' => 'textarea' ],
						(object) [ 'name' => 'Concept', 'field_type' => 'text' ],
					],
				],
			],
		];
	}

	private function submit_identity( string $field, string $value ): array {
		return Change_Validator::validate(
			[
				'change_type' => 'modify_identity',
				'change_data' => [ 'block_slug' => 'notes-block', 'fields' => [ $field => $value ] ],
			],
			$this->blocks(),
			[],
			false
		);
	}

	public function test_an_identity_textarea_keeps_markup(): void {
		$result = $this->submit_identity(
			'Roleplaying Notes',
			'<p>Speaks <strong>slowly</strong>.</p><script>alert(1)</script>'
		);

		$this->assertTrue( $result['ok'] );
		$saved = (string) $result['change_data']['fields']['Roleplaying Notes'];

		$this->assertStringContainsString(
			'<strong>slowly</strong>',
			$saved,
			'A textarea identity field is prose - its formatting must survive.'
		);
		$this->assertStringNotContainsString( '<script', $saved );
	}

	public function test_every_other_identity_field_type_still_loses_its_tags(): void {
		$result = $this->submit_identity( 'Concept', '<b>Brujah</b> rabble' );

		$this->assertTrue( $result['ok'] );
		$this->assertSame(
			'Brujah rabble',
			(string) $result['change_data']['fields']['Concept'],
			'Every other identity field type is a value, not prose - tags still go.'
		);
	}
}
