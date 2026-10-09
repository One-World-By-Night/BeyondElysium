/**
 * Storyteller Toolkit: a circular chart of character-to-character relationships, grouped by faction. Defaults to
 * one focus character and one step out; the whole chronicle is only offered once it has few enough connected
 * characters to stay readable.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { everyPage } from '../../lib/everyPage';
import { characterSheetUrl } from '../../lib/pluginPages';
import {
	edgeSummary,
	groupEdges,
	mergeCharacters,
	normalizeConnections,
	splitStMarkers,
	wrapLabel,
} from '../../lib/relationshipPairs';
import HelpButton from '../shared/HelpButton';
import Modal from '../shared/Modal';
import type { Character } from '../../types/character';
import type { Connection } from '../../types/plot';
import type { Faction } from '../../types/faction';
import './RelationshipChart.css';

export interface RelationshipChartProps {
	gameSlug: string;
}

const WHOLE_CHRONICLE_LIMIT = 60;
const CENTER = 300;
const RADIUS = 240;
const LABEL_OFFSET = 14;
const LABEL_WRAP = 22;
const LABEL_CHARACTER_WIDTH = 7;
const PALETTE = [
	'#4e79a7',
	'#f28e2b',
	'#59a14f',
	'#e15759',
	'#b07aa1',
	'#76b7b2',
	'#edc948',
	'#ff9da7',
];
const UNAFFILIATED_COLOR = '#999';

export interface Node {
	id: number;
	name: string;
	factionId: number | null;
	x: number;
	y: number;
}

/**
 * Whole-chronicle mode: every connected character. Otherwise: one focus character plus whoever it's directly
 * connected to. Neither reads the filters - those narrow this result afterward.
 */
export function computeBaseVisibleIds(
	wholeChronicle: boolean,
	connectedIds: Set< number >,
	focusId: number | null,
	connections: Connection[]
): Set< number > {
	if ( wholeChronicle ) {
		return connectedIds;
	}
	if ( focusId === null ) {
		return new Set< number >();
	}
	const ids = new Set< number >( [ focusId ] );
	connections.forEach( ( c ) => {
		const source = Number( c.source_id );
		const target = c.target_id === null ? null : Number( c.target_id );
		if ( source === focusId && target !== null ) {
			ids.add( target );
		}
		if ( target === focusId ) {
			ids.add( source );
		}
	} );
	return ids;
}

/**
 * Narrows a visible-id set to characters matching the given faction and/or creature-type filter, dropping any
 * id with no matching character at all.
 */
export function applyChartFilters(
	ids: Set< number >,
	charactersById: Record< number, Character >,
	membership: Record< number, number >,
	factionFilter: string,
	creatureTypeFilter: string
): Set< number > {
	return new Set(
		Array.from( ids ).filter( ( id ) => {
			const character = charactersById[ id ];
			if ( ! character ) {
				return false;
			}
			if (
				factionFilter &&
				String( membership[ id ] ?? '' ) !== factionFilter
			) {
				return false;
			}
			if (
				creatureTypeFilter &&
				character.stack_slug !== creatureTypeFilter
			) {
				return false;
			}
			return true;
		} )
	);
}

/**
 * Places every visible character on a fixed circle, sorted by faction then name so same-faction characters
 * cluster into a contiguous arc rather than scattering around it.
 */
export function layoutNodes(
	visibleIds: Set< number >,
	charactersById: Record< number, Character >,
	membership: Record< number, number >
): Node[] {
	const sorted = Array.from( visibleIds )
		.map( ( id ) => charactersById[ id ] )
		.filter( ( c ): c is Character => !! c )
		.sort( ( a, b ) => {
			const fa = membership[ a.id ] ?? Number.MAX_SAFE_INTEGER;
			const fb = membership[ b.id ] ?? Number.MAX_SAFE_INTEGER;
			if ( fa !== fb ) {
				return fa - fb;
			}
			return a.name.localeCompare( b.name );
		} );

	const count = sorted.length;
	return sorted.map( ( character, index ) => {
		const angle =
			( index / Math.max( count, 1 ) ) * 2 * Math.PI - Math.PI / 2;
		return {
			id: character.id,
			name: character.name,
			factionId: membership[ character.id ] ?? null,
			x: CENTER + RADIUS * Math.cos( angle ),
			y: CENTER + RADIUS * Math.sin( angle ),
		};
	} );
}

export function RelationshipChart( { gameSlug }: RelationshipChartProps ) {
	const [ characters, setCharacters ] = useState< Character[] >( [] );
	const [ connections, setConnections ] = useState< Connection[] >( [] );
	const [ factions, setFactions ] = useState< Faction[] >( [] );
	const [ membership, setMembership ] = useState< Record< number, number > >(
		{}
	);
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

	const [ focusId, setFocusId ] = useState< number | null >( null );
	const [ wholeChronicle, setWholeChronicle ] = useState( false );
	const [ factionFilter, setFactionFilter ] = useState( '' );
	const [ creatureTypeFilter, setCreatureTypeFilter ] = useState( '' );
	const [ openPair, setOpenPair ] = useState< string | null >( null );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setError( null );

		Promise.all( [
			everyPage< Character >( ( page ) =>
				api
					.characters( gameSlug )
					.listPaginated( { page, per_page: 100 } )
			),
			everyPage< Character >( ( page ) =>
				api
					.characters( gameSlug )
					.listPaginated( { page, per_page: 100, is_npc: true } )
			),
			api.connections( gameSlug ).list( {
				source_type: 'character',
				target_type: 'character',
			} ),
			api.factions( gameSlug ).list(),
		] )
			.then( ( [ players, npcs, allConnections, allFactions ] ) =>
				Promise.all(
					allFactions.map( ( f ) =>
						api.factions( gameSlug ).members( f.id )
					)
				).then( ( rosters ) => {
					if ( cancelled ) {
						return;
					}
					const byCharacter: Record< number, number > = {};
					rosters.forEach( ( roster, index ) => {
						roster.forEach( ( member ) => {
							byCharacter[ member.character_id ] =
								allFactions[ index ].id;
						} );
					} );
					const sorted = mergeCharacters( players, npcs );
					setCharacters( sorted );
					setConnections( normalizeConnections( allConnections ) );
					setFactions( allFactions );
					setMembership( byCharacter );
					setFocusId( sorted[ 0 ]?.id ?? null );
					setLoading( false );
				} )
			)
			.catch( () => {
				if ( ! cancelled ) {
					setError(
						__( 'Failed to load relationships.', 'beyond-elysium' )
					);
					setLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ gameSlug ] );

	const charactersById = useMemo( () => {
		const map: Record< number, Character > = {};
		characters.forEach( ( c ) => {
			map[ c.id ] = c;
		} );
		return map;
	}, [ characters ] );

	const connectedIds = useMemo( () => {
		const ids = new Set< number >();
		connections.forEach( ( c ) => {
			ids.add( c.source_id );
			if ( c.target_id !== null ) {
				ids.add( c.target_id );
			}
		} );
		return ids;
	}, [ connections ] );

	const creatureTypes = useMemo(
		() =>
			Array.from(
				new Set( characters.map( ( c ) => c.stack_slug ) )
			).sort(),
		[ characters ]
	);

	const canShowWholeChronicle = connectedIds.size <= WHOLE_CHRONICLE_LIMIT;

	const baseVisibleIds = useMemo(
		() =>
			computeBaseVisibleIds(
				wholeChronicle,
				connectedIds,
				focusId,
				connections
			),
		[ wholeChronicle, connectedIds, focusId, connections ]
	);

	const visibleIds = useMemo(
		() =>
			applyChartFilters(
				baseVisibleIds,
				charactersById,
				membership,
				factionFilter,
				creatureTypeFilter
			),
		[
			baseVisibleIds,
			charactersById,
			membership,
			factionFilter,
			creatureTypeFilter,
		]
	);

	const factionColor = useMemo( () => {
		const map: Record< number, string > = {};
		factions.forEach( ( f, index ) => {
			map[ f.id ] = PALETTE[ index % PALETTE.length ];
		} );
		return map;
	}, [ factions ] );

	const nodes = useMemo< Node[] >(
		() => layoutNodes( visibleIds, charactersById, membership ),
		[ visibleIds, charactersById, membership ]
	);

	const nodesById = useMemo( () => {
		const map: Record< number, Node > = {};
		nodes.forEach( ( n ) => {
			map[ n.id ] = n;
		} );
		return map;
	}, [ nodes ] );

	const edgeGroups = useMemo(
		() => groupEdges( connections, ( id ) => !! nodesById[ id ] ),
		[ connections, nodesById ]
	);

	const labelLines = useMemo( () => {
		const map: Record< number, string[] > = {};
		nodes.forEach( ( n ) => {
			map[ n.id ] = wrapLabel( n.name, LABEL_WRAP );
		} );
		return map;
	}, [ nodes ] );

	const labelRoom = ( onLeft: boolean ) =>
		LABEL_OFFSET +
		8 +
		LABEL_CHARACTER_WIDTH *
			nodes
				.filter( ( n ) => n.x < CENTER === onLeft )
				.reduce(
					( widest, n ) =>
						Math.max(
							widest,
							...( labelLines[ n.id ] ?? [] ).map(
								( line ) => line.length
							)
						),
					0
				);
	const roomLeft = labelRoom( true );
	const roomRight = labelRoom( false );
	const viewWidth = RADIUS * 2 + roomLeft + roomRight;

	const nameOf = ( id: number ) => charactersById[ id ]?.name ?? '';
	const openGroup = edgeGroups.find( ( group ) => group.key === openPair );

	if ( loading ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}

	return (
		<div className="be-relationship-chart">
			<div className="be-help-heading">
				<h2>{ __( 'Relationships', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="relationships" />
			</div>

			{ error && (
				<div className="be-relationship-chart__error" role="alert">
					{ error }
				</div>
			) }

			<div className="be-relationship-chart__controls">
				<label>
					{ __( 'Focus on', 'beyond-elysium' ) }
					<select
						value={ focusId ?? '' }
						disabled={ wholeChronicle }
						onChange={ ( e ) => {
							setFocusId( Number( e.target.value ) );
							setWholeChronicle( false );
						} }
					>
						{ characters.map( ( c ) => (
							<option key={ c.id } value={ c.id }>
								{ c.name }
							</option>
						) ) }
					</select>
				</label>

				<label className="be-relationship-chart__toggle">
					<input
						type="checkbox"
						checked={ wholeChronicle }
						disabled={ ! canShowWholeChronicle }
						onChange={ ( e ) =>
							setWholeChronicle( e.target.checked )
						}
					/>
					{ __( 'Show whole chronicle', 'beyond-elysium' ) }
				</label>

				<label>
					{ __( 'Faction', 'beyond-elysium' ) }
					<select
						value={ factionFilter }
						onChange={ ( e ) => setFactionFilter( e.target.value ) }
					>
						<option value="">
							{ __( 'All', 'beyond-elysium' ) }
						</option>
						{ factions.map( ( f ) => (
							<option key={ f.id } value={ String( f.id ) }>
								{ f.name }
							</option>
						) ) }
					</select>
				</label>

				<label>
					{ __( 'Creature type', 'beyond-elysium' ) }
					<select
						value={ creatureTypeFilter }
						onChange={ ( e ) =>
							setCreatureTypeFilter( e.target.value )
						}
					>
						<option value="">
							{ __( 'All', 'beyond-elysium' ) }
						</option>
						{ creatureTypes.map( ( slug ) => (
							<option key={ slug } value={ slug }>
								{ slug }
							</option>
						) ) }
					</select>
				</label>
			</div>

			{ ! canShowWholeChronicle && (
				<p className="description">
					{ __(
						'Too many connected characters to show the whole chronicle at once - narrow with a filter, or focus on one character.',
						'beyond-elysium'
					) }
				</p>
			) }

			{ nodes.length === 0 ? (
				<p>
					{ __(
						'No characters match the current focus and filters.',
						'beyond-elysium'
					) }
				</p>
			) : (
				<>
					<svg
						className="be-relationship-chart__svg"
						style={ { maxWidth: viewWidth } }
						viewBox={ `${ CENTER - RADIUS - roomLeft } 0 ${ viewWidth } ${
							CENTER * 2
						}` }
					>
						{ edgeGroups.map( ( group ) => {
							const from = nodesById[ group.a ];
							const to = nodesById[ group.b ];
							const summary = edgeSummary(
								nameOf( group.a ),
								nameOf( group.b ),
								group.connections
							);
							return (
								<g
									key={ group.key }
									className="be-relationship-chart__edge-group"
									role="button"
									tabIndex={ 0 }
									aria-label={ summary }
									onClick={ () => setOpenPair( group.key ) }
									onKeyDown={ ( e ) => {
										if (
											e.key === 'Enter' ||
											e.key === ' '
										) {
											e.preventDefault();
											setOpenPair( group.key );
										}
									} }
								>
									<title>{ summary }</title>
									<line
										x1={ from.x }
										y1={ from.y }
										x2={ to.x }
										y2={ to.y }
										className="be-relationship-chart__edge"
									/>
									<line
										x1={ from.x }
										y1={ from.y }
										x2={ to.x }
										y2={ to.y }
										className="be-relationship-chart__edge-hit"
									/>
								</g>
							);
						} ) }
						{ nodes.map( ( node ) => {
							const isFocus =
								! wholeChronicle && node.id === focusId;
							const color = node.factionId
								? factionColor[ node.factionId ]
								: UNAFFILIATED_COLOR;
							const onLeft = node.x < CENTER;
							return (
								<a
									key={ node.id }
									href={ characterSheetUrl(
										node.id,
										gameSlug
									) }
									className="be-relationship-chart__node"
								>
									<circle
										cx={ node.x }
										cy={ node.y }
										r={ isFocus ? 10 : 6 }
										fill={ color }
										className={
											isFocus
												? 'be-relationship-chart__node-circle be-relationship-chart__node-circle--focus'
												: 'be-relationship-chart__node-circle'
										}
									/>
									<text
										x={
											node.x +
											( onLeft
												? -LABEL_OFFSET
												: LABEL_OFFSET )
										}
										y={ node.y }
										textAnchor={ onLeft ? 'end' : 'start' }
										dominantBaseline="middle"
										className="be-relationship-chart__node-label"
									>
										{ ( labelLines[ node.id ] ?? [] ).map(
											( line, index, lines ) => (
												<tspan
													key={ index }
													x={
														node.x +
														( onLeft
															? -LABEL_OFFSET
															: LABEL_OFFSET )
													}
													dy={
														index === 0
															? `${
																	-(
																		lines.length -
																		1
																	) * 0.6
																}em`
															: '1.2em'
													}
												>
													{ line }
												</tspan>
											)
										) }
									</text>
								</a>
							);
						} ) }
					</svg>

					<h3 className="be-relationship-chart__list-heading">
						{ __( 'Connections', 'beyond-elysium' ) }
					</h3>
					<ul className="be-relationship-chart__list">
						{ edgeGroups.map( ( group ) => (
							<li key={ group.key }>
								<button
									type="button"
									className="be-relationship-chart__list-button"
									onClick={ () => setOpenPair( group.key ) }
								>
									{ edgeSummary(
										nameOf( group.a ),
										nameOf( group.b ),
										group.connections
									) }
								</button>
							</li>
						) ) }
					</ul>
				</>
			) }

			{ openGroup && (
				<Modal
					title={ sprintf(
						/* translators: 1: a character's name, 2: another character's name */
						__( '%1$s and %2$s', 'beyond-elysium' ),
						nameOf( openGroup.a ),
						nameOf( openGroup.b )
					) }
					onClose={ () => setOpenPair( null ) }
					footer={
						<button
							type="button"
							onClick={ () => setOpenPair( null ) }
						>
							{ __( 'Close', 'beyond-elysium' ) }
						</button>
					}
				>
					<ul className="be-relationship-chart__connections">
						{ openGroup.connections.map( ( c ) => (
							<li key={ c.id }>
								<p className="be-relationship-chart__connection-who">
									<a
										href={ characterSheetUrl(
											c.source_id,
											gameSlug
										) }
									>
										{ nameOf( c.source_id ) }
									</a>
									{ ' → ' }
									<a
										href={ characterSheetUrl(
											c.target_id as number,
											gameSlug
										) }
									>
										{ nameOf( c.target_id as number ) }
									</a>
								</p>
								{ c.label && (
									<p>
										<strong>{ c.label }</strong>
									</p>
								) }
								{ c.notes && (
									<p className="be-relationship-chart__connection-notes">
										{ splitStMarkers( c.notes ).map(
											( segment, index ) =>
												segment.st ? (
													<mark
														key={ index }
														className="be-st-marker"
													>
														{ segment.text }
													</mark>
												) : (
													<span key={ index }>
														{ segment.text }
													</span>
												)
										) }
									</p>
								) }
								<p className="description">
									{ c.created_at.slice( 0, 10 ) }
								</p>
							</li>
						) ) }
					</ul>
				</Modal>
			) }
		</div>
	);
}

export default RelationshipChart;
