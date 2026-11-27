import { __, _n, sprintf } from '@wordpress/i18n';
import type {
	ChangeCoordinator,
	CoordinatorOffice,
	CoordinatorRequest,
	CoordinatorSendBody,
	CoordinatorSendLine,
	CoordinatorSignal,
	PendingPick,
	ToolkitRule,
} from '../types/coordinator';

/**
 * What a waiting change shows and offers where its chronicle sends Coordinator approvals to the Archivist Toolkit.
 */

interface WithCoordinator {
	coordinator?: ChangeCoordinator;
}

const OPEN_STATES = [ 'sending', 'unconfirmed', 'sent' ];

/**
 * The request a Storyteller sent for a change while it is out or waiting, or null.
 */
export function activeRequest(
	change: WithCoordinator
): CoordinatorRequest | null {
	const request = change.coordinator?.request ?? null;
	return request && OPEN_STATES.includes( request.state ) ? request : null;
}

/**
 * What Approve asks: `sent` once the change is with a Coordinator, `approval` or `notify` when its reason names one and
 * nothing was sent, and `none` otherwise.
 */
export function choiceFor(
	change: WithCoordinator
): 'sent' | 'approval' | 'notify' | 'none' {
	if ( activeRequest( change ) ) {
		return 'sent';
	}
	const kind = change.coordinator?.signal.kind ?? null;
	return kind ?? 'none';
}

/**
 * Where a request stands, as the pieces of one line: its state, its number in the Toolkit and the step it is at.
 */
export function requestParts( request: CoordinatorRequest ): string[] {
	const label: Record< string, string > = {
		sending: __( 'Sent, not confirmed', 'beyond-elysium' ),
		unconfirmed: __( 'Sent, not confirmed', 'beyond-elysium' ),
		sent: __( 'With the Coordinator', 'beyond-elysium' ),
		approved: __( 'Approved by the Coordinator', 'beyond-elysium' ),
		denied: __( 'Not approved by the Coordinator', 'beyond-elysium' ),
		cancelled: __( 'Cancelled in the Archivist Toolkit', 'beyond-elysium' ),
		filed: __( 'Sent to the Archivist Toolkit', 'beyond-elysium' ),
	};
	const parts = [ label[ request.state ] ?? request.state ];
	if ( request.oat_entry_id ) {
		parts.push(
			sprintf(
				/* translators: %d: the Archivist Toolkit's request number */
				__( 'R&U #%d', 'beyond-elysium' ),
				request.oat_entry_id
			)
		);
	}
	if ( request.step_label && request.state !== 'unconfirmed' ) {
		parts.push( request.step_label );
	}
	return parts;
}

function officeKey( text: string ): string {
	return text
		.trim()
		.toLowerCase()
		.replace( /\s+coordinator$/, '' );
}

/**
 * The slugs of the offices a reason names, found among the offices the Toolkit lists, by name or slug.
 */
export function defaultOffices(
	names: string[],
	offices: CoordinatorOffice[]
): string[] {
	const found: string[] = [];
	for ( const name of names ) {
		const key = officeKey( name );
		const office = offices.find(
			( candidate ) =>
				candidate.slug === key || officeKey( candidate.name ) === key
		);
		if ( office && ! found.includes( office.slug ) ) {
			found.push( office.slug );
		}
	}
	return found;
}

/**
 * What to search the Toolkit's rules for: the trait, power, pool or identity field a change is about.
 */
export function searchTermFor( change: {
	change_type: string;
	change_data: Record< string, unknown >;
} ): string {
	const data = change.change_data ?? {};
	const trait = ( data.trait ?? {} ) as Record< string, unknown >;
	if ( change.change_type.endsWith( '_trait' ) ) {
		return String( trait.power_name ?? trait.name ?? '' );
	}
	if ( change.change_type === 'modify_resource' ) {
		return Object.keys( ( data.values ?? {} ) as object )[ 0 ] ?? '';
	}
	if ( change.change_type === 'modify_identity' ) {
		return Object.keys( ( data.fields ?? {} ) as object )[ 0 ] ?? '';
	}
	return '';
}

/**
 * The waiting changes of one character that ask for a Coordinator's approval and have not been sent.
 */
export function sendableIds(
	rows: Array< WithCoordinator & { id: number; character_id: number } >,
	characterId: number
): number[] {
	return rows
		.filter(
			( row ) =>
				Number( row.character_id ) === characterId &&
				choiceFor( row ) === 'approval'
		)
		.map( ( row ) => row.id );
}

/**
 * A waiting change as a line to send.
 */
export function lineForChange( change: {
	id: number;
	change_type: string;
	change_data: Record< string, unknown >;
	reason: string | null;
	coordinator?: ChangeCoordinator;
} ): CoordinatorSendLine {
	const term = searchTermFor( change );
	return {
		id: change.id,
		label: term || change.change_type,
		reason: change.reason ? change.reason.split( '\n' )[ 0 ] : null,
		coordinators: change.coordinator?.signal.coordinators ?? [],
		term,
	};
}

/**
 * A pick of a pending new character as a line to send.
 */
export function lineForPick( pick: PendingPick ): CoordinatorSendLine {
	return {
		key: pick.key,
		label: pick.name,
		reason: pick.reason ? pick.reason.split( '\n' )[ 0 ] : null,
		coordinators: pick.signal.coordinators,
		term: pick.name,
	};
}

/**
 * The body of a send, naming either waiting changes or a new character's picks.
 */
export function sendBody( input: {
	characterId: number;
	changeIds?: number[];
	pickKeys?: string[];
	rules: ToolkitRule[];
	coordinators: string[];
	note: string;
	purpose: 'approval' | 'notify';
} ): CoordinatorSendBody {
	return {
		character_id: input.characterId,
		...( input.pickKeys
			? { pick_keys: input.pickKeys }
			: { change_ids: input.changeIds ?? [] } ),
		rules: input.rules.map( ( rule ) => ( {
			id: rule.id,
			pc_level: rule.pc_level,
			npc_level: rule.npc_level,
		} ) ),
		coordinators: input.coordinators,
		note: input.note.trim(),
		purpose: input.purpose,
	};
}

/**
 * What a bulk approval says about the Coordinator approvals it left for a Storyteller to decide.
 */
export function needsCoordinatorMessage( count: number ): string {
	return sprintf(
		/* translators: %d: number of changes that need a Coordinator's approval */
		_n(
			'%d change needs a Coordinator. Send it to the Coordinator, or open it and approve it yourself.',
			'%d changes need a Coordinator. Send each to the Coordinator, or open each one and approve it yourself.',
			count,
			'beyond-elysium'
		),
		count
	);
}

/**
 * What a player is told about a change that asks for a Coordinator: a Storyteller may send it on.
 */
export function coordinatorNote(
	signal: CoordinatorSignal | undefined
): string | null {
	if ( signal?.kind !== 'approval' ) {
		return null;
	}
	const who =
		signal.coordinators.length > 0
			? signal.coordinators
					.map( ( name ) =>
						sprintf(
							/* translators: %s: a Coordinator's office, such as Assamite */
							__( 'the %s Coordinator', 'beyond-elysium' ),
							name
						)
					)
					.join( ` ${ __( 'and', 'beyond-elysium' ) } ` )
			: __( 'a Coordinator', 'beyond-elysium' );
	return sprintf(
		/* translators: %s: who it may be sent to, such as "the Assamite Coordinator" */
		__(
			'A Storyteller may send this to %s through the Archivist Toolkit.',
			'beyond-elysium'
		),
		who
	);
}
