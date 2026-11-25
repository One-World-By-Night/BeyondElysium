/**
 * The pop-up that adds a family of powers (a Discipline, an Arcanos, a Sphere, a Blood Magic path, a tribe's Gifts) or
 * changes one: its levels, its elder powers or Gifts, its traditions, and where it is in-clan. A book family keeps its
 * names and takes a description and prices.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { errorMessage } from '../../lib/errorMessage';
import {
	chosenNote,
	defaultPickCost,
	familyChanges,
	familyDraft,
	inClanBoxes,
	type FamilyDraft,
	type PickDraft,
} from '../../lib/catalogEntries';
import type { CatalogDescription } from '../../types';
import type {
	CatalogEntryFlag,
	CatalogFamilyEntry,
	CatalogSection,
} from '../../types/catalog';
import Modal from '../shared/Modal';
import { DescriptionEditorButton } from '../shared/CatalogDescriptionEditor';
import { CatalogFlags } from './CatalogFlags';
import './Catalog.css';

function sameSet( a: string[], b: string[] ): boolean {
	return a.length === b.length && a.every( ( value ) => b.includes( value ) );
}

export interface CatalogFamilyEditorProps {
	gameSlug: string;
	stack: string;
	section: CatalogSection;
	/**
	 * The family being changed, or null for a new one.
	 */
	entry: CatalogFamilyEntry | null;
	onClose: () => void;
	/**
	 * Called after anything was written, so the list reloads.
	 */
	onChanged: () => void;
}

/**
 * The family pop-up.
 */
export function CatalogFamilyEditor( {
	gameSlug,
	stack,
	section,
	entry,
	onClose,
	onChanged,
}: CatalogFamilyEditorProps ) {
	const [ draft, setDraft ] = useState< FamilyDraft >( () =>
		familyDraft( entry, section )
	);
	const [ inClan, setInClan ] = useState< Record< string, string[] > >( () =>
		Object.fromEntries(
			inClanBoxes( section, entry ).map( ( box ) => [
				box.map,
				box.ticked,
			] )
		)
	);
	const [ filed, setFiled ] = useState< Record< string, string > >( () => ( {
		...( entry?.category_values ?? {} ),
	} ) );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ stale, setStale ] = useState( false );
	const [ describing, setDescribing ] = useState( false );

	const book = entry !== null && 'added' !== entry.origin;
	const pickRanks = section.pick_ranks ?? [];
	const changes = familyChanges( entry, draft, section );
	const heading =
		'pick' === section.kind
			? __( 'Gifts', 'beyond-elysium' )
			: __( 'Elder powers', 'beyond-elysium' );

	const inClanDiffers = section.in_type.maps.some(
		( map ) =>
			! sameSet(
				inClan[ map.map ] ?? [],
				entry?.in_clan[ map.map ] ?? []
			)
	);
	const filedDiffers = section.in_type.facets.some(
		( facet ) =>
			( filed[ facet.facet ] ?? '' ) !==
			( entry?.category_values[ facet.facet ] ?? '' )
	);
	const typeChanged = ! book && ( inClanDiffers || filedDiffers );
	const changed = Object.keys( changes ).length > 0 || typeChanged;

	function fail( err: unknown, partial = false ) {
		setStale(
			partial || ( err as { code?: string } )?.code === 'entry_changed'
		);
		setError(
			errorMessage(
				err,
				__( 'Failed to save the entry.', 'beyond-elysium' )
			)
		);
	}

	function run( work: () => Promise< unknown > ) {
		setBusy( true );
		setError( null );
		work()
			.then( () => {
				onChanged();
				onClose();
			} )
			.catch( ( err ) => fail( err ) )
			.finally( () => setBusy( false ) );
	}

	async function save() {
		setBusy( true );
		setError( null );
		let wrote = false;
		try {
			let current = entry;
			if ( entry === null || Object.keys( changes ).length > 0 ) {
				const result =
					entry === null
						? await api
								.catalog( gameSlug )
								.add( section.block_slug, stack, changes )
						: await api
								.catalog( gameSlug )
								.change(
									section.block_slug,
									stack,
									entry.name,
									entry.etag,
									changes
								);
				wrote = true;
				current = result.entry as CatalogFamilyEntry | null;
			}
			if ( typeChanged && current ) {
				const placed: Record< string, string[] > = {};
				for ( const map of section.in_type.maps ) {
					if (
						! sameSet(
							inClan[ map.map ] ?? [],
							entry?.in_clan[ map.map ] ?? []
						)
					) {
						placed[ map.map ] = inClan[ map.map ] ?? [];
					}
				}
				const values: Record< string, string > = {};
				for ( const facet of section.in_type.facets ) {
					if (
						( filed[ facet.facet ] ?? '' ) !==
						( entry?.category_values[ facet.facet ] ?? '' )
					) {
						values[ facet.facet ] = filed[ facet.facet ] ?? '';
					}
				}
				await api
					.catalog( gameSlug )
					.setInType(
						section.block_slug,
						stack,
						current.name,
						current.etag,
						{
							...( Object.keys( placed ).length > 0
								? { in_clan: placed }
								: {} ),
							...( Object.keys( values ).length > 0
								? { category_values: values }
								: {} ),
						}
					);
			}
			onChanged();
			onClose();
		} catch ( err ) {
			fail( err, wrote );
		} finally {
			setBusy( false );
		}
	}

	function remove() {
		if (
			entry === null ||
			! window.confirm(
				sprintf(
					/* translators: %s: the name of a catalog entry */
					__(
						'Take "%s" out of this chronicle\'s catalog? Characters cannot pick it afterwards.',
						'beyond-elysium'
					),
					entry.name
				)
			)
		) {
			return;
		}
		run( () =>
			api
				.catalog( gameSlug )
				.remove( section.block_slug, entry.name, entry.etag )
		);
	}

	function useTheBook() {
		if ( entry !== null ) {
			run( () =>
				api
					.catalog( gameSlug )
					.revert( section.block_slug, stack, entry.name )
			);
		}
	}

	function settle( action: 'keep' | 'take', flag: CatalogEntryFlag ) {
		run( () =>
			api
				.catalog( gameSlug )
				.settle( action, section.block_slug, flag.path )
		);
	}

	function setPick( index: number, next: Partial< PickDraft > ) {
		setDraft( {
			...draft,
			picks: draft.picks.map( ( pick, at ) =>
				at === index ? { ...pick, ...next } : pick
			),
		} );
	}

	function addPick() {
		const rank = pickRanks[ 0 ] ?? '';
		setDraft( {
			...draft,
			picks: [
				...draft.picks,
				{
					rank,
					power_name: '',
					cost: defaultPickCost( section, rank ),
					description: {},
					origin: 'added',
					held: false,
				},
			],
		} );
	}

	function toggleTradition( tradition: string ) {
		const next = { ...draft.traditions };
		if ( tradition in next ) {
			delete next[ tradition ];
		} else {
			next[ tradition ] = '';
		}
		setDraft( { ...draft, traditions: next } );
	}

	function toggleKey( map: string, key: string ) {
		const held = inClan[ map ] ?? [];
		setInClan( {
			...inClan,
			[ map ]: held.includes( key )
				? held.filter( ( k ) => k !== key )
				: [ ...held, key ],
		} );
	}

	const label =
		entry === null
			? __( 'New entry', 'beyond-elysium' )
			: ( entry.name_pt ?? entry.name );
	const note = chosenNote( section );

	return (
		<Modal
			title={ label }
			onClose={ () => ! describing && onClose() }
			footer={
				<>
					{ entry !== null && ! book && (
						<button
							type="button"
							className="be-catalog__button be-catalog__button--danger"
							disabled={ busy }
							onClick={ remove }
						>
							{ __( 'Remove', 'beyond-elysium' ) }
						</button>
					) }
					{ entry !== null && 'changed' === entry.origin && (
						<button
							type="button"
							className="be-catalog__button"
							disabled={ busy }
							onClick={ useTheBook }
						>
							{ __( "Use the book's", 'beyond-elysium' ) }
						</button>
					) }
					<button type="button" onClick={ onClose }>
						{ __( 'Cancel', 'beyond-elysium' ) }
					</button>
					<button
						type="button"
						disabled={ busy || ! changed }
						onClick={ () => void save() }
					>
						{ __( 'Save', 'beyond-elysium' ) }
					</button>
				</>
			}
		>
			<div className="be-catalog__form">
				{ book && (
					<p className="be-catalog__hint">
						{ __(
							'From the book. Its names and levels stay; you can describe it and add or price powers for this chronicle.',
							'beyond-elysium'
						) }
					</p>
				) }
				{ entry?.in_use_note && ! book && (
					<p className="be-catalog__hint">
						{ entry.in_use_note }{ ' ' }
						{ __(
							'It cannot be renamed or removed.',
							'beyond-elysium'
						) }
					</p>
				) }

				<label className="be-catalog__field">
					<span>{ __( 'Name', 'beyond-elysium' ) }</span>
					<input
						type="text"
						value={ draft.name }
						disabled={ book || busy }
						onChange={ ( e ) =>
							setDraft( { ...draft, name: e.target.value } )
						}
					/>
				</label>

				<div className="be-catalog__field">
					<DescriptionEditorButton
						label={ draft.name || label }
						value={ draft.description }
						onOpenChange={ setDescribing }
						onSave={ ( value: CatalogDescription ) =>
							setDraft( { ...draft, description: value } )
						}
					/>
				</div>

				{ draft.levels.length > 0 && (
					<fieldset className="be-catalog__group">
						<legend>{ __( 'Levels', 'beyond-elysium' ) }</legend>
						{ draft.levels.map( ( level, index ) => (
							<div
								className="be-catalog__row"
								key={ level.level }
							>
								<span className="be-catalog__rung">
									{ sprintf(
										/* translators: 1: a level number, 2: its rank, 3: its price in XP */
										__(
											'Level %1$d, %2$s, %3$s XP',
											'beyond-elysium'
										),
										level.level,
										level.tier,
										level.cost
									) }
								</span>
								<input
									type="text"
									aria-label={ sprintf(
										/* translators: %d: a level number */
										__(
											'Name of level %d',
											'beyond-elysium'
										),
										level.level
									) }
									value={ level.power_name }
									disabled={ book || busy }
									onChange={ ( e ) =>
										setDraft( {
											...draft,
											levels: draft.levels.map(
												( row, at ) =>
													at === index
														? {
																...row,
																power_name:
																	e.target
																		.value,
															}
														: row
											),
										} )
									}
								/>
								<DescriptionEditorButton
									label={
										level.power_name ||
										String( level.level )
									}
									value={ level.description }
									onOpenChange={ setDescribing }
									onSave={ ( value: CatalogDescription ) =>
										setDraft( {
											...draft,
											levels: draft.levels.map(
												( row, at ) =>
													at === index
														? {
																...row,
																description:
																	value,
															}
														: row
											),
										} )
									}
								/>
							</div>
						) ) }
					</fieldset>
				) }

				{ pickRanks.length > 0 && (
					<fieldset className="be-catalog__group">
						<legend>{ heading }</legend>
						{ draft.picks.map( ( pick, index ) => (
							<div className="be-catalog__row" key={ index }>
								<input
									type="text"
									aria-label={ __(
										'Name',
										'beyond-elysium'
									) }
									value={ pick.power_name }
									disabled={ 'book' === pick.origin || busy }
									onChange={ ( e ) =>
										setPick( index, {
											power_name: e.target.value,
										} )
									}
								/>
								<select
									aria-label={ __(
										'Power rank',
										'beyond-elysium'
									) }
									value={ pick.rank }
									disabled={ 'book' === pick.origin || busy }
									onChange={ ( e ) =>
										setPick( index, {
											rank: e.target.value,
											cost:
												pick.cost ===
												defaultPickCost(
													section,
													pick.rank
												)
													? defaultPickCost(
															section,
															e.target.value
														)
													: pick.cost,
										} )
									}
								>
									{ pickRanks.map( ( rank ) => (
										<option key={ rank } value={ rank }>
											{ rank }
										</option>
									) ) }
								</select>
								<input
									type="text"
									className="be-catalog__price"
									aria-label={ __(
										'Price',
										'beyond-elysium'
									) }
									value={ pick.cost }
									disabled={ busy }
									onChange={ ( e ) =>
										setPick( index, {
											cost: e.target.value,
										} )
									}
								/>
								<DescriptionEditorButton
									label={ pick.power_name || heading }
									value={ pick.description }
									onOpenChange={ setDescribing }
									onSave={ ( value: CatalogDescription ) =>
										setPick( index, { description: value } )
									}
								/>
								<button
									type="button"
									className="be-catalog__button be-catalog__button--danger"
									disabled={
										'book' === pick.origin ||
										pick.held ||
										busy
									}
									title={
										pick.held
											? __(
													'Characters hold this, so it stays.',
													'beyond-elysium'
												)
											: undefined
									}
									onClick={ () =>
										setDraft( {
											...draft,
											picks: draft.picks.filter(
												( _, at ) => at !== index
											),
										} )
									}
								>
									{ __( 'Remove', 'beyond-elysium' ) }
								</button>
							</div>
						) ) }
						<button
							type="button"
							className="be-catalog__button"
							disabled={ busy }
							onClick={ addPick }
						>
							{ 'pick' === section.kind
								? __( '+ Add a Gift', 'beyond-elysium' )
								: __(
										'+ Add an elder power',
										'beyond-elysium'
									) }
						</button>
					</fieldset>
				) }

				{ section.blood_magic && (
					<fieldset className="be-catalog__group">
						<legend>
							{ __( 'Traditions', 'beyond-elysium' ) }
						</legend>
						{ section.traditions.map( ( tradition ) => (
							<div className="be-catalog__row" key={ tradition }>
								<label className="be-catalog__area">
									<input
										type="checkbox"
										checked={
											tradition in draft.traditions
										}
										disabled={ book || busy }
										onChange={ () =>
											toggleTradition( tradition )
										}
									/>
									<span>{ tradition }</span>
								</label>
								{ tradition in draft.traditions && (
									<input
										type="text"
										aria-label={ sprintf(
											/* translators: %s: a tradition's name */
											__(
												"%s's name for it",
												'beyond-elysium'
											),
											tradition
										) }
										placeholder={ __(
											"The tradition's own name for it",
											'beyond-elysium'
										) }
										value={ draft.traditions[ tradition ] }
										disabled={ book || busy }
										onChange={ ( e ) =>
											setDraft( {
												...draft,
												traditions: {
													...draft.traditions,
													[ tradition ]:
														e.target.value,
												},
											} )
										}
									/>
								) }
							</div>
						) ) }
					</fieldset>
				) }

				{ section.in_type.maps.map( ( map ) => (
					<fieldset className="be-catalog__group" key={ map.map }>
						<legend>
							{ __( 'In-clan for', 'beyond-elysium' ) }
						</legend>
						{ map.keys.map( ( key ) => (
							<label className="be-catalog__area" key={ key }>
								<input
									type="checkbox"
									checked={ (
										inClan[ map.map ] ?? []
									).includes( key ) }
									disabled={ book || busy }
									onChange={ () => toggleKey( map.map, key ) }
								/>
								<span>{ key }</span>
							</label>
						) ) }
						{ book && (
							<p className="be-catalog__hint">
								{ __(
									"A book entry's in-clan lists are changed in wp-admin's Schema Blocks.",
									'beyond-elysium'
								) }
							</p>
						) }
					</fieldset>
				) ) }
				{ note && <p className="be-catalog__hint">{ note }</p> }

				{ section.in_type.facets.length > 0 && (
					<fieldset className="be-catalog__group">
						<legend>
							{ __( 'Filed under', 'beyond-elysium' ) }
						</legend>
						{ section.in_type.facets.map( ( facet ) => (
							<label
								className="be-catalog__field"
								key={ facet.facet }
							>
								<span>{ facet.facet }</span>
								<select
									value={ filed[ facet.facet ] ?? '' }
									disabled={ book || busy }
									onChange={ ( e ) =>
										setFiled( {
											...filed,
											[ facet.facet ]: e.target.value,
										} )
									}
								>
									<option value="">
										{ __( 'None', 'beyond-elysium' ) }
									</option>
									{ facet.options.map( ( option ) => (
										<option key={ option } value={ option }>
											{ option }
										</option>
									) ) }
								</select>
							</label>
						) ) }
					</fieldset>
				) }

				{ entry && (
					<CatalogFlags
						flags={ entry.corrections }
						busy={ busy }
						onKeep={ ( flag ) => settle( 'keep', flag ) }
						onTake={ ( flag ) => settle( 'take', flag ) }
					/>
				) }

				{ error && (
					<p className="be-catalog__error" role="alert">
						{ error }{ ' ' }
						{ stale && (
							<button
								type="button"
								className="be-catalog__button"
								onClick={ () => {
									onChanged();
									onClose();
								} }
							>
								{ __( 'Reload', 'beyond-elysium' ) }
							</button>
						) }
					</p>
				) }
			</div>
		</Modal>
	);
}
