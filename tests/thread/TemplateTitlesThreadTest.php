<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Schema;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Template;
use BeyondElysium\Services\Template_Titles;
use WP_UnitTestCase;

/**
 * `Template_Titles::run()` gives an installed system template the section titles its declared file names, for a section
 * still carrying one of the declared section's former titles, and touches nothing else.
 */
class TemplateTitlesThreadTest extends WP_UnitTestCase {

	private function layout( array $titles ): array {
		$sections = [];
		$order    = 1;
		foreach ( $titles as $slug => $title ) {
			$sections[] = [ 'block_slug' => $slug, 'column' => 1, 'order' => $order++, 'title' => $title, 'display' => null, 'collapsed' => false, 'width' => 'half' ];
		}
		return [ 'version' => 1, 'columns' => 6, 'sections' => $sections ];
	}

	private function template( array $titles, int $is_system = 1, ?int $game_id = null, string $type = 'sheet_full' ): int {
		$id = Template::create( [
			'game_id'       => $game_id,
			'stack_slug'    => 'kueijin',
			'template_type' => $type,
			'name'          => 'Kuei-Jin Sheet',
			'layout'        => $this->layout( $titles ),
			'is_system'     => $is_system,
		] );
		$this->assertGreaterThan( 0, $id );
		return $id;
	}

	private function titles( int $id ): array {
		return array_column( Template::find( $id )->layout['sections'], 'title', 'block_slug' );
	}

	private function old_titles(): array {
		return [
			'kueijin-abilities'    => 'Kueijin Abilities',
			'kueijin-backgrounds'  => 'Kueijin Backgrounds',
			'kueijin-disciplines'  => 'Kuei-Jin Disciplines',
			'kueijin-merits'       => 'Kueijin Merits',
			'kueijin-flaws'        => 'Kueijin Flaws',
		];
	}

	public function test_a_system_template_takes_the_titles_its_declared_file_names(): void {
		$id = $this->template( $this->old_titles() );

		Template_Titles::run();

		$this->assertSame(
			[
				'kueijin-abilities'   => 'Abilities',
				'kueijin-backgrounds' => 'Kuei-Jin Backgrounds',
				'kueijin-disciplines' => 'Kuei-Jin Disciplines',
				'kueijin-merits'      => 'Merits',
				'kueijin-flaws'       => 'Flaws',
			],
			$this->titles( $id )
		);
	}

	public function test_an_npc_template_is_renamed_too(): void {
		$id = $this->template( $this->old_titles(), 1, null, 'npc_full' );

		Template_Titles::run();

		$this->assertSame( 'Kuei-Jin Backgrounds', $this->titles( $id )['kueijin-backgrounds'] );
	}

	public function test_a_second_run_changes_nothing(): void {
		$this->template( $this->old_titles() );

		Template_Titles::run();
		$second = Template_Titles::run();

		$this->assertSame( [ 'templates' => 0, 'sections' => 0 ], $second );
	}

	public function test_a_title_someone_chose_is_left_alone(): void {
		$titles                         = $this->old_titles();
		$titles['kueijin-abilities']    = 'Our Abilities';
		$titles['kueijin-backgrounds']  = 'Kuei-Jin Backgrounds';
		$id                             = $this->template( $titles );

		Template_Titles::run();

		$after = $this->titles( $id );
		$this->assertSame( 'Our Abilities', $after['kueijin-abilities'] );
		$this->assertSame( 'Kuei-Jin Backgrounds', $after['kueijin-backgrounds'] );
		$this->assertSame( 'Merits', $after['kueijin-merits'], 'a section still on its former title is renamed beside them' );
	}

	public function test_a_template_that_is_not_the_systems_is_left_alone(): void {
		$game_id  = Game::create( [ 'slug' => 'titles-test', 'name' => 'Titles Test', 'created_by' => 1 ] );
		$own      = $this->template( $this->old_titles(), 1, (int) $game_id );
		$not_sys  = $this->template( $this->old_titles(), 0 );

		Template_Titles::run();

		$this->assertSame( 'Kueijin Abilities', $this->titles( $own )['kueijin-abilities'] );
		$this->assertSame( 'Kueijin Backgrounds', $this->titles( $not_sys )['kueijin-backgrounds'] );
	}

	public function test_another_creature_types_template_is_left_alone(): void {
		$id = Template::create( [
			'stack_slug'    => 'vampire',
			'template_type' => 'sheet_full',
			'name'          => 'Vampire Sheet',
			'layout'        => $this->layout( [ 'kueijin-backgrounds' => 'Kueijin Backgrounds' ] ),
			'is_system'     => 1,
		] );

		Template_Titles::run();

		$this->assertSame( 'Kueijin Backgrounds', $this->titles( $id )['kueijin-backgrounds'] );
	}

	public function test_the_upgrade_runs_it(): void {
		$id = $this->template( $this->old_titles() );
		update_option( Schema::VERSION_OPTION, '0.99.0' );

		Schema::maybe_upgrade();

		$this->assertSame( 'Kuei-Jin Backgrounds', $this->titles( $id )['kueijin-backgrounds'] );
		$this->assertSame( 'Merits', $this->titles( $id )['kueijin-merits'] );
	}
}
