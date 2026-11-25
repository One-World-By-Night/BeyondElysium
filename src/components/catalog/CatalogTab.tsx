/**
 * Storyteller Toolkit: Catalog. An HST adds and changes what the chronicle's characters can buy, section by section.
 * Every change lands on the chronicle's own copy; the book and other chronicles never change.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { errorMessage } from '../../lib/errorMessage';
import {
	CATALOG_FILTERS,
	entryBadges,
	entryShows,
	filterLabel,
	groupItems,
	priceLine,
	type CatalogFilter,
} from '../../lib/catalogEntries';
import type {
	CatalogCreatureType,
	CatalogEntry,
	CatalogFamilyEntry,
	CatalogItemEntry,
	CatalogListing,
	CatalogSection,
} from '../../types/catalog';
import HelpButton from '../shared/HelpButton';
import { CatalogFamilyEditor } from './CatalogFamilyEditor';
import { CatalogItemEditor } from './CatalogItemEditor';
import './Catalog.css';

export interface CatalogTabProps {
	gameSlug: string;
}

function Badges( {
	entry,
}: {
	entry: { origin: CatalogItemEntry[ 'origin' ]; flagged: boolean };
} ) {
	return (
		<>
			{ entryBadges( entry ).map( ( badge ) => (
				<span className="be-catalog__badge" key={ badge }>
					{ badge }
				</span>
			) ) }
		</>
	);
}

/**
 * The Catalog tab.
 */
export function CatalogTab( { gameSlug }: CatalogTabProps ) {
	const [ types, setTypes ] = useState< CatalogCreatureType[] | null >(
		null
	);
	const [ stack, setStack ] = useState( '' );
	const [ block, setBlock ] = useState( '' );
	const [ listing, setListing ] = useState< CatalogListing | null >( null );
	const [ filter, setFilter ] = useState< CatalogFilter >( 'all' );
	const [ search, setSearch ] = useState( '' );
	const [ error, setError ] = useState< string | null >( null );
	const [ editing, setEditing ] = useState< {
		entry: CatalogEntry | null;
	} | null >( null );
	const [ reloads, setReloads ] = useState( 0 );

	useEffect( () => {
		api.catalog( gameSlug )
			.sections()
			.then( ( loaded ) => {
				setTypes( loaded.creature_types );
				const first = loaded.creature_types[ 0 ];
				setStack( first?.stack ?? '' );
				setBlock( first?.sections[ 0 ]?.block_slug ?? '' );
				setError( null );
			} )
			.catch( ( err ) =>
				setError(
					errorMessage(
						err,
						__( 'Failed to load the catalog.', 'beyond-elysium' )
					)
				)
			);
	}, [ gameSlug ] );

	useEffect( () => {
		if ( ! stack || ! block ) {
			setListing( null );
			return;
		}
		let current = true;
		setListing( null );
		api.catalog( gameSlug )
			.entries( block, stack )
			.then( ( loaded ) => {
				if ( current ) {
					setListing( loaded );
					setError( null );
				}
			} )
			.catch( ( err ) => {
				if ( current ) {
					setError(
						errorMessage(
							err,
							__(
								'Failed to load the entries.',
								'beyond-elysium'
							)
						)
					);
				}
			} );
		return () => {
			current = false;
		};
	}, [ gameSlug, stack, block, reloads ] );

	const creature = types?.find( ( type ) => type.stack === stack );
	const section: CatalogSection | undefined = creature?.sections.find(
		( each ) => each.block_slug === block
	);

	function pickStack( next: string ) {
		const type = types?.find( ( each ) => each.stack === next );
		setStack( next );
		setBlock( type?.sections[ 0 ]?.block_slug ?? '' );
		setSearch( '' );
		setFilter( 'all' );
	}

	function pickBlock( next: string ) {
		setBlock( next );
		setSearch( '' );
		setFilter( 'all' );
	}

	const entries = listing?.entries ?? [];
	const groups =
		listing && 'item' === listing.kind
			? groupItems( entries as CatalogItemEntry[], filter, search )
			: [];
	const families =
		listing && 'item' !== listing.kind
			? ( entries as CatalogFamilyEntry[] ).filter( ( entry ) =>
					entryShows( entry, filter, search )
				)
			: [];
	const shown =
		groups.reduce( ( total, group ) => total + group.entries.length, 0 ) +
		families.length;

	return (
		<section className="be-catalog">
			<div className="be-help-heading">
				<h2>{ __( 'Catalog', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="catalog" />
			</div>
			<p className="be-catalog__hint">
				{ __(
					"Add to and change what this chronicle's characters can buy. What you change here is this chronicle's own; the book and every other chronicle stay as they are.",
					'beyond-elysium'
				) }
			</p>

			{ error && (
				<p className="be-catalog__error" role="alert">
					{ error }
				</p>
			) }
			{ types && types.length === 0 && (
				<p className="be-catalog__hint">
					{ __(
						'No section of this chronicle takes entries of its own.',
						'beyond-elysium'
					) }
				</p>
			) }

			{ types && types.length > 0 && (
				<div className="be-catalog__pickers">
					<label className="be-catalog__field">
						<span>{ __( 'Creature type', 'beyond-elysium' ) }</span>
						<select
							value={ stack }
							onChange={ ( e ) => pickStack( e.target.value ) }
						>
							{ types.map( ( type ) => (
								<option key={ type.stack } value={ type.stack }>
									{ type.name }
								</option>
							) ) }
						</select>
					</label>
					<label className="be-catalog__field">
						<span>{ __( 'Section', 'beyond-elysium' ) }</span>
						<select
							value={ block }
							onChange={ ( e ) => pickBlock( e.target.value ) }
						>
							{ ( creature?.sections ?? [] ).map( ( each ) => (
								<option
									key={ each.block_slug }
									value={ each.block_slug }
								>
									{ each.label }
								</option>
							) ) }
						</select>
					</label>
				</div>
			) }

			{ section && (
				<>
					<p className="be-catalog__price-line">
						{ priceLine( section ) }
					</p>
					<div className="be-catalog__controls">
						<label className="be-catalog__field">
							<span>{ __( 'Search', 'beyond-elysium' ) }</span>
							<input
								type="search"
								value={ search }
								onChange={ ( e ) =>
									setSearch( e.target.value )
								}
							/>
						</label>
						<label className="be-catalog__field">
							<span>{ __( 'Show', 'beyond-elysium' ) }</span>
							<select
								value={ filter }
								onChange={ ( e ) =>
									setFilter( e.target.value as CatalogFilter )
								}
							>
								{ CATALOG_FILTERS.map( ( each ) => (
									<option key={ each } value={ each }>
										{ filterLabel( each ) }
									</option>
								) ) }
							</select>
						</label>
						<button
							type="button"
							className="be-catalog__button be-catalog__new"
							onClick={ () => setEditing( { entry: null } ) }
						>
							{ __( '+ New', 'beyond-elysium' ) }
						</button>
					</div>

					{ ! listing && ! error && (
						<p className="be-catalog__hint">
							{ __( 'Loading…', 'beyond-elysium' ) }
						</p>
					) }
					{ listing && shown === 0 && (
						<p className="be-catalog__hint">
							{ __( 'Nothing matches.', 'beyond-elysium' ) }
						</p>
					) }

					{ groups.map( ( group ) => {
						const rows = (
							<ul className="be-catalog__list">
								{ group.entries.map( ( entry ) => (
									<li key={ entry.name }>
										<button
											type="button"
											className="be-catalog__row-button"
											onClick={ () =>
												setEditing( { entry } )
											}
										>
											<span className="be-catalog__name">
												{ entry.name_pt ?? entry.name }
											</span>
											<span className="be-catalog__cost">
												{ entry.cost }
											</span>
											<Badges entry={ entry } />
										</button>
									</li>
								) ) }
							</ul>
						);
						return groups.length === 1 && group.name === '' ? (
							<div key="all">{ rows }</div>
						) : (
							<details
								className="be-catalog__fold"
								key={ group.name }
								open={ ! group.folded }
							>
								<summary>
									{ sprintf(
										/* translators: 1: a group's name, 2: how many entries it holds */
										__( '%1$s (%2$d)', 'beyond-elysium' ),
										group.name ||
											__( 'No group', 'beyond-elysium' ),
										group.entries.length
									) }
								</summary>
								{ rows }
							</details>
						);
					} ) }

					{ families.length > 0 && (
						<ul className="be-catalog__list">
							{ families.map( ( entry ) => (
								<li key={ entry.name }>
									<button
										type="button"
										className="be-catalog__row-button"
										onClick={ () =>
											setEditing( { entry } )
										}
									>
										<span className="be-catalog__name">
											{ entry.name_pt ?? entry.name }
										</span>
										<Badges entry={ entry } />
									</button>
								</li>
							) ) }
						</ul>
					) }
				</>
			) }

			{ editing && section && 'item' === section.kind && (
				<CatalogItemEditor
					gameSlug={ gameSlug }
					stack={ stack }
					section={ section }
					entry={ editing.entry as CatalogItemEntry | null }
					onClose={ () => setEditing( null ) }
					onChanged={ () => setReloads( ( n ) => n + 1 ) }
				/>
			) }
			{ editing && section && 'item' !== section.kind && (
				<CatalogFamilyEditor
					gameSlug={ gameSlug }
					stack={ stack }
					section={ section }
					entry={ editing.entry as CatalogFamilyEntry | null }
					onClose={ () => setEditing( null ) }
					onChanged={ () => setReloads( ( n ) => n + 1 ) }
				/>
			) }
		</section>
	);
}

export default CatalogTab;
