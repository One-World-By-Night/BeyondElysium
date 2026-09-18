/**
 * Admin page for managing sheet Templates.
 *
 * Without a game_slug in the page URL it manages the global templates every
 * chronicle shares - a site administrator's job. With one, it manages that
 * chronicle's own overrides: the chronicle's templates are listed and
 * editable, and each global template can be copied into a chronicle-only
 * override. Both forms compose TemplateLayoutEditor for the section layout.
 */
import {
	createInterpolateElement,
	useEffect,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import TemplateLayoutEditor from './TemplateLayoutEditor';
import type { Template, TemplateLayout } from '../../types';
import { errorMessage } from '../../lib/errorMessage';
import HelpButton from '../shared/HelpButton';
import './Admin.css';

const DEFAULT_LAYOUT: TemplateLayout = { version: 1, columns: 3, sections: [] };

const EMPTY_FORM = {
	name: '',
	template_type: 'sheet_full',
	stack_slug: '',
	layout: DEFAULT_LAYOUT,
};

/** Which collection the open form writes to. */
type Scope = 'global' | 'chronicle';

/**
 * Renders the Templates admin page. Loads the global templates, plus the
 * chronicle's own when a game_slug is present, and saves through the
 * templatesGlobal API or the chronicle's templates API depending on scope.
 */
export function AdminTemplates() {
	const [ templates, setTemplates ] = useState< Template[] >( [] );
	const [ chronicleTemplates, setChronicleTemplates ] = useState<
		Template[]
	>( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ editingId, setEditingId ] = useState< number | null >( null );
	const [ creating, setCreating ] = useState( false );
	const [ scope, setScope ] = useState< Scope >( 'global' );
	const [ form, setForm ] = useState( EMPTY_FORM );
	const [ saving, setSaving ] = useState( false );

	const gameSlug =
		new URLSearchParams( window.location.search ).get( 'game_slug' ) ?? '';
	// Global templates are shared by every chronicle, so only a site administrator changes them.
	const canEditGlobal =
		!! window.beyondElysium?.capabilities?.be_manage_games;

	/**
	 * Fetches the global templates, and this chronicle's own when a game_slug
	 * is present, storing both in state. Sets the error message on failure and
	 * clears the loading flag either way.
	 */
	function load() {
		setLoading( true );
		// per_page: 100 is the REST route's own hard cap - without it this call silently
		// truncated to the route's default of 20, hiding whichever templates sorted past
		// that point (same defect class as AdminSchemaBlocks.tsx's own fix).
		Promise.all( [
			api.templatesGlobal.list( { per_page: 100 } ),
			gameSlug
				? api.templates( gameSlug ).list( { per_page: 100 } )
				: Promise.resolve( [] as Template[] ),
		] )
			.then( ( [ global, chronicle ] ) => {
				setTemplates( global );
				setChronicleTemplates( chronicle );
				setLoading( false );
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

	function formFrom( template: Template ) {
		return {
			name: template.name,
			template_type: template.template_type,
			stack_slug: template.stack_slug ?? '',
			layout: template.layout,
		};
	}

	function startEdit( template: Template, editScope: Scope ) {
		setEditingId( template.id );
		setCreating( false );
		setScope( editScope );
		setForm( formFrom( template ) );
	}

	function startCreate( createScope: Scope ) {
		setEditingId( null );
		setCreating( true );
		setScope( createScope );
		setForm( EMPTY_FORM );
	}

	/** Opens a create form for this chronicle pre-filled from a global template. */
	function startOverride( template: Template ) {
		setEditingId( null );
		setCreating( true );
		setScope( 'chronicle' );
		setForm( formFrom( template ) );
	}

	function cancel() {
		setEditingId( null );
		setCreating( false );
		setForm( EMPTY_FORM );
	}

	/**
	 * Submits the create or edit form to the global or chronicle collection,
	 * depending on the form's scope, then reloads both lists and closes the
	 * form. Surfaces any API error and tracks the saving state for the submit
	 * button.
	 */
	async function save( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! form.name.trim() || ! form.template_type.trim() ) {
			return;
		}

		const data = {
			name: form.name.trim(),
			template_type: form.template_type.trim(),
			layout: form.layout,
			stack_slug: form.stack_slug.trim() || undefined,
		};
		const target =
			scope === 'chronicle' && gameSlug
				? api.templates( gameSlug )
				: api.templatesGlobal;

		setSaving( true );
		setError( null );
		try {
			if ( creating ) {
				await target.create( data );
			} else if ( editingId !== null ) {
				await target.update( editingId, data );
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
	 * Deletes a template after the user confirms via a browser dialog, then
	 * reloads the lists. Surfaces any API error without dismissing the tables.
	 */
	async function remove( template: Template, removeScope: Scope ) {
		const message =
			removeScope === 'chronicle'
				? // translators: %s: template name.
				  __(
						'Delete "%s"? This chronicle\'s sheets go back to the shared layout.',
						'beyond-elysium'
				  )
				: // translators: %s: template name.
				  __(
						'Delete "%s"? Sheets resolving to it fall back to the next level (per-game, then generated).',
						'beyond-elysium'
				  );
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( sprintf( message, template.name ) ) ) {
			return;
		}
		try {
			if ( removeScope === 'chronicle' && gameSlug ) {
				await api.templates( gameSlug ).delete( template.id );
			} else {
				await api.templatesGlobal.delete( template.id );
			}
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

	const formOpen = creating || editingId !== null;

	return (
		<div className="be-admin">
			<div className="be-help-heading">
				<h1>{ __( 'Templates', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="templates" />
			</div>
			{ gameSlug ? (
				<p className="be-admin__game-scope-notice">
					{ createInterpolateElement(
						__(
							"Customizing for chronicle <slug/> - a template you save here applies to that chronicle's sheets only.",
							'beyond-elysium'
						),
						{ slug: <strong>{ gameSlug }</strong> }
					) }
				</p>
			) : (
				<p>
					{ canEditGlobal
						? __(
								'Shared sheet layouts every chronicle uses unless it has its own override.',
								'beyond-elysium'
						  )
						: __(
								'Shared sheet layouts every chronicle uses. Only a site administrator can change them - to customize a chronicle you run, open its Chronicle Setup and choose Sheet templates.',
								'beyond-elysium'
						  ) }
				</p>
			) }
			{ error && (
				<div className="be-admin__error" role="alert">
					{ error }
				</div>
			) }

			{ loading && <p>{ __( 'Loading…', 'beyond-elysium' ) }</p> }

			{ ! loading && gameSlug && (
				<>
					<h2>
						{ __( "This chronicle's templates", 'beyond-elysium' ) }
					</h2>
					<table className="be-admin__table">
						<thead>
							<tr>
								<th>{ __( 'Name', 'beyond-elysium' ) }</th>
								<th>{ __( 'Type', 'beyond-elysium' ) }</th>
								<th>{ __( 'Stack', 'beyond-elysium' ) }</th>
								<th>{ __( 'Actions', 'beyond-elysium' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ chronicleTemplates.length === 0 && (
								<tr>
									<td colSpan={ 4 }>
										{ __(
											'None yet - this chronicle uses the shared templates below.',
											'beyond-elysium'
										) }
									</td>
								</tr>
							) }
							{ chronicleTemplates.map( ( template ) => (
								<tr key={ template.id }>
									<td>{ template.name }</td>
									<td>{ template.template_type }</td>
									<td>
										{ template.stack_slug ?? (
											<em>
												{ __(
													'all',
													'beyond-elysium'
												) }
											</em>
										) }
									</td>
									<td>
										<button
											type="button"
											onClick={ () =>
												startEdit(
													template,
													'chronicle'
												)
											}
										>
											{ __( 'Edit', 'beyond-elysium' ) }
										</button>
										<button
											type="button"
											onClick={ () =>
												remove( template, 'chronicle' )
											}
										>
											{ __( 'Delete', 'beyond-elysium' ) }
										</button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
					{ ! formOpen && (
						<button
							type="button"
							onClick={ () => startCreate( 'chronicle' ) }
						>
							{ __(
								'+ New Template for this chronicle',
								'beyond-elysium'
							) }
						</button>
					) }
					<h2>{ __( 'Shared templates', 'beyond-elysium' ) }</h2>
				</>
			) }

			{ ! loading && (
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
								<td colSpan={ 5 }>
									{ __(
										'No global templates yet - sheets fall back to a generated layout.',
										'beyond-elysium'
									) }
								</td>
							</tr>
						) }
						{ templates.map( ( template ) => (
							<tr key={ template.id }>
								<td>{ template.name }</td>
								<td>{ template.template_type }</td>
								<td>
									{ template.stack_slug ?? (
										<em>
											{ __( 'all', 'beyond-elysium' ) }
										</em>
									) }
								</td>
								<td>
									{ template.is_system
										? __( 'Yes', 'beyond-elysium' )
										: __( 'No', 'beyond-elysium' ) }
								</td>
								<td>
									{ gameSlug && (
										<button
											type="button"
											onClick={ () =>
												startOverride( template )
											}
										>
											{ __(
												'Customize for this chronicle',
												'beyond-elysium'
											) }
										</button>
									) }
									{ canEditGlobal && ! gameSlug && (
										<button
											type="button"
											onClick={ () =>
												startEdit( template, 'global' )
											}
										>
											{ __( 'Edit', 'beyond-elysium' ) }
										</button>
									) }
									{ canEditGlobal &&
										! gameSlug &&
										! template.is_system && (
											<button
												type="button"
												onClick={ () =>
													remove( template, 'global' )
												}
											>
												{ __(
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

			{ canEditGlobal && ! gameSlug && ! formOpen && (
				<button type="button" onClick={ () => startCreate( 'global' ) }>
					{ __( '+ New Template', 'beyond-elysium' ) }
				</button>
			) }

			{ formOpen && (
				<form
					className="be-admin__form be-admin__form--wide"
					onSubmit={ save }
				>
					<h2>
						{ creating
							? scope === 'chronicle'
								? __(
										'New template for this chronicle',
										'beyond-elysium'
								  )
								: __( 'New Template', 'beyond-elysium' )
							: sprintf(
									/* translators: %d: the numeric id of the template being edited */
									__( 'Edit template #%d', 'beyond-elysium' ),
									editingId as number
							  ) }
					</h2>
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
					<label>
						{ __( 'Template Type', 'beyond-elysium' ) }
						<input
							type="text"
							value={ form.template_type }
							onChange={ ( e ) =>
								setForm( {
									...form,
									template_type: e.target.value,
								} )
							}
							placeholder={ __(
								'sheet_full, sheet_compact, sheet_mobile…',
								'beyond-elysium'
							) }
							required
						/>
					</label>
					<label>
						{ __(
							'Stack Slug (optional - blank applies to every creature type)',
							'beyond-elysium'
						) }
						<input
							type="text"
							value={ form.stack_slug }
							onChange={ ( e ) =>
								setForm( {
									...form,
									stack_slug: e.target.value,
								} )
							}
						/>
					</label>

					<TemplateLayoutEditor
						layout={ form.layout }
						onChange={ ( layout ) =>
							setForm( { ...form, layout } )
						}
					/>

					<div className="be-admin__form-actions">
						<button type="submit" disabled={ saving }>
							{ saving
								? __( 'Saving…', 'beyond-elysium' )
								: __( 'Save', 'beyond-elysium' ) }
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
