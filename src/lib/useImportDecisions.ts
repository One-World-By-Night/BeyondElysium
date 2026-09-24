/**
 * Owns the Storyteller's in-progress decisions on an import preview.
 */
import { useState } from '@wordpress/element';
import {
	blockingCount as countBlocking,
	decisionsFor,
	noDecisions,
	previewKey,
	withDecision,
	type ImportDecisions,
} from './importDecisions';
import type {
	DuplicateAction,
	ImportPreview,
	TraitResolution,
} from '../types/import';

export interface UseImportDecisionsResult {
	decisions: ImportDecisions;
	madeFor: string;
	traitResolutions: Record< string, TraitResolution >;
	duplicateActions: Record< string, DuplicateAction >;
	worldObjectActions: Record< string, DuplicateAction >;
	onTraitResolutionChange: (
		key: string,
		resolution: TraitResolution | null
	) => void;
	onDuplicateActionChange: (
		character: string,
		action: DuplicateAction | null
	) => void;
	onWorldObjectActionChange: (
		key: string,
		action: DuplicateAction | null
	) => void;
	/**
	 * How many trait/duplicate/world-object decisions still block a commit.
	 */
	blockingCount: () => number;
}

export function useImportDecisions< P extends ImportPreview >(
	preview: P | null,
	previewTarget = ''
): UseImportDecisionsResult {
	const [ decisions, setDecisions ] = useState< ImportDecisions >(
		noDecisions()
	);

	const madeFor = preview ? previewKey( preview.job_id, previewTarget ) : '';
	const {
		traits: traitResolutions,
		duplicates: duplicateActions,
		worldObjects: worldObjectActions,
	} = decisionsFor( decisions, madeFor );

	function onTraitResolutionChange(
		key: string,
		resolution: TraitResolution | null
	) {
		setDecisions( ( prev ) =>
			withDecision( prev, madeFor, 'traits', key, resolution )
		);
	}

	function onDuplicateActionChange(
		character: string,
		action: DuplicateAction | null
	) {
		setDecisions( ( prev ) =>
			withDecision( prev, madeFor, 'duplicates', character, action )
		);
	}

	function onWorldObjectActionChange(
		key: string,
		action: DuplicateAction | null
	) {
		setDecisions( ( prev ) =>
			withDecision( prev, madeFor, 'worldObjects', key, action )
		);
	}

	function blockingCount(): number {
		return preview
			? countBlocking(
					preview,
					traitResolutions,
					duplicateActions,
					worldObjectActions
			  )
			: 0;
	}

	return {
		decisions,
		madeFor,
		traitResolutions,
		duplicateActions,
		worldObjectActions,
		onTraitResolutionChange,
		onDuplicateActionChange,
		onWorldObjectActionChange,
		blockingCount,
	};
}
