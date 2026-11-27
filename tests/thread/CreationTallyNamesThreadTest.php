<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Catalog_Labels;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * The tally and the screens that serve a creature type, a template and the compendium print a catalog name in the
 * site's language while every slug and key stays as stored.
 */
class CreationTallyNamesThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-tally-names';
	private int $game_id;
	private int $hst;
	private int $player;

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Named by Night' ] );
		$this->hst     = self::factory()->user->create( [ 'role' => 'editor' ] );
		Game_Member::set_role( $this->game_id, $this->hst, 'hst' );
		$this->player = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		Game_Member::set_role( $this->game_id, $this->player, 'player' );
	}

	public function tearDown(): void {
		remove_all_filters( 'gettext' );
		Catalog_Labels::reset();
		parent::tearDown();
	}

	/**
	 * Makes every catalog name read in Portuguese: "Abilities" becomes "Habilidades", "Free Traits" "Pontos Livres",
	 * "Rage" "Fúria", and the rest gain a marker.
	 */
	private function in_portuguese(): void {
		$words = [ 'Abilities' => 'Habilidades', 'Free Traits' => 'Pontos Livres', 'Attributes' => 'Atributos', 'Rage' => 'Fúria', 'Archetypes' => 'Arquétipos' ];
		add_filter( 'gettext', static function ( $translated, $text, $domain ) use ( $words ) {
			return $domain === 'beyond-elysium' && isset( $words[ $text ] ) ? $words[ $text ] : $translated;
		}, 10, 3 );
		Catalog_Labels::reset();
	}

	private function send( string $method, string $route, array $body = [], ?int $as = null ): \WP_REST_Response {
		wp_set_current_user( $as ?? $this->hst );
		$request = new WP_REST_Request( $method, $route );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $method === 'GET' ) {
			$request->set_query_params( $body );
		} else {
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		return rest_get_server()->dispatch( $request );
	}

	/**
	 * @param array<string,mixed> $sheet
	 * @return array<string,mixed>
	 */
	private function tally( string $stack, array $sheet = [] ): array {
		$response = $this->send( 'POST', "/be/v1/{$this->slug}/creation-tally", [ 'stack_slug' => $stack, 'sheet_data' => $sheet ] );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		return $response->get_data();
	}

	/**
	 * @param array<string,mixed> $tally
	 * @return array<string,mixed>|null
	 */
	private function step( array $tally, string $kind, string $key, string $value ): ?array {
		foreach ( $tally['steps'] as $step ) {
			if ( $step['kind'] === $kind && ( $step[ $key ] ?? null ) === $value ) {
				return $step;
			}
		}
		return null;
	}

	public function test_the_tally_names_each_section_pool_and_target_in_words_not_slugs(): void {
		$tally = $this->tally( 'vampire' );

		$prioritized = $this->step( $tally, 'prioritized', 'label', 'Attributes' );
		$this->assertNotNull( $prioritized );
		foreach ( $prioritized['sections'] as $row ) {
			$this->assertStringStartsWith( 'met-', $row['section'], 'The slug stays as the key.' );
			$this->assertStringNotContainsString( 'met-', $row['label'], 'The label is a word.' );
			$this->assertNotSame( '', $row['label'] );
		}

		$budget = $this->step( $tally, 'budget', 'section', 'vampire-abilities' );
		$this->assertSame( 'Abilities', $budget['section_label'] );

		$free = $this->step( $tally, 'free', 'pool', 'Free Traits' );
		$this->assertSame( 'Free Traits', $free['pool_label'] );
		$this->assertSame( 'Free Traits', $tally['pools']['Free Traits']['label'] );
	}

	public function test_a_start_line_names_its_pool_not_its_slug(): void {
		$tally = $this->tally( 'werewolf', [ 'werewolf-identity' => [ 'Auspice' => 'Ahroun', 'Breed' => 'Homid', 'Tribe' => 'Silver Fangs' ] ] );

		$rage = $this->step( $tally, 'start', 'target', 'werewolf-resources.Rage' );
		$this->assertNotNull( $rage );
		$this->assertSame( 'Rage', $rage['target_label'] );
	}

	public function test_a_limit_flag_names_its_target(): void {
		$sheet = [
			'hunter-identity' => [ 'Creed' => 'Defense' ],
			'hunter-virtues'  => [ [ 'name' => 'Mercy', 'level' => 3 ], [ 'name' => 'Vision', 'level' => 1 ], [ 'name' => 'Zeal', 'level' => 1 ] ],
		];
		$tally = $this->tally( 'hunter', $sheet );

		$flagged = array_values( array_filter( $tally['limits'], static fn( array $flag ): bool => $flag['reason'] === 'ceiling' ) );
		$this->assertNotEmpty( $flagged, 'A Virtue above the primary Virtue of the creed flags.' );
		foreach ( $flagged as $flag ) {
			$this->assertStringStartsWith( 'hunter-virtues.', $flag['target'] );
			$this->assertStringNotContainsString( 'hunter-virtues', $flag['target_label'] );
		}
	}

	public function test_a_portuguese_site_names_everything_in_portuguese_with_every_key_unchanged(): void {
		$english = $this->tally( 'vampire' );
		$this->in_portuguese();
		$tally = $this->tally( 'vampire' );

		$budget = $this->step( $tally, 'budget', 'section', 'vampire-abilities' );
		$this->assertSame( 'Habilidades', $budget['section_label'] );
		$this->assertSame( 'Habilidades', $budget['label'] );

		$free = $this->step( $tally, 'free', 'pool', 'Free Traits' );
		$this->assertSame( 'Pontos Livres', $free['pool_label'] );
		$this->assertArrayHasKey( 'Free Traits', $tally['pools'] );
		$this->assertSame( 'Pontos Livres', $tally['pools']['Free Traits']['label'] );

		$this->assertSame( array_keys( $english['pools'] ), array_keys( $tally['pools'] ) );
		$this->assertSame( $english['xp'], $tally['xp'] );
	}

	public function test_a_portuguese_werewolf_names_its_pool_in_portuguese(): void {
		$this->in_portuguese();
		$tally = $this->tally( 'werewolf', [ 'werewolf-identity' => [ 'Auspice' => 'Ahroun', 'Breed' => 'Homid', 'Tribe' => 'Silver Fangs' ] ] );

		$rage = $this->step( $tally, 'start', 'target', 'werewolf-resources.Rage' );
		$this->assertSame( 'Fúria', $rage['target_label'] );
		$this->assertSame( 'werewolf-resources.Rage', $rage['target'] );
	}

	public function test_a_creature_type_resolve_carries_portuguese_labels_and_the_same_slugs(): void {
		$english = $this->send( 'GET', '/be/v1/creature-stacks/vampire', [ 'resolve' => 'true', 'game_slug' => $this->slug ] )->get_data();
		$this->in_portuguese();
		$data = $this->send( 'GET', '/be/v1/creature-stacks/vampire', [ 'resolve' => 'true', 'game_slug' => $this->slug ] )->get_data();

		$labels = static fn( array $payload ): array => array_map(
			static fn( $section ): array => [ 'slug' => $section->block_slug, 'label' => $section->label ],
			(array) $payload['stack']->stack_definition->sections
		);
		$before = $labels( $english );
		$after  = $labels( $data );

		$this->assertSame( array_column( $before, 'slug' ), array_column( $after, 'slug' ) );
		$by_slug = array_column( $after, 'label', 'slug' );
		$this->assertSame( 'Habilidades', $by_slug['vampire-abilities'] );

		$step = null;
		foreach ( (array) $data['stack']->creation_rules->steps as $candidate ) {
			if ( ( $candidate->label ?? '' ) === 'Habilidades' ) {
				$step = $candidate;
			}
		}
		$this->assertNotNull( $step, 'A creation rule label is translated.' );
		$this->assertSame( 'vampire-abilities', $step->section );
	}

	public function test_a_creature_type_list_translates_but_a_stored_read_does_not(): void {
		$this->in_portuguese();
		$translated = $this->send( 'GET', '/be/v1/creature-stacks', [ 'game_slug' => $this->slug, 'per_page' => 100 ] )->get_data();
		$stored     = $this->send( 'GET', '/be/v1/creature-stacks', [ 'game_slug' => $this->slug, 'per_page' => 100, 'stored' => true ] )->get_data();

		$label = static function ( array $list, string $slug ): ?string {
			foreach ( $list as $stack ) {
				if ( $stack->slug !== $slug ) {
					continue;
				}
				foreach ( (array) $stack->stack_definition->sections as $section ) {
					if ( $section->block_slug === 'vampire-abilities' ) {
						return $section->label;
					}
				}
			}
			return null;
		};
		$this->assertSame( 'Habilidades', $label( $translated, 'vampire' ) );
		$this->assertSame( 'Abilities', $label( $stored, 'vampire' ), 'An editor reads the stored label.' );
	}

	public function test_a_template_resolve_carries_portuguese_titles_and_the_same_blocks(): void {
		$english = $this->send( 'GET', "/be/v1/{$this->slug}/templates/resolve", [ 'stack_slug' => 'vampire', 'template_type' => 'sheet_full' ] )->get_data();
		$this->in_portuguese();
		$data = $this->send( 'GET', "/be/v1/{$this->slug}/templates/resolve", [ 'stack_slug' => 'vampire', 'template_type' => 'sheet_full' ] )->get_data();

		$titles = static fn( array $payload ): array => array_column( $payload['template']['layout']['sections'], 'title', 'block_slug' );
		$before = $titles( $english );
		$after  = $titles( $data );

		$this->assertSame( array_keys( $before ), array_keys( $after ) );
		$this->assertSame( 'Archetypes', $before['met-archetypes'] );
		$this->assertSame( 'Arquétipos', $after['met-archetypes'] );
	}

	public function test_a_chronicles_own_title_shows_as_typed(): void {
		$this->in_portuguese();
		$this->assertSame( 'Our Own Heading', Catalog_Labels::word( 'Our Own Heading' ) );
		$this->assertSame( 'Habilidades', Catalog_Labels::word( 'Abilities' ) );
	}

	public function test_the_compendium_names_its_sections_in_portuguese(): void {
		$this->in_portuguese();
		$response = $this->send( 'GET', "/be/v1/{$this->slug}/compendium/vampire", [], $this->player );
		$this->assertSame( 200, $response->get_status(), wp_json_encode( $response->get_data() ) );
		$sections = array_column( $response->get_data()['sections'], 'label', 'block_slug' );
		$this->assertSame( 'Habilidades', $sections['vampire-abilities'] );
	}
}
