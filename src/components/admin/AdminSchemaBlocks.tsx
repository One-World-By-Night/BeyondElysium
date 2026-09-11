/**
 * Admin page for managing Schema Blocks.
 *
 * Lists a chronicle's custom schema blocks alongside the shared system
 * blocks, with filtering by section type and an add/edit/delete form built
 * on SchemaBlockDefinitionEditor. Supports scoping to one chronicle via a
 * game_slug query parameter, or managing the global block catalog when none
 * is present.
 */
import { createInterpolateElement, useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import SchemaBlockDefinitionEditor from './SchemaBlockDefinitionEditor';
import type { SchemaBlock, SectionType } from '../../types';
import './Admin.css';

interface RestError {
	message?: string;
}

/**
 * Extracts a human-readable message from a REST API error response.
 * Falls back to a generic message when the error object doesn't carry a
 * usable `message` field.
 */
function errorMessage( error: unknown ): string {
	if ( typeof error === 'object' && error !== null && ( error as RestError ).message ) {
		return ( error as RestError ).message as string;
	}
	return __( 'Something went wrong.', 'beyond-elysium' );
}

const SECTION_TYPES: SectionType[] = [ 'trait_list', 'tiered_power', 'resource_pool', 'identity_field' ];

const DEFAULT_DEFINITION: Record<SectionType, Record<string, unknown>> = {
	trait_list: { items: [] },
	tiered_power: { powers: [] },
	resource_pool: { pools: [] },
	identity_field: { fields: [] },
};

const EMPTY_FORM = { slug: '', name: '', section_type: 'trait_list' as SectionType, storyteller_only: 0 as 0 | 1, definition: DEFAULT_DEFINITION.trait_list };

/**
 * Renders the Schema Blocks admin page: a filterable table of existing
 * blocks plus a create/edit form. Loads blocks for the current scope
 * (global or a specific chronicle), lets staff toggle visibility of system
 * blocks and filter by section type, and delegates definition editing to
 * SchemaBlockDefinitionEditor.
 */
export function AdminSchemaBlocks() {
	const [ blocks, setBlocks ] = useState<SchemaBlock[]>( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );
	const [ editingSlug, setEditingSlug ] = useState<string | null>( null );
	const [ creating, setCreating ] = useState( false );
	const [ form, setForm ] = useState( EMPTY_FORM );
	const [ saving, setSaving ] = useState( false );
	const [ showSystem, setShowSystem ] = useState( true );
	// Filters the visible list by section type; combines with the system/custom toggle above.
	const [ sectionTypeFilter, setSectionTypeFilter ] = useState<SectionType | ''>( '' );
	// Optional game_slug query param scopes editing to one chronicle's custom blocks.
	const gameSlug = new URLSearchParams( window.location.search ).get( 'game_slug' ) ?? '';

	/**
	 * Fetches the schema block list for the current scope (global catalog, or
	 * one chronicle when a game_slug is present) and stores the result in
	 * state. Sets the error message on failure and clears the loading flag
	 * either way.
	 */
	function load() {
		setLoading( true );
		api.schemaBlocks
			.list( gameSlug ? { game_slug: gameSlug } : {} )
			.then( ( result ) => {
				setBlocks( result );
				setLoading( false );
			} )
			.catch( ( err: unknown ) => {
				setError( errorMessage( err ) );
				setLoading( false );
			} );
	}

	useEffect( load, [] );

	const visible = ( showSystem ? blocks : blocks.filter( ( b ) => ! b.is_system ) ).filter(
		( b ) => sectionTypeFilter === '' || b.section_type === sectionTypeFilter
	);

	function startEdit( block: SchemaBlock ) {
		setEditingSlug( block.slug );
		setCreating( false );
		setForm( {
			slug: block.slug,
			name: block.name,
			section_type: block.section_type,
			storyteller_only: block.storyteller_only ? 1 : 0,
			definition: block.definition as unknown as Record<string, unknown>,
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
	 * Switches the form's section type and replaces the definition with that
	 * type's default empty shape, since each section type stores its entries
	 * under a different key (items, powers, pools, or fields).
	 */
	function changeSectionType( sectionType: SectionType ) {
		// Reset to the new type's default definition shape.
		setForm( { ...form, section_type: sectionType, definition: DEFAULT_DEFINITION[ sectionType ] } );
	}

	/**
	 * Submits the create or edit form. Creates a new schema block or updates
	 * the one being edited depending on which mode is active, then reloads
	 * the list and closes the form. Surfaces any API error and tracks the
	 * saving state for the submit button.
	 */
	async function save( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! form.name.trim() || ( creating && ! form.slug.trim() ) ) {
			return;
		}

		setSaving( true );
		setError( null );
		try {
			if ( creating ) {
				await api.schemaBlocks.create( {
					slug: form.slug.trim(),
					name: form.name.trim(),
					section_type: form.section_type,
					storyteller_only: form.storyteller_only,
					definition: form.definition as any,
				} );
			} else if ( editingSlug ) {
				await api.schemaBlocks.update(
					editingSlug,
					{
						name: form.name.trim(),
						section_type: form.section_type,
						storyteller_only: form.storyteller_only,
						definition: form.definition as any,
					},
					gameSlug || undefined
				);
			}
			cancel();
			load();
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		} finally {
			setSaving( false );
		}
	}

	/**
	 * Deletes a schema block after the user confirms via a browser dialog,
	 * then reloads the list. Surfaces any API error without dismissing the
	 * table.
	 */
	async function remove( block: SchemaBlock ) {
		// eslint-disable-next-line no-alert
		if (
			! window.confirm(
				sprintf(
					// translators: %s: schema block name.
					__( 'Delete "%s"? Any creature stack or template referencing it will show a missing section.', 'beyond-elysium' ),
					block.name
				)
			)
		) {
			return;
		}
		try {
			await api.schemaBlocks.delete( block.slug );
			load();
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		}
	}

	return (
		<div className="be-admin">
			<h1>{ __( 'Schema Blocks', 'beyond-elysium' ) }</h1>
			{ gameSlug && (
				<p className="be-admin__game-scope-notice">
					{ createInterpolateElement(
						__( "Editing for chronicle <slug/> - a block you save here becomes that chronicle's own customized copy, never the shared base catalog.", 'beyond-elysium' ),
						{ slug: <strong>{ gameSlug }</strong> }
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
						onChange={ ( e ) => setSectionTypeFilter( e.target.value as SectionType | '' ) }
					>
						<option value="">{ __( 'All', 'beyond-elysium' ) }</option>
						{ SECTION_TYPES.map( ( t ) => (
							<option key={ t } value={ t }>
								{ t }
							</option>
						) ) }
					</select>
				</label>
				<label>
					<input type="checkbox" checked={ showSystem } onChange={ ( e ) => setShowSystem( e.target.checked ) } />
					{ ' ' }
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
								<td colSpan={ 5 }>{ __( 'No custom blocks yet.', 'beyond-elysium' ) }</td>
							</tr>
						) }
						{ visible.map( ( block ) => (
							<tr key={ block.slug }>
								<td>{ block.name }</td>
								<td>
									<code>{ block.slug }</code>
								</td>
								<td>{ block.section_type }</td>
								<td>{ block.is_system ? __( 'Yes', 'beyond-elysium' ) : __( 'No', 'beyond-elysium' ) }</td>
								<td>
									<button type="button" onClick={ () => startEdit( block ) }>
										{ __( 'Edit', 'beyond-elysium' ) }
									</button>
									{ ! block.is_system && (
										<button type="button" onClick={ () => remove( block ) }>
											{ __( 'Delete', 'beyond-elysium' ) }
										</button>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ ! creating && editingSlug === null && (
				<button type="button" onClick={ startCreate }>
					{ __( '+ New Schema Block', 'beyond-elysium' ) }
				</button>
			) }

			{ ( creating || editingSlug !== null ) && (
				<form className="be-admin__form be-admin__form--wide" onSubmit={ save }>
					<h2>{ creating ? __( 'New Schema Block', 'beyond-elysium' ) : sprintf( __( 'Edit %s', 'beyond-elysium' ), editingSlug as string ) }</h2>
					<label>
						{ __( 'Name', 'beyond-elysium' ) }
						<input type="text" value={ form.name } onChange={ ( e ) => setForm( { ...form, name: e.target.value } ) } required />
					</label>
					{ creating && (
						<label>
							{ __( 'Slug', 'beyond-elysium' ) }
							<input type="text" value={ form.slug } onChange={ ( e ) => setForm( { ...form, slug: e.target.value } ) } required />
						</label>
					) }
					<label>
						{ __( 'Section Type', 'beyond-elysium' ) }
						<select value={ form.section_type } onChange={ ( e ) => changeSectionType( e.target.value as SectionType ) }>
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
							onChange={ ( e ) => setForm( { ...form, storyteller_only: e.target.checked ? 1 : 0 } ) }
						/>
						{ __( 'Storyteller only — hide this section and its data from players', 'beyond-elysium' ) }
					</label>

					<SchemaBlockDefinitionEditor
						sectionType={ form.section_type }
						definition={ form.definition }
						onChange={ ( definition ) => setForm( { ...form, definition } ) }
					/>

					<div className="be-admin__form-actions">
						<button type="submit" disabled={ saving }>
							{ saving ? __( 'Saving…', 'beyond-elysium' ) : __( 'Save', 'beyond-elysium' ) }
						</button>
						<button type="button" onClick={ cancel }>
							{ __( 'Cancel', 'beyond-elysium' ) }
						</button>
					</div>
				</form>
			) }
		</div>
	);
}

export default AdminSchemaBlocks;
