/**
 * Detail view for a single world object (item, location, rote, or boon).
 * Renders its scalar and trait-list properties from the object type's
 * schema, rarity/cost/limitations metadata, and either a full connection
 * manager or a read-only list of connected characters depending on the
 * viewer's permissions.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import { displayTrait } from '../../lib/displayTrait';
import { WORLD_OBJECT_SCHEMAS } from '../../types/world';
import type { WorldObject } from '../../types/world';
import { ConnectionManager } from '../apr/ConnectionManager';
import './WorldObjectCard.css';

export interface WorldObjectCardProps {
	gameSlug: string;
	objectId: number;
}

const LABELS: Record<string, string> = {
	item_type: __( 'Type', 'beyond-elysium' ), item_subtype: __( 'Subtype', 'beyond-elysium' ), level: __( 'Level', 'beyond-elysium' ), bonus: __( 'Bonus', 'beyond-elysium' ),
	damage_type: __( 'Damage Type', 'beyond-elysium' ), damage_amount: __( 'Damage Amount', 'beyond-elysium' ), concealability: __( 'Concealability', 'beyond-elysium' ),
	powers: __( 'Powers', 'beyond-elysium' ), appearance: __( 'Appearance', 'beyond-elysium' ), location_type: __( 'Type', 'beyond-elysium' ), owner: __( 'Owner', 'beyond-elysium' ), where: __( 'Where', 'beyond-elysium' ),
	access: __( 'Access', 'beyond-elysium' ), security: __( 'Security', 'beyond-elysium' ), security_traits: __( 'Security Traits', 'beyond-elysium' ),
	security_retests: __( 'Security Retests', 'beyond-elysium' ), gauntlet: __( 'Gauntlet', 'beyond-elysium' ), umbra: __( 'Umbra', 'beyond-elysium' ), affinity: __( 'Affinity', 'beyond-elysium' ),
	totem: __( 'Totem', 'beyond-elysium' ), duration: __( 'Duration', 'beyond-elysium' ), description: __( 'Description', 'beyond-elysium' ), grades: __( 'Grades', 'beyond-elysium' ),
};

const TEXT_TYPES = new Set( [ 'string', 'text', 'int', 'date' ] );

/**
 * Loads and renders one world object's detail view: scalar properties
 * rendered as text, trait-list properties formatted through
 * `displayTrait()`, plus rarity/cost/limitations metadata and the
 * object's connected characters. Shows loading and error states while
 * the fetch is in flight or if it fails.
 */
export function WorldObjectCard( { gameSlug, objectId }: WorldObjectCardProps ) {
	const [ object, setObject ] = useState<WorldObject | null>( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );

	useEffect( () => {
		setLoading( true );
		setError( null );
		api
			.worldObjects( gameSlug )
			.get( objectId )
			.then( ( result ) => {
				setObject( result );
				setLoading( false );
			} )
			.catch( () => {
				setError( __( 'Failed to load this world object.', 'beyond-elysium' ) );
				setLoading( false );
			} );
	}, [ gameSlug, objectId ] );

	if ( loading ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}
	if ( error || ! object ) {
		return (
			<div className="be-world-card__error" role="alert">
				{ error ?? __( 'Not found.', 'beyond-elysium' ) }
			</div>
		);
	}

	const schema = WORLD_OBJECT_SCHEMAS[ object.object_type ] ?? {};

	return (
		<div className="be-world-card">
			<h3 className="be-world-card__name">{ object.name }</h3>
			{ object.description && <p className="be-world-card__description">{ object.description }</p> }

			<dl className="be-world-card__properties">
				{ Object.entries( schema ).map( ( [ key, type ] ) => {
					const value = object.properties[ key ];
					if ( value === undefined || value === null || value === '' ) {
						return null;
					}
					return (
						<div key={ key } className="be-world-card__property">
							<dt>{ LABELS[ key ] ?? key }</dt>
							<dd>
								{ type === 'trait_list' ? (
									<TraitList entries={ value as Array<{ name: string; count?: number; note?: string }> } />
								) : TEXT_TYPES.has( type ) ? (
									String( value )
								) : (
									JSON.stringify( value )
								) }
							</dd>
						</div>
					);
				} ) }
			</dl>

			{ object.rarity && (
				<p className="be-world-card__meta">
					<strong>{ __( 'Rarity:', 'beyond-elysium' ) }</strong> { object.rarity }
				</p>
			) }
			{ object.cost && (
				<p className="be-world-card__meta">
					<strong>{ __( 'Cost:', 'beyond-elysium' ) }</strong> { object.cost }
				</p>
			) }
			{ object.limitations && (
				<p className="be-world-card__meta">
					<strong>{ __( 'Limitations:', 'beyond-elysium' ) }</strong> { object.limitations }
				</p>
			) }

			{ /* Full connection manager when permitted, otherwise a read-only connected-characters list. */ }
			{ window.beyondElysium?.capabilities?.be_manage_connections ? (
				<>
					<h4>{ __( 'Connections', 'beyond-elysium' ) }</h4>
					<ConnectionManager gameSlug={ gameSlug } entityType="world_object" entityId={ objectId } />
				</>
			) : (
				<>
					<h4>{ __( 'Connected Characters', 'beyond-elysium' ) }</h4>
					{ ! object.connected_characters || object.connected_characters.length === 0 ? (
						<p>{ __( 'None.', 'beyond-elysium' ) }</p>
					) : (
						<ul className="be-world-card__characters">
							{ object.connected_characters.map( ( character ) => (
								<li key={ character.id }>
									{ character.name }
									{ character.label && <span className="be-world-card__character-label"> ({ character.label })</span> }
								</li>
							) ) }
						</ul>
					) }
				</>
			) }
		</div>
	);
}

/**
 * Renders a list of trait-list property entries (name, count, note)
 * using the same `displayTrait()` formatter as character-sheet trait
 * lists. Renders "None" when the entry list is empty or not an array.
 */
function TraitList( { entries }: { entries: Array<{ name: string; count?: number; note?: string }> } ) {
	if ( ! Array.isArray( entries ) || entries.length === 0 ) {
		return <>{ __( 'None', 'beyond-elysium' ) }</>;
	}
	return (
		<ul className="be-world-card__trait-list">
			{ entries.map( ( entry, i ) => (
				<li key={ `${ entry.name }-${ i }` }>
					{ displayTrait( { name: entry.name, total: entry.count, note: entry.note }, 'multiplier' ) }
				</li>
			) ) }
		</ul>
	);
}

export default WorldObjectCard;
