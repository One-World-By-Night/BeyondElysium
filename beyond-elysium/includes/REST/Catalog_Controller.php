<?php

namespace BeyondElysium\REST;

use BeyondElysium\Models\Game;
use BeyondElysium\Models\Schema_Block;
use BeyondElysium\Services\Catalog_Entry;
use BeyondElysium\Services\Catalog_Holders;
use BeyondElysium\Services\Catalog_Screen;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for a chronicle's Catalog screen: the sections that take entries of the chronicle's own, and the
 * entry-sized writes to them. Every write lands on the chronicle's own copy of a block; the book and every other
 * chronicle are never touched.
 */
class Catalog_Controller extends Base_Controller {

	protected $rest_base = 'catalog';

	/**
	 * Registers the Catalog routes.
	 */
	public function register_routes(): void {
		// The item catalog's own route, /items/catalog, is not a chronicle's.
		$base  = '/(?P<game_slug>(?!items/)[a-z0-9\-]+)/' . $this->rest_base;
		$block = $base . '/(?P<block_slug>[a-z0-9\-_]+)';
		$gate  = $this->permission( 'be_manage_schemas' );

		register_rest_route( $this->namespace, $base, [
			[ 'methods' => 'GET', 'callback' => [ $this, 'get_sections' ], 'permission_callback' => $gate ],
		] );
		register_rest_route( $this->namespace, $block, [
			[ 'methods' => 'GET', 'callback' => [ $this, 'get_entries' ], 'permission_callback' => $gate ],
		] );
		register_rest_route( $this->namespace, $block . '/entries', [
			[ 'methods' => 'POST', 'callback' => [ $this, 'add_entry' ], 'permission_callback' => $gate ],
			[ 'methods' => 'PUT', 'callback' => [ $this, 'change_entry' ], 'permission_callback' => $gate ],
			[ 'methods' => 'DELETE', 'callback' => [ $this, 'remove_entry' ], 'permission_callback' => $gate ],
		] );
		register_rest_route( $this->namespace, $block . '/entries/revert', [
			[ 'methods' => 'POST', 'callback' => [ $this, 'revert_entry' ], 'permission_callback' => $gate ],
		] );
		register_rest_route( $this->namespace, $block . '/in-type', [
			[ 'methods' => 'PUT', 'callback' => [ $this, 'set_in_type' ], 'permission_callback' => $gate ],
		] );
	}

	/**
	 * The sections that take entries, by creature type.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_sections( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		return $this->success( [ 'creature_types' => Catalog_Screen::sections( $game ) ] );
	}

	/**
	 * A section's entries.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_entries( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$listing = Catalog_Screen::entries( $game, (string) $request['block_slug'], (string) $request->get_param( 'stack' ) );
		return null === $listing ? $this->no_section() : $this->success( $listing );
	}

	/**
	 * Adds one entry.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function add_entry( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$kind = $this->kind_of( $game, (string) $request['block_slug'] );
		if ( null === $kind ) {
			return $this->no_section();
		}
		$fields = $this->fields( $kind, $request->get_json_params() ?: $request->get_params() );
		$result = Catalog_Entry::ITEM === $kind
			? Catalog_Entry::save_item( (string) $request['block_slug'], (string) $game->slug, null, $fields, null )
			: Catalog_Entry::save_family( (string) $request['block_slug'], (string) $game->slug, null, $fields, null );
		return $this->answer( $result, $game, $request, $this->name_after( $fields, $result ), 201 );
	}

	/**
	 * Changes one entry, sending only the fields that changed and the hash it was opened with.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function change_entry( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$kind = $this->kind_of( $game, (string) $request['block_slug'] );
		if ( null === $kind ) {
			return $this->no_section();
		}
		$params = $request->get_json_params() ?: $request->get_params();
		$name   = (string) ( $params['name'] ?? '' );
		$etag   = isset( $params['etag'] ) ? (string) $params['etag'] : null;
		$fields = $this->fields( $kind, is_array( $params['changes'] ?? null ) ? $params['changes'] : [] );
		$result = Catalog_Entry::ITEM === $kind
			? Catalog_Entry::save_item( (string) $request['block_slug'], (string) $game->slug, $name, $fields, $etag )
			: Catalog_Entry::save_family( (string) $request['block_slug'], (string) $game->slug, $name, $fields, $etag );
		return $this->answer( $result, $game, $request, $this->name_after( $fields, $result, $name ) );
	}

	/**
	 * Takes away one entry the chronicle added.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function remove_entry( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		if ( null === $this->kind_of( $game, (string) $request['block_slug'] ) ) {
			return $this->no_section();
		}
		$params = $request->get_json_params() ?: $request->get_params();
		$result = Catalog_Entry::remove_entry(
			(string) $request['block_slug'],
			(string) $game->slug,
			(string) ( $params['name'] ?? '' ),
			isset( $params['etag'] ) ? (string) $params['etag'] : null
		);
		if ( Catalog_Entry::REMOVED !== $result['status'] ) {
			return $this->refusal( $result );
		}
		return $this->success( [ 'removed' => true ] );
	}

	/**
	 * Puts a book entry's price and description back as the book has them.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function revert_entry( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		if ( null === $this->kind_of( $game, (string) $request['block_slug'] ) ) {
			return $this->no_section();
		}
		$params = $request->get_json_params() ?: $request->get_params();
		$name   = (string) ( $params['name'] ?? '' );
		$result = Catalog_Entry::revert_entry( (string) $request['block_slug'], (string) $game->slug, $name );
		return $this->answer( $result, $game, $request, $name );
	}

	/**
	 * Files a family the chronicle added under in-clan keys and facet values, as its section's tests read them.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function set_in_type( $request ) {
		$game = $this->resolve_game( (string) $request['game_slug'] );
		if ( is_wp_error( $game ) ) {
			return $game;
		}
		$block_slug = (string) $request['block_slug'];
		if ( null === $this->kind_of( $game, $block_slug ) ) {
			return $this->no_section();
		}
		$params = $request->get_json_params() ?: $request->get_params();
		$stack  = (string) ( $params['stack'] ?? '' );
		$name   = (string) ( $params['name'] ?? '' );

		$section = $this->section_of( $game, $stack, $block_slug );
		if ( null === $section ) {
			return $this->no_section();
		}

		$in_clan = null;
		if ( isset( $params['in_clan'] ) && is_array( $params['in_clan'] ) ) {
			$offered = array_column( $section['in_type']['maps'], 'map' );
			$in_clan = [];
			foreach ( $params['in_clan'] as $map => $keys ) {
				if ( ! in_array( (string) $map, $offered, true ) || ! is_array( $keys ) ) {
					return $this->error( 'invalid_param', __( 'That section does not read an in-clan list by that name.', 'beyond-elysium' ), 400 );
				}
				$in_clan[ (string) $map ] = array_map( 'strval', $keys );
			}
		}

		$categories = null;
		if ( isset( $params['category_values'] ) && is_array( $params['category_values'] ) ) {
			$axes       = array_column( $section['in_type']['facets'], 'options', 'facet' );
			$categories = [];
			foreach ( $params['category_values'] as $axis => $value ) {
				$value = trim( (string) $value );
				if ( ! isset( $axes[ (string) $axis ] ) || ( '' !== $value && ! in_array( $value, $axes[ (string) $axis ], true ) ) ) {
					return $this->error( 'invalid_param', __( 'That is not a value this section files a family under.', 'beyond-elysium' ), 400 );
				}
				$categories[ (string) $axis ] = $value;
			}
		}

		$result = Catalog_Entry::set_in_type(
			$block_slug,
			(string) $game->slug,
			$name,
			$in_clan,
			$categories,
			isset( $params['etag'] ) ? (string) $params['etag'] : null
		);
		return $this->answer( $result, $game, $request, $name );
	}

	/**
	 * The entry a successful write left, read back as the screen reads it, or the refusal.
	 *
	 * @param array{status:string,detail?:mixed,entry?:object} $result
	 * @return \WP_REST_Response|\WP_Error
	 */
	private function answer( array $result, object $game, \WP_REST_Request $request, string $name, int $status = 200 ) {
		if ( ! in_array( $result['status'], [ Catalog_Entry::ADDED, Catalog_Entry::CHANGED, Catalog_Entry::PRESENT ], true ) ) {
			return $this->refusal( $result );
		}
		$listing = Catalog_Screen::entries( $game, (string) $request['block_slug'], (string) $request->get_param( 'stack' ) );
		foreach ( $listing['entries'] ?? [] as $entry ) {
			if ( 0 === strcasecmp( (string) $entry['name'], $name ) ) {
				return $this->success( [ 'entry' => $entry ], $status );
			}
		}
		return $this->success( [ 'entry' => null ], $status );
	}

	/**
	 * The name an entry is read back under after a write: the new name when the write gave one, else the old.
	 *
	 * @param array<string,mixed>                              $fields
	 * @param array{status:string,detail?:mixed,entry?:object} $result
	 */
	private function name_after( array $fields, array $result, string $before = '' ): string {
		return isset( $result['entry']->name ) ? (string) $result['entry']->name : ( isset( $fields['name'] ) ? (string) $fields['name'] : $before );
	}

	/**
	 * The error a refused write stands for.
	 *
	 * @param array{status:string,detail?:mixed,entry?:object} $result
	 */
	private function refusal( array $result ): \WP_Error {
		$detail = $result['detail'] ?? null;
		switch ( $result['status'] ) {
			case Catalog_Entry::MISSING:
				return $this->error( 'entry_not_found', __( 'That entry is not in this chronicle\'s catalog.', 'beyond-elysium' ), 404 );
			case Catalog_Entry::STALE:
				return $this->error( 'entry_changed', __( 'Someone changed this since you opened it.', 'beyond-elysium' ), 409 );
			case Catalog_Entry::EXISTS:
				return 'power_name' === $detail
					? $this->error( 'entry_exists', __( 'Two powers in this family have the same name.', 'beyond-elysium' ), 409 )
					: $this->error( 'entry_exists', __( 'An entry with that name is already there.', 'beyond-elysium' ), 409 );
			case Catalog_Entry::IN_USE:
				$count = is_array( $detail ) ? $detail : [ 'characters' => 0, 'changes' => 0 ];
				return $this->error( 'entry_in_use', (string) Catalog_Holders::message( $count ), 409 );
			case Catalog_Entry::BOOK_FIELD:
				return $this->book_refusal( (string) $detail );
			case Catalog_Entry::INVALID:
				return $this->invalid( (string) $detail );
		}
		return $this->error( 'save_failed', __( 'The change could not be saved.', 'beyond-elysium' ), 500 );
	}

	private function book_refusal( string $detail ): \WP_Error {
		$messages = [
			'remove'      => __( 'A book entry cannot be taken away here. An approval rule is what disallows one.', 'beyond-elysium' ),
			'revert'      => __( 'Only an entry from the book has a book version to go back to.', 'beyond-elysium' ),
			'in_type'     => __( 'Which clans take a book entry in-clan is changed in wp-admin\'s Schema Blocks.', 'beyond-elysium' ),
			'remove_pick' => __( 'A power from the book cannot be taken away here.', 'beyond-elysium' ),
		];
		return $this->error(
			'book_entry_field',
			$messages[ $detail ] ?? __( 'A book entry can only have its price and description changed here.', 'beyond-elysium' ),
			400
		);
	}

	private function invalid( string $detail ): \WP_Error {
		if ( 'invalid_price' === $detail ) {
			return $this->error(
				'invalid_price',
				/* translators: %d: the most a price may be, 500 */
				sprintf( __( 'A price is a whole number, a range such as 1-3 or a choice such as 1 or 3, with no number above %d.', 'beyond-elysium' ), \BeyondElysium\Services\Cost_Engine::MAX_CUSTOM_PRICE ),
				400
			);
		}
		$messages = [
			'name_required'     => __( 'Give it a name.', 'beyond-elysium' ),
			'invalid_rank'      => __( 'That is not a rank this list uses.', 'beyond-elysium' ),
			'invalid_pick'      => __( 'Each power needs a name and a rank this section has above its levels.', 'beyond-elysium' ),
			'invalid_level'     => __( 'That is not a level of this section.', 'beyond-elysium' ),
			'invalid_tradition' => __( 'That is not a tradition this list has.', 'beyond-elysium' ),
			'invalid_in_clan'   => __( 'That is not a key of the list this section reads.', 'beyond-elysium' ),
		];
		return $this->error( 'invalid_param', $messages[ $detail ] ?? __( 'Some of what was sent is not usable.', 'beyond-elysium' ), 400 );
	}

	/**
	 * What kind of entries the chronicle's section of a block takes, or null when it is not a catalog section.
	 */
	private function kind_of( object $game, string $block_slug ): ?string {
		$block = Schema_Block::find_for_game( $block_slug, (string) $game->slug );
		return $block && ! empty( $block->definition->allow_custom ) ? Catalog_Entry::section_kind( $block ) : null;
	}

	/**
	 * The section descriptor of one creature type's section of a block.
	 *
	 * @return array<string,mixed>|null
	 */
	private function section_of( object $game, string $stack, string $block_slug ): ?array {
		foreach ( Catalog_Screen::sections( $game ) as $group ) {
			if ( $group['stack'] !== $stack ) {
				continue;
			}
			foreach ( $group['sections'] as $section ) {
				if ( $section['block_slug'] === $block_slug ) {
					return $section;
				}
			}
		}
		return null;
	}

	/**
	 * Only the fields a kind of entry takes, from what a request sent.
	 *
	 * @param array<string,mixed> $sent
	 * @return array<string,mixed>
	 */
	private function fields( string $kind, array $sent ): array {
		$allowed = Catalog_Entry::ITEM === $kind
			? [ 'name', 'group', 'tier', 'cost', 'description' ]
			: [ 'name', 'description', 'levels', 'picks', 'traditions' ];
		return array_intersect_key( $sent, array_flip( $allowed ) );
	}

	private function no_section(): \WP_Error {
		return $this->error( 'catalog_section_not_found', __( 'This chronicle has no such catalog section.', 'beyond-elysium' ), 404 );
	}

	/**
	 * @return object|\WP_Error
	 */
	protected function resolve_game( string $game_slug ) {
		$game = Game::find_by_slug( $game_slug );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}
		return $game;
	}
}
