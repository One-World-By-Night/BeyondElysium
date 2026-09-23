<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the declared catalog at `data/catalog/**` and produces the plain arrays
 * `Seeder::seed_schema_blocks()` / `seed_creature_stacks()` / `seed_default_templates()`
 * already expect - the same `{slug,name,section_type,definition,is_system,created_by}`
 * shape `make_trait_list_block()`/`make_tiered_power_block()` build from the GVM, and the
 * matching `stack_definition`/`creation_rules`/`layout` shapes `Creature_Stack`/`Template`
 * already read.
 *
 * **Measured, not assumed: this is closer to a direct decode than a transform.** Tonight's
 * ruling made the runtime list shape (`reference/CATALOG-JSON-FORMAT.md` §4.2) the only
 * accepted catalog shape everywhere, so a declared file's `definition` is already the exact
 * tree `TraitListDefinition`/`TieredPowerDefinition`/`IdentityFieldDefinition`/
 * `ResourcePoolDefinition` expect - checked against `mage-rotes.json` (804 items),
 * `demon-evocations.json` (23 families on a 2/2/1 ladder) and a plain trait_list file, all
 * three decode into the shape the engine already reads with no field renamed or restructured.
 * The only real work left is: applying the same definition-flag defaults
 * `make_tiered_power_block()`/`make_trait_list_block()` already apply for a block that omits
 * them, and folding a `mode: add` variant's families into its base.
 *
 * **`Catalog_Validator` is the gate, reused rather than duplicated.** `bin/validate-catalog`
 * already fails the build on a malformed file; this class calls the same `validate_file()`/
 * `validate_references()` a second time at read time and excludes a failing file rather than
 * seeding a broken one, the same graceful-degradation style every other Seeder source already
 * follows (a missing GVM file, a malformed CSV) - never fatal, always falls back to whatever
 * the caller does when a slug has no declared entry.
 *
 * **No merge, no precedence, one file, one record** - except `mode: add`, which is the one
 * place two files describe the same block. `reference/CATALOG-JSON-FORMAT.md` §4b only
 * sketched `add` for trait lists ("merges its items in on top" - a flat list concatenation);
 * `merge_add_variant()` extends that same idea to `tiered_power`'s two-level shape: a variant
 * family whose `name` already exists in the base has its `elder` picks unioned in per rank
 * (Werewolf/Fera's Wyld West and Dark Ages packets, which re-use every base family name), and
 * a variant family with no match in the base is appended whole (Dark Ages'/2nd ed.'s
 * `<Family> (Dark Ages)` families, which never collide with a base name at all). Both shapes
 * are real, measured against the authored files, not guessed.
 *
 * **Selection is not built here.** Which variant a chronicle sees (`format §4b`'s
 * `be_games.settings.catalog_variants`) is a read-time architecture question - where the
 * lookup composes with `Creature_Stack::resolve()`/`Schema_Block::find_for_game()`'s existing
 * fork precedence - and is flagged as an owner question rather than guessed at here. This
 * class only makes every declared file, base or variant, a complete and correctly-shaped
 * seeded row under its own slug; `wyldwest-werewolf_gifts` seeds as a *complete* 27-family
 * catalog (base + packet unioned), ready for a future mechanism to point a chronicle at,
 * never as the packet's own 20 partial families alone.
 */
class Catalog_Reader {

	/** Default catalog root, relative to this file. */
	const DEFAULT_ROOT = __DIR__ . '/../../data/catalog';

	/**
	 * Per-request cache, keyed by root path. `load()` is called from several independent
	 * places within one request/process (`get_blocks_to_seed()`, and any test or future
	 * caller reading `stacks_to_seed()`/`templates_to_seed()` alongside it) - 170 files
	 * decoded and validated once rather than once per call is the difference between a
	 * single seed pass and, measured against the real unit suite, dozens of redundant
	 * full-directory scans in one PHP process.
	 *
	 * @var array<string,array>
	 */
	private static array $cache = [];

	/**
	 * Whether a declared catalog exists at all. `Seeder` falls back to the GVM path entirely
	 * when this is false - a fresh checkout before 1.3.0/1.3.1 land, or any environment
	 * that has not shipped `data/catalog/` yet.
	 */
	public static function available( string $root = self::DEFAULT_ROOT ): bool {
		return is_dir( $root . '/blocks' );
	}

	/**
	 * Every `.json` file under $root, sorted for deterministic processing order.
	 *
	 * @return string[]
	 */
	private static function scan_files( string $root ): array {
		if ( ! is_dir( $root ) ) {
			return [];
		}
		$files = [];
		$it    = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $it as $file ) {
			if ( $file->isFile() && strtolower( $file->getExtension() ) === 'json' ) {
				$files[] = $file->getPathname();
			}
		}
		sort( $files );
		return $files;
	}

	/**
	 * Decodes and validates every file under $root, grouped by `kind`. A file that fails
	 * `Catalog_Validator` is dropped from the result and logged - never fatal. Excluding it
	 * here means a caller's own slug-preference merge simply finds no declared entry for
	 * that slug and falls back to whatever it already does when a slug is undeclared.
	 *
	 * @return array{blocks:array<string,array>,stacks:array<string,array>,templates:array<string,array>,presets:array<string,array>,errors:string[]}
	 */
	public static function load( string $root = self::DEFAULT_ROOT ): array {
		if ( isset( self::$cache[ $root ] ) ) {
			return self::$cache[ $root ];
		}

		$result = [ 'blocks' => [], 'stacks' => [], 'templates' => [], 'presets' => [], 'errors' => [] ];

		$files = self::scan_files( $root );
		if ( $files === [] ) {
			self::$cache[ $root ] = $result;
			return $result;
		}

		$decoded     = [];
		$block_slugs = [];
		$stack_slugs = [];

		foreach ( $files as $path ) {
			$raw  = (string) file_get_contents( $path );
			$data = json_decode( $raw, true );
			$stem = pathinfo( $path, PATHINFO_FILENAME );

			if ( json_last_error() !== JSON_ERROR_NONE || ! is_array( $data ) ) {
				$result['errors'][] = sprintf( '%s: not valid JSON', $path );
				continue;
			}

			$decoded[ $path ] = [
				'stem' => $stem,
				'data' => $data,
			];

			$slug = (string) ( $data['slug'] ?? $stem );
			if ( ( $data['kind'] ?? '' ) === 'block' ) {
				$block_slugs[] = $slug;
			} elseif ( ( $data['kind'] ?? '' ) === 'stack' ) {
				$stack_slugs[] = $slug;
			}
		}

		foreach ( $decoded as $path => $entry ) {
			$stem = $entry['stem'];
			$data = $entry['data'];

			$errors = array_merge(
				Catalog_Validator::validate_file( $data, $stem ),
				Catalog_Validator::validate_references( $data, $stem, $block_slugs, $stack_slugs )
			);
			if ( $errors !== [] ) {
				$result['errors'][] = sprintf( '%s: %s', $path, implode( '; ', $errors ) );
				continue;
			}

			$kind = (string) ( $data['kind'] ?? '' );
			$slug = (string) ( $data['slug'] ?? $stem );

			if ( $kind === 'block' ) {
				$result['blocks'][ $slug ] = $data;
			} elseif ( $kind === 'stack' ) {
				$result['stacks'][ $slug ] = $data;
			} elseif ( $kind === 'template' ) {
				$result['templates'][ $slug ] = $data;
			} elseif ( $kind === 'preset' ) {
				$result['presets'][ $slug ] = $data;
			}
		}

		if ( $result['errors'] !== [] ) {
			foreach ( $result['errors'] as $error ) {
				error_log( 'Beyond Elysium: declared catalog file skipped - ' . $error );
			}
		}

		self::$cache[ $root ] = $result;
		return $result;
	}

	/**
	 * Clears the per-root cache. Production never needs this - the catalog on disk does not
	 * change mid-request - but a test pointing `$root` at a temporary fixture directory
	 * across multiple assertions needs a way to stop an earlier call's cached result from
	 * masking a later fixture change at the same path.
	 */
	public static function reset_cache(): void {
		self::$cache = [];
	}

	/**
	 * Every declared block file, base and variant alike, as a `Schema_Block`-ready array
	 * keyed by slug. A `mode: add` variant is folded onto its base via
	 * {@see merge_add_variant()} before being seeded under its *own* slug, so it is a
	 * complete, usable catalog on its own rather than the packet's partial content alone. A
	 * `mode: replace` variant, or a base file, is already complete and seeds as decoded.
	 *
	 * @return array<string,array{slug:string,name:string,section_type:string,definition:array,is_system:int,created_by:int}>
	 */
	public static function blocks_to_seed( string $root = self::DEFAULT_ROOT ): array {
		$catalog = self::load( $root );
		$blocks  = [];

		foreach ( $catalog['blocks'] as $slug => $data ) {
			$section_type = (string) ( $data['section_type'] ?? '' );
			$definition   = (array) ( $data['definition'] ?? [] );
			$variant      = is_array( $data['variant'] ?? null ) ? $data['variant'] : null;

			if ( $variant !== null && ( $variant['mode'] ?? '' ) === 'add' ) {
				$base_slug = (string) ( $variant['of'] ?? '' );
				$base      = $catalog['blocks'][ $base_slug ] ?? null;
				if ( $base === null ) {
					// The base this variant adds to isn't a declared file (missing, or itself
					// failed validation) - nothing to merge onto, so this variant is skipped
					// rather than seeded as a broken partial catalog.
					error_log( "Beyond Elysium: catalog variant \"{$slug}\" adds to \"{$base_slug}\", which has no valid declared file - skipped." );
					continue;
				}
				$definition = self::merge_add_variant( $section_type, (array) $base['definition'], $definition );
			}

			$definition = self::apply_definition_defaults( $section_type, $definition );
			if ( $section_type === 'tiered_power' ) {
				$definition = self::bridge_out_of_type_modifier( $definition );
			}

			$block = [
				'slug'         => $slug,
				'name'         => (string) ( $data['name'] ?? $slug ),
				'section_type' => $section_type,
				'definition'   => $definition,
				'is_system'    => 1,
				'created_by'   => 0,
			];

			// Not part of `definition` at all - a real `schema_blocks` column
			// (`Schema_Block::create()`'s own `storyteller_only`), so it is only ever
			// carried when a file states it (`npc-roleplaying-notes`, the one declared
			// block that needs it) rather than forced onto every other block's default of 0.
			if ( array_key_exists( 'storyteller_only', $data ) ) {
				$block['storyteller_only'] = $data['storyteller_only'] ? 1 : 0;
			}

			$blocks[ $slug ] = $block;
		}

		return $blocks;
	}

	/**
	 * Folds a `mode: add` variant's content onto its base definition.
	 *
	 * `trait_list`: a flat concatenation - `items` from the base, then the variant's, in that
	 * order. Every authored `add` variant of a trait_list block (`owbn-kueijin_techniques`)
	 * has zero name overlap with its base, so this is exactly "merges its items in on top",
	 * format §4b's own words, with nothing to reconcile.
	 *
	 * `tiered_power`: matched by family `name`. A variant family whose name already exists in
	 * the base (every Werewolf/Fera packet family - `Homid`, `Bone Gnawers`, ...) has its
	 * `elder` picks unioned into the base family's own `elder`, per rank - never its `levels`,
	 * since a ladder's length is fixed by `_meta.ladder` and no authored `add` variant carries
	 * ladder-rank levels at all (measured). A variant family with no match in the base (every
	 * Dark Ages/2nd-ed. `<Family> (Dark Ages)` family, which never collides with a base name)
	 * is appended whole, unchanged.
	 *
	 * @param array<string,mixed> $base_definition
	 * @param array<string,mixed> $variant_definition
	 * @return array<string,mixed>
	 */
	public static function merge_add_variant( string $section_type, array $base_definition, array $variant_definition ): array {
		if ( $section_type === 'trait_list' ) {
			$merged            = $base_definition;
			$merged['items']   = array_merge(
				(array) ( $base_definition['items'] ?? [] ),
				(array) ( $variant_definition['items'] ?? [] )
			);
			return $merged;
		}

		if ( $section_type === 'tiered_power' ) {
			$merged  = $base_definition;
			$powers  = (array) ( $merged['powers'] ?? [] );
			$by_name = [];
			foreach ( $powers as $i => $power ) {
				if ( is_array( $power ) && is_string( $power['name'] ?? null ) ) {
					$by_name[ $power['name'] ] = $i;
				}
			}

			foreach ( (array) ( $variant_definition['powers'] ?? [] ) as $variant_power ) {
				if ( ! is_array( $variant_power ) || ! is_string( $variant_power['name'] ?? null ) ) {
					continue;
				}
				$name = $variant_power['name'];

				if ( ! isset( $by_name[ $name ] ) ) {
					// No matching base family - a whole new one, appended unchanged.
					$powers[] = $variant_power;
					continue;
				}

				$i          = $by_name[ $name ];
				$base_power = is_array( $powers[ $i ] ) ? $powers[ $i ] : [];
				foreach ( (array) ( $variant_power['elder'] ?? [] ) as $rank => $picks ) {
					$base_power['elder'][ $rank ] = array_merge(
						(array) ( $base_power['elder'][ $rank ] ?? [] ),
						(array) $picks
					);
				}
				$powers[ $i ] = $base_power;
			}

			$merged['powers'] = $powers;
			return $merged;
		}

		return $base_definition;
	}

	/**
	 * Defaults a definition's flags the same way `make_tiered_power_block()`/
	 * `make_trait_list_block()` already do for a block built from the GVM - only ever filling
	 * a genuinely *absent* key, never overwriting an explicit `false` a file actually declares
	 * (demon-rituals.json and kueijin-rites.json both declare `"allow_custom": false` for
	 * real; that is data, not a gap).
	 *
	 * `atomic` and `player_order` need no entry here: neither `make_tiered_power_block()` nor
	 * `make_trait_list_block()` sets either in its own base defaults (only specific hardcoded
	 * callers pass them as `$extra`), and every consumer already reads an absent flag as
	 * `false` (`Change_Validator`'s `! empty( $definition->atomic )`,
	 * `TieredPowerEditor.tsx`'s `definition.player_order &&`) - so leaving them exactly as the
	 * file states them already matches "no extra override", the GVM path's own default state.
	 *
	 * @param array<string,mixed> $definition
	 * @return array<string,mixed>
	 */
	public static function apply_definition_defaults( string $section_type, array $definition ): array {
		if ( $section_type === 'tiered_power' ) {
			return array_merge( [
				'sequential'   => true,
				'allow_custom' => true,
			], $definition );
		}

		if ( $section_type === 'trait_list' ) {
			return array_merge( [
				'allow_custom'    => true,
				'allow_multiples' => false,
			], $definition );
		}

		return $definition;
	}

	/**
	 * Bridges `_meta.out_of_type` (1.2.10's per-rank expression) back onto the deprecated
	 * flat `out_of_type_cost_modifier` scalar `Cost_Engine` still reads.
	 *
	 * **Why this exists.** Owner ruling for this release is "ingest now, engine later" -
	 * `Cost_Engine` itself is untouched, and reading `_meta.out_of_type`'s per-rank
	 * expressions directly is explicitly 1.4.0's job. But no declared file writes the
	 * deprecated scalar at all (only `_meta.out_of_type`), so ingesting a declared
	 * tiered_power block as-is would silently **zero out** an out-of-type surcharge that
	 * works today - measured on `vampire-disciplines`, the one block whose surcharge
	 * currently fires (D75): its GVM-built definition carries `out_of_type_cost_modifier:
	 * 1`, its declared file carries only `_meta.out_of_type: {"basic":"+1", ...}`, and
	 * without this bridge `Cost_Engine::in_type_check()`'s `(int) ($definition-
	 * >out_of_type_cost_modifier ?? 0)` would read the now-missing key as `0` - a real
	 * regression, not a neutral gap, caught by `CostEngineHeldPricingTest` failing first.
	 *
	 * **Only the lossless case is bridged.** When every rank in `_meta.out_of_type` carries
	 * the identical flat `"+N"` expression, that is exactly what the deprecated scalar
	 * already represented, so `out_of_type_cost_modifier` is set to `N` - Vampire's case,
	 * and in fact every genre except Mage/Demon. A scaling (`"+1"/"+2"/"+3"`) or
	 * multiplicative (`"×2"`) expression cannot be represented by one flat integer at all;
	 * for those this deliberately leaves the scalar unset, which is the same inert state
	 * D75 already measured for them under the old scalar system too (Mage's surcharge has
	 * never correctly fired) - no worse than today, and not a guess at what 1.4.0's real
	 * expression parser should do. An already-present explicit `out_of_type_cost_modifier`
	 * is never overwritten.
	 *
	 * @param array<string,mixed> $definition
	 * @return array<string,mixed>
	 */
	private static function bridge_out_of_type_modifier( array $definition ): array {
		if ( array_key_exists( 'out_of_type_cost_modifier', $definition ) ) {
			return $definition;
		}

		$meta = is_array( $definition['_meta'] ?? null ) ? $definition['_meta'] : null;
		$expr = $meta !== null && is_array( $meta['out_of_type'] ?? null ) ? $meta['out_of_type'] : null;
		if ( $expr === null || $expr === [] ) {
			return $definition;
		}

		$values = array_values( array_unique( array_values( $expr ) ) );
		if ( count( $values ) === 1 && is_string( $values[0] ) && preg_match( '/^\+(\d+)$/', $values[0], $m ) ) {
			$definition['out_of_type_cost_modifier'] = (int) $m[1];
		}

		return $definition;
	}

	/**
	 * Every declared stack file as a `Creature_Stack`-ready array keyed by slug, in the same
	 * `{slug,name,game_line,is_system,stack_definition,creation_rules}` shape
	 * `Seeder::get_stacks_to_seed()` already hand-writes. Built for shape parity
	 * (`reference/CATALOG-JSON-FORMAT.md` §5) and covered by its own round-trip test; not yet
	 * wired into `Seeder::get_stacks_to_seed()`'s own call path - see the 1.3.2 release doc.
	 *
	 * @return array<string,array>
	 */
	public static function stacks_to_seed( string $root = self::DEFAULT_ROOT ): array {
		$catalog = self::load( $root );
		$stacks  = [];

		foreach ( $catalog['stacks'] as $slug => $data ) {
			$definition = (array) ( $data['definition'] ?? [] );

			$stacks[ $slug ] = [
				'slug'             => $slug,
				'name'             => (string) ( $data['name'] ?? $slug ),
				'game_line'        => (string) ( $definition['game_line'] ?? 'met' ),
				'is_system'        => 1,
				'stack_definition' => [
					'sections'            => $definition['sections'] ?? [],
					'display_preferences' => $definition['display_preferences'] ?? [],
				],
				'creation_rules'   => $definition['creation_rules'] ?? [],
			];
		}

		return $stacks;
	}

	/**
	 * Every declared template file as a `Template`-ready array keyed by its `<stack>.<type>`
	 * slug. A declared template's `definition` (`version`/`columns`/`sections`) is already
	 * exactly `Template::create()`'s own `layout` shape - direct decode, no reshaping. Built
	 * for shape parity (`reference/CATALOG-JSON-FORMAT.md` §6) and covered by its own
	 * round-trip test; not yet wired into `Seeder::seed_default_templates()`'s own call path -
	 * see the 1.3.2 release doc.
	 *
	 * @return array<string,array>
	 */
	public static function templates_to_seed( string $root = self::DEFAULT_ROOT ): array {
		$catalog   = self::load( $root );
		$templates = [];

		foreach ( $catalog['templates'] as $slug => $data ) {
			$parts = explode( '.', $slug, 2 );
			if ( count( $parts ) !== 2 ) {
				continue;
			}
			[ $stack_slug, $template_type ] = $parts;

			$templates[ $slug ] = [
				'stack_slug'    => $stack_slug,
				'name'          => (string) ( $data['name'] ?? $slug ),
				'template_type' => $template_type,
				'layout'        => (array) ( $data['definition'] ?? [] ),
				'is_system'     => 1,
				'created_by'    => 0,
			];
		}

		return $templates;
	}
}
