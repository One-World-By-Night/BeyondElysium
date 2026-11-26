/**
 * Loads a character's sheet: the character, its creature type, its template, its style and whether signed PDFs can
 * be made.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../api/client';
import type { ResolvedStack, TemplateResolveResponse } from '../types';
import type { Character, SheetStyle } from '../types/character';

interface RestError {
	code?: string;
	message?: string;
	data?: { status?: number };
}

export type SheetState =
	| { status: 'loading' }
	| { status: 'error'; httpStatus: number | null; message: string }
	| {
			status: 'ready';
			character: Character;
			stack: ResolvedStack;
			resolved: TemplateResolveResponse;
	  };

/**
 * A style-load failure never blocks the sheet itself.
 */
const NO_STYLE: SheetStyle = {};

function isRestError( error: unknown ): error is RestError {
	return typeof error === 'object' && error !== null;
}

export interface CharacterSheetData {
	state: SheetState;
	style: SheetStyle;
	setStyle: ( style: SheetStyle ) => void;
	pdfAvailability: { ok: boolean; code: string } | null;
}

export function useCharacterSheet(
	characterId: number,
	gameSlug: string,
	templateType: string
): CharacterSheetData {
	const [ state, setState ] = useState< SheetState >( { status: 'loading' } );
	const [ style, setStyle ] = useState< SheetStyle >( NO_STYLE );
	const [ pdfAvailability, setPdfAvailability ] = useState< {
		ok: boolean;
		code: string;
	} | null >( null );

	useEffect( () => {
		let cancelled = false;
		setState( { status: 'loading' } );

		( async () => {
			try {
				// Stack and template both depend on character.stack_slug, so those two calls run in parallel after it loads.
				const character = await api
					.characters( gameSlug )
					.get( characterId );
				// An NPC gets the NPC sheet.
				const resolvedType = character.is_npc
					? character.npc_detail === 'quick'
						? 'npc_quick'
						: 'npc_full'
					: templateType;
				const [ stack, resolved ] = await Promise.all( [
					api.creatureStacks.resolve(
						character.stack_slug,
						gameSlug,
						undefined,
						characterId
					),
					api
						.templates( gameSlug )
						.resolve( character.stack_slug, resolvedType ),
				] );

				// Best-effort: a style-load failure never blocks the sheet, it just renders unstyled.
				api.sheetStyle( gameSlug )
					.get( characterId )
					.then( ( loaded ) => {
						if ( ! cancelled ) {
							setStyle( loaded );
						}
					} )
					.catch( () => undefined );

				// Best-effort preflight: an availability-check failure leaves pdfAvailability null.
				api.sheets( gameSlug )
					.availability()
					.then( ( loaded ) => {
						if ( ! cancelled ) {
							setPdfAvailability( loaded );
						}
					} )
					.catch( () => undefined );

				if ( ! cancelled ) {
					setState( { status: 'ready', character, stack, resolved } );
				}
			} catch ( error ) {
				if ( cancelled ) {
					return;
				}

				const httpStatus = isRestError( error )
					? ( error.data?.status ?? null )
					: null;
				const message =
					httpStatus === 403
						? __(
								'You do not have permission to view this character.',
								'beyond-elysium'
							)
						: httpStatus === 404
							? __( 'No such character.', 'beyond-elysium' )
							: isRestError( error ) && error.message
								? error.message
								: __(
										'Failed to load the character sheet.',
										'beyond-elysium'
									);

				setState( { status: 'error', httpStatus, message } );
			}
		} )();

		return () => {
			cancelled = true;
		};
	}, [ characterId, gameSlug, templateType ] );

	return { state, style, setStyle, pdfAvailability };
}
