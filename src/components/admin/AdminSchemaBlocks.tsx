/**
 * Admin page for Schema Blocks: the book's, read-only, and a chronicle's own and its copies of the book's.
 */
import {
	createInterpolateElement,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import SchemaBlockDefinitionEditor from './SchemaBlockDefinitionEditor';
import type { SchemaBlock, SectionType } from '../../types';
import { errorMessage } from '../../lib/errorMessage';
import { useRevealOnOpen } from '../../lib/revealEditor';
import { everyPage } from '../../lib/everyPage';
import HelpButton from '../shared/HelpButton';
import './Admin.css';

const SECTION_TYPES: SectionType[] = [
	'trait_list',
	'tiered_power',
	'resource_pool',
	'identity_field',
];

const DEFAULT_DEFINITION: Record< SectionType, Record< string, unknown > > = {
	trait_list: { items: [] },
	tiered_power: { powers: [] },
	resource_pool: { pools: [] },
	identity_field: { fields: [] },
};

const EMPTY_FORM = {
	slug: '',
	name: '',
	section_type: 'trait_list' as SectionType,
	storyteller_only: false,
	definition: DEFAULT_DEFINITION.trait_list,
};

/**
 * Renders the Schema Blocks admin page: a filterable table of existing blocks plus a create/edit form.
 */
export function AdminSchemaBlocks() {
	const [ blocks, setBlocks ] = useState< SchemaBlock[] >( [] );
	const [ bookBlocks, setBookBlocks ] = useState< SchemaBlock[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ editingSlug, setEditingSlug ] = useState< string | null >( null );
	const [ creating, setCreating ] = useState( false );
	const editorRef = useRef< HTMLFormElement >( null );
	useRevealOnOpen( editorRef, creating ? 'new' : editingSlug );
	const [ form, setForm ] = useState( EMPTY_FORM );
	const [ saving, setSaving ] = useState( false );
	const [ showSystem, setShowSystem ] = useState( true );
	// Filters the visible list by section type; combines with the system/custom toggle above.
	const [ sectionTypeFilter, setSectionTypeFilter ] = useState<
		SectionType | ''
	>( '' );
	// Optional game_slug query param scopes the page to one chronicle, where its blocks are edited.
	const gameSlug =
		new URLSearchParams( window.location.search ).get( 'game_slug' ) ?? '';
	// The book is read-only; blocks are changed for a chronicle.
	const canEdit = gameSlug !== '';
	// A block named by `edit` opens once, when the list first loads.
	const openOnLoad = useRef(
		new URLSearchParams( window.location.search ).get( 'edit' ) ?? ''
	);

	/**
	 * Fetches the schema block list for the current scope (global catalog, or one chronicle when a game_slug is present)
	 * and stores the result in state.
	 */
	function load() {
		setLoading( true );
		Promise.all( [
			everyPage( ( page ) =>
				api.schemaBlocks.listPaginated( {
					...( gameSlug ? { game_slug: gameSlug } : {} ),
					page,
					per_page: 100,
				} )
			),
			gameSlug
				? everyPage( ( page ) =>
						api.schemaBlocks.listPaginated( {
							page,
							per_page: 100,
						} )
					)
				: Promise.resolve< SchemaBlock[] >( [] ),
		] )
			.then( ( [ result, book ] ) => {
				setBlocks( result );
				setBookBlocks( book );
				setLoading( false );
				const named = result.find(
					( block ) => block.slug === openOnLoad.current
				);
				openOnLoad.current = '';
				if ( named ) {
					startEdit( named );
				}
			} )
			.catch( ( err: unknown ) => {
				setError(
					errorMessage(
						err,
						__( 'Something went wrong.', 'beyond-elysium' )
					)
				);
				setLoading( false );
			} );
	}

	useEffect( load, [ gameSlug ] );

	const visible = (
		showSystem ? blocks : blocks.filter( ( b ) => ! b.is_system )
	).filter(
		( b ) =>
			sectionTypeFilter === '' || b.section_type === sectionTypeFilter
	);

	function startEdit( block: SchemaBlock ) {
		setEditingSlug( block.slug );
		setCreating( false );
		setForm( {
			slug: block.slug,
			name: block.name,
			section_type: block.section_type,
			storyteller_only: !! block.storyteller_only,
			definition: block.definition as unknown as Record<
				string,
				unknown
			>,
		} );
	}

	function startCreate() {
		setEditingSlug( null );
		setCreating( true );
		setForm( EMPTY_FORM );
	}

	function cancel() {
		setEditingSlug( null );
		setCreating( false );
		setForm( EMPTY_FORM );
	}

	/**
	 * Switches the form's section type and replaces the definition with that type's default empty shape.
	 */
	function changeSectionType( sectionType: SectionType ) {
		// Reset to the new type's default definition shape.
		setForm( {
			...form,
			section_type: sectionType,
			definition: DEFAULT_DEFINITION[ sectionType ],
		} );
	}

	/**
	 * Submits the create or edit form.
	 */
	async function save( e: React.FormEvent ) {
		e.preventDefault();
		if (
			! canEdit ||
			! form.name.trim() ||
			( creating && ! form.slug.trim() )
		) {
			return;
		}

		setSaving( true );
		setError( null );
		try {
			if ( creating ) {
				await api.schemaBlocks.create(
					{
						slug: form.slug.trim(),
						name: form.name.trim(),
						section_type: form.section_type,
						storyteller_only: form.storyteller_only,
						definition: form.definition as any,
					},
					gameSlug
				);
			} else if ( editingSlug ) {
				await api.schemaBlocks.update(
					editingSlug,
					{
						name: form.name.trim(),
						section_type: form.section_type,
						storyteller_only: form.storyteller_only,
						definition: form.definition as any,
					},
					gameSlug
				);
			}
			cancel();
			load();
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setSaving( false );
		}
	}

	/**
	 * A chronicle's own row that shares its slug with a real book block is that book block's fork; deleting it resets
	 * the chronicle to the book instead of removing something with no book counterpart.
	 */
	function isBookFork( block: SchemaBlock ): boolean {
		return bookBlocks.some( ( b ) => b.slug === block.slug );
	}

	/**
	 * Deletes a schema block after the user confirms via a browser dialog; a book fork resets to the book instead.
	 */
	async function remove( block: SchemaBlock ) {
		const confirmed = isBookFork( block )
			? window.confirm(
					sprintf(
						// translators: %s: schema block name.
						__(
							'Reset "%s" to the book? This chronicle\'s own changes to it are lost.',
							'beyond-elysium'
						),
						block.name
					)
				)
			: window.confirm(
					sprintf(
						// translators: %s: schema block name.
						__(
							'Delete "%s"? Any creature stack or template referencing it will show a missing section.',
							'beyond-elysium'
						),
						block.name
					)
				);
		if ( ! confirmed ) {
			return;
		}
		try {
			await api.schemaBlocks.delete( block.slug, gameSlug );
			load();
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		}
	}

	return (
		<div className="be-admin">
			<div className="be-help-heading">
				<h1>{ __( 'Schema Blocks', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="schema-blocks" />
			</div>
			{ gameSlug && (
				<p className="be-admin__game-scope-notice">
					{ createInterpolateElement(
						__(
							'Editing for chronicle <slug/> - a block you save or create here belongs to that chronicle alone, never the shared base catalog.',
							'beyond-elysium'
						),
						{ slug: <strong>{ gameSlug }</strong> }
					) }
				</p>
			) }
			{ ! canEdit && (
				<p className="be-admin__game-scope-notice">
					{ __(
						"This is the book: the catalog as released, read-only here. To change a block for a chronicle you run, open that chronicle's Chronicle Setup and choose Catalog customisation.",
						'beyond-elysium'
					) }
				</p>
			) }
			{ error && (
				<div className="be-admin__error" role="alert">
					{ error }
				</div>
			) }

			<div className="be-admin__filters">
				<label>
					{ __( 'Section type', 'beyond-elysium' ) }{ ' ' }
					<select
						value={ sectionTypeFilter }
						onChange={ ( e ) =>
							setSectionTypeFilter(
								e.target.value as SectionType | ''
							)
						}
					>
						<option value="">
							{ __( 'All', 'beyond-elysium' ) }
						</option>
						{ SECTION_TYPES.map( ( t ) => (
							<option key={ t } value={ t }>
								{ t }
							</option>
						) ) }
					</select>
				</label>
				<label>
					<input
						type="checkbox"
						checked={ showSystem }
						onChange={ ( e ) => setShowSystem( e.target.checked ) }
					/>{ ' ' }
					{ sprintf(
						// translators: %d: number of system schema blocks.
						__( 'Show system blocks (%d)', 'beyond-elysium' ),
						blocks.filter( ( b ) => b.is_system ).length
					) }
				</label>
			</div>

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : (
				<table className="be-admin__table">
					<thead>
						<tr>
							<th>{ __( 'Name', 'beyond-elysium' ) }</th>
							<th>{ __( 'Slug', 'beyond-elysium' ) }</th>
							<th>{ __( 'Section Type', 'beyond-elysium' ) }</th>
							<th>{ __( 'System?', 'beyond-elysium' ) }</th>
							<th>{ __( 'Actions', 'beyond-elysium' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ visible.length === 0 && (
							<tr>
								<td colSpan={ 5 }>
									{ __(
										'No custom blocks yet.',
										'beyond-elysium'
									) }
								</td>
							</tr>
						) }
						{ visible.map( ( block ) => (
							<tr key={ block.slug }>
								<td>{ block.name }</td>
								<td>
									<code>{ block.slug }</code>
								</td>
								<td>{ block.section_type }</td>
								<td>
									{ block.is_system
										? __( 'Yes', 'beyond-elysium' )
										: __( 'No', 'beyond-elysium' ) }
								</td>
								<td>
									<button
										type="button"
										onClick={ () => startEdit( block ) }
									>
										{ canEdit
											? __( 'Edit', 'beyond-elysium' )
											: __( 'View', 'beyond-elysium' ) }
									</button>
									{ /* A chronicle deletes only its own blocks. */ }
									{ ! block.is_system &&
										canEdit &&
										block.game_slug === gameSlug && (
											<button
												type="button"
												onClick={ () =>
													remove( block )
												}
											>
												{ isBookFork( block )
													? __(
															'Reset to book',
															'beyond-elysium'
														)
													: __(
															'Delete',
															'beyond-elysium'
														) }
											</button>
										) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ canEdit && ! creating && editingSlug === null && (
				<button type="button" onClick={ startCreate }>
					{ __( '+ New Schema Block', 'beyond-elysium' ) }
				</button>
			) }

			{ ( creating || editingSlug !== null ) && (
				<form
					ref={ editorRef }
					className="be-admin__form be-admin__form--wide"
					onSubmit={ save }
				>
					<h2>
						{ creating &&
							__( 'New Schema Block', 'beyond-elysium' ) }
						{ ! creating &&
							canEdit &&
							sprintf(
								/* translators: %s: the schema block's slug being edited */
								__( 'Edit %s', 'beyond-elysium' ),
								editingSlug as string
							) }
						{ ! creating &&
							! canEdit &&
							sprintf(
								/* translators: %s: the schema block's slug */
								__( '%s (read-only)', 'beyond-elysium' ),
								editingSlug as string
							) }
					</h2>
					<fieldset
						disabled={ ! canEdit }
						className="be-admin__read-only"
					>
						<label>
							{ __( 'Name', 'beyond-elysium' ) }
							<input
								type="text"
								value={ form.name }
								onChange={ ( e ) =>
									setForm( { ...form, name: e.target.value } )
								}
								required
							/>
						</label>
						{ creating && (
							<label>
								{ __( 'Slug', 'beyond-elysium' ) }
								<input
									type="text"
									value={ form.slug }
									onChange={ ( e ) =>
										setForm( {
											...form,
											slug: e.target.value,
										} )
									}
									required
								/>
							</label>
						) }
						<label>
							{ __( 'Section Type', 'beyond-elysium' ) }
							<select
								value={ form.section_type }
								onChange={ ( e ) =>
									changeSectionType(
										e.target.value as SectionType
									)
								}
							>
								{ SECTION_TYPES.map( ( t ) => (
									<option key={ t } value={ t }>
										{ t }
									</option>
								) ) }
							</select>
						</label>

						<label>
							<input
								type="checkbox"
								checked={ !! form.storyteller_only }
								onChange={ ( e ) =>
									setForm( {
										...form,
										storyteller_only: e.target.checked,
									} )
								}
							/>
							{ __(
								'Storyteller only — hide this section and its data from players',
								'beyond-elysium'
							) }
						</label>

						<SchemaBlockDefinitionEditor
							sectionType={ form.section_type }
							definition={ form.definition }
							onChange={ ( definition ) =>
								setForm( { ...form, definition } )
							}
						/>
					</fieldset>

					<div className="be-admin__form-actions">
						{ canEdit && (
							<button type="submit" disabled={ saving }>
								{ saving
									? __( 'Saving…', 'beyond-elysium' )
									: __( 'Save', 'beyond-elysium' ) }
							</button>
						) }
						<button type="button" onClick={ cancel }>
							{ canEdit
								? __( 'Cancel', 'beyond-elysium' )
								: __( 'Close', 'beyond-elysium' ) }
						</button>
					</div>
				</form>
			) }
		</div>
	);
}

export default AdminSchemaBlocks;
