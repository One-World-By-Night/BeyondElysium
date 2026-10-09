/**
 * Loads a chronicle's creature types once, for screens that show a character's type by name.
 */
import { useEffect, useState } from '@wordpress/element';
import api from '../api/client';

export interface CreatureTypeName {
	slug: string;
	name: string;
}

const NONE: CreatureTypeName[] = [];
const loaded = new Map< string, CreatureTypeName[] >();

/**
 * The creature types already loaded for one chronicle, by slug and name; none until they arrive. A chronicle never
 * reads another chronicle's list.
 */
export function loadedCreatureTypes( gameSlug: string ): CreatureTypeName[] {
	return loaded.get( gameSlug ) ?? NONE;
}

/**
 * Keeps a chronicle's creature types for every screen that asks for them next.
 */
export function rememberCreatureTypes(
	gameSlug: string,
	types: CreatureTypeName[]
): void {
	loaded.set( gameSlug, types );
}

/**
 * Every creature type a chronicle could use, by slug and name; empty until they load.
 */
export function useCreatureTypeNames( gameSlug: string ): CreatureTypeName[] {
	const [ , setArrivals ] = useState( 0 );

	useEffect( () => {
		if ( ! gameSlug || loaded.has( gameSlug ) ) {
			return undefined;
		}
		let live = true;
		api.creatureStacks
			.list( {
				game_slug: gameSlug,
				include_disabled: true,
				per_page: 100,
			} )
			.then( ( stacks ) => {
				rememberCreatureTypes(
					gameSlug,
					stacks.map( ( stack ) => ( {
						slug: stack.slug,
						name: stack.name,
					} ) )
				);
				if ( live ) {
					setArrivals( ( count ) => count + 1 );
				}
			} )
			.catch( () => {
				// The stored words stay on screen when the names do not arrive.
			} );
		return () => {
			live = false;
		};
	}, [ gameSlug ] );

	return loadedCreatureTypes( gameSlug );
}
