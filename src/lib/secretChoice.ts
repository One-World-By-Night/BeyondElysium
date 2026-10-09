/**
 * The Approval Queue's rule for whether a reviewer's secret choice on a log_knowledge change is complete enough to
 * approve.
 */
import type { ChangeType } from '../types/character';

export const SECRET_ENTITY_TYPES = [
	'plot',
	'item',
	'location',
	'character',
	'npc',
] as const;

/**
 * Entity types a reviewer picks by searching a real character list, rather than typing a raw numeric id.
 */
export const SECRET_CHARACTER_ENTITY_TYPES = [ 'character', 'npc' ] as const;

export interface SecretChoiceDraft {
	mode: 'existing' | 'new';
	secretId?: number;
	entityType?: ( typeof SECRET_ENTITY_TYPES )[ number ];
	entityId?: string;
	title?: string;
	content?: string;
}

/**
 * Whether a change is a logged knowledge claim, which needs the reviewer to name which secret it belongs to before it
 * can be approved.
 */
export function isLogPending( change: { change_type: ChangeType } ): boolean {
	return change.change_type === 'log_knowledge';
}

/**
 * Whether a reviewer's in-progress secret choice is complete: an existing secret's id, or a new one's non-empty
 * title - naming an entity is optional, but a half-picked one (a type with no id yet, or the reverse) isn't valid.
 */
export function secretChoiceValid(
	draft: SecretChoiceDraft | undefined
): boolean {
	if ( ! draft ) {
		return false;
	}
	if ( draft.mode === 'existing' ) {
		return !! draft.secretId;
	}
	if ( !! draft.entityType !== !! draft.entityId ) {
		return false;
	}
	return !! draft.title && draft.title.trim() !== '';
}
