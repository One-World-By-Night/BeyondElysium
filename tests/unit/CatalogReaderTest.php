<?php

namespace BeyondElysium\Tests\Unit;

use BeyondElysium\Services\Catalog_Reader;
use PHPUnit\Framework\TestCase;

/**
 * The 1.3.2 declared-catalog loader: load -> validate -> seed, against both synthetic
 * fixtures (isolating one behavior at a time) and the real shipped files under
 * `data/catalog/` (proving the loader against what 1.3.0/1.3.1 actually authored, not just
 * a hand-built shape). See `BE_PROCESS/releases/1.3.2-design-workflow.md`.
 */
class CatalogReaderTest extends TestCase {

	/** @var string[] Temp directories created by build_catalog(), removed in tearDown(). */
	private array $tmp_dirs = [];

	protected function tearDown(): void {
		foreach ( $this->tmp_dirs as $dir ) {
			self::remove_dir( $dir );
		}
		$this->tmp_dirs = [];
		Catalog_Reader::reset_cache();
		parent::tearDown();
	}

	private static function remove_dir( string $dir ): void {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$items = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $items as $item ) {
			$item->isDir() ? rmdir( $item->getPathname() ) : unlink( $item->getPathname() );
		}
		rmdir( $dir );
	}

	/**
	 * Builds a temp `data/catalog`-shaped tree from `['blocks/foo' => [...], 'stacks/bar' =>
	 * [...]]`, returns its root. Every fixture is completed with the envelope fields a real
	 * file always carries (`format`, `provenance`) unless the caller already supplied them,
	 * so a test can focus on the one field under test.
	 *
	 * @param array<string,array<string,mixed>> $files Path (without `.json`) => decoded content.
	 */
	private function build_catalog( array $files ): string {
		$root = sys_get_temp_dir() . '/be-catalog-reader-test-' . uniqid();
		foreach ( $files as $relative => $data ) {
			$path = "{$root}/{$relative}.json";
			@mkdir( dirname( $path ), 0777, true );
			$data += [
				'format'     => 1,
				'provenance' => [ 'sources' => [ 'test fixture' ] ],
			];
			file_put_contents( $path, (string) wp_json_encode_for_test( $data ) );
		}
		$this->tmp_dirs[] = $root;
		return $root;
	}

	// -------------------------------------------------------------------------
	// Round trip against synthetic fixtures - one behavior isolated at a time.
	// -------------------------------------------------------------------------

	public function test_a_plain_trait_list_decodes_directly_with_no_field_renamed(): void {
		$root = $this->build_catalog( [
			'blocks/plain-merits' => [
				'slug'         => 'plain-merits',
				'name'         => 'Plain Merits',
				'kind'         => 'block',
				'section_type' => 'trait_list',
				'definition'   => [
					'allow_multiples' => false,
					'allow_custom'    => true,
					'items'           => [
						[
							'name'              => 'Iron Will',
							'cost'              => '1 or 3',
							'tier'              => null,
							'group'             => null,
							'subgroup'          => null,
							'source'            => 'Merits, Vampire',
							'note'              => null,
							'description'       => null,
							'approval'          => null,
							'reason'            => null,
							'approval_by_value' => [],
							'prerequisites'     => [],
						],
					],
				],
			],
		] );

		$blocks = Catalog_Reader::blocks_to_seed( $root );

		$this->assertArrayHasKey( 'plain-merits', $blocks );
		$block = $blocks['plain-merits'];
		$this->assertSame( 'trait_list', $block['section_type'] );
		$this->assertSame( 1, $block['is_system'] );
		$this->assertSame( 0, $block['created_by'] );
		$this->assertSame( 'Iron Will', $block['definition']['items'][0]['name'] );
		$this->assertSame( '1 or 3', $block['definition']['items'][0]['cost'] );
		// tier/group/subgroup survive as real null, not dropped - exactly the shape
		// Cost_Engine/TraitListRenderer already read for every other trait_list block.
		$this->assertNull( $block['definition']['items'][0]['tier'] );
	}

	public function test_a_full_ladder_tiered_power_decodes_with_meta_and_split_containers_intact(): void {
		$root = $this->build_catalog( [
			'blocks/plain-disciplines' => $this->tiered_file( 'plain-disciplines', [
				[
					'name'   => 'Animalism',
					'source' => 'Disciplines, Vampire',
					'levels' => [
						[ 'level' => 1, 'tier' => 'basic', 'power_name' => 'Feral Whispers', 'cost' => '3' ],
						[ 'level' => 2, 'tier' => 'basic', 'power_name' => 'Beckoning', 'cost' => '3' ],
						[ 'level' => 3, 'tier' => 'intermediate', 'power_name' => 'Quell the Beast', 'cost' => '6' ],
						[ 'level' => 4, 'tier' => 'intermediate', 'power_name' => 'Subsume the Spirit', 'cost' => '6' ],
						[ 'level' => 5, 'tier' => 'advanced', 'power_name' => 'Drawing Out the Beast', 'cost' => '9' ],
					],
					'elder'  => [
						'elder' => [
							[ 'level' => null, 'tier' => 'elder', 'power_name' => 'Animal Succulence', 'cost' => '12' ],
						],
					],
				],
			] ),
		] );

		$blocks     = Catalog_Reader::blocks_to_seed( $root );
		$definition = $blocks['plain-disciplines']['definition'];

		$this->assertSame( [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ], $definition['_meta']['ladder'] );
		$this->assertCount( 5, $definition['powers'][0]['levels'] );
		$this->assertCount( 1, $definition['powers'][0]['elder']['elder'] );
		// Defaults filled in exactly the way make_tiered_power_block() does for a block
		// with no $extra override - the fixture declared neither key.
		$this->assertTrue( $definition['sequential'] );
		$this->assertTrue( $definition['allow_custom'] );
	}

	public function test_a_pick_only_tiered_power_keeps_its_empty_ladder_and_gets_no_phantom_default(): void {
		$root = $this->build_catalog( [
			'blocks/plain-gifts' => $this->tiered_file(
				'plain-gifts',
				[
					[
						'name'  => 'Homid',
						'levels' => [],
						'elder' => [
							'basic' => [
								[ 'level' => null, 'tier' => 'basic', 'power_name' => 'Jam Gun', 'cost' => '3' ],
							],
						],
					],
				],
				[ 'ladder' => [] ]
			),
		] );

		$definition = Catalog_Reader::blocks_to_seed( $root )['plain-gifts']['definition'];

		$this->assertSame( [], $definition['_meta']['ladder'] );
		$this->assertSame( [], $definition['powers'][0]['levels'] );
	}

	public function test_definition_flag_defaults_never_overwrite_an_explicit_false(): void {
		$root = $this->build_catalog( [
			'blocks/explicit-false' => $this->tiered_file(
				'explicit-false',
				[ [ 'name' => 'Foo', 'levels' => [], 'elder' => [] ] ],
				[ 'ladder' => [] ],
				[ 'allow_custom' => false ]
			),
		] );

		$definition = Catalog_Reader::blocks_to_seed( $root )['explicit-false']['definition'];

		$this->assertFalse( $definition['allow_custom'], 'an explicit false in the file is data, never a gap to default over' );
		// sequential was never declared at all - that one really is defaulted.
		$this->assertTrue( $definition['sequential'] );
	}

	/** mage-rotes' own shape: a trait_list carrying its own `_meta.untiered.derived_from`. */
	public function test_a_trait_lists_own_meta_untiered_survives_ingestion_unmodified(): void {
		$root = $this->build_catalog( [
			'blocks/plain-rotes' => [
				'slug'         => 'plain-rotes',
				'name'         => 'Plain Rotes',
				'kind'         => 'block',
				'section_type' => 'trait_list',
				'definition'   => [
					'_meta'           => [ 'untiered' => [ 'derived_from' => 'mage-spheres', 'per_level' => 1 ] ],
					'allow_multiples' => false,
					'allow_custom'    => true,
					'items'           => [
						[ 'name' => 'Ball of Abysmal Flame', 'cost' => null, 'tier' => null, 'group' => null, 'subgroup' => null ],
					],
				],
			],
		] );

		$definition = Catalog_Reader::blocks_to_seed( $root )['plain-rotes']['definition'];

		$this->assertSame( 'mage-spheres', $definition['_meta']['untiered']['derived_from'] );
		$this->assertSame( 1, $definition['_meta']['untiered']['per_level'] );
	}

	/** Item-level allow_multiples overriding the block default - pure data passthrough (D86). */
	public function test_item_level_allow_multiples_survives_ingestion_unmodified(): void {
		$root = $this->build_catalog( [
			'blocks/plain-backgrounds' => [
				'slug'         => 'plain-backgrounds',
				'name'         => 'Plain Backgrounds',
				'kind'         => 'block',
				'section_type' => 'trait_list',
				'definition'   => [
					'allow_multiples' => false,
					'allow_custom'    => true,
					'items'           => [
						[ 'name' => 'Retainers', 'cost' => '1', 'tier' => null, 'group' => null, 'subgroup' => null, 'allow_multiples' => true ],
						[ 'name' => 'Resources', 'cost' => '1', 'tier' => null, 'group' => null, 'subgroup' => null ],
					],
				],
			],
		] );

		$items = Catalog_Reader::blocks_to_seed( $root )['plain-backgrounds']['definition']['items'];

		$this->assertTrue( $items[0]['allow_multiples'], 'Retainers overrides the block default to true' );
		$this->assertArrayNotHasKey( 'allow_multiples', $items[1], 'Resources states no override - absent, not forced to a value' );
	}

	public function test_storyteller_only_is_carried_only_when_the_file_states_it(): void {
		$root = $this->build_catalog( [
			'blocks/quiet-notes'    => array_merge( $this->identity_file( 'quiet-notes' ), [ 'storyteller_only' => true ] ),
			'blocks/ordinary-field' => $this->identity_file( 'ordinary-field' ),
		] );

		$blocks = Catalog_Reader::blocks_to_seed( $root );

		$this->assertSame( 1, $blocks['quiet-notes']['storyteller_only'] );
		$this->assertArrayNotHasKey( 'storyteller_only', $blocks['ordinary-field'], 'no file states it, so nothing is forced onto the row' );
	}

	// -------------------------------------------------------------------------
	// mode: add - the merge reader (item 4).
	// -------------------------------------------------------------------------

	public function test_add_variant_unions_elder_picks_into_a_same_named_base_family(): void {
		$root = $this->build_catalog( [
			'blocks/base-gifts'    => $this->tiered_file(
				'base-gifts',
				[
					[
						'name'  => 'Homid',
						'levels' => [],
						'elder' => [ 'basic' => [ [ 'level' => null, 'tier' => 'basic', 'power_name' => 'Jam Gun', 'cost' => '3' ] ] ],
					],
				],
				[ 'ladder' => [] ]
			),
			'blocks/packet-gifts'  => array_merge(
				$this->tiered_file(
					'packet-gifts',
					[
						[
							'name'  => 'Homid',
							'levels' => [],
							'elder' => [ 'advanced' => [ [ 'level' => null, 'tier' => 'advanced', 'power_name' => 'Weave of Steel', 'cost' => '9' ] ] ],
						],
					],
					[ 'ladder' => [] ]
				),
				[ 'variant' => [ 'of' => 'base-gifts', 'id' => 'packet', 'label' => 'Packet', 'mode' => 'add' ] ]
			),
		] );

		$definition = Catalog_Reader::blocks_to_seed( $root )['packet-gifts']['definition'];

		$this->assertCount( 1, $definition['powers'], 'still one Homid family, not two' );
		$this->assertCount( 1, $definition['powers'][0]['elder']['basic'], 'the base pick is kept' );
		$this->assertCount( 1, $definition['powers'][0]['elder']['advanced'], 'the packet pick is unioned in' );
	}

	public function test_add_variant_appends_a_family_with_no_name_match_in_the_base(): void {
		$root = $this->build_catalog( [
			'blocks/base-disciplines'    => $this->tiered_file( 'base-disciplines', [
				[ 'name' => 'Animalism', 'levels' => self::five_rung_ladder(), 'elder' => [] ],
			] ),
			'blocks/edition-disciplines' => array_merge(
				$this->tiered_file( 'edition-disciplines', [
					[ 'name' => 'Animalism (Dark Ages)', 'source' => 'Animalism', 'levels' => self::five_rung_ladder(), 'elder' => [], 'split_from' => 'Animalism' ],
				] ),
				[ 'variant' => [ 'of' => 'base-disciplines', 'id' => 'dark-ages', 'label' => 'Dark Ages', 'mode' => 'add' ] ]
			),
		] );

		$definition = Catalog_Reader::blocks_to_seed( $root )['edition-disciplines']['definition'];

		$names = array_column( $definition['powers'], 'name' );
		$this->assertContains( 'Animalism', $names, 'the base family is kept' );
		$this->assertContains( 'Animalism (Dark Ages)', $names, 'the new family is appended whole' );
		$this->assertCount( 2, $definition['powers'] );
	}

	/**
	 * Review index §3 item 3: `alternatives` (a rung's own field), `category_values` (a
	 * tiered_power family's own field) and `option_aliases` (an identity_field's own field)
	 * are never touched by `apply_definition_defaults()` or `merge_add_variant()` - both
	 * operate only on the specific top-level/family keys they document - so a direct decode
	 * carries all three through unmodified with no dedicated read path needed for any of
	 * them.
	 */
	public function test_alternatives_category_values_and_option_aliases_all_survive_direct_decode(): void {
		$root = $this->build_catalog( [
			'blocks/plain-arcanoi' => $this->tiered_file( 'plain-arcanoi', [
				[
					'name'   => 'Argos',
					'levels' => [
						array_merge( self::five_rung_ladder()[0], [
							'alternatives' => [ [ 'power_name' => 'Phantom Whispers', 'note' => 'packet variant' ] ],
						] ),
						self::five_rung_ladder()[1],
						self::five_rung_ladder()[2],
						self::five_rung_ladder()[3],
						self::five_rung_ladder()[4],
					],
					'elder'          => [],
					'category_values' => [ 'breed' => 'Homid' ],
				],
			] ),
			'blocks/plain-identity' => [
				'slug'         => 'plain-identity',
				'name'         => 'Plain Identity',
				'kind'         => 'block',
				'section_type' => 'identity_field',
				'definition'   => [
					'fields' => [
						[
							'name'           => 'Tribe',
							'field_type'     => 'select',
							'required'       => true,
							'options'        => [ 'Bone Gnawers' ],
							'option_aliases' => [ 'Bone Gnawer' => 'Bone Gnawers' ],
						],
					],
				],
			],
		] );

		$blocks = Catalog_Reader::blocks_to_seed( $root );

		$this->assertSame(
			'Phantom Whispers',
			$blocks['plain-arcanoi']['definition']['powers'][0]['levels'][0]['alternatives'][0]['power_name']
		);
		$this->assertSame( 'Homid', $blocks['plain-arcanoi']['definition']['powers'][0]['category_values']['breed'] );
		$this->assertSame(
			'Bone Gnawers',
			$blocks['plain-identity']['definition']['fields'][0]['option_aliases']['Bone Gnawer']
		);
	}

	public function test_a_replace_variant_seeds_as_its_own_complete_row_unmerged(): void {
		$root = $this->build_catalog( [
			'blocks/base-arcanoi'  => $this->tiered_file( 'base-arcanoi', [
				[ 'name' => 'Argos', 'levels' => self::five_rung_ladder(), 'elder' => [] ],
			], [ 'costs' => [ 'basic' => 3, 'intermediate' => 6, 'advanced' => 9, 'elder' => 12, 'master' => 15 ] ] ),
			'blocks/owbn-arcanoi'  => array_merge(
				$this->tiered_file( 'owbn-arcanoi', [
					[ 'name' => 'Argos', 'levels' => self::five_rung_ladder(), 'elder' => [] ],
				], [ 'costs' => [ 'basic' => 4, 'intermediate' => 7, 'advanced' => 10, 'elder' => 12, 'master' => 15 ] ] ),
				[ 'variant' => [ 'of' => 'base-arcanoi', 'id' => 'owbn', 'label' => 'OWBN', 'mode' => 'replace' ] ]
			),
		] );

		$blocks = Catalog_Reader::blocks_to_seed( $root );

		$this->assertSame( 3, $blocks['base-arcanoi']['definition']['_meta']['costs']['basic'], 'the base is untouched' );
		$this->assertSame( 4, $blocks['owbn-arcanoi']['definition']['_meta']['costs']['basic'], 'the variant carries its own complete _meta, never merged' );
	}

	public function test_an_add_variant_with_no_valid_base_is_skipped_not_seeded_broken(): void {
		$root = $this->build_catalog( [
			'blocks/orphan-variant' => array_merge(
				$this->tiered_file( 'orphan-variant', [ [ 'name' => 'Solo', 'levels' => [], 'elder' => [] ] ], [ 'ladder' => [] ] ),
				[ 'variant' => [ 'of' => 'nonexistent-base', 'id' => 'x', 'label' => 'X', 'mode' => 'add' ] ]
			),
		] );

		$blocks = Catalog_Reader::blocks_to_seed( $root );

		$this->assertArrayNotHasKey( 'orphan-variant', $blocks );
	}

	// -------------------------------------------------------------------------
	// The out-of-type bridge (untouched Cost_Engine still reads the deprecated scalar).
	// -------------------------------------------------------------------------

	public function test_a_flat_out_of_type_expression_bridges_to_the_deprecated_scalar(): void {
		$root = $this->build_catalog( [
			'blocks/flat-modifier' => $this->tiered_file(
				'flat-modifier',
				[ [ 'name' => 'Foo', 'levels' => self::five_rung_ladder(), 'elder' => [] ] ],
				[ 'out_of_type' => [ 'basic' => '+1', 'intermediate' => '+1', 'advanced' => '+1' ] ]
			),
		] );

		$definition = Catalog_Reader::blocks_to_seed( $root )['flat-modifier']['definition'];

		$this->assertSame( 1, $definition['out_of_type_cost_modifier'] );
	}

	public function test_a_scaling_out_of_type_expression_is_never_flattened_into_a_wrong_scalar(): void {
		$root = $this->build_catalog( [
			'blocks/scaling-modifier' => $this->tiered_file(
				'scaling-modifier',
				[ [ 'name' => 'Foo', 'levels' => self::five_rung_ladder(), 'elder' => [] ] ],
				[ 'out_of_type' => [ 'basic' => '+1', 'intermediate' => '+2', 'advanced' => '+3' ] ]
			),
		] );

		$definition = Catalog_Reader::blocks_to_seed( $root )['scaling-modifier']['definition'];

		$this->assertArrayNotHasKey( 'out_of_type_cost_modifier', $definition, 'a scaling expression cannot be a single flat scalar - left for 1.4.0, not guessed at' );
	}

	public function test_an_explicit_deprecated_scalar_is_never_overwritten_by_the_bridge(): void {
		$root = $this->build_catalog( [
			'blocks/already-set' => array_merge(
				$this->tiered_file(
					'already-set',
					[ [ 'name' => 'Foo', 'levels' => self::five_rung_ladder(), 'elder' => [] ] ],
					[ 'out_of_type' => [ 'basic' => '+1', 'intermediate' => '+1', 'advanced' => '+1' ] ]
				),
				[]
			),
		] );
		// Inject the deprecated key directly into the fixture's definition to prove it wins.
		$path = "{$root}/blocks/already-set.json";
		$data = json_decode( (string) file_get_contents( $path ), true );
		$data['definition']['out_of_type_cost_modifier'] = 9;
		file_put_contents( $path, (string) wp_json_encode_for_test( $data ) );

		$definition = Catalog_Reader::blocks_to_seed( $root )['already-set']['definition'];

		$this->assertSame( 9, $definition['out_of_type_cost_modifier'] );
	}

	// -------------------------------------------------------------------------
	// Graceful degradation - a malformed file never crashes ingestion.
	// -------------------------------------------------------------------------

	public function test_a_file_failing_validation_is_excluded_not_fatal(): void {
		$root = $this->build_catalog( [
			'blocks/broken' => [
				'slug'         => 'broken',
				'name'         => 'Broken',
				'kind'         => 'block',
				'section_type' => 'trait_list',
				// No `items` key at all - a real validation failure.
				'definition'   => [ 'allow_custom' => true ],
			],
			'blocks/fine'   => $this->identity_file( 'fine' ),
		] );

		$catalog = Catalog_Reader::load( $root );

		$this->assertArrayNotHasKey( 'broken', $catalog['blocks'] );
		$this->assertArrayHasKey( 'fine', $catalog['blocks'] );
		$this->assertNotEmpty( $catalog['errors'] );
	}

	public function test_available_is_false_with_no_catalog_directory(): void {
		$this->assertFalse( Catalog_Reader::available( sys_get_temp_dir() . '/be-catalog-reader-never-created' ) );
	}

	// -------------------------------------------------------------------------
	// Round trip against the real shipped catalog - the whole point of the format
	// (measure against real authored files, not only hand-built fixtures).
	// -------------------------------------------------------------------------

	public function test_round_trips_a_real_full_ladder_block_vampire_disciplines(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		$block = Catalog_Reader::blocks_to_seed()['vampire-disciplines'] ?? null;
		$this->assertNotNull( $block );
		$this->assertSame( 'tiered_power', $block['section_type'] );
		$this->assertSame( [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ], $block['definition']['_meta']['ladder'] );
		$this->assertTrue( $block['definition']['sequential'] );
		// D75's one already-working case - the bridge must preserve it exactly.
		$this->assertSame( 1, $block['definition']['out_of_type_cost_modifier'] );
	}

	public function test_round_trips_a_real_pick_only_block_werewolf_gifts(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		$block = Catalog_Reader::blocks_to_seed()['werewolf-gifts'] ?? null;
		$this->assertNotNull( $block );
		$this->assertSame( [], $block['definition']['_meta']['ladder'] );
	}

	public function test_round_trips_the_real_edition_variant_darkages_vampire_disciplines(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		$block = Catalog_Reader::blocks_to_seed()['darkages-vampire_disciplines'] ?? null;
		$this->assertNotNull( $block );
		$names = array_column( $block['definition']['powers'], 'name' );
		// The variant's own families, plus every base family carried through untouched.
		$this->assertContains( 'Animalism (Dark Ages)', $names );
		$this->assertContains( 'Animalism', $names );
	}

	public function test_round_trips_the_real_mage_rotes_trait_list_meta(): void {
		if ( ! Catalog_Reader::available() ) {
			$this->markTestSkipped( 'no declared catalog in this checkout' );
		}
		$block = Catalog_Reader::blocks_to_seed()['mage-rotes'] ?? null;
		$this->assertNotNull( $block );
		$this->assertSame( 'trait_list', $block['section_type'] );
		$this->assertSame( 'mage-spheres', $block['definition']['_meta']['untiered']['derived_from'] );
	}

	// -------------------------------------------------------------------------
	// Fixture builders.
	// -------------------------------------------------------------------------

	/** @return array<int,array{level:int,tier:string,power_name:string,cost:string}> */
	private static function five_rung_ladder(): array {
		return [
			[ 'level' => 1, 'tier' => 'basic', 'power_name' => 'One', 'cost' => '3' ],
			[ 'level' => 2, 'tier' => 'basic', 'power_name' => 'Two', 'cost' => '3' ],
			[ 'level' => 3, 'tier' => 'intermediate', 'power_name' => 'Three', 'cost' => '6' ],
			[ 'level' => 4, 'tier' => 'intermediate', 'power_name' => 'Four', 'cost' => '6' ],
			[ 'level' => 5, 'tier' => 'advanced', 'power_name' => 'Five', 'cost' => '9' ],
		];
	}

	/**
	 * @param array<int,array<string,mixed>> $powers
	 * @param array<string,mixed>            $meta_overrides
	 * @param array<string,mixed>            $definition_extra
	 */
	private function tiered_file( string $slug, array $powers, array $meta_overrides = [], array $definition_extra = [] ): array {
		$meta = array_merge( [
			'ranks'  => [ 'basic', 'intermediate', 'advanced', 'elder', 'master' ],
			'ladder' => [ 'basic' => 2, 'intermediate' => 2, 'advanced' => 1 ],
			'costs'  => [ 'basic' => 3, 'intermediate' => 6, 'advanced' => 9, 'elder' => 12, 'master' => 15 ],
		], $meta_overrides );

		return [
			'slug'         => $slug,
			'name'         => $slug,
			'kind'         => 'block',
			'section_type' => 'tiered_power',
			'definition'   => array_merge( [
				'_meta'  => $meta,
				'powers' => $powers,
			], $definition_extra ),
		];
	}

	private function identity_file( string $slug ): array {
		return [
			'slug'         => $slug,
			'name'         => $slug,
			'kind'         => 'block',
			'section_type' => 'identity_field',
			'definition'   => [
				'fields' => [
					[ 'name' => 'Notes', 'field_type' => 'textarea', 'required' => false ],
				],
			],
		];
	}
}

/**
 * `wp_json_encode()` is a WordPress function unavailable in this bare-PHPUnit unit suite
 * (no WP bootstrap - see CLAUDE.md's test-layer split). Every other test file in this suite
 * that writes its own JSON fixture uses plain `json_encode()`; named separately here only so
 * the intent ("this is a test fixture, not the plugin's own encode path") stays visible at
 * the call site.
 */
function wp_json_encode_for_test( $data ): string {
	return (string) json_encode( $data, JSON_PRETTY_PRINT );
}
