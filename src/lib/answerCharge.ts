/**
 * The choice a Storyteller makes when answering a character's downtime: whether the answer cost the character an
 * action, and which background it is charged to.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import type { ActionCharge, ActionChargeRequest } from '../types/plot';
import type { DowntimeCharge } from '../types/downtime';

export type ChargeChoice =
	{ mode: 'none' } | { mode: 'charge'; name: string; cost: number };

/**
 * Whether the choice says enough to send: "no action charged", or a charge naming a background and at least one
 * action.
 */
export function chargeComplete( choice: ChargeChoice | null ): boolean {
	if ( choice === null ) {
		return false;
	}
	return choice.mode === 'none' || ( choice.name !== '' && choice.cost >= 1 );
}

/**
 * The request body for the choice; undefined while nothing is chosen.
 */
export function chargePayload(
	choice: ChargeChoice | null
): ActionChargeRequest | undefined {
	if ( choice === null ) {
		return undefined;
	}
	if ( choice.mode === 'none' ) {
		return { charged: false };
	}
	return { charged: true, name: choice.name, cost: choice.cost };
}

function chargedLine( name: string, cost: number ): string {
	return sprintf(
		/* translators: 1: how many actions, 2: the background they are charged to */
		_n(
			'Charged %1$d action: %2$s',
			'Charged %1$d actions: %2$s',
			cost,
			'beyond-elysium'
		),
		cost,
		name
	);
}

/**
 * The decision stored with an answer, as the thread shows it; null for an answer written before the choice was asked.
 */
export function storedChargeLabel(
	charge: ActionCharge | null | undefined
): string | null {
	if ( ! charge ) {
		return null;
	}
	if ( ! charge.charged ) {
		return __( 'No action charged', 'beyond-elysium' );
	}
	return chargedLine( charge.name, charge.cost );
}

/**
 * The decision a Downtime Queue row reports; null for an unanswered row.
 */
export function queueChargeLabel(
	charge: DowntimeCharge | null | undefined
): string | null {
	if ( ! charge ) {
		return null;
	}
	switch ( charge.state ) {
		case 'none':
			return __( 'No action charged', 'beyond-elysium' );
		case 'charged':
			return chargedLine( charge.name ?? '', charge.cost ?? 1 );
		case 'charge_removed':
			return sprintf(
				/* translators: %s: the background the action was charged to */
				__( 'Charged to %s, use since removed', 'beyond-elysium' ),
				charge.name ?? ''
			);
		default:
			return __( 'Action charge not recorded', 'beyond-elysium' );
	}
}
