<?php

namespace BeyondElysium\Services;

use BeyondElysium\Database\Manager;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Models\Submission;
use BeyondElysium\REST\Approval_Rules_Controller;

defined( 'ABSPATH' ) || exit;

/**
 * The rows of a chronicle's Chronicle Setup checklist, and the one place that decides what each says.
 */
class Setup_Status {

	/**
	 * Every row for one chronicle, in the order the screen shows them.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function rows( object $game ): array {
		$rows = [
			self::row_creature_types( $game ),
			self::row_storytellers( $game ),
			self::row_new_character_approval( $game ),
			self::row_starting_xp( $game ),
			self::row_front_end_pages( $game ),
			self::row_characters( $game ),
			self::row_approval_rules( $game ),
			self::row_catalog_customisation( $game ),
			self::row_book_variants( $game ),
			self::row_sheet_templates( $game ),
			self::row_downtime_and_rumors( $game ),
			self::row_plot_features( $game ),
			self::row_branding( $game ),
			self::row_faction_restrictions( $game ),
			self::row_purchase_lists( $game ),
			self::row_grapevine_files( $game ),
			self::row_join_requests( $game ),
			self::row_secret_passing( $game ),
		];

		if ( $game->slug === 'be-demo' ) {
			$rows[] = self::row_demo_chronicle( $game );
		}

		return $rows;
	}

	/**
	 * How many rows are in each state, and how many there are to do.
	 *
	 * @param array<int,array<string,mixed>> $rows
	 * @return array{attention:int,ok:int,info:int,total:int}
	 */
	public static function summarise( array $rows ): array {
		$summary = [ 'attention' => 0, 'ok' => 0, 'info' => 0, 'total' => 0 ];
		foreach ( $rows as $row ) {
			$summary[ $row['status'] ]++;
			if ( $row['id'] !== 'demo_chronicle' ) {
				$summary['total']++;
			}
		}
		return $summary;
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function row_creature_types( object $game ): array {
		$enabled = $game->settings->enabled_stacks ?? null;
		$ok      = is_array( $enabled ) && ! empty( $enabled );
		$total   = \BeyondElysium\Models\Creature_Stack::total_for_game( (string) $game->slug );

		return [
			'id'         => 'enabled_stacks',
			'status'     => $ok ? 'ok' : 'attention',
			'title'      => __( 'Creature types', 'beyond-elysium' ),
			'detail'     => $ok
				? sprintf(
					/* translators: %1$d: number of enabled creature types. %2$d: how many exist to choose from. */
					__( '%1$d of %2$d creature types are enabled.', 'beyond-elysium' ),
					count( $enabled ),
					$total
				)
				: sprintf(
					/* translators: %d: how many creature types exist to choose from. */
					__( 'All %d creature types are available. Narrow this to what your chronicle actually runs.', 'beyond-elysium' ),
					$total
				),
			'fix'        => [ 'kind' => 'inline', 'capability' => 'be_manage_chronicle_setup' ],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function row_storytellers( object $game ): array {
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
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function row_new_character_approval( object $game ): array {
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
			'fix'        => [ 'kind' => 'inline', 'capability' => 'be_manage_chronicle_setup' ],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function row_join_requests( object $game ): array {
		$chosen = isset( $game->settings->join_requests );
		$on     = $chosen ? (bool) $game->settings->join_requests : true;

		return [
			'id'     => 'join_requests',
			'status' => $chosen ? 'ok' : 'info',
			'title'  => __( 'Join requests', 'beyond-elysium' ),
			'detail' => $on
				? __( 'On. Anyone signed in can ask to join from My Chronicle; nothing is granted without a Storyteller\'s approval.', 'beyond-elysium' )
				: __( 'Off. This chronicle does not appear in the join list, and asking to join is refused.', 'beyond-elysium' ),
			'fix'    => [ 'kind' => 'inline', 'capability' => 'be_manage_chronicle_setup' ],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function row_secret_passing( object $game ): array {
		$chosen = isset( $game->settings->secret_passing );
		$mode   = $chosen ? (string) $game->settings->secret_passing : 'approval';

		$detail = [
			'off'       => __( 'Off. Players cannot log what their characters learned or tell a secret to another character.', 'beyond-elysium' ),
			'approval'  => __( 'Needs a Storyteller. A logged claim or a passed secret waits for a Storyteller before it reaches anyone.', 'beyond-elysium' ),
			'immediate' => __( 'Immediate. A passed secret reaches its recipient right away; a Storyteller still reviews it afterward.', 'beyond-elysium' ),
		][ $mode ] ?? '';

		return [
			'id'     => 'secret_passing',
			'status' => $chosen ? 'ok' : 'info',
			'title'  => __( 'Players and secrets', 'beyond-elysium' ),
			'detail' => $detail,
			'fix'    => [ 'kind' => 'inline', 'capability' => 'be_manage_chronicle_setup' ],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function row_starting_xp( object $game ): array {
		$xp = (int) ( $game->settings->starting_xp ?? 0 );

		return [
			'id'     => 'starting_xp',
			'status' => $xp > 0 ? 'ok' : 'info',
			'title'  => __( 'Starting experience', 'beyond-elysium' ),
			'detail' => $xp > 0
				/* translators: %d: how much experience a new character starts with */
				? sprintf( __( 'A new character starts with %d experience, which its build can spend.', 'beyond-elysium' ), $xp )
				: __( 'A new character starts with no experience beyond its build.', 'beyond-elysium' ),
			'fix'    => [ 'kind' => 'inline', 'capability' => 'be_manage_chronicle_setup' ],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function row_front_end_pages( object $game ): array {
		// Checks the four fixed pages' real existence directly.
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
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function row_characters( object $game ): array {
		$count = Character::count_for_game( $game->slug );

		return [
			'id'         => 'characters',
			'status'     => $count > 0 ? 'ok' : 'attention',
			'title'      => __( 'Characters', 'beyond-elysium' ),
			'detail'     => $count > 0
				? sprintf( /* translators: %d: number of characters */ __( '%d character(s) exist in this chronicle.', 'beyond-elysium' ), $count )
				: __( 'No characters exist in this chronicle yet.', 'beyond-elysium' ),
			'fix'        => [ 'kind' => 'link', 'href' => 'admin.php?page=beyond-elysium-characters&game_slug=' . rawurlencode( $game->slug ), 'capability' => 'be_manage_characters' ],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function row_approval_rules( object $game ): array {
		$policy = $game->settings->auto_approve ?? null;
		$rules  = Approval_Rules_Controller::own_rule_count( $game->slug );
		$done   = is_bool( $policy ) || $rules > 0;

		if ( ! $done ) {
			$detail = __( 'Everything requires Storyteller approval until a rule says otherwise.', 'beyond-elysium' );
		} elseif ( $rules > 0 ) {
			$detail = $policy === true
				? sprintf( /* translators: %d: number of approval rules */ __( '%d approval rule(s) set for this chronicle. A change no rule covers is approved automatically.', 'beyond-elysium' ), $rules )
				: sprintf( /* translators: %d: number of approval rules */ __( '%d approval rule(s) set for this chronicle. A change no rule covers waits for Storyteller approval.', 'beyond-elysium' ), $rules );
		} else {
			$detail = $policy === true
				? __( 'No individual rules yet. A change no rule covers is approved automatically.', 'beyond-elysium' )
				: __( 'No individual rules yet. A change no rule covers waits for Storyteller approval.', 'beyond-elysium' );
		}

		return [
			'id'         => 'approval_rules',
			'status'     => $done ? 'ok' : 'info',
			'title'      => __( 'Approval rules', 'beyond-elysium' ),
			'detail'     => $detail,
			'fix'        => [ 'kind' => 'link', 'href' => 'admin.php?page=beyond-elysium-system-config&tab=approval-rules&game=' . rawurlencode( $game->slug ), 'capability' => 'be_manage_approval_rules' ],
		];
	}

	/**
	 * The Catalog customisation row of the setup checklist: needing attention while the chronicle has book corrections to
	 * review.
	 *
	 * @return array<string,mixed>
	 */
	private static function row_catalog_customisation( object $game ): array {
		global $wpdb;
		$blocks_table = Manager::table( 'schema_blocks' );
		$stacks_table = Manager::table( 'creature_stacks' );
		$block_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$blocks_table} WHERE game_slug = %s", $game->slug ) );
		$stack_count  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$stacks_table} WHERE game_slug = %s", $game->slug ) );
		$count        = $block_count + $stack_count;
		$corrections  = Catalog_Corrections::count( $game );

		if ( $corrections > 0 ) {
			$detail = sprintf(
				/* translators: %d: number of changes this chronicle made where the book has changed since */
				_n( '%d book correction to review.', '%d book corrections to review.', $corrections, 'beyond-elysium' ),
				$corrections
			);
		} elseif ( $count > 0 ) {
			$detail = sprintf( /* translators: %d: number of forked schema blocks and creature types */ __( '%d schema block(s) or creature type(s) are customised for this chronicle.', 'beyond-elysium' ), $count );
		} else {
			$detail = __( 'This chronicle uses the shared catalog with no customisation.', 'beyond-elysium' );
		}

		return [
			'id'         => 'catalog_customisation',
			'status'     => $corrections > 0 ? 'attention' : ( $count > 0 ? 'ok' : 'info' ),
			'title'      => __( 'Catalog customisation', 'beyond-elysium' ),
			'detail'     => $detail,
			'fix'        => [ 'kind' => 'link', 'href' => 'admin.php?page=beyond-elysium-system-config&tab=schema-blocks&game_slug=' . rawurlencode( $game->slug ), 'capability' => 'be_manage_schemas' ],
		];
	}

	/**
	 * The Book variants row of the setup checklist: the variants the chronicle chose for its blocks.
	 *
	 * @return array<string,mixed>
	 */
	private static function row_book_variants( object $game ): array {
		$chosen = [];
		foreach ( Catalog_Variants::for_game( $game ) as $base ) {
			foreach ( $base['variants'] as $variant ) {
				if ( in_array( $variant['id'], $base['chosen'], true ) ) {
					/* translators: 1: a block, such as Disciplines, 2: a book variant of it, such as Dark Ages printings */
					$chosen[] = sprintf( __( '%1$s: %2$s', 'beyond-elysium' ), $base['base_name'], $variant['label'] );
				}
			}
		}

		return [
			'id'     => 'book_variants',
			'status' => $chosen !== [] ? 'ok' : 'info',
			'title'  => __( 'Book variants', 'beyond-elysium' ),
			'detail' => $chosen !== []
				? implode( '; ', $chosen ) . '.'
				: __( 'This chronicle uses the book with no variants.', 'beyond-elysium' ),
			'fix'    => [ 'kind' => 'inline', 'capability' => 'be_manage_schemas' ],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function row_sheet_templates( object $game ): array {
		global $wpdb;
		$table = Manager::table( 'templates' );
		$count = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE game_id = %d", (int) $game->id ) );

		return [
			'id'         => 'sheet_templates',
			'status'     => $count > 0 ? 'ok' : 'info',
			'title'      => __( 'Sheet templates', 'beyond-elysium' ),
			'detail'     => $count > 0
				? sprintf( /* translators: %d: number of overridden templates */ __( '%d template(s) are overridden for this chronicle.', 'beyond-elysium' ), $count )
				: __( 'This chronicle uses the shared templates. Customize one to give this chronicle its own layout.', 'beyond-elysium' ),
			'fix'        => [ 'kind' => 'link', 'href' => 'admin.php?page=beyond-elysium-system-config&tab=templates&game_slug=' . rawurlencode( $game->slug ), 'capability' => 'be_manage_templates' ],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function row_downtime_and_rumors( object $game ): array {
		$apr     = $game->settings->apr ?? null;
		$has_apr = $apr !== null && (array) $apr !== [];

		return [
			'id'         => 'downtime_and_rumors',
			'status'     => $has_apr ? 'ok' : 'info',
			'title'      => __( 'Downtime actions & rumors', 'beyond-elysium' ),
			'detail'     => $has_apr
				? __( 'This chronicle has its own downtime and rumor settings.', 'beyond-elysium' )
				: __( 'Using Beyond Elysium\'s default downtime and rumor settings.', 'beyond-elysium' ),
			'fix'        => [ 'kind' => 'link', 'href' => 'admin.php?page=beyond-elysium-chronicle-setup-hub&tab=apr&game=' . rawurlencode( $game->slug ), 'capability' => 'be_manage_apr' ],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function row_plot_features( object $game ): array {
		$on = ! empty( $game->settings->plots->expanded_enabled );

		return [
			'id'     => 'plot_features',
			'status' => $on ? 'ok' : 'info',
			'title'  => __( 'Plot features', 'beyond-elysium' ),
			'detail' => $on
				? __( 'Faction Goals, plot categories and timeline dates are on.', 'beyond-elysium' )
				: __( 'Off. Turn on to add Faction Goals, plot categories and timeline dates.', 'beyond-elysium' ),
			'fix'    => [ 'kind' => 'inline', 'capability' => 'be_manage_games' ],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function row_branding( object $game ): array {
		$color = (string) ( $game->settings->accent_color ?? '' );

		return [
			'id'     => 'branding',
			'status' => $color !== '' ? 'ok' : 'info',
			'title'  => __( 'Branding', 'beyond-elysium' ),
			'detail' => $color !== ''
				? sprintf( /* translators: %s: a hex color such as #8b0000 */ __( 'This chronicle has its own accent color (%s).', 'beyond-elysium' ), $color )
				: __( 'Using the site-wide accent color.', 'beyond-elysium' ),
			'fix'    => [ 'kind' => 'inline', 'capability' => 'be_manage_chronicle_setup' ],
		];
	}

	/**
	 * A stored list of allowed values counts as a choice made.
	 *
	 * @return array<string,mixed>
	 */
	private static function row_faction_restrictions( object $game ): array {
		$fields = 0;
		foreach ( (array) ( $game->settings->enabled_factions ?? [] ) as $by_field ) {
			foreach ( (array) $by_field as $allowed ) {
				if ( is_array( $allowed ) && $allowed !== [] ) {
					++$fields;
				}
			}
		}

		return [
			'id'     => 'faction_restrictions',
			'status' => $fields > 0 ? 'ok' : 'info',
			'title'  => __( 'Sub-faction restrictions', 'beyond-elysium' ),
			'detail' => $fields > 0
				? sprintf( /* translators: %d: number of catalog fields with their own list of allowed values */ __( '%d field(s) have their own list of allowed values.', 'beyond-elysium' ), $fields )
				: __( 'Every value of every field is open. Narrow a field such as Clan or Tribe to what this chronicle runs.', 'beyond-elysium' ),
			'fix'    => [ 'kind' => 'inline', 'capability' => 'be_manage_chronicle_setup' ],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function row_purchase_lists( object $game ): array {
		$labels = [
			'abilities'    => __( 'Abilities', 'beyond-elysium' ),
			'backgrounds'  => __( 'Backgrounds', 'beyond-elysium' ),
			'merits_flaws' => __( 'Merits and Flaws', 'beyond-elysium' ),
		];
		$open = [];
		foreach ( Purchase_Scope::normalize( $game->settings->purchase_scope ?? null ) as $area => $on ) {
			if ( $on ) {
				$open[] = $labels[ $area ] ?? $area;
			}
		}

		return [
			'id'     => 'purchase_lists',
			'status' => $open !== [] ? 'ok' : 'info',
			'title'  => __( 'Purchase lists', 'beyond-elysium' ),
			'detail' => $open !== []
				? sprintf( /* translators: %s: comma-separated list of the areas that are open, such as Abilities, Backgrounds */ __( 'Open to every creature type: %s.', 'beyond-elysium' ), implode( ', ', $open ) )
				: __( 'All three lists are off, so each creature type buys from its own.', 'beyond-elysium' ),
			'fix'    => [ 'kind' => 'inline', 'capability' => 'be_manage_chronicle_setup' ],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function row_grapevine_files( object $game ): array {
		$count = Submission::count_for_game( (int) $game->id );

		return [
			'id'     => 'grapevine_files',
			'status' => $count > 0 ? 'ok' : 'info',
			'title'  => __( "Players' Grapevine files", 'beyond-elysium' ),
			'detail' => $count > 0
				? sprintf( /* translators: %d: number of files players have sent */ __( '%d player file(s) have come in through the link.', 'beyond-elysium' ), $count )
				: __( 'Share the link so players can send their Grapevine files. Nothing is added until a Storyteller accepts.', 'beyond-elysium' ),
			'fix'    => [ 'kind' => 'inline', 'capability' => 'be_manage_characters' ],
		];
	}

	/**
	 * @return array<string,mixed>
	 */
	private static function row_demo_chronicle( object $game ): array {
		return [
			'id'         => 'demo_chronicle',
			'status'     => 'info',
			'title'      => __( 'Demo chronicle', 'beyond-elysium' ),
			'detail'     => __( 'This is sample data. Safe to delete once you have your own chronicle set up.', 'beyond-elysium' ),
			'fix'        => [ 'kind' => 'inline', 'capability' => 'be_manage_games' ],
		];
	}
}
