/**
 * The compendium: the chronicle's catalog a creature type and a section at a time, with what each entry costs a
 * chosen character, the chronicle's own notes and the trait panel one tap away.
 */
import { __, _n, sprintf } from '@wordpress/i18n';
import { useEffect, useMemo, useState } from '@wordpress/element';
import api from '../../api/client';
import useCharacterEditorStore from '../../store/characterEditorStore';
import HelpButton from '../shared/HelpButton';
import TraitPanel from '../character/TraitPanel';
import { canIn } from '../../lib/chronicleCapabilities';
import {
	asTrait,
	costWords,
	familyCost,
	groupEntries,
	heldInSection,
	heldWords,
	isFamily,
	withNotes,
} from '../../lib/compendium';
import { compendiumTarget } from '../../lib/compendiumLinks';
import { localizedItemName } from '../../lib/localizeName';
import { PriceBook } from '../../lib/priceBook';
import { cartStep } from '../../lib/traitPanel';
import type { MyCapabilities, SchemaBlock } from '../../types';
import type { Character } from '../../types/character';
import type {
	CompendiumEntry,
	CompendiumEntries,
	CompendiumHit,
	CompendiumSections,
	CompendiumType,
} from '../../types/compendium';
import './Compendium.css';

const PAGE = 60;

export interface CompendiumProps {
	gameSlug: string;
	capabilities?: MyCapabilities;
}

function shownName( name: string, translated: string | null ): string {
	return localizedItemName( { name, name_pt: translated ?? undefined } );
}

function nameOf( entry: CompendiumEntry ): string {
	return shownName( entry.name, entry.name_pt );
}

/**
 * Renders the compendium for one chronicle.
 */
export function Compendium( { gameSlug, capabilities }: CompendiumProps ) {
	const canManage = canIn( 'be_manage_characters', capabilities );
	const [ target ] = useState( () =>
		compendiumTarget( window.location.search )
	);
	const [ types, setTypes ] = useState< CompendiumType[] | null >( null );
	const [ characters, setCharacters ] = useState< Character[] | null >(
		null
	);
	const [ characterId, setCharacterId ] = useState< number | null >(
		target?.characterId ?? null
	);
	const [ stack, setStack ] = useState( target?.stack ?? '' );
	const [ sections, setSections ] = useState< CompendiumSections | null >(
		null
	);
	const [ blockSlug, setBlockSlug ] = useState( target?.block ?? '' );
	const [ listing, setListing ] = useState< CompendiumEntries | null >(
		null
	);
	const [ block, setBlock ] = useState< SchemaBlock | null >( null );
	const [ sheet, setSheet ] = useState< Record< string, unknown > >( {} );
	const [ query, setQuery ] = useState( '' );
	const [ hits, setHits ] = useState< CompendiumHit[] | null >( null );
	const [ notesOnly, setNotesOnly ] = useState( false );
	const [ shown, setShown ] = useState( PAGE );
	const [ opening, setOpening ] = useState< string | null >(
		target?.entry ?? null
	);
	const [ openEntry, setOpenEntry ] = useState< CompendiumEntry | null >(
		null
	);
	const [ error, setError ] = useState< string | null >( null );
	const [ , refresh ] = useState( 0 );

	const failed = () =>
		setError( __( 'Failed to load the compendium.', 'beyond-elysium' ) );

	// The creature types and the characters a person may price for.
	useEffect( () => {
		api.compendium( gameSlug ).types().then( setTypes ).catch( failed );
		const load = canManage
			? api.characters( gameSlug ).list( {
					per_page: 200,
					is_npc: false,
					orderby: 'name',
					order: 'ASC',
				} )
			: api.characters( gameSlug ).myCharacters();
		load.then( setCharacters ).catch( () => setCharacters( [] ) );
	}, [ gameSlug, canManage ] );

	// With nothing asked for, a player's own first active character is chosen and its type opened.
	useEffect( () => {
		if ( types === null || characters === null ) {
			return;
		}
		let chosenId = characterId;
		if ( chosenId === null && ! canManage ) {
			const first = characters.find( ( c ) => c.status === 'active' );
			if ( first ) {
				chosenId = Number( first.id );
				setCharacterId( chosenId );
			}
		}
		if ( stack === '' ) {
			const mine = characters.find(
				( c ) => Number( c.id ) === chosenId
			);
			const wanted = mine
				? types.find( ( t ) => t.slug === mine.stack_slug )
				: undefined;
			setStack( wanted?.slug ?? types[ 0 ]?.slug ?? '' );
		}
	}, [ types, characters, characterId, canManage, stack ] );

	// The chosen type's sections.
	useEffect( () => {
		if ( stack === '' ) {
			return;
		}
		setSections( null );
		api.compendium( gameSlug )
			.sections( stack )
			.then( ( result ) => {
				setSections( result );
				setBlockSlug( ( current ) =>
					result.sections.some( ( s ) => s.block_slug === current )
						? current
						: ( result.sections[ 0 ]?.block_slug ?? '' )
				);
			} )
			.catch( failed );
	}, [ gameSlug, stack ] );

	// The chosen section's entries and its block.
	useEffect( () => {
		if ( stack === '' || blockSlug === '' ) {
			return;
		}
		let live = true;
		setListing( null );
		setBlock( null );
		setShown( PAGE );
		Promise.all( [
			api.compendium( gameSlug ).entries( stack, blockSlug ),
			api.schemaBlocks.get( blockSlug, gameSlug ),
		] )
			.then( ( [ entries, loaded ] ) => {
				if ( live ) {
					setListing( entries );
					setBlock( loaded );
				}
			} )
			.catch( () => live && failed() );
		return () => {
			live = false;
		};
	}, [ gameSlug, stack, blockSlug ] );

	// The chosen character's sheet, and the cart the panel adds to.
	useEffect( () => {
		if ( characterId === null ) {
			setSheet( {} );
			return;
		}
		let live = true;
		api.characters( gameSlug )
			.get( characterId )
			.then(
				( character ) => live && setSheet( character.sheet_data ?? {} )
			)
			.catch( () => live && setSheet( {} ) );
		useCharacterEditorStore
			.getState()
			.loadCharacter( characterId, gameSlug );
		return () => {
			live = false;
		};
	}, [ gameSlug, characterId ] );

	// A name opened by an address or a search hit, once its section is listed.
	useEffect( () => {
		if ( opening === null || listing === null ) {
			return;
		}
		const found = listing.entries.find(
			( entry ) =>
				entry.name === opening ||
				( isFamily( entry ) &&
					entry.levels.some( ( l ) => l.power_name === opening ) )
		);
		if ( found ) {
			setOpenEntry( found );
		}
		setOpening( null );
	}, [ opening, listing ] );

	// The search, a moment after the typing stops.
	useEffect( () => {
		const words = query.trim();
		if ( words.length < 2 || stack === '' ) {
			setHits( null );
			return undefined;
		}
		const timer = window.setTimeout( () => {
			api.compendium( gameSlug )
				.search( stack, words )
				.then( setHits )
				.catch( () => setHits( [] ) );
		}, 300 );
		return () => window.clearTimeout( timer );
	}, [ gameSlug, stack, query ] );

	const section = useMemo(
		() =>
			listing && block
				? {
						blockSlug: listing.block_slug,
						title: listing.label,
						sectionType: block.section_type,
					}
				: null,
		[ listing, block ]
	);
	const held = useMemo(
		() => ( section ? heldInSection( section, sheet ) : new Map() ),
		[ section, sheet ]
	);

	const prices = useMemo( () => {
		if ( characterId === null || ! block || ! section ) {
			return null;
		}
		const saved = sheet[ block.slug ];
		return new PriceBook( {
			preview: ( changes ) =>
				api
					.characters( gameSlug )
					.previewChanges( characterId, changes ),
			changeFor: ( name ) =>
				cartStep(
					{
						blockSlug: block.slug,
						name,
						sectionType:
							block.section_type === 'tiered_power'
								? 'tiered_power'
								: 'trait_list',
					},
					block,
					saved,
					saved
				)?.next ?? null,
		} );
	}, [ gameSlug, characterId, block, section, sheet ] );

	useEffect(
		() => prices?.subscribe( () => refresh( ( count ) => count + 1 ) ),
		[ prices ]
	);

	const visible = useMemo( () => {
		const all = listing?.entries ?? [];
		return notesOnly ? withNotes( all ) : all;
	}, [ listing, notesOnly ] );
	const onScreen = visible.slice( 0, shown );

	useEffect( () => {
		prices?.request( onScreen.map( ( entry ) => entry.name ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ prices, listing, notesOnly, shown ] );

	const grouped = useMemo(
		() => groupEntries( onScreen ),
		// eslint-disable-next-line react-hooks/exhaustive-deps
		[ listing, notesOnly, shown ]
	);

	const chosen = characters?.find( ( c ) => Number( c.id ) === characterId );
	const ownCharacter = !! chosen && ! canManage;

	function row( entry: CompendiumEntry ) {
		const heldTrait = held.get( entry.name );
		const cost = isFamily( entry )
			? familyCost( entry )
			: costWords( entry.cost );
		const price = prices?.hint( entry.name ) ?? null;
		return (
			<li key={ entry.name }>
				<button
					type="button"
					className="be-compendium__row"
					onClick={ () => setOpenEntry( entry ) }
				>
					<span className="be-compendium__name">
						{ nameOf( entry ) }
					</span>
					<span className="be-compendium__facts">
						{ [ price ?? cost, heldWords( heldTrait ), entry.book ]
							.filter( Boolean )
							.join( ' · ' ) }
					</span>
					{ entry.description && (
						<span className="be-compendium__note">
							{ __( 'Note', 'beyond-elysium' ) }
						</span>
					) }
				</button>
			</li>
		);
	}

	return (
		<div className="be-compendium">
			<div className="be-help-heading">
				<h2>{ __( 'Compendium', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="compendium" />
			</div>
			{ error && (
				<div className="be-game-dashboard__error" role="alert">
					{ error }
				</div>
			) }

			<div className="be-compendium__tools">
				<label>
					<span>{ __( 'Creature type', 'beyond-elysium' ) }</span>
					<select
						value={ stack }
						onChange={ ( e ) => {
							setStack( e.target.value );
							setBlockSlug( '' );
							setQuery( '' );
						} }
					>
						{ ( types ?? [] ).map( ( type ) => (
							<option key={ type.slug } value={ type.slug }>
								{ type.name }
							</option>
						) ) }
					</select>
				</label>
				<label>
					<span>{ __( 'Prices for', 'beyond-elysium' ) }</span>
					<select
						value={ characterId ?? '' }
						onChange={ ( e ) => {
							const next =
								e.target.value === ''
									? null
									: Number( e.target.value );
							setCharacterId( next );
							const own = characters?.find(
								( c ) => Number( c.id ) === next
							);
							if (
								own &&
								own.stack_slug !== stack &&
								types?.some(
									( t ) => t.slug === own.stack_slug
								)
							) {
								setStack( own.stack_slug );
								setBlockSlug( '' );
								setQuery( '' );
							}
						} }
					>
						<option value="">
							{ __( 'No character', 'beyond-elysium' ) }
						</option>
						{ ( characters ?? [] ).map( ( c ) => (
							<option key={ c.id } value={ c.id }>
								{ c.name }
							</option>
						) ) }
					</select>
				</label>
				<label>
					<span>{ __( 'Search this type', 'beyond-elysium' ) }</span>
					<input
						type="search"
						value={ query }
						onChange={ ( e ) => setQuery( e.target.value ) }
					/>
				</label>
				<label className="be-compendium__check">
					<input
						type="checkbox"
						checked={ notesOnly }
						onChange={ ( e ) => setNotesOnly( e.target.checked ) }
					/>
					<span>
						{ __( 'Has a chronicle note', 'beyond-elysium' ) }
					</span>
				</label>
			</div>

			{ characterId === null && (
				<p className="be-compendium__hint">
					{ __(
						'Choose a character to see what it costs them.',
						'beyond-elysium'
					) }
				</p>
			) }

			{ hits !== null && (
				<section className="be-compendium__hits">
					<h3>{ __( 'Search results', 'beyond-elysium' ) }</h3>
					{ hits.length === 0 ? (
						<p>{ __( 'Nothing matches.', 'beyond-elysium' ) }</p>
					) : (
						<ul>
							{ hits.map( ( hit ) => (
								<li
									key={ `${ hit.block_slug }-${ hit.family }-${ hit.name }` }
								>
									<button
										type="button"
										className="be-compendium__row"
										onClick={ () => {
											setBlockSlug( hit.block_slug );
											setOpening(
												hit.family ?? hit.name
											);
											setQuery( '' );
										} }
									>
										<span className="be-compendium__name">
											{ shownName(
												hit.name,
												hit.name_pt
											) }
										</span>
										<span className="be-compendium__facts">
											{ [ hit.family, hit.label ]
												.filter( Boolean )
												.join( ' · ' ) }
										</span>
									</button>
								</li>
							) ) }
						</ul>
					) }
				</section>
			) }

			{ sections && (
				<ul className="be-compendium__sections">
					{ sections.sections.map( ( s ) => (
						<li key={ s.block_slug }>
							<button
								type="button"
								className={
									s.block_slug === blockSlug
										? 'be-compendium__section be-compendium__section--active'
										: 'be-compendium__section'
								}
								aria-pressed={ s.block_slug === blockSlug }
								onClick={ () => setBlockSlug( s.block_slug ) }
							>
								{ s.label }
								<span className="be-compendium__count">
									{ s.count }
								</span>
							</button>
						</li>
					) ) }
				</ul>
			) }

			{ listing === null && sections !== null && ! error && (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) }

			{ listing && (
				<section className="be-compendium__list">
					{ visible.length === 0 && (
						<p>{ __( 'No entries match.', 'beyond-elysium' ) }</p>
					) }
					{ grouped.map( ( group ) => (
						<div key={ group.group }>
							{ group.group && <h3>{ group.group }</h3> }
							{ group.subgroups.map( ( sub ) => (
								<div key={ sub.subgroup }>
									{ sub.subgroup && (
										<h4>{ sub.subgroup }</h4>
									) }
									<ul>{ sub.entries.map( row ) }</ul>
								</div>
							) ) }
						</div>
					) ) }
					{ visible.length > shown && (
						<button
							type="button"
							className="be-compendium__more"
							onClick={ () => setShown( ( n ) => n + PAGE ) }
						>
							{ sprintf(
								/* translators: %d: how many more entries the list holds */
								_n(
									'Show %d more',
									'Show %d more',
									visible.length - shown,
									'beyond-elysium'
								),
								visible.length - shown
							) }
						</button>
					) }
				</section>
			) }

			{ openEntry && section && (
				<TraitPanel
					gameSlug={ gameSlug }
					characterId={ characterId }
					trait={
						held.get( openEntry.name ) ??
						asTrait( section, openEntry )
					}
					notHeld={ ! held.has( openEntry.name ) }
					block={ block ?? undefined }
					sheetData={ sheet }
					label={ nameOf( openEntry ) }
					canEdit={ ownCharacter }
					canManage={ canManage }
					onClose={ () => setOpenEntry( null ) }
				/>
			) }
		</div>
	);
}

export default Compendium;
