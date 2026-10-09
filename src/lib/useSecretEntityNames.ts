/**
 * Loads every character (player and NPC), plot and world object in a chronicle once, and exposes a display-name
 * lookup for each of the five entity types a secret can attach to.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import api from '../api/client';
import { everyPage } from './everyPage';
import { pickerNames } from './pickerNames';
import type { Character } from '../types/character';
import type { Plot } from '../types/plot';
import type { WorldObject } from '../types/world';
import type { SecretEntityType } from '../types/secret';

export interface SecretEntityNames {
	characters: Character[];
	namesByType: Record< SecretEntityType, Map< string, number > >;
	nameFor: (
		entityType: SecretEntityType,
		entityId: number
	) => string | null;
}

/**
 * Builds each of the five entity-type name maps from a chronicle's own loaded characters, plots and world objects.
 */
export function buildSecretNamesByType(
	characters: Character[],
	plots: Plot[],
	worldObjects: WorldObject[]
): Record< SecretEntityType, Map< string, number > > {
	return {
		character: pickerNames(
			characters.filter( ( c ) => ! c.is_npc ),
			( c ) => c.name
		),
		npc: pickerNames(
			characters.filter( ( c ) => c.is_npc ),
			( c ) => c.name
		),
		plot: pickerNames( plots, ( p ) => p.title ),
		item: pickerNames(
			worldObjects.filter( ( w ) => w.object_type === 'item' ),
			( w ) => w.name
		),
		location: pickerNames(
			worldObjects.filter( ( w ) => w.object_type === 'location' ),
			( w ) => w.name
		),
	};
}

/**
 * Resolves one entity's own display name from a chronicle's loaded characters, plots and world objects, or `null`
 * when it hasn't loaded yet (or doesn't exist).
 */
export function resolveSecretEntityName(
	entityType: SecretEntityType,
	entityId: number,
	characters: Character[],
	plots: Plot[],
	worldObjects: WorldObject[]
): string | null {
	if ( entityType === 'plot' ) {
		return plots.find( ( p ) => p.id === entityId )?.title ?? null;
	}
	if ( entityType === 'item' || entityType === 'location' ) {
		return (
			worldObjects.find(
				( w ) => w.id === entityId && w.object_type === entityType
			)?.name ?? null
		);
	}
	return characters.find( ( c ) => c.id === entityId )?.name ?? null;
}

export function useSecretEntityNames( gameSlug: string ): SecretEntityNames {
	const [ characters, setCharacters ] = useState< Character[] >( [] );
	const [ plots, setPlots ] = useState< Plot[] >( [] );
	const [ worldObjects, setWorldObjects ] = useState< WorldObject[] >( [] );

	useEffect( () => {
		Promise.all( [
			everyPage( ( page ) =>
				api
					.characters( gameSlug )
					.listPaginated( { page, per_page: 100 } )
			),
			everyPage( ( page ) =>
				api
					.characters( gameSlug )
					.listPaginated( { page, per_page: 100, is_npc: true } )
			),
		] )
			.then( ( lists ) => setCharacters( lists.flat() ) )
			.catch( () => setCharacters( [] ) );
		everyPage( ( page ) =>
			api.plots( gameSlug ).listPaginated( { page, per_page: 100 } )
		)
			.then( setPlots )
			.catch( () => setPlots( [] ) );
		everyPage( ( page ) =>
			api
				.worldObjects( gameSlug )
				.listPaginated( { page, per_page: 100 } )
		)
			.then( setWorldObjects )
			.catch( () => setWorldObjects( [] ) );
	}, [ gameSlug ] );

	const namesByType = useMemo(
		() => buildSecretNamesByType( characters, plots, worldObjects ),
		[ characters, plots, worldObjects ]
	);

	function nameFor(
		entityType: SecretEntityType,
		entityId: number
	): string | null {
		return resolveSecretEntityName(
			entityType,
			entityId,
			characters,
			plots,
			worldObjects
		);
	}

	return { characters, namesByType, nameFor };
}
