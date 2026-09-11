/**
 * Admin page for managing global sheet Templates.
 *
 * Lists the global (non-chronicle-specific) templates, with a create/edit
 * form that composes TemplateLayoutEditor for the section layout. A
 * per-chronicle override, when one exists, lives on the game itself rather
 * than this page.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import TemplateLayoutEditor from './TemplateLayoutEditor';
import type { Template, TemplateLayout } from '../../types';
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

const DEFAULT_LAYOUT: TemplateLayout = { version: 1, columns: 3, sections: [] };

const EMPTY_FORM = { name: '', template_type: 'sheet_full', stack_slug: '', layout: DEFAULT_LAYOUT };

/**
 * Renders the Templates admin page: a table of global templates plus a
 * create/edit form. Loads the global template list, lets staff edit a
 * template's name, type, optional creature-stack scope, and section
 * layout, and saves changes through the templatesGlobal API.
 */
export function AdminTemplates() {
	const [ templates, setTemplates ] = useState<Template[]>( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );
	const [ editingId, setEditingId ] = useState<number | null>( null );
	const [ creating, setCreating ] = useState( false );
	const [ form, setForm ] = useState( EMPTY_FORM );
	const [ saving, setSaving ] = useState( false );

	/**
	 * Fetches the list of global templates and stores the result in state.
	 * Sets the error message on failure and clears the loading flag either
	 * way.
	 */
	function load() {
		setLoading( true );
		api.templatesGlobal
			.list()
			.then( ( result ) => {
				setTemplates( result );
				setLoading( false );
			} )
			.catch( ( err: unknown ) => {
				setError( errorMessage( err ) );
				setLoading( false );
			} );
	}

	useEffect( load, [] );

	function startEdit( template: Template ) {
		setEditingId( template.id );
		setCreating( false );
		setForm( {
			name: template.name,
			template_type: template.template_type,
			stack_slug: template.stack_slug ?? '',
			layout: template.layout,
		} );
	}

	function startCreate() {
		setEditingId( null );
		setCreating( true );
		setForm( EMPTY_FORM );
	}

	function cancel() {
		setEditingId( null );
		setCreating( false );
		setForm( EMPTY_FORM );
	}

	/**
	 * Submits the create or edit form. Creates a new global template or
	 * updates the one being edited depending on which mode is active, then
	 * reloads the list and closes the form. Surfaces any API error and
	 * tracks the saving state for the submit button.
	 */
	async function save( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! form.name.trim() || ! form.template_type.trim() ) {
			return;
		}

		setSaving( true );
		setError( null );
		try {
			if ( creating ) {
				await api.templatesGlobal.create( {
					name: form.name.trim(),
					template_type: form.template_type.trim(),
					layout: form.layout,
					stack_slug: form.stack_slug.trim() || undefined,
				} );
			} else if ( editingId !== null ) {
				await api.templatesGlobal.update( editingId, {
					name: form.name.trim(),
					template_type: form.template_type.trim(),
					layout: form.layout,
					stack_slug: form.stack_slug.trim() || undefined,
				} );
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
	 * Deletes a template after the user confirms via a browser dialog, then
	 * reloads the list. Surfaces any API error without dismissing the table,
	 * leaving the existing rows visible.
	 */
	async function remove( template: Template ) {
		// eslint-disable-next-line no-alert
		if (
			! window.confirm(
				sprintf(
					// translators: %s: template name.
					__( 'Delete "%s"? Sheets resolving to it fall back to the next level (per-game, then generated).', 'beyond-elysium' ),
					template.name
				)
			)
		) {
			return;
		}
		try {
			await api.templatesGlobal.delete( template.id );
			load();
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		}
	}

	return (
		<div className="be-admin">
			<h1>{ __( 'Templates', 'beyond-elysium' ) }</h1>
			<p>{ __( 'Global sheet layouts. A chronicle-specific override lives on the game itself, not here.', 'beyond-elysium' ) }</p>
			{ error && (
				<div className="be-admin__error" role="alert">
					{ error }
				</div>
			) }

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : (
				<table className="be-admin__table">
					<thead>
						<tr>
							<th>{ __( 'Name', 'beyond-elysium' ) }</th>
							<th>{ __( 'Type', 'beyond-elysium' ) }</th>
							<th>{ __( 'Stack', 'beyond-elysium' ) }</th>
							<th>{ __( 'System?', 'beyond-elysium' ) }</th>
							<th>{ __( 'Actions', 'beyond-elysium' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ templates.length === 0 && (
							<tr>
								<td colSpan={ 5 }>{ __( 'No global templates yet - sheets fall back to a generated layout.', 'beyond-elysium' ) }</td>
							</tr>
						) }
						{ templates.map( ( template ) => (
							<tr key={ template.id }>
								<td>{ template.name }</td>
								<td>{ template.template_type }</td>
								<td>{ template.stack_slug ?? <em>{ __( 'all', 'beyond-elysium' ) }</em> }</td>
								<td>{ template.is_system ? __( 'Yes', 'beyond-elysium' ) : __( 'No', 'beyond-elysium' ) }</td>
								<td>
									<button type="button" onClick={ () => startEdit( template ) }>
										{ __( 'Edit', 'beyond-elysium' ) }
									</button>
									{ ! template.is_system && (
										<button type="button" onClick={ () => remove( template ) }>
											{ __( 'Delete', 'beyond-elysium' ) }
										</button>
									) }
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ ! creating && editingId === null && (
				<button type="button" onClick={ startCreate }>
					{ __( '+ New Template', 'beyond-elysium' ) }
				</button>
			) }

			{ ( creating || editingId !== null ) && (
				<form className="be-admin__form be-admin__form--wide" onSubmit={ save }>
					<h2>{ creating ? __( 'New Template', 'beyond-elysium' ) : sprintf( __( 'Edit template #%d', 'beyond-elysium' ), editingId as number ) }</h2>
					<label>
						{ __( 'Name', 'beyond-elysium' ) }
						<input type="text" value={ form.name } onChange={ ( e ) => setForm( { ...form, name: e.target.value } ) } required />
					</label>
					<label>
						{ __( 'Template Type', 'beyond-elysium' ) }
						<input
							type="text"
							value={ form.template_type }
							onChange={ ( e ) => setForm( { ...form, template_type: e.target.value } ) }
							placeholder={ __( 'sheet_full, sheet_compact, sheet_mobile…', 'beyond-elysium' ) }
							required
						/>
					</label>
					<label>
						{ __( 'Stack Slug (optional - blank applies to every creature type)', 'beyond-elysium' ) }
						<input type="text" value={ form.stack_slug } onChange={ ( e ) => setForm( { ...form, stack_slug: e.target.value } ) } />
					</label>

					<TemplateLayoutEditor layout={ form.layout } onChange={ ( layout ) => setForm( { ...form, layout } ) } />

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

export default AdminTemplates;
