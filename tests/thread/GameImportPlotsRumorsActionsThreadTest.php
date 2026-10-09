<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Connection;
use BeyondElysium\Models\Plot;
use BeyondElysium\Models\Plot_Entry;
use BeyondElysium\REST\Import_Controller;
use BeyondElysium\Services\Action_Allocator;
use WP_UnitTestCase;

/**
 * A full game file's own plots, rumors and actions become real be_plots/be_plot_entries rows on import.
 */
class GameImportPlotsRumorsActionsThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-import-narrative';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();

		global $wpdb;
		$wpdb->insert( $wpdb->prefix . 'be_games', [
			'slug'       => $this->game_slug,
			'name'       => 'Thread Import Narrative',
			'created_by' => 1,
			'created_at' => current_time( 'mysql' ),
			'updated_at' => current_time( 'mysql' ),
			'settings'   => wp_json_encode( [] ),
		] );
		$this->game_id = (int) $wpdb->insert_id;
	}

	private function synthetic_parsed( array $overrides = [] ): array {
		return array_merge( [
			'players'    => [],
			'characters' => [],
			'items'      => [],
			'locations'  => [],
			'rotes'      => [],
			'actions'    => [],
			'plots'      => [],
			'rumors'     => [],
			'queries'    => [],
		], $overrides );
	}

	private function create_character( string $name ): object {
		$id = Character::create( [
			'name'       => $name,
			'stack_slug' => 'vampire',
			'owner_type' => 'chronicle',
			'owner_slug' => $this->game_slug,
		] );
		return Character::find( $id );
	}

	public function test_a_plot_imports_with_matched_cast_as_connections_and_developments_as_notes(): void {
		$cast = $this->create_character( 'Isolde Marchetti' );

		$parsed = $this->synthetic_parsed( [
			'plots' => [
				[
					'name'          => 'The Tremere Gambit',
					'start_date'    => '2026-01-01 00:00:00',
					'end_date'      => '2026-02-01 00:00:00',
					'narrator'      => 'Greg',
					'outline'       => 'A chantry schemes.',
					'cast_list'     => [
						'traits' => [
							[ 'name' => 'Isolde Marchetti', 'total' => '0', 'note' => '' ],
							[ 'name' => 'Nobody Real', 'total' => '0', 'note' => '' ],
						],
					],
					'developments'  => [
						[ 'dev_date' => '2026-01-05 00:00:00', 'development' => 'A ritual is attempted.', 'effects' => [] ],
					],
				],
			],
		] );

		$created = Import_Controller::apply_import( $this->game_id, $this->game_slug, $parsed, 'synthetic.gv3', [] );

		$this->assertSame( 1, $created['plots'] );
		$this->assertSame( [ 'Nobody Real' ], $created['unmatched_cast'] );

		$plot = Plot::find_by_title_and_date( $this->game_id, 'The Tremere Gambit', 'start_date', '2026-01-01' );
		$this->assertNotNull( $plot );
		$this->assertSame( 'storytellers', $plot->audience );
		$this->assertStringContainsString( 'Greg', $plot->st_notes );
		$this->assertStringContainsString( 'Nobody Real', $plot->st_notes );

		$connections = Connection::for_source( 'plot', (int) $plot->id );
		$this->assertCount( 1, $connections );
		$this->assertSame( (int) $cast->id, (int) $connections[0]->target_id );

		$entries = Plot_Entry::for_plot( (int) $plot->id, [ 'entry_type' => 'note' ] );
		$this->assertCount( 1, $entries );
		$this->assertStringContainsString( 'A ritual is attempted.', $entries[0]->content );
	}

	public function test_a_plot_with_the_same_title_and_start_date_is_skipped_on_re_import(): void {
		$parsed = $this->synthetic_parsed( [
			'plots' => [
				[ 'name' => 'Repeated Plot', 'start_date' => '2026-01-01 00:00:00', 'end_date' => '', 'narrator' => '', 'outline' => '', 'cast_list' => null, 'developments' => [] ],
			],
		] );

		$first = Import_Controller::apply_import( $this->game_id, $this->game_slug, $parsed, 'synthetic.gv3', [] );
		$this->assertSame( 1, $first['plots'] );
		$this->assertSame( 0, $first['skipped_plots'] );

		$second = Import_Controller::apply_import( $this->game_id, $this->game_slug, $parsed, 'synthetic.gv3', [] );
		$this->assertSame( 0, $second['plots'] );
		$this->assertSame( 1, $second['skipped_plots'] );
	}

	private function synthetic_rumor( array $overrides = [] ): array {
		return array_merge( [
			'title'       => 'Something is Afoot',
			'rumor_date'  => '2026-01-01 00:00:00',
			'category'    => 0,
			'multi_key'   => '',
			'multi_match' => '',
			'done'        => false,
			'query'       => null,
			'variants'    => [ [ 'level' => 1, 'rumor' => 'Everyone whispers about it.' ] ],
		], $overrides );
	}

	public function test_a_done_rumor_arrives_delivered_an_undone_one_arrives_held(): void {
		$parsed = $this->synthetic_parsed( [
			'rumors' => [
				$this->synthetic_rumor( [ 'title' => 'Done Rumor', 'done' => true ] ),
				$this->synthetic_rumor( [ 'title' => 'Open Rumor', 'done' => false ] ),
			],
		] );

		$created = Import_Controller::apply_import( $this->game_id, $this->game_slug, $parsed, 'synthetic.gv3', [] );
		$this->assertSame( 2, $created['rumors'] );

		$done = Plot::find_by_title_and_date( $this->game_id, 'Done Rumor', 'game_date', '2026-01-01' );
		$this->assertSame( 0, (int) $done->held );

		$open = Plot::find_by_title_and_date( $this->game_id, 'Open Rumor', 'game_date', '2026-01-01' );
		$this->assertSame( 1, (int) $open->held );

		$levels = Plot_Entry::for_plot( (int) $done->id, [ 'entry_type' => 'rumor_level' ] );
		$this->assertCount( 1, $levels );
		$this->assertSame( 'Everyone whispers about it.', $levels[0]->content );

		$tag = Connection::for_source( 'plot', (int) $done->id );
		$this->assertCount( 1, $tag );
		$this->assertSame( 'apr_rumor', $tag[0]->label );
	}

	public function test_a_single_clause_query_converts_to_a_restricted_target_query(): void {
		$parsed = $this->synthetic_parsed( [
			'rumors' => [
				$this->synthetic_rumor( [
					'title' => 'Clan Rumor',
					'query' => [
						'clauses' => [
							[ 'key' => 'clan', 'comparison' => 1, 'comp_not' => false, 'find' => 'Toreador', 'number' => 0.0 ],
						],
					],
				] ),
			],
		] );

		Import_Controller::apply_import( $this->game_id, $this->game_slug, $parsed, 'synthetic.gv3', [] );

		$plot = Plot::find_by_title_and_date( $this->game_id, 'Clan Rumor', 'game_date', '2026-01-01' );
		$this->assertSame( 'restricted', $plot->audience );
		$this->assertSame( 'clan', $plot->target_query['field'] );
		$this->assertSame( 'equals', $plot->target_query['operator'] );
		$this->assertSame( 'Toreador', $plot->target_query['value'] );
	}

	public function test_a_multi_clause_query_falls_back_to_storytellers_only_with_a_reason(): void {
		$parsed = $this->synthetic_parsed( [
			'rumors' => [
				$this->synthetic_rumor( [
					'title' => 'Compound Rumor',
					'query' => [
						'clauses' => [
							[ 'key' => 'clan', 'comparison' => 1, 'comp_not' => false, 'find' => 'Toreador', 'number' => 0.0 ],
							[ 'key' => 'generation', 'comparison' => 4, 'comp_not' => false, 'find' => '', 'number' => 10.0 ],
						],
					],
				] ),
			],
		] );

		Import_Controller::apply_import( $this->game_id, $this->game_slug, $parsed, 'synthetic.gv3', [] );

		$plot = Plot::find_by_title_and_date( $this->game_id, 'Compound Rumor', 'game_date', '2026-01-01' );
		$this->assertSame( 'storytellers', $plot->audience );
		$this->assertNull( $plot->target_query );
		$this->assertStringContainsString( 'more than one condition', $plot->st_notes );
	}

	public function test_a_rumor_with_the_same_title_and_date_is_skipped_on_re_import(): void {
		$parsed = $this->synthetic_parsed( [ 'rumors' => [ $this->synthetic_rumor() ] ] );

		$first = Import_Controller::apply_import( $this->game_id, $this->game_slug, $parsed, 'synthetic.gv3', [] );
		$this->assertSame( 1, $first['rumors'] );

		$second = Import_Controller::apply_import( $this->game_id, $this->game_slug, $parsed, 'synthetic.gv3', [] );
		$this->assertSame( 0, $second['rumors'] );
		$this->assertSame( 1, $second['skipped_rumors'] );
	}

	public function test_an_action_matches_a_character_and_builds_an_allocator_plot_marked_resolved_when_done(): void {
		$character = $this->create_character( 'Jimmy the Martyr' );

		$parsed = $this->synthetic_parsed( [
			'actions' => [
				[
					'act_date'   => '2026-01-01 00:00:00',
					'char_name'  => 'Jimmy the Martyr',
					'done'       => true,
					'subactions' => [
						[ 'name' => 'Academics', 'level' => 3, 'action' => 'Researched the ritual.', 'result' => 'Found the name.' ],
					],
				],
			],
		] );

		$created = Import_Controller::apply_import( $this->game_id, $this->game_slug, $parsed, 'synthetic.gv3', [] );
		$this->assertSame( 1, $created['actions'] );
		$this->assertSame( [], $created['unmatched_actors'] );

		$plot_id = Action_Allocator::find_own_plot_id( (int) $character->id, '2026-01-01' );
		$this->assertNotNull( $plot_id );

		$plot = Plot::find( $plot_id );
		$this->assertSame( 'resolved', $plot->status );

		$entries = Plot_Entry::for_plot( $plot_id, [ 'entry_type' => 'action' ] );
		$this->assertCount( 1, $entries );
		$this->assertStringContainsString( 'Academics (3)', $entries[0]->content );
		$this->assertStringContainsString( 'Found the name.', $entries[0]->content );
	}

	public function test_an_action_matching_no_character_is_skipped_and_listed(): void {
		$parsed = $this->synthetic_parsed( [
			'actions' => [
				[ 'act_date' => '2026-01-01 00:00:00', 'char_name' => 'Nobody Real', 'done' => false, 'subactions' => [] ],
			],
		] );

		$created = Import_Controller::apply_import( $this->game_id, $this->game_slug, $parsed, 'synthetic.gv3', [] );
		$this->assertSame( 0, $created['actions'] );
		$this->assertSame( [ 'Nobody Real' ], $created['unmatched_actors'] );
	}

	public function test_an_allocator_plot_already_present_for_the_same_character_and_date_is_skipped(): void {
		$character = $this->create_character( 'Repeat Actor' );
		$parsed    = $this->synthetic_parsed( [
			'actions' => [
				[ 'act_date' => '2026-01-01 00:00:00', 'char_name' => 'Repeat Actor', 'done' => false, 'subactions' => [] ],
			],
		] );

		$first = Import_Controller::apply_import( $this->game_id, $this->game_slug, $parsed, 'synthetic.gv3', [] );
		$this->assertSame( 1, $first['actions'] );

		$second = Import_Controller::apply_import( $this->game_id, $this->game_slug, $parsed, 'synthetic.gv3', [] );
		$this->assertSame( 0, $second['actions'] );
		$this->assertSame( 1, $second['skipped_actions'] );
	}

	public function test_unticking_a_kind_skips_it_entirely_without_counting_it_present(): void {
		$parsed = $this->synthetic_parsed( [ 'rumors' => [ $this->synthetic_rumor() ] ] );

		$created = Import_Controller::apply_import(
			$this->game_id,
			$this->game_slug,
			$parsed,
			'synthetic.gv3',
			[ 'import_kinds' => [ 'rumors' => false ] ]
		);

		$this->assertArrayNotHasKey( 'rumors', $created );
		$this->assertSame( 1, $created['skipped_rumors'] );
		$this->assertNull( Plot::find_by_title_and_date( $this->game_id, 'Something is Afoot', 'game_date', '2026-01-01' ) );
	}
}
