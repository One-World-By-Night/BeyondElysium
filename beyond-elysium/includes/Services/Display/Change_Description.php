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
 * Operates only on plain data passed in as arguments - no WordPress calls, no
 * database access.
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
		switch ( $change_type ) {
			case 'add_trait':
				$trait = (array) ( $change_data['trait'] ?? [] );
				$name  = $trait['name'] ?? 'Unknown';
				if ( isset( $trait['level'] ) ) {
					return 'Added ' . $name . ' ' . $trait['level'];
				}
				$count  = $trait['count'] ?? 1;
				$suffix = ! empty( $trait['specialization'] ) ? ' (' . $trait['specialization'] . ')' : '';
				return $count > 1 ? 'Added ' . $name . ' x' . $count . $suffix : 'Added ' . $name . $suffix;

			case 'remove_trait':
				$trait = (array) ( $change_data['trait'] ?? [] );
				return 'Removed ' . ( $trait['name'] ?? 'Unknown' );

			case 'modify_trait':
				$trait    = (array) ( $change_data['trait'] ?? [] );
				$previous = isset( $change_data['previous'] ) ? (array) $change_data['previous'] : null;
				$name     = $trait['name'] ?? ( $previous['name'] ?? 'Unknown' );

				if ( isset( $trait['level'] ) ) {
					return isset( $previous['level'] )
						? $name . ' ' . $previous['level'] . ' → ' . $trait['level']
						: $name . ' → level ' . $trait['level'];
				}
				if ( isset( $trait['count'] ) ) {
					return isset( $previous['count'] )
						? $name . ' x' . $previous['count'] . ' → x' . $trait['count']
						: $name . ' → x' . $trait['count'];
				}
				return $name . ' updated';

			case 'modify_resource':
				$values = (array) ( $change_data['values'] ?? [] );
				if ( empty( $values ) ) {
					return 'Resource updated';
				}
				$pool  = array_key_first( $values );
				$value = (array) $values[ $pool ];
				return $pool . ': ' . ( $value['permanent'] ?? '?' ) . ' perm / ' . ( $value['temporary'] ?? '?' ) . ' temp';

			case 'modify_identity':
				$fields = (array) ( $change_data['fields'] ?? [] );
				if ( empty( $fields ) ) {
					return 'Identity updated';
				}
				$field = array_key_first( $fields );
				return $field . ' → ' . $fields[ $field ];

			case 'xp_earn':
				$amount = $change_data['amount'] ?? 0;
				return '+' . $amount . ' XP' . ( ! empty( $change_data['reason'] ) ? ' (' . $change_data['reason'] . ')' : '' );

			case 'xp_adjust':
				$amount = $change_data['amount'] ?? 0;
				return 'XP adjusted by ' . $amount . ( ! empty( $change_data['reason'] ) ? ' (' . $change_data['reason'] . ')' : '' );

			case 'import_note':
				return ! empty( $change_data['reason'] ) ? $change_data['reason'] : 'Imported note';

			default:
				return 'Unknown change';
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
