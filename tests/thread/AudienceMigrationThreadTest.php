<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Plot;
use BeyondElysium\Services\Audience;
use WP_UnitTestCase;

/**
 * 1.1.0's one-time backfill: an existing site's plots must keep behaving exactly as they do
 * today the moment the `audience` column exists, before anyone has touched a new setting.
 *
 * A plot connected to a character via `apr_actor` - that character's own personal plot - is
 * already hidden from every other player by `Plot::actor_ownership_exclusion()`. Setting its
 * audience to `restricted` (with no rules) makes the new system agree with what already runs:
 * restricted-with-no-rules-and-only-the-owner-connected means "the owner only," precisely
 * today's behavior. Every other existing plot is left at `everyone` - unchanged.
 *
 * No manual tearDown() - WP_UnitTestCase's own ambient transaction rolls back every write
 * this file makes.
 *
 * @see BE_PROCESS/releases/1.1.0-design-workflow.md §2.3
 */
class AudienceMigrationThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-audience-migration';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();
		delete_option( 'be_actor_plots_audience_migrated' );
		$this->game_id = (int) Game::create( [ 'slug' => $this->game_slug, 'name' => 'Thread Audience Migration' ] );
	}

	public function test_a_personal_plot_becomes_restricted(): void {
		$plot_id = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => 'A Personal Plot', 'created_by' => 1 ] );
		Connection::create( [
			'game_id'     => $this->game_id,
			'source_type' => 'plot',
			'source_id'   => $plot_id,
			'target_type' => 'character',
			'target_id'   => 12345,
			'label'       => 'apr_actor',
			'created_by'  => 1,
		] );

		Schema::migrate_actor_plots_to_restricted_audience();

		$plot = Plot::find( $plot_id );
		$this->assertSame( Audience::RESTRICTED, $plot->audience );
	}

	public function test_an_ordinary_global_plot_is_left_at_everyone(): void {
		$plot_id = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => 'A Global Plot', 'created_by' => 1 ] );

		Schema::migrate_actor_plots_to_restricted_audience();

		$plot = Plot::find( $plot_id );
		$this->assertSame( Audience::EVERYONE, $plot->audience );
	}

	/**
	 * A plot connected to a character by some other label - a plot referencing a character
	 * for narrative reasons, not action-allocation ownership - must not be swept into
	 * `restricted` just because a connection exists. Only the exact `apr_actor` label means
	 * "this is that character's own plot."
	 */
	public function test_a_plot_connected_by_a_different_label_is_not_touched(): void {
		$plot_id = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => 'Mentioned In', 'created_by' => 1 ] );
		Connection::create( [
			'game_id'     => $this->game_id,
			'source_type' => 'plot',
			'source_id'   => $plot_id,
			'target_type' => 'character',
			'target_id'   => 12345,
			'label'       => 'mentioned',
			'created_by'  => 1,
		] );

		Schema::migrate_actor_plots_to_restricted_audience();

		$plot = Plot::find( $plot_id );
		$this->assertSame( Audience::EVERYONE, $plot->audience );
	}

	public function test_it_runs_once_and_never_overwrites_a_deliberate_later_choice(): void {
		$plot_id = (int) Plot::create( [ 'game_id' => $this->game_id, 'title' => 'A Personal Plot', 'created_by' => 1 ] );
		Connection::create( [
			'game_id'     => $this->game_id,
			'source_type' => 'plot',
			'source_id'   => $plot_id,
			'target_type' => 'character',
			'target_id'   => 12345,
			'label'       => 'apr_actor',
			'created_by'  => 1,
		] );

		Schema::migrate_actor_plots_to_restricted_audience();

		// A Storyteller deliberately widens this personal plot afterward.
		Plot::update( $plot_id, [ 'audience' => Audience::EVERYONE ] );

		// A later upgrade re-runs migrate() - the one-time guard must not overwrite the choice.
		Schema::migrate_actor_plots_to_restricted_audience();

		$plot = Plot::find( $plot_id );
		$this->assertSame( Audience::EVERYONE, $plot->audience );
	}
}
