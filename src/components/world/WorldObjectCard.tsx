/**
 * Detail view for a single world object (item, location, rote, or boon).
 * Renders its scalar and trait-list properties from the object type's
 * schema, rarity/cost/limitations metadata, and either a full connection
 * manager or a read-only list of connected characters depending on the
 * viewer's permissions.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { displayTrait } from '../../lib/displayTrait';
import { WORLD_OBJECT_SCHEMAS } from '../../types/world';
import type { ItemEvent, LocationLink, WorldObject } from '../../types/world';
import { ConnectionManager } from '../apr/ConnectionManager';
import AttachmentList from '../shared/AttachmentList';
import WhatYouKnow from '../shared/WhatYouKnow';
import './WorldObjectCard.css';

export interface WorldObjectCardProps {
	gameSlug: string;
	objectId: number;
}

const LABELS: Record< string, string > = {
	item_type: __( 'Type', 'beyond-elysium' ),
	item_subtype: __( 'Subtype', 'beyond-elysium' ),
	level: __( 'Level', 'beyond-elysium' ),
	bonus: __( 'Bonus', 'beyond-elysium' ),
	damage_type: __( 'Damage Type', 'beyond-elysium' ),
	damage_amount: __( 'Damage Amount', 'beyond-elysium' ),
	concealability: __( 'Concealability', 'beyond-elysium' ),
	powers: __( 'Powers', 'beyond-elysium' ),
	appearance: __( 'Appearance', 'beyond-elysium' ),
	location_type: __( 'Type', 'beyond-elysium' ),
	owner: __( 'Owner', 'beyond-elysium' ),
	where: __( 'Where', 'beyond-elysium' ),
	access: __( 'Access', 'beyond-elysium' ),
	security: __( 'Security', 'beyond-elysium' ),
	security_traits: __( 'Security Traits', 'beyond-elysium' ),
	security_retests: __( 'Security Retests', 'beyond-elysium' ),
	gauntlet: __( 'Gauntlet', 'beyond-elysium' ),
	umbra: __( 'Umbra', 'beyond-elysium' ),
	affinity: __( 'Affinity', 'beyond-elysium' ),
	totem: __( 'Totem', 'beyond-elysium' ),
	duration: __( 'Duration', 'beyond-elysium' ),
	description: __( 'Description', 'beyond-elysium' ),
	grades: __( 'Grades', 'beyond-elysium' ),
	uses_max: __( 'Uses', 'beyond-elysium' ),
	uses_left: __( 'Uses Left', 'beyond-elysium' ),
	expires_on: __( 'Expires', 'beyond-elysium' ),
};

const EVENT_LABELS: Record< ItemEvent[ 'event' ], string > = {
	given: __( 'Given', 'beyond-elysium' ),
	taken: __( 'Taken', 'beyond-elysium' ),
	traded: __( 'Traded', 'beyond-elysium' ),
	stolen: __( 'Stolen', 'beyond-elysium' ),
	lost: __( 'Lost', 'beyond-elysium' ),
	used: __( 'Used', 'beyond-elysium' ),
	copied: __( 'Copied', 'beyond-elysium' ),
	proposed: __( 'Proposed', 'beyond-elysium' ),
	adjusted: __( 'Adjusted', 'beyond-elysium' ),
};

const TEXT_TYPES = new Set( [ 'string', 'text', 'int', 'date' ] );

/**
 * Loads and renders one world object's detail view: scalar properties
 * rendered as text, trait-list properties formatted through
 * `displayTrait()`, plus rarity/cost/limitations metadata and the
 * object's connected characters. Shows loading and error states while
 * the fetch is in flight or if it fails.
 */
export function WorldObjectCard( {
	gameSlug,
	objectId,
}: WorldObjectCardProps ) {
	const [ object, setObject ] = useState< WorldObject | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	// "Who's here" (1.1.0 §3.9 item 4) - the same /links route the editor's Links panel uses,
	// audience-narrowed to based_at NPCs already for a non-manager viewer.
	const [ whosHere, setWhosHere ] = useState< LocationLink[] >( [] );

	useEffect( () => {
		setLoading( true );
		setError( null );
		api.worldObjects( gameSlug )
			.get( objectId )
			.then( ( result ) => {
				setObject( result );
				setLoading( false );
			} )
			.catch( () => {
				setError(
					__( 'Failed to load this world object.', 'beyond-elysium' )
				);
				setLoading( false );
			} );
	}, [ gameSlug, objectId ] );

	useEffect( () => {
		if ( object?.object_type !== 'location' ) {
			return;
		}
		api.locations( gameSlug )
			.links( objectId )
			.then( setWhosHere )
			.catch( () => setWhosHere( [] ) );
	}, [ gameSlug, objectId, object?.object_type ] );

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
	const canManage =
		!! window.beyondElysium?.capabilities?.be_manage_world_objects;
	const takesAttachments =
		object.object_type === 'item' || object.object_type === 'location';

	function setAttachments( attachments: WorldObject[ 'attachments' ] ) {
		setObject( ( prev ) => ( prev ? { ...prev, attachments } : prev ) );
	}

	return (
		<div className="be-world-card">
			{ object.object_type === 'location' &&
				!! object.ancestors?.length && (
					<nav
						className="be-world-card__breadcrumb"
						aria-label={ __(
							'Location breadcrumb',
							'beyond-elysium'
						) }
					>
						{ object.ancestors
							.slice()
							.reverse()
							.map( ( a ) => a.name )
							.join( ' › ' ) }
						{ ' › ' }
					</nav>
				) }
			<h3 className="be-world-card__name">{ object.name }</h3>
			{ /* 1.1.0 §3.12 item 1 - based_on is absent entirely for an ordinary item, null when
				the source has since been deleted. */ }
			{ object.based_on && (
				<p className="be-world-card__based-on">
					{ sprintf(
						/* translators: %s: the source item's name */
						__( 'Based on %s', 'beyond-elysium' ),
						object.based_on.name
					) }
				</p>
			) }
			{ ( object.used_up || object.expired ) && (
				<p className="be-world-card__status-banner">
					{ object.used_up && __( 'Used up.', 'beyond-elysium' ) }
					{ object.used_up && object.expired && ' ' }
					{ object.expired && __( 'Expired.', 'beyond-elysium' ) }
				</p>
			) }
			{ /* Rich text, sanitized server-side with wp_kses_post() on save. */ }
			{ object.description && (
				<div
					className="be-world-card__description"
					dangerouslySetInnerHTML={ {
						__html: object.description,
					} }
				/>
			) }

			<dl className="be-world-card__properties">
				{ Object.entries( schema ).map( ( [ key, type ] ) => {
					// Display over Grapevine text (1.1.0 §3.9 item 3): a location's Owner/Where
					// prefer a real link/parent name, already resolved server-side.
					const value =
						object.object_type === 'location' &&
						( key === 'owner' || key === 'where' ) &&
						object.display
							? object.display[ key as 'owner' | 'where' ]
							: object.properties[ key ];
					if (
						value === undefined ||
						value === null ||
						value === ''
					) {
						return null;
					}
					return (
						<div key={ key } className="be-world-card__property">
							<dt>{ LABELS[ key ] ?? key }</dt>
							<dd>
								{ type === 'trait_list' ? (
									<TraitList
										entries={
											value as Array< {
												name: string;
												count?: number;
												note?: string;
											} >
										}
									/>
								) : type === 'text' ? (
									// Rich text, sanitized server-side with wp_kses_post() on save.
									<div
										dangerouslySetInnerHTML={ {
											__html: value as string,
										} }
									/>
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
					<strong>{ __( 'Rarity:', 'beyond-elysium' ) }</strong>{ ' ' }
					{ object.rarity }
				</p>
			) }
			{ object.cost && (
				<p className="be-world-card__meta">
					<strong>{ __( 'Cost:', 'beyond-elysium' ) }</strong>{ ' ' }
					{ object.cost }
				</p>
			) }
			{ /* Rich text, sanitized server-side with wp_kses_post() on save. */ }
			{ object.limitations && (
				<div className="be-world-card__meta">
					<strong>{ __( 'Limitations:', 'beyond-elysium' ) }</strong>{ ' ' }
					<span
						dangerouslySetInnerHTML={ {
							__html: object.limitations,
						} }
					/>
				</div>
			) }

			{ object.object_type === 'location' &&
				!! object.children?.length && (
					<>
						<h4>
							{ __( 'Inside This Location', 'beyond-elysium' ) }
						</h4>
						<ul className="be-world-card__children">
							{ object.children.map( ( c ) => (
								<li key={ c.id }>{ c.name }</li>
							) ) }
						</ul>
					</>
				) }

			{ object.object_type === 'location' && (
				<>
					<h4>{ __( "Who's Here", 'beyond-elysium' ) }</h4>
					{ whosHere.length === 0 ? (
						<p>
							{ __(
								'Nobody known is here right now.',
								'beyond-elysium'
							) }
						</p>
					) : (
						<ul className="be-world-card__characters">
							{ whosHere.map( ( link ) => (
								<li key={ link.id }>
									{ link.name }
									{ canManage && (
										<span className="be-world-card__character-label">
											{ ' ' }
											({ link.label })
										</span>
									) }
								</li>
							) ) }
						</ul>
					) }
				</>
			) }

			{ ! canManage &&
				( object.object_type === 'item' ||
					object.object_type === 'location' ) && (
					<WhatYouKnow
						gameSlug={ gameSlug }
						entityType={ object.object_type }
						entityId={ objectId }
					/>
				) }

			{ takesAttachments && (
				<>
					<h4>{ __( 'Files', 'beyond-elysium' ) }</h4>
					<AttachmentList
						gameSlug={ gameSlug }
						entityType={ object.object_type as 'item' | 'location' }
						entityId={ objectId }
						attachments={ object.attachments ?? [] }
						canManage={ canManage }
						onChange={ setAttachments }
					/>
				</>
			) }

			{ /* Full connection manager when permitted, otherwise a read-only connected-characters list. */ }
			{ window.beyondElysium?.capabilities?.be_manage_connections ? (
				<>
					<h4>{ __( 'Connections', 'beyond-elysium' ) }</h4>
					<ConnectionManager
						gameSlug={ gameSlug }
						entityType="world_object"
						entityId={ objectId }
					/>
				</>
			) : (
				<>
					<h4>{ __( 'Connected Characters', 'beyond-elysium' ) }</h4>
					{ ! object.connected_characters ||
					object.connected_characters.length === 0 ? (
						<p>{ __( 'None.', 'beyond-elysium' ) }</p>
					) : (
						<ul className="be-world-card__characters">
							{ object.connected_characters.map(
								( character ) => (
									<li key={ character.id }>
										{ character.name }
										{ character.label && (
											<span className="be-world-card__character-label">
												{ ' ' }
												({ character.label })
											</span>
										) }
									</li>
								)
							) }
						</ul>
					) }
				</>
			) }
			{ canManage && object.object_type === 'item' && (
				<ItemHistory gameSlug={ gameSlug } objectId={ objectId } />
			) }
		</div>
	);
}

/**
 * A manager-only history section for one item (1.1.0 §3.12 item 3) - every event recorded
 * against it, oldest first. Loaded on mount rather than gated behind a tab, since this
 * component has no existing tab UI to reuse.
 */
function ItemHistory( {
	gameSlug,
	objectId,
}: {
	gameSlug: string;
	objectId: number;
} ) {
	const [ events, setEvents ] = useState< ItemEvent[] | null >( null );

	useEffect( () => {
		api.worldObjects( gameSlug )
			.events( objectId )
			.then( setEvents )
			.catch( () => setEvents( [] ) );
	}, [ gameSlug, objectId ] );

	return (
		<div className="be-world-card__history">
			<h4>{ __( 'History', 'beyond-elysium' ) }</h4>
			{ events === null ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : events.length === 0 ? (
				<p>{ __( 'No history yet.', 'beyond-elysium' ) }</p>
			) : (
				<ul className="be-world-card__history-list">
					{ events.map( ( event ) => (
						<li key={ event.id }>
							{ EVENT_LABELS[ event.event ] }
							{ ' · ' }
							{ event.created_at }
							{ event.note && (
								<>
									{ ' — ' }
									{ event.note }
								</>
							) }
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

/**
 * Renders a list of trait-list property entries (name, count, note)
 * using the same `displayTrait()` formatter as character-sheet trait
 * lists. Renders "None" when the entry list is empty or not an array.
 */
function TraitList( {
	entries,
}: {
	entries: Array< { name: string; count?: number; note?: string } >;
} ) {
	if ( ! Array.isArray( entries ) || entries.length === 0 ) {
		return <>{ __( 'None', 'beyond-elysium' ) }</>;
	}
	return (
		<ul className="be-world-card__trait-list">
			{ entries.map( ( entry, i ) => (
				<li key={ `${ entry.name }-${ i }` }>
					{ displayTrait(
						{
							name: entry.name,
							total: entry.count,
							note: entry.note,
						},
						'multiplier'
					) }
				</li>
			) ) }
		</ul>
	);
}

export default WorldObjectCard;
