/**
 * Sending a waiting change to the Archivist Toolkit for a Coordinator, as the screens see it.
 */

/**
 * What a change's reason asks of a Coordinator: an approval, a notification, or nothing.
 */
export type CoordinatorKind = 'approval' | 'notify' | null;

export interface CoordinatorSignal {
	kind: CoordinatorKind;
	/**
	 * The Coordinators the reason names ("Assamite").
	 */
	coordinators: string[];
}

export type CoordinatorRequestState =
	| 'sending'
	| 'unconfirmed'
	| 'sent'
	| 'approved'
	| 'denied'
	| 'cancelled'
	| 'filed';

/**
 * A request sent to the Toolkit. A person who is not staff gets the first group of fields only.
 */
export interface CoordinatorRequest {
	id: number;
	change_id: number | null;
	character_id: number;
	purpose: 'approval' | 'notify';
	state: CoordinatorRequestState;
	oat_entry_id: number | null;
	step: string | null;
	step_label: string;
	sent_at: string | null;
	checked_at: string | null;
	answered_at: string | null;
	check_error?: string | null;
	entry_url?: string | null;
	sent_by?: number;
}

/**
 * What a change carries about a Coordinator where its chronicle sends to the Toolkit.
 */
export interface ChangeCoordinator {
	signal: CoordinatorSignal;
	request: CoordinatorRequest | null;
}

/**
 * How a send goes in a chronicle, and who it would be made as.
 */
export interface CoordinatorStatus {
	/**
	 * `live` files the request, `preview` shows what would be filed, and null means the choice is not offered.
	 */
	mode: 'live' | 'preview' | null;
	enabled: boolean;
	demo: boolean;
	host: string | null;
	chronicle: string | null;
	sender: { name: string; email: string };
}

export interface ToolkitCharacter {
	uuid: string;
	name: string;
	chronicle: string;
	player: string;
	pc_npc: string;
	exact: boolean;
}

export interface ToolkitRule {
	id: number;
	label: string;
	genre: string;
	category: string;
	condition: string;
	pc_level: string;
	npc_level: string;
	coordinator: string;
}

export interface CoordinatorOffice {
	slug: string;
	name: string;
}

/**
 * A pick of a pending new character that asks for a Coordinator, with the request a Storyteller sent for it.
 */
export interface PendingPick {
	key: string;
	section: string;
	section_label: string;
	name: string;
	reason: string | null;
	signal: CoordinatorSignal;
	request: CoordinatorRequest | null;
}

/**
 * One thing a send carries: a waiting change (`id`) or a pick of a new character (`key`).
 */
export interface CoordinatorSendLine {
	id?: number;
	key?: string;
	label: string;
	/**
	 * The first line of its reason.
	 */
	reason: string | null;
	coordinators: string[];
	/**
	 * What to search the Toolkit's rules for.
	 */
	term: string;
}

export interface CoordinatorSendBody {
	character_id: number;
	change_ids?: number[];
	pick_keys?: string[];
	rules: Array< { id: number; pc_level: string; npc_level: string } >;
	coordinators: string[];
	note: string;
	purpose: 'approval' | 'notify';
}

export type CoordinatorLineState =
	'sent' | 'filed' | 'unconfirmed' | 'refused' | 'not_sent';

export interface CoordinatorSendResult {
	change_id: number | null;
	key?: string | null;
	state: CoordinatorLineState;
	request: CoordinatorRequest | null;
	code?: string;
	message?: string;
}

/**
 * The answer to a send: the requests made, or in a demo the request that would be made.
 */
export interface CoordinatorSendResponse {
	mode: 'live' | 'preview';
	results?: CoordinatorSendResult[];
	previews?: Array< {
		change_id: number | null;
		key?: string | null;
		body: {
			form_slug: string;
			chronicle_slug: string;
			title: string;
			note: string;
			rules: number[];
			meta: Record< string, string >;
		};
	} >;
}
