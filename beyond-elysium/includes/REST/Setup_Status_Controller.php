<?php

namespace BeyondElysium\REST;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;

defined( 'ABSPATH' ) || exit;

/**
 * The Chronicle Setup checklist's status endpoint (GS-4,
 * guided-chronicle-setup-design.md §6.3-6.4): `GET /{game_slug}/setup-status`.
 *
 * Three deliberate differences from `Game_Stats_Controller`, the precedent
 * this shape otherwise follows exactly:
 *
 *  1. No transient. A checklist that shows "no Storytellers assigned" sixty
 *     seconds after one was assigned is worse than no checklist - every
 *     query here is a bounded COUNT(*) or a single-row read, so there is
 *     nothing worth caching.
 *  2. Capability `be_manage_characters` - staff: an administrator or editor
 *     site-wide, then narrowed by chronicle role to an HST or AST. `actionable`
 *     is still reported per row, so an AST reads the checklist read-only and
 *     an HST acts on the rows their own capability covers. It was
 *     `be_view_characters` until 1.3.2.2 - "the widest capability that still
 *     requires a real user" - which let any player read the chronicle's
 *     governance settings; Chronicle Setup is for staff (owner, 2026-09-23).
 *  3. No `ORDER BY` on `schema_blocks` (v0.21.29's own sort-buffer overflow,
 *     `Schema_Block.php`'s own doc comment) - the fork count is a bare
 *     `COUNT(*) WHERE game_slug = %s`.
 *
 * §5.5's rule, restated because it is the one most likely to be quietly
 * violated at build time: **no stored completion state of any kind.** Every
 * row's status is derived on read from the rows that actually exist.
 *
 * @see BE_PROCESS/design/guided-chronicle-setup-design.md §6.3, §6.4
 */
class Setup_Status_Controller extends Base_Controller {

	protected $rest_base = 'setup-status';

	public function register_routes(): void {
		register_rest_route( $this->namespace, '/(?P<game_slug>[a-z0-9\-]+)/setup-status', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_status' ],
				'permission_callback' => $this->permission( 'be_manage_characters' ),
			],
		] );
	}

	/**
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_status( $request ) {
		$game = Game::find_by_slug( $request['game_slug'] );
		if ( ! $game ) {
			return $this->error( 'game_not_found', __( 'Game not found.', 'beyond-elysium' ), 404 );
		}

		$items = [
			$this->row_creature_types( $game ),
			$this->row_storytellers( $game ),
			$this->row_new_character_approval( $game ),
			$this->row_front_end_pages( $game ),
			$this->row_characters( $game ),
			$this->row_approval_rules( $game ),
			$this->row_catalog_customisation( $game ),
			$this->row_sheet_templates( $game ),
			$this->row_downtime_and_rumors( $game ),
		];

		if ( $game->slug === 'be-demo' ) {
			$items[] = $this->row_demo_chronicle( $game );
		}

		$summary = [ 'attention' => 0, 'ok' => 0, 'info' => 0 ];
		foreach ( $items as $item ) {
			$summary[ $item['status'] ]++;
		}

		return $this->success( [ 'items' => $items, 'summary' => $summary ] );
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row_creature_types( object $game ): array {
		$enabled = $game->settings->enabled_stacks ?? null;
		$ok      = is_array( $enabled ) && ! empty( $enabled );

		return [
			'id'         => 'enabled_stacks',
			'status'     => $ok ? 'ok' : 'attention',
			'title'      => __( 'Creature types', 'beyond-elysium' ),
			'detail'     => $ok
				? sprintf(
					/* translators: %d: number of enabled creature types */
					__( '%d of 11 creature types are enabled.', 'beyond-elysium' ),
					count( $enabled )
				)
				: __( 'All 11 creature types are available. Narrow this to what your chronicle actually runs.', 'beyond-elysium' ),
			// Owner ruling, 1.0.0-checklist.md item 18: an HST sets this for their own
			// chronicle now, not a site administrator only.
			'fix'        => [ 'kind' => 'inline', 'capability' => 'be_manage_chronicle_setup' ],
			'actionable' => \BeyondElysium\Core\Authorization::can( 'be_manage_chronicle_setup' ),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row_storytellers( object $game ): array {
		$members    = Game_Member::for_game( (int) $game->id );
		$has_leader = false;
		foreach ( $members as $member ) {
			if ( in_array( $member->role, [ 'hst', 'ast' ], true ) ) {
				$has_leader = true;
				break;
			}
		}

		return [
			'id'         => 'storytellers',
			'status'     => $has_leader ? 'ok' : 'attention',
			'title'      => __( 'Storytellers', 'beyond-elysium' ),
			'detail'     => $has_leader
				? __( 'At least one Head or Assistant Storyteller is assigned.', 'beyond-elysium' )
				: __( 'No Storyteller is assigned to this chronicle yet.', 'beyond-elysium' ),
			'fix'        => [ 'kind' => 'link', 'href' => 'admin.php?page=beyond-elysium-chronicle-setup-hub&tab=access&game=' . rawurlencode( $game->slug ), 'capability' => 'be_manage_games' ],
			'actionable' => \BeyondElysium\Core\Authorization::can( 'be_manage_games' ),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row_new_character_approval( object $game ): array {
		$chosen = isset( $game->settings->require_new_character_approval );

		return [
			'id'         => 'require_new_character_approval',
			'status'     => $chosen ? 'ok' : 'attention',
			'title'      => __( 'New-character approval', 'beyond-elysium' ),
			'detail'     => $chosen
				? ( $game->settings->require_new_character_approval
					? __( 'A player-created character starts pending, awaiting Storyteller approval.', 'beyond-elysium' )
					: __( 'A player-created character starts active immediately, with no Storyteller review.', 'beyond-elysium' ) )
				: __( 'Not chosen yet. Today, unset means a new character goes active immediately with no Storyteller ever seeing it.', 'beyond-elysium' ),
			// Owner ruling, 1.0.0-checklist.md item 18: an HST sets this for their own
			// chronicle now, not a site administrator only.
			'fix'        => [ 'kind' => 'inline', 'capability' => 'be_manage_chronicle_setup' ],
			'actionable' => \BeyondElysium\Core\Authorization::can( 'be_manage_chronicle_setup' ),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row_front_end_pages( object $game ): array {
		// page-consolidation-design.md: the four fixed pages are chronicle-independent
		// (each resolves its own chronicle via a switcher, not a baked gameSlug), so this
		// checks their real existence directly rather than counting per-chronicle content -
		// the old LIKE-on-post_content check has nothing left to count under this model.
		$missing = [];
		foreach ( \BeyondElysium\Core\Page_Provisioner::PAGES as $slug => $page ) {
			if ( ! get_page_by_path( $slug, OBJECT, 'page' ) ) {
				$missing[] = $page['title'];
			}
		}

		return [
			'id'         => 'front_end_pages',
			'status'     => empty( $missing ) ? 'ok' : 'attention',
			'title'      => __( 'Front-end pages', 'beyond-elysium' ),
			'detail'     => empty( $missing )
				? __( 'My Chronicle, Storyteller Toolkit, and the print/verify pages are all provisioned.', 'beyond-elysium' )
				: sprintf( /* translators: %s: comma-separated list of missing page titles */ __( 'Missing: %s.', 'beyond-elysium' ), implode( ', ', $missing ) ),
			'fix'        => [ 'kind' => 'link', 'href' => 'admin.php?page=beyond-elysium-chronicle-setup-hub&tab=setup&provision_pages=1', 'capability' => 'be_manage_games' ],
			'actionable' => \BeyondElysium\Core\Authorization::can( 'be_manage_games' ),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row_characters( object $game ): array {
		$count = Character::count_for_game( $game->slug );

		return [
			'id'         => 'characters',
			'status'     => $count > 0 ? 'ok' : 'attention',
			'title'      => __( 'Characters', 'beyond-elysium' ),
			'detail'     => $count > 0
				? sprintf( /* translators: %d: number of characters */ __( '%d character(s) exist in this chronicle.', 'beyond-elysium' ), $count )
				: __( 'No characters exist in this chronicle yet.', 'beyond-elysium' ),
			'fix'        => [ 'kind' => 'link', 'href' => 'admin.php?page=beyond-elysium-characters&game_slug=' . rawurlencode( $game->slug ), 'capability' => 'be_manage_characters' ],
			'actionable' => \BeyondElysium\Core\Authorization::can( 'be_manage_characters' ),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row_approval_rules( object $game ): array {
		return [
			'id'         => 'approval_rules',
			'status'     => 'info',
			'title'      => __( 'Approval rules', 'beyond-elysium' ),
			'detail'     => __( 'Everything requires Storyteller approval until a rule says otherwise.', 'beyond-elysium' ),
			'fix'        => [ 'kind' => 'link', 'href' => 'admin.php?page=beyond-elysium-system-config&tab=approval-rules&game=' . rawurlencode( $game->slug ), 'capability' => 'be_manage_approval_rules' ],
			'actionable' => \BeyondElysium\Core\Authorization::can( 'be_manage_approval_rules' ),
		];
	}

	/**
	 * No `ORDER BY` - v0.21.29's own sort-buffer overflow against the real
	 * catalog's largest blocks; a bare `COUNT(*)` never triggers it.
	 *
	 * @return array<string,mixed>
	 */
	private function row_catalog_customisation( object $game ): array {
		global $wpdb;
		$table = Manager::table( 'schema_blocks' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE game_slug = %s", $game->slug ) );

		return [
			'id'         => 'catalog_customisation',
			'status'     => 'info',
			'title'      => __( 'Catalog customisation', 'beyond-elysium' ),
			'detail'     => $count > 0
				? sprintf( /* translators: %d: number of forked schema blocks */ __( '%d schema block(s) are customised for this chronicle.', 'beyond-elysium' ), $count )
				: __( 'This chronicle uses the shared catalog with no customisation.', 'beyond-elysium' ),
			'fix'        => [ 'kind' => 'link', 'href' => 'admin.php?page=beyond-elysium-system-config&tab=schema-blocks&game_slug=' . rawurlencode( $game->slug ), 'capability' => 'be_manage_schemas' ],
			'actionable' => \BeyondElysium\Core\Authorization::can( 'be_manage_schemas' ),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row_sheet_templates( object $game ): array {
		global $wpdb;
		$table = Manager::table( 'templates' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE game_id = %d", (int) $game->id ) );

		return [
			'id'         => 'sheet_templates',
			'status'     => 'info',
			'title'      => __( 'Sheet templates', 'beyond-elysium' ),
			'detail'     => $count > 0
				? sprintf( /* translators: %d: number of overridden templates */ __( '%d template(s) are overridden for this chronicle.', 'beyond-elysium' ), $count )
				: __( 'This chronicle uses the shared templates. Customize one to give this chronicle its own layout.', 'beyond-elysium' ),
			'fix'        => [ 'kind' => 'link', 'href' => 'admin.php?page=beyond-elysium-system-config&tab=templates&game_slug=' . rawurlencode( $game->slug ), 'capability' => 'be_manage_templates' ],
			'actionable' => \BeyondElysium\Core\Authorization::can( 'be_manage_templates' ),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row_downtime_and_rumors( object $game ): array {
		$apr = $game->settings->apr ?? null;

		return [
			'id'         => 'downtime_and_rumors',
			'status'     => 'info',
			'title'      => __( 'Downtime actions & rumors', 'beyond-elysium' ),
			'detail'     => $apr !== null
				? __( 'This chronicle has its own downtime and rumor settings.', 'beyond-elysium' )
				: __( 'Using Beyond Elysium\'s default downtime and rumor settings.', 'beyond-elysium' ),
			'fix'        => [ 'kind' => 'link', 'href' => 'admin.php?page=beyond-elysium-chronicle-setup-hub&tab=apr&game=' . rawurlencode( $game->slug ), 'capability' => 'be_manage_apr' ],
			'actionable' => \BeyondElysium\Core\Authorization::can( 'be_manage_apr' ),
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private function row_demo_chronicle( object $game ): array {
		return [
			'id'         => 'demo_chronicle',
			'status'     => 'info',
			'title'      => __( 'Demo chronicle', 'beyond-elysium' ),
			'detail'     => __( 'This is sample data. Safe to delete once you have your own chronicle set up.', 'beyond-elysium' ),
			'fix'        => [ 'kind' => 'inline', 'capability' => 'be_manage_games' ],
			'actionable' => \BeyondElysium\Core\Authorization::can( 'be_manage_games' ),
		];
	}
}
