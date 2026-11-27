<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Reads a change's reason text for a Coordinator Approval or a Coordinator Notify, from a bylaw line
 * (`Coordinator Approval — Assamite Coordinator [OWBN Character Bylaws 10.m.v.10, clause 8992]`) or from a
 * chronicle's own reason that begins with the same words. Pure - no database, no network.
 */
class Coordinator_Signal {

	public const APPROVAL = 'approval';
	public const NOTIFY   = 'notify';

	/**
	 * What a reason asks of a Coordinator. An approval outranks a notification, and `coordinators` and `lines` hold
	 * only what made it the answer.
	 *
	 * @return array{kind:?string,coordinators:string[],lines:string[]}
	 */
	public static function of( ?string $reason ): array {
		$found = [
			self::APPROVAL => [ 'coordinators' => [], 'lines' => [] ],
			self::NOTIFY   => [ 'coordinators' => [], 'lines' => [] ],
		];

		foreach ( preg_split( '/\R/u', (string) $reason ) ?: [] as $line ) {
			$line = trim( $line );
			if ( $line === '' ) {
				continue;
			}
			$parts = self::parts( $line );
			$kind  = Bylaw_Reason::tier_kind( $parts['tier'] );
			if ( $kind === null ) {
				continue;
			}
			$found[ $kind ]['lines'][]        = $line;
			$found[ $kind ]['coordinators'] = array_merge( $found[ $kind ]['coordinators'], $parts['coordinators'] );
		}

		foreach ( [ self::APPROVAL, self::NOTIFY ] as $kind ) {
			if ( $found[ $kind ]['lines'] !== [] ) {
				return [
					'kind'         => $kind,
					'coordinators' => array_values( array_unique( $found[ $kind ]['coordinators'] ) ),
					'lines'        => $found[ $kind ]['lines'],
				];
			}
		}
		return [ 'kind' => null, 'coordinators' => [], 'lines' => [] ];
	}

	/**
	 * One reason line's tier and the Coordinators it names: the citation at the end dropped, the line split at its
	 * " — " separators, the second part read as the authority.
	 *
	 * @return array{tier:string,coordinators:string[]}
	 */
	private static function parts( string $line ): array {
		$line  = (string) preg_replace( '/\s*\[OWBN Character Bylaws [^\]]*\]\s*$/u', '', $line );
		$parts = preg_split( '/\s+—\s+/u', $line ) ?: [ $line ];
		$tier  = (string) array_shift( $parts );

		$names     = [];
		$authority = $parts[0] ?? '';
		if ( $authority !== '' && stripos( $authority, 'applies to:' ) !== 0 && stripos( $authority, 'OWBN Council' ) !== 0 ) {
			foreach ( preg_split( '/\s+and\s+/i', $authority ) ?: [] as $name ) {
				$name = trim( (string) preg_replace( '/\s+Coordinator$/i', '', trim( $name ) ) );
				if ( $name !== '' && preg_match( '/^(?:coordinator\s+)?varies/i', $name ) !== 1 ) {
					$names[] = $name;
				}
			}
		}
		return [ 'tier' => $tier, 'coordinators' => $names ];
	}
}
