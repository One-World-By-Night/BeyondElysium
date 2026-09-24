/**
 * The choices a Storyteller makes reviewing an import.
 */
import { duplicateActionsFor } from './duplicateActions';
import type {
	DuplicateAction,
	FlaggedTrait,
	ImportPreview,
	TraitResolution,
	UnresolvedTrait,
} from '../types/import';

export interface ImportDecisions {
	/**
	 * The preview these were made on, from previewKey().
	 */
	madeFor: string;
	traits: Record< string, TraitResolution >;
	duplicates: Record< string, DuplicateAction >;
	worldObjects: Record< string, DuplicateAction >;
}

type Kind = 'traits' | 'duplicates' | 'worldObjects';

/**
 * Names one preview: the import job it came from and the chronicle it was checked against, if any.
 */
export function previewKey( jobId: string, target = '' ): string {
	return `${ jobId }|${ target }`;
}

/**
 * An empty set of decisions for one preview.
 */
export function noDecisions( madeFor = '' ): ImportDecisions {
	return { madeFor, traits: {}, duplicates: {}, worldObjects: {} };
}

/**
 * The decisions that apply to a preview: the ones made on it, or none at all when the ones held were made on a
 * different preview.
 */
export function decisionsFor(
	decisions: ImportDecisions,
	madeFor: string
): ImportDecisions {
	return decisions.madeFor === madeFor ? decisions : noDecisions( madeFor );
}

/**
 * Records one decision on a preview, or withdraws it when given null.
 */
export function withDecision< K extends Kind >(
	decisions: ImportDecisions,
	madeFor: string,
	kind: K,
	key: string,
	value: ImportDecisions[ K ][ string ] | null
): ImportDecisions {
	const current = decisionsFor( decisions, madeFor );
	const next: Record< string, unknown > = { ...current[ kind ] };
	if ( value ) {
		next[ key ] = value;
	} else {
		delete next[ key ];
	}
	return { ...current, [ kind ]: next };
}

/**
 * Builds the lookup key for one flagged or unresolved trait row, combining its character, block, and raw value with
 * its row index.
 */
export function keyFor(
	trait: FlaggedTrait | UnresolvedTrait,
	index: number
): string {
	return `${ trait.character }|${ trait.block }|${ trait.raw }|${ index }`;
}

/**
 * Counts the decisions still blocking a commit or a transfer accept, after the Storyteller's choices.
 */
export function blockingCount(
	preview: ImportPreview,
	traitResolutions: Record< string, TraitResolution >,
	duplicateActions: Record< string, DuplicateAction >,
	worldObjectActions: Record< string, DuplicateAction >
): number {
	const stillFlagged = preview.flagged_traits.filter(
		( t, i ) => ! traitResolutions[ keyFor( t, i ) ]
	).length;
	const stillUnresolved = preview.unresolved.filter(
		( t, i ) => ! traitResolutions[ keyFor( t, i ) ]
	).length;
	const stillDuplicate = preview.duplicates.filter(
		( d ) =>
			! duplicateActionsFor( d.matched_by ).includes(
				duplicateActions[ d.character ]
			)
	).length;
	const stillWorldObject = preview.world_object_duplicates.filter(
		( d ) => ! worldObjectActions[ `${ d.type }:${ d.name }` ]
	).length;
	return stillUnresolved + stillFlagged + stillDuplicate + stillWorldObject;
}
