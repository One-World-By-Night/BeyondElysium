<?php

namespace BeyondElysium\Core;

use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\Game_Member;
use BeyondElysium\Services\Setup_Status;

defined( 'ABSPATH' ) || exit;

/**
 * Points a new admin at Chronicle Setup (guided-chronicle-setup-design.md §6.6, built in 1.3.6).
 *
 * Shown to someone who can set up a chronicle that has no characters yet, on the WordPress
 * Dashboard, the Plugins page and Beyond Elysium's own screens - never on Chronicle Setup itself,
 * which is the thing it points at. The design's own condition was any chronicle with a row needing
 * attention; that would nag every established chronicle for good (kony's Creature types row is
 * unset by default and stays that way), so it is limited to a chronicle nobody has created a
 * character in. A chronicle with no characters always has one row needing attention, the
 * Characters row, so the rows are asked only for the count the sentence quotes.
 *
 * An administrator is pointed at every such chronicle; anyone else only at a chronicle they are the
 * HST of. The demo chronicle is sample data and is never mentioned. Dismissal is per user and per
 * chronicle and dismisses the pointer, never the condition (§5.5): the checklist still says what
 * is left.
 */
class Setup_Notice {

	const DISMISSED_META = 'be_setup_notice_dismissed';
	const DISMISS_ACTION = 'be_dismiss_setup_notice';

	public static function register(): void {
		add_action( 'admin_init', [ self::class, 'maybe_dismiss' ] );
		add_action( 'admin_notices', [ self::class, 'render' ] );
	}

	public static function screen_shows_pointer( string $screen_id ): bool {
		if ( str_contains( $screen_id, 'chronicle-setup-hub' ) ) {
			return false;
		}
		return in_array( $screen_id, [ 'dashboard', 'plugins' ], true ) || str_contains( $screen_id, 'beyond-elysium' );
	}

	/**
	 * The chronicles this user should be pointed at, each with how many checklist rows need attention.
	 *
	 * @return array<int,array{game:object,attention:int}>
	 */
	public static function chronicles_needing_setup( int $user_id ): array {
		if ( ! user_can( $user_id, 'be_manage_chronicle_setup' ) ) {
			return [];
		}

		$dismissed = get_user_meta( $user_id, self::DISMISSED_META, true );
		$dismissed = is_array( $dismissed ) ? $dismissed : [];
		$is_admin  = user_can( $user_id, 'be_manage_games' );

		$found = [];
		foreach ( Game::all() as $game ) {
			if ( $game->slug === 'be-demo' || in_array( $game->slug, $dismissed, true ) ) {
				continue;
			}
			if ( ! $is_admin ) {
				$member = Game_Member::find( (int) $game->id, $user_id );
				if ( ! $member || $member->role !== 'hst' ) {
					continue;
				}
			}
			if ( Character::count_for_game( $game->slug ) > 0 ) {
				continue;
			}
			$found[] = [
				'game'      => $game,
				'attention' => Setup_Status::summarise( Setup_Status::rows( $game ) )['attention'],
			];
		}
		return $found;
	}

	/**
	 * @param string[] $slugs
	 */
	public static function dismiss( int $user_id, array $slugs ): void {
		$current = get_user_meta( $user_id, self::DISMISSED_META, true );
		$current = is_array( $current ) ? $current : [];
		update_user_meta( $user_id, self::DISMISSED_META, array_values( array_unique( array_merge( $current, array_filter( $slugs ) ) ) ) );
	}

	/** Handles the Dismiss link: a nonce-checked GET that records the dismissal and returns to the page it came from. */
	public static function maybe_dismiss(): void {
		if ( ! isset( $_GET[ self::DISMISS_ACTION ] ) ) {
			return;
		}
		check_admin_referer( self::DISMISS_ACTION );

		if ( current_user_can( 'be_manage_chronicle_setup' ) ) {
			$slugs = array_map( 'sanitize_title', explode( ',', (string) wp_unslash( $_GET[ self::DISMISS_ACTION ] ) ) );
			self::dismiss( get_current_user_id(), $slugs );
		}

		wp_safe_redirect( remove_query_arg( [ self::DISMISS_ACTION, '_wpnonce' ] ) );
		exit;
	}

	public static function render(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! self::screen_shows_pointer( (string) $screen->id ) ) {
			return;
		}

		$needing = self::chronicles_needing_setup( get_current_user_id() );
		if ( $needing === [] ) {
			return;
		}

		$slugs = array_map( static fn( $entry ) => $entry['game']->slug, $needing );
		if ( count( $needing ) === 1 ) {
			$sentence = sprintf(
				/* translators: 1: chronicle name, 2: number of checklist items needing attention */
				_n( '%1$s is not set up yet: %2$d item needs attention.', '%1$s is not set up yet: %2$d items need attention.', $needing[0]['attention'], 'beyond-elysium' ),
				$needing[0]['game']->name,
				$needing[0]['attention']
			);
		} else {
			$sentence = sprintf(
				/* translators: %d: number of chronicles with no characters yet */
				__( '%d chronicles are not set up yet.', 'beyond-elysium' ),
				count( $needing )
			);
		}

		printf(
			'<div class="notice notice-warning"><p><strong>%1$s</strong> %2$s <a href="%3$s">%4$s</a> &middot; <a href="%5$s">%6$s</a></p></div>',
			esc_html__( 'Beyond Elysium:', 'beyond-elysium' ),
			esc_html( $sentence ),
			esc_url( admin_url( 'admin.php?page=beyond-elysium-chronicle-setup-hub&tab=setup&game=' . rawurlencode( $slugs[0] ) ) ),
			esc_html__( 'Open Chronicle Setup', 'beyond-elysium' ),
			esc_url( wp_nonce_url( add_query_arg( self::DISMISS_ACTION, implode( ',', $slugs ) ), self::DISMISS_ACTION ) ),
			esc_html__( 'Dismiss', 'beyond-elysium' )
		);
	}
}
