/**
 * The pop-up that adds an item to a list (a Ritual, a Merit, a Combination Discipline) or changes one: its name and
 * rank, its group, its price and its description. A book item changes only its price and description.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { errorMessage } from '../../lib/errorMessage';
import { composedName, itemChanges, itemDraft } from '../../lib/catalogEntries';
import type { CatalogDescription } from '../../types';
import type {
	CatalogEntryFlag,
	CatalogItemEntry,
	CatalogSection,
} from '../../types/catalog';
import Modal from '../shared/Modal';
import { DescriptionEditorButton } from '../shared/CatalogDescriptionEditor';
import { CatalogFlags } from './CatalogFlags';
import './Catalog.css';

const NEW_GROUP = '__new__';

export interface CatalogItemEditorProps {
	gameSlug: string;
	stack: string;
	section: CatalogSection;
	/**
	 * The item being changed, or null for a new one.
	 */
	entry: CatalogItemEntry | null;
	onClose: () => void;
	/**
	 * Called after anything was written, so the list reloads.
	 */
	onChanged: () => void;
}

/**
 * The item pop-up.
 */
export function CatalogItemEditor( {
	gameSlug,
	stack,
	section,
	entry,
	onClose,
	onChanged,
}: CatalogItemEditorProps ) {
	const [ draft, setDraft ] = useState( () => itemDraft( entry ) );
	const [ newGroup, setNewGroup ] = useState( false );
	const [ busy, setBusy ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ stale, setStale ] = useState( false );
	const [ describing, setDescribing ] = useState( false );

	const book = entry !== null && 'added' !== entry.origin;
	const changes = itemChanges( entry, draft );
	const groups = section.groups ?? [];
	const stored = composedName( section, draft );

	function fail( err: unknown ) {
		setStale( ( err as { code?: string } )?.code === 'entry_changed' );
		setError(
			errorMessage(
				err,
				__( 'Failed to save the entry.', 'beyond-elysium' )
			)
		);
	}

	function run( work: () => Promise< unknown >, close = true ) {
		setBusy( true );
		setError( null );
		work()
			.then( () => {
				onChanged();
				if ( close ) {
					onClose();
				}
			} )
			.catch( fail )
			.finally( () => setBusy( false ) );
	}

	function save() {
		run( () =>
			entry === null
				? api
						.catalog( gameSlug )
						.add( section.block_slug, stack, changes )
				: api
						.catalog( gameSlug )
						.change(
							section.block_slug,
							stack,
							entry.name,
							entry.etag,
							changes
						)
		);
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

	const label =
		entry === null
			? __( 'New entry', 'beyond-elysium' )
			: ( entry.name_pt ?? entry.name );

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
						disabled={ busy || Object.keys( changes ).length === 0 }
						onClick={ save }
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
							'From the book. You can change its price and description for this chronicle.',
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

				{ section.name_form && (
					<label className="be-catalog__field">
						<span>{ __( 'Power rank', 'beyond-elysium' ) }</span>
						<select
							value={ draft.tier }
							disabled={ book || busy }
							onChange={ ( e ) =>
								setDraft( { ...draft, tier: e.target.value } )
							}
						>
							<option value="">
								{ __( 'No rank', 'beyond-elysium' ) }
							</option>
							{ ( section.name_ranks ?? [] ).map( ( rank ) => (
								<option key={ rank } value={ rank }>
									{ rank }
								</option>
							) ) }
						</select>
					</label>
				) }

				{ ( groups.length > 0 || section.name_form ) && (
					<label className="be-catalog__field">
						<span>{ __( 'Group', 'beyond-elysium' ) }</span>
						<select
							value={ newGroup ? NEW_GROUP : draft.group }
							disabled={ book || busy }
							onChange={ ( e ) => {
								if ( e.target.value === NEW_GROUP ) {
									setNewGroup( true );
									setDraft( { ...draft, group: '' } );
								} else {
									setNewGroup( false );
									setDraft( {
										...draft,
										group: e.target.value,
									} );
								}
							} }
						>
							<option value="">
								{ __( 'No group', 'beyond-elysium' ) }
							</option>
							{ groups.map( ( group ) => (
								<option key={ group } value={ group }>
									{ group }
								</option>
							) ) }
							<option value={ NEW_GROUP }>
								{ __( 'New group…', 'beyond-elysium' ) }
							</option>
						</select>
					</label>
				) }
				{ newGroup && (
					<label className="be-catalog__field">
						<span>{ __( 'New group', 'beyond-elysium' ) }</span>
						<input
							type="text"
							value={ draft.group }
							disabled={ busy }
							onChange={ ( e ) =>
								setDraft( { ...draft, group: e.target.value } )
							}
						/>
					</label>
				) }

				{ section.name_form && draft.name.trim() !== '' && (
					<p className="be-catalog__hint">
						{ sprintf(
							/* translators: %s: the name an entry will be stored under */
							__( 'Stored as: %s', 'beyond-elysium' ),
							stored
						) }
					</p>
				) }

				<label className="be-catalog__field">
					<span>
						{ 'dot' === section.priced_by
							? __( 'Price per dot', 'beyond-elysium' )
							: __( 'Price per pick', 'beyond-elysium' ) }
					</span>
					<input
						type="text"
						inputMode="numeric"
						value={ draft.cost }
						disabled={ busy }
						onChange={ ( e ) =>
							setDraft( { ...draft, cost: e.target.value } )
						}
					/>
					<small>
						{ __(
							'A whole number, a range such as 1–3, or a choice such as 1 or 3.',
							'beyond-elysium'
						) }
					</small>
				</label>

				<div className="be-catalog__field">
					<DescriptionEditorButton
						label={ stored || label }
						value={ draft.description }
						onOpenChange={ setDescribing }
						onSave={ ( value: CatalogDescription ) =>
							setDraft( { ...draft, description: value } )
						}
					/>
				</div>

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
