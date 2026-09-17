<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Attachment;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Attachment_Storage;
use BeyondElysium\Services\Audience;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * 1.1.0 U6: file uploads on a plot, item, or location - private storage, per-entity limits,
 * MIME validation from real file content, and a download route that checks the owning
 * entity's audience on every request. `stored_name` (the file's real path on disk) must never
 * reach a client through any response this file's own tests touch.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §2.6, U6
 */
class AttachmentThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-attachments';
	private int $game_id;

	/** @var string[] Real files written during a test, removed in tearDown() regardless of outcome. */
	private array $written_paths = [];

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Attachments',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;
	}

	public function tearDown(): void {
		foreach ( $this->written_paths as $path ) {
			if ( is_dir( $path ) ) {
				$this->remove_dir( $path );
			} elseif ( file_exists( $path ) ) {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		parent::tearDown();
	}

	private function remove_dir( string $dir ): void {
		foreach ( scandir( $dir ) ?: [] as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			is_dir( $path ) ? $this->remove_dir( $path ) : unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
	}

	private function make_player(): int {
		$player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $player, 'player' );
		return $player;
	}

	private function make_manager(): int {
		$hst = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $hst, 'hst' );
		return $hst;
	}

	private function make_character( int $wp_user_id ): int {
		return (int) Character::create( [
			'name'       => 'Fixture Character',
			'stack_slug' => 'vampire',
			'owner_slug' => $this->game_slug,
			'wp_user_id' => $wp_user_id,
			'created_by' => $wp_user_id,
		] );
	}

	/** A player-owned plot (linked plot_owner, §2.3a) via the real create route. */
	private function make_owned_plot( int $owner_player ): int {
		$character_id = $this->make_character( $owner_player );
		wp_set_current_user( $owner_player );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/plots" );
		$request->set_param( 'title', 'Owned Plot' );
		$request->set_param( 'character_id', $character_id );
		return (int) $this->dispatch( $request )->get_data()->id;
	}

	private function make_open_plot(): int {
		return (int) Plot::create( [
			'game_id'    => $this->game_id,
			'title'      => 'A Plot',
			'audience'   => Audience::EVERYONE,
			'created_by' => 1,
		] );
	}

	private function make_item(): int {
		return (int) World_Object::create( [
			'game_id'     => $this->game_id,
			'object_type' => 'item',
			'name'        => 'An Item',
			'created_by'  => 1,
		] );
	}

	private function dispatch( WP_REST_Request $request ) {
		return rest_get_server()->dispatch( $request );
	}

	/** A real, valid PNG - generated with GD rather than hand-written bytes, so it genuinely passes wp_check_filetype_and_ext(). */
	private function real_png_path(): string {
		$path  = tempnam( sys_get_temp_dir(), 'be-test-png-' );
		$image = imagecreate( 2, 2 );
		imagecolorallocate( $image, 255, 0, 0 );
		imagepng( $image, $path ); // GdImage frees itself; no imagedestroy() needed since PHP 8.
		$this->written_paths[] = $path;
		return $path;
	}

	private function fake_file_params( string $path, string $name = 'photo.png', string $type = 'image/png' ): array {
		return [ 'file' => [
			'tmp_name' => $path,
			'name'     => $name,
			'error'    => 0,
			'size'     => filesize( $path ),
			'type'     => $type,
		] ];
	}

	private function upload( int $wp_user_id, string $entity_type, int $entity_id, ?string $path = null ): \WP_REST_Response {
		wp_set_current_user( $wp_user_id );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/attachments" );
		$request->set_param( 'entity_type', $entity_type );
		$request->set_param( 'entity_id', $entity_id );
		$request->set_file_params( $this->fake_file_params( $path ?? $this->real_png_path() ) );
		$response = $this->dispatch( $request );

		// A successful upload writes a real file that a test-DB rollback never touches (files
		// live outside any transaction) - tracked here, by the real stored path, so tearDown()
		// removes it regardless of whether the test itself ever calls the delete route.
		if ( $response->get_status() === 201 ) {
			$row = Attachment::find( (int) $response->get_data()['id'] );
			if ( $row ) {
				$this->written_paths[] = dirname( Attachment_Storage::path_for( $row->stored_name, $row->original_name ) );
			}
		}

		return $response;
	}

	// -------------------------------------------------------------------------
	// Upload authorization
	// -------------------------------------------------------------------------

	public function test_a_manager_may_upload_to_a_plot(): void {
		$plot_id  = $this->make_open_plot();
		$response = $this->upload( $this->make_manager(), 'plot', $plot_id );

		$this->assertSame( 201, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'plot', $data['entity_type'] );
		$this->assertSame( 'image/png', $data['mime'] );
		$this->assertArrayNotHasKey( 'stored_name', $data, 'stored_name must never reach a client' );
	}

	public function test_the_owning_player_may_upload_to_their_own_plot(): void {
		$owner   = $this->make_player();
		$plot_id = $this->make_owned_plot( $owner );

		$this->assertSame( 201, $this->upload( $owner, 'plot', $plot_id )->get_status() );
	}

	public function test_an_unrelated_player_cannot_upload_to_someone_elses_plot(): void {
		$plot_id  = $this->make_owned_plot( $this->make_player() );
		$stranger = $this->make_player();

		$this->assertSame( 403, $this->upload( $stranger, 'plot', $plot_id )->get_status() );
	}

	public function test_a_player_cannot_upload_to_an_item_even_one_they_hold(): void {
		$player       = $this->make_player();
		$character_id = $this->make_character( $player );
		$item_id      = $this->make_item();
		Connection::create( [
			'game_id'     => $this->game_id,
			'source_type' => 'character',
			'source_id'   => $character_id,
			'target_type' => 'world_object',
			'target_id'   => $item_id,
			'label'       => 'holds',
			'created_by'  => 1,
		] );

		$this->assertSame(
			403,
			$this->upload( $player, 'item', $item_id )->get_status(),
			'items have no player-ownership concept - be_manage_world_objects is the only path'
		);
	}

	public function test_invalid_entity_type_is_rejected(): void {
		wp_set_current_user( $this->make_manager() );
		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/attachments" );
		$request->set_param( 'entity_type', 'character' );
		$request->set_param( 'entity_id', 1 );
		$request->set_file_params( $this->fake_file_params( $this->real_png_path() ) );

		$this->assertSame( 400, $this->dispatch( $request )->get_status() );
	}

	public function test_a_nonexistent_entity_404s(): void {
		$response = $this->upload( $this->make_manager(), 'plot', 999999 );
		$this->assertSame( 404, $response->get_status() );
	}

	public function test_naming_an_item_id_as_entity_type_location_404s(): void {
		$item_id  = $this->make_item();
		$response = $this->upload( $this->make_manager(), 'location', $item_id );
		$this->assertSame( 404, $response->get_status(), 'the id is real, but it is not a location' );
	}

	// -------------------------------------------------------------------------
	// Validation: MIME type, size, per-entity limits
	// -------------------------------------------------------------------------

	public function test_a_file_that_is_not_really_an_image_is_refused_despite_its_claimed_type(): void {
		$path = tempnam( sys_get_temp_dir(), 'be-test-fake-' );
		file_put_contents( $path, "just some text, not a real png\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		$this->written_paths[] = $path;

		$plot_id  = $this->make_open_plot();
		$response = $this->upload( $this->make_manager(), 'plot', $plot_id, $path );

		$this->assertSame( 400, $response->get_status() );
		$this->assertSame( 'invalid_file_type', $response->as_error()->get_error_code() );
	}

	public function test_a_file_over_the_size_limit_is_refused(): void {
		wp_set_current_user( $this->make_manager() );
		$plot_id = $this->make_open_plot();
		$path    = $this->real_png_path();

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/attachments" );
		$request->set_param( 'entity_type', 'plot' );
		$request->set_param( 'entity_id', $plot_id );
		$request->set_file_params( [ 'file' => [
			'tmp_name' => $path,
			'name'     => 'huge.png',
			'error'    => 0,
			'size'     => 11 * 1024 * 1024, // the claimed size, exactly what PHP's own SAPI would report for a real 11MB upload
			'type'     => 'image/png',
		] ] );

		$this->assertSame( 400, $this->dispatch( $request )->get_status() );
	}

	public function test_an_item_may_hold_exactly_one_attachment(): void {
		$item_id = $this->make_item();
		$manager = $this->make_manager();

		$this->assertSame( 201, $this->upload( $manager, 'item', $item_id )->get_status() );
		$second = $this->upload( $manager, 'item', $item_id );

		$this->assertSame( 409, $second->get_status() );
		$this->assertSame( 'limit_reached', $second->as_error()->get_error_code() );
	}

	public function test_a_plot_may_hold_up_to_twenty_attachments(): void {
		$plot_id = $this->make_open_plot();
		// 20 rows seeded directly - proving the limit-check logic itself, not re-uploading 20
		// real files for a check that reads only Attachment::count_for_entity().
		for ( $i = 0; $i < 20; $i++ ) {
			Attachment::create( [
				'game_id'       => $this->game_id,
				'entity_type'   => 'plot',
				'entity_id'     => $plot_id,
				'original_name' => "file-{$i}.png",
				'stored_name'   => bin2hex( random_bytes( 16 ) ),
				'mime'          => 'image/png',
				'bytes'         => 100,
				'created_by'    => 1,
			] );
		}

		$response = $this->upload( $this->make_manager(), 'plot', $plot_id );
		$this->assertSame( 409, $response->get_status() );
	}

	// -------------------------------------------------------------------------
	// Download - audience-gated, streams real bytes, never leaks stored_name
	// -------------------------------------------------------------------------

	public function test_a_manager_can_download_what_they_uploaded(): void {
		$plot_id       = $this->make_open_plot();
		$attachment_id = $this->upload( $this->make_manager(), 'plot', $plot_id )->get_data()['id'];

		wp_set_current_user( $this->make_manager() );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/attachments/{$attachment_id}" ) );

		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'image/png', $data['mime'] );
		$this->assertNotEmpty( $data['bytes'] );
		$this->assertArrayNotHasKey( 'stored_name', $data );
	}

	public function test_a_player_who_cannot_see_the_plot_gets_a_404_not_a_403(): void {
		$plot_id       = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => 'Hidden', 'audience' => Audience::STORYTELLERS, 'created_by' => 1 ] );
		$attachment_id = $this->upload( $this->make_manager(), 'plot', $plot_id )->get_data()['id'];

		wp_set_current_user( $this->make_player() );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/attachments/{$attachment_id}" ) );

		$this->assertSame( 404, $response->get_status() );
	}

	public function test_a_character_connected_to_a_hidden_item_can_still_download_its_file(): void {
		$item_id      = (int) World_Object::create( [ 'game_id' => $this->game_id, 'object_type' => 'item', 'name' => 'Relic', 'audience' => Audience::STORYTELLERS, 'created_by' => 1 ] );
		$player       = $this->make_player();
		$character_id = $this->make_character( $player );
		Connection::create( [
			'game_id'     => $this->game_id,
			'source_type' => 'character',
			'source_id'   => $character_id,
			'target_type' => 'world_object',
			'target_id'   => $item_id,
			'label'       => 'holds',
			'created_by'  => 1,
		] );
		$attachment_id = $this->upload( $this->make_manager(), 'item', $item_id )->get_data()['id'];

		wp_set_current_user( $player );
		$response = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/attachments/{$attachment_id}" ) );

		$this->assertSame( 200, $response->get_status(), 'a connected character always sees the object it holds, whatever its audience' );
	}

	// -------------------------------------------------------------------------
	// Delete - row and file together, and cascades
	// -------------------------------------------------------------------------

	public function test_deleting_an_attachment_removes_the_row_and_the_file(): void {
		$plot_id       = $this->make_open_plot();
		$manager       = $this->make_manager();
		$attachment_id = $this->upload( $manager, 'plot', $plot_id )->get_data()['id'];
		$row           = Attachment::find( $attachment_id );
		$path          = Attachment_Storage::path_for( $row->stored_name, $row->original_name );
		$this->assertFileExists( $path );

		wp_set_current_user( $manager );
		$response = $this->dispatch( new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/attachments/{$attachment_id}" ) );

		$this->assertSame( 204, $response->get_status() );
		$this->assertNull( Attachment::find( $attachment_id ) );
		$this->assertFileDoesNotExist( $path );
	}

	public function test_a_stranger_cannot_delete_an_attachment(): void {
		$plot_id       = $this->make_open_plot();
		$attachment_id = $this->upload( $this->make_manager(), 'plot', $plot_id )->get_data()['id'];

		wp_set_current_user( $this->make_player() );
		$response = $this->dispatch( new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/attachments/{$attachment_id}" ) );

		$this->assertSame( 403, $response->get_status() );
		$this->assertNotNull( Attachment::find( $attachment_id ), 'refused deletes must not touch the row' );
	}

	public function test_deleting_a_plot_removes_its_attachments_row_and_file(): void {
		$plot_id       = $this->make_open_plot();
		$manager       = $this->make_manager();
		$attachment_id = $this->upload( $manager, 'plot', $plot_id )->get_data()['id'];
		$row           = Attachment::find( $attachment_id );
		$path          = Attachment_Storage::path_for( $row->stored_name, $row->original_name );

		wp_set_current_user( $manager );
		$this->dispatch( new WP_REST_Request( 'DELETE', "/be/v1/{$this->game_slug}/plots/{$plot_id}" ) );

		$this->assertNull( Attachment::find( $attachment_id ) );
		$this->assertFileDoesNotExist( $path );
	}

	// -------------------------------------------------------------------------
	// Embedding on the entity's own response
	// -------------------------------------------------------------------------

	public function test_a_plots_own_response_embeds_its_attachments_without_stored_name(): void {
		$plot_id = $this->make_open_plot();
		$manager = $this->make_manager();
		$this->upload( $manager, 'plot', $plot_id );

		wp_set_current_user( $manager );
		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/plots/{$plot_id}" ) )->get_data();

		$this->assertCount( 1, $data->attachments );
		$this->assertArrayNotHasKey( 'stored_name', (array) $data->attachments[0] );
	}

	public function test_an_items_own_response_embeds_its_attachments(): void {
		$item_id = $this->make_item();
		$manager = $this->make_manager();
		$this->upload( $manager, 'item', $item_id );

		wp_set_current_user( $manager );
		$data = $this->dispatch( new WP_REST_Request( 'GET', "/be/v1/{$this->game_slug}/world-objects/{$item_id}" ) )->get_data();

		$this->assertCount( 1, $data->attachments );
	}
}
