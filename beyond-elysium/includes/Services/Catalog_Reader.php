<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Reads the declared catalog at `data/catalog/**` and produces the plain arrays `Seeder::seed_schema_blocks()` /
 * `seed_creature_stacks()` / `seed_default_templates()` already expect.
 */
class Catalog_Reader {

	/**
	 * Default catalog root, relative to this file.
	 */
	const DEFAULT_ROOT = __DIR__ . '/../../data/catalog';

	/**
	 * Per-request cache, keyed by root path.
	 *
	 * @var array<string,array>
	 */
	private static array $cache = [];

	/**
	 * Whether a declared catalog exists at all.
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
	 * Decodes and validates every file under $root, grouped by `kind`.
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
	 * Clears the per-root cache.
	 */
	public static function reset_cache(): void {
		self::$cache = [];
	}

	/**
	 * Every declared block file, base and variant alike, as a `Schema_Block`-ready array keyed by slug.
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

			// storyteller_only is a schema_blocks column, carried only when the file states it.
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
	 * Defaults a definition's flags the same way `make_tiered_power_block()`/ `make_trait_list_block()` already do for a
	 * block built from the GVM.
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
	 * Bridges `_meta.out_of_type`, the per-rank expression, back onto the deprecated flat `out_of_type_cost_modifier`
	 * scalar `Cost_Engine` still reads.
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
	 * Every declared stack file as a `Creature_Stack`-ready array keyed by slug.
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
					'sections'            => self::strip_replaces( (array) ( $definition['sections'] ?? [] ) ),
					'display_preferences' => $definition['display_preferences'] ?? [],
				],
				'creation_rules'   => $definition['creation_rules'] ?? [],
			];
		}

		return $stacks;
	}

	/**
	 * @param array<int,mixed> $sections
	 * @return array<int,mixed>
	 */
	private static function strip_replaces( array $sections ): array {
		foreach ( $sections as &$section ) {
			if ( is_array( $section ) ) {
				unset( $section['replaces'] );
			}
		}
		unset( $section );
		return $sections;
	}

	/**
	 * The retired->live block map each declared stack states via its sections' `replaces`, keyed by stack slug.
	 *
	 * @return array<string,array<string,string>> stack slug => [ retired slug => live slug ].
	 */
	public static function replacement_maps( string $root = self::DEFAULT_ROOT ): array {
		$catalog = self::load( $root );
		$maps    = [];

		foreach ( $catalog['stacks'] as $slug => $data ) {
			$map = [];
			foreach ( (array) ( $data['definition']['sections'] ?? [] ) as $section ) {
				if ( ! is_array( $section ) || ! is_array( $section['replaces'] ?? null ) ) {
					continue;
				}
				$live = (string) ( $section['block_slug'] ?? '' );
				if ( $live === '' ) {
					continue;
				}
				foreach ( $section['replaces'] as $old ) {
					if ( is_string( $old ) && $old !== '' ) {
						$map[ $old ] = $live;
					}
				}
			}
			if ( $map !== [] ) {
				$maps[ $slug ] = $map;
			}
		}

		return $maps;
	}

	/**
	 * Maps a replaced block slug to the block the given stack declares in its place: `met-abilities` becomes
	 * `vampire-abilities` for a Vampire and `fera-abilities` for a Fera or a Bete; a slug the stack does not replace
	 * comes back unchanged.
	 *
	 * @param string $stack_slug Creature type.
	 * @param string $slug       Block slug to map.
	 * @param string $root       Catalog directory.
	 */
	public static function current_slug( string $stack_slug, string $slug, string $root = self::DEFAULT_ROOT ): string {
		return self::replacement_maps( $root )[ $stack_slug ][ $slug ] ?? $slug;
	}

	/**
	 * Every declared template file as a `Template`-ready array keyed by its `<stack>.<type>` slug.
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
