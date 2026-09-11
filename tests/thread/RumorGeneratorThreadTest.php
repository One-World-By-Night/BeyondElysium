<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Services\Rumor_Generator;
use WP_UnitTestCase;

/**
 * The database-touching half of Rumor Generation: persisting rumor-tagged plots,
 * skipping titles already present for a date, previous-date cloning with and without
 * `copy_previous`, and inactive characters excluded. The pure per-character candidate
 * logic is covered without a database in `tests/unit/RumorGeneratorTest.php`.
 *
 * @see BE_PROCESS/workflow-0.5.md Step 5g
 */
class RumorGeneratorThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-test-rumors-game';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug' => $this->game_slug, 'name' => 'Thread Test Rumors Game',
			'created_by' => 1, 'created_at' => current_time( 'mysql' ), 'updated_at' => current_time( 'mysql' ),
			'settings' => wp_json_encode( [
				'apr' => [
					'public_rumors'    => true,
					'personal_rumors'  => true,
					'race_rumors'      => false,
					'influence_rumors' => false,
					'previous_rumors'  => false,
					'copy_previous'    => false,
				],
			] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;

		Character::create( [
			'name' => 'Active Character', 'stack_slug' => 'test-stack', 'status' => 'active',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
		] );
		Character::create( [
			'name' => 'Retired Character', 'stack_slug' => 'test-stack', 'status' => 'retired',
			'owner_type' => 'chronicle', 'owner_slug' => $this->game_slug,
		] );
	}

	public function test_inactive_characters_are_excluded(): void {
		$rumors = Rumor_Generator::generate( $this->game_id, '2026-01-01' );
		$titles = array_column( $rumors, 'title' );

		$this->assertContains( 'Active Character', $titles );
		$this->assertNotContains( 'Retired Character', $titles );
	}

	public function test_generate_includes_public_knowledge_by_default(): void {
		$rumors = Rumor_Generator::generate( $this->game_id, '2026-01-01' );
		$titles = array_column( $rumors, 'title' );

		$this->assertContains( Rumor_Generator::PUBLIC_TITLE, $titles );
	}

	public function test_commit_persists_one_tagged_plot_per_rumor(): void {
		$rumors = Rumor_Generator::generate( $this->game_id, '2026-01-01', true );

		global $wpdb;
		$plots_table       = Manager::table( 'plots' );
		$connections_table = Manager::table( 'connections' );

		$count = (int) $wpdb->get_var( $wpdb->prepare(
			"SELECT COUNT(*) FROM {$plots_table} p
			 INNER JOIN {$connections_table} c ON c.source_type = 'plot' AND c.source_id = p.id
			 WHERE c.target_type = 'tag' AND c.label = %s AND p.game_id = %d AND p.game_date = %s",
			Rumor_Generator::RUMOR_LABEL,
			$this->game_id,
			'2026-01-01'
		) );

		$this->assertSame( count( $rumors ), $count );
	}

	public function test_regenerating_the_same_date_skips_titles_already_present(): void {
		Rumor_Generator::generate( $this->game_id, '2026-01-01', true );
		$second_pass = Rumor_Generator::generate( $this->game_id, '2026-01-01' );

		$this->assertSame( [], $second_pass, 'every title for this date already exists - nothing new to generate' );
	}

	public function test_previous_date_cloning_without_copy_previous_clears_description(): void {
		Game::update( $this->game_slug, [ 'settings' => [
			'apr' => [ 'public_rumors' => true, 'previous_rumors' => true, 'copy_previous' => false ],
		] ] );

		$first = Rumor_Generator::generate( $this->game_id, '2026-01-01', true );
		$plot  = $this->find_plot_by_title( $first[0]['title'], '2026-01-01' );
		\BeyondElysium\Models\Plot::update( (int) $plot->id, [ 'description' => 'Week one gossip.' ] );

		$second = Rumor_Generator::generate( $this->game_id, '2026-01-08', true );
		$cloned = $this->find_plot_by_title( Rumor_Generator::PUBLIC_TITLE, '2026-01-08' );

		$this->assertNotNull( $cloned );
		$this->assertSame( '', (string) $cloned->description, 'copy_previous is off - text must not carry over' );
	}

	public function test_previous_date_cloning_with_copy_previous_carries_description(): void {
		Game::update( $this->game_slug, [ 'settings' => [
			'apr' => [ 'public_rumors' => true, 'previous_rumors' => true, 'copy_previous' => true ],
		] ] );

		Rumor_Generator::generate( $this->game_id, '2026-01-01', true );
		$plot = $this->find_plot_by_title( Rumor_Generator::PUBLIC_TITLE, '2026-01-01' );
		\BeyondElysium\Models\Plot::update( (int) $plot->id, [ 'description' => 'Week one gossip.' ] );

		Rumor_Generator::generate( $this->game_id, '2026-01-08', true );
		$cloned = $this->find_plot_by_title( Rumor_Generator::PUBLIC_TITLE, '2026-01-08' );

		$this->assertSame( 'Week one gossip.', (string) $cloned->description );
	}

	private function find_plot_by_title( string $title, string $game_date ) {
		global $wpdb;
		$table = Manager::table( 'plots' );
		$row   = $wpdb->get_row( $wpdb->prepare(
			"SELECT * FROM {$table} WHERE game_id = %d AND game_date = %s AND title = %s",
			$this->game_id,
			$game_date,
			$title
		) );
		return $row;
	}
}
