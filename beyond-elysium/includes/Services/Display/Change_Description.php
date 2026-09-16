<?php

namespace BeyondElysium\Services\Display;

defined( 'ABSPATH' ) || exit;

/**
 * Formats one `character_changes` row as a short human-readable string, e.g.
 * "Celerity 2 -> 4" - an exact PHP twin of `src/lib/describeChange.ts`, so a
 * signed PDF's XP history table reads the same descriptions the on-screen
 * approval queue and change history already show, instead of a third,
 * independently-derived wording (signed-pdf-design.md Section 3a's `xp_history`
 * field). Verified against the TypeScript original by having both read the
 * same JSON fixture and assert the same output; see
 * tests/unit/Display/ChangeDescriptionParityTest.php and
 * src/lib/describeChange.test.ts.
 *
 * Also carries `xp_delta()`, the signed-amount convention `Change_Engine::approve()`
 * and `Character_Exporter::write_experience()` each already apply inline when a
 * change is approved - restated here as a named, callable form since neither of
 * those is a shared helper and `xp_history` needs the same arithmetic a third
 * time. Not a refactor of either existing site; both are left exactly as they are.
 *
 * Operates only on plain data passed in as arguments - no database access; its
 * words go through `__()` so a translated chronicle reads its own language.
 *
 * @see BE_PROCESS/signed-pdf-design.md Section 3a, SP-5
 */
class Change_Description {

	/**
	 * Builds the display string for one change, branching on `$change_type` and
	 * reading whatever fields that type's `$change_data` carries. Falls back to
	 * showing only the new value when a `previous` side isn't available, and to
	 * a generic label for an unrecognized type.
	 *
	 * @param string               $change_type
	 * @param array<string,mixed>  $change_data
	 */
	public static function describe( string $change_type, array $change_data ): string {
		// Every word is translated; names, numbers, and reasons are the change's own (1.0.0-review F-084).
		switch ( $change_type ) {
			case 'add_trait':
				$trait = (array) ( $change_data['trait'] ?? [] );
				$name  = $trait['name'] ?? __( 'Unknown', 'beyond-elysium' );
				if ( isset( $trait['level'] ) ) {
					/* translators: 1: trait or power name, 2: level */
					return sprintf( __( 'Added %1$s %2$s', 'beyond-elysium' ), $name, $trait['level'] );
				}
				$count  = $trait['count'] ?? 1;
				$suffix = ! empty( $trait['specialization'] ) ? ' (' . $trait['specialization'] . ')' : '';
				return $count > 1
					/* translators: 1: trait name, 2: count, 3: specialization in parentheses, or nothing */
					? sprintf( __( 'Added %1$s x%2$s%3$s', 'beyond-elysium' ), $name, $count, $suffix )
					/* translators: 1: trait name, 2: specialization in parentheses, or nothing */
					: sprintf( __( 'Added %1$s%2$s', 'beyond-elysium' ), $name, $suffix );

			case 'remove_trait':
				$trait = (array) ( $change_data['trait'] ?? [] );
				/* translators: %s: trait name */
				return sprintf( __( 'Removed %s', 'beyond-elysium' ), $trait['name'] ?? __( 'Unknown', 'beyond-elysium' ) );

			case 'modify_trait':
				$trait    = (array) ( $change_data['trait'] ?? [] );
				$previous = isset( $change_data['previous'] ) ? (array) $change_data['previous'] : null;
				$name     = $trait['name'] ?? ( $previous['name'] ?? __( 'Unknown', 'beyond-elysium' ) );

				if ( isset( $trait['level'] ) ) {
					return isset( $previous['level'] )
						/* translators: 1: power name, 2: level before, 3: level after */
						? sprintf( __( '%1$s %2$s → %3$s', 'beyond-elysium' ), $name, $previous['level'], $trait['level'] )
						/* translators: 1: power name, 2: level after */
						: sprintf( __( '%1$s → level %2$s', 'beyond-elysium' ), $name, $trait['level'] );
				}
				if ( isset( $trait['count'] ) ) {
					return isset( $previous['count'] )
						/* translators: 1: trait name, 2: count before, 3: count after */
						? sprintf( __( '%1$s x%2$s → x%3$s', 'beyond-elysium' ), $name, $previous['count'], $trait['count'] )
						/* translators: 1: trait name, 2: count after */
						: sprintf( __( '%1$s → x%2$s', 'beyond-elysium' ), $name, $trait['count'] );
				}
				/* translators: %s: trait name */
				return sprintf( __( '%s updated', 'beyond-elysium' ), $name );

			case 'modify_resource':
				$values = (array) ( $change_data['values'] ?? [] );
				if ( empty( $values ) ) {
					return __( 'Resource updated', 'beyond-elysium' );
				}
				$pool  = array_key_first( $values );
				$value = (array) $values[ $pool ];
				/* translators: 1: resource pool name, 2: permanent value, 3: temporary value */
				return sprintf( __( '%1$s: %2$s perm / %3$s temp', 'beyond-elysium' ), $pool, $value['permanent'] ?? '?', $value['temporary'] ?? '?' );

			case 'modify_identity':
				$fields = (array) ( $change_data['fields'] ?? [] );
				if ( empty( $fields ) ) {
					return __( 'Identity updated', 'beyond-elysium' );
				}
				$field = array_key_first( $fields );
				$value = $fields[ $field ];
				// A multiselect's choices read as the sheet shows them, never "Array" (F-086).
				$value = is_array( $value ) ? implode( ', ', array_map( 'strval', $value ) ) : (string) $value;
				/* translators: 1: identity field name, 2: its new value */
				return sprintf( __( '%1$s → %2$s', 'beyond-elysium' ), $field, $value );

			case 'xp_earn':
				$amount = $change_data['amount'] ?? 0;
				$reason = ! empty( $change_data['reason'] ) ? ' (' . $change_data['reason'] . ')' : '';
				/* translators: 1: XP awarded, 2: the award's reason in parentheses, or nothing */
				return sprintf( __( '+%1$s XP%2$s', 'beyond-elysium' ), $amount, $reason );

			case 'xp_adjust':
				$amount = $change_data['amount'] ?? 0;
				$reason = ! empty( $change_data['reason'] ) ? ' (' . $change_data['reason'] . ')' : '';
				/* translators: 1: signed XP adjustment, 2: the adjustment's reason in parentheses, or nothing */
				return sprintf( __( 'XP adjusted by %1$s%2$s', 'beyond-elysium' ), $amount, $reason );

			case 'import_note':
				return ! empty( $change_data['reason'] ) ? $change_data['reason'] : __( 'Imported note', 'beyond-elysium' );

			default:
				return __( 'Unknown change', 'beyond-elysium' );
		}
	}

	/**
	 * The signed XP delta one approved change actually applied, matching
	 * `Change_Engine::approve()`'s own inline arithmetic: `xp_earn`/`xp_adjust`
	 * carry an already-signed `amount`; every other costed change is a spend
	 * (negative); an uncosted change (e.g. `import_note`) is zero.
	 *
	 * @param array<string,mixed> $change_data
	 */
	public static function xp_delta( string $change_type, array $change_data, float $xp_cost ): int {
		if ( $change_type === 'xp_earn' || $change_type === 'xp_adjust' ) {
			return (int) ( $change_data['amount'] ?? 0 );
		}
		if ( $xp_cost > 0 ) {
			return -(int) $xp_cost;
		}
		return 0;
	}
}
