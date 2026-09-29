/**
 * Admin page for sheet Templates: the book's, read-only, and a chronicle's own.
 */
import {
	createInterpolateElement,
	useEffect,
	useRef,
	useState,
} from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import TemplateLayoutEditor from './TemplateLayoutEditor';
import type { Template, TemplateLayout } from '../../types';
import { errorMessage } from '../../lib/errorMessage';
import { useRevealOnOpen } from '../../lib/revealEditor';
import HelpButton from '../shared/HelpButton';
import './Admin.css';

const DEFAULT_LAYOUT: TemplateLayout = { version: 1, columns: 3, sections: [] };

const EMPTY_FORM = {
	name: '',
	template_type: 'sheet_full',
	stack_slug: '',
	layout: DEFAULT_LAYOUT,
};

/**
 * Renders the Templates admin page.
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
	const editorRef = useRef< HTMLFormElement >( null );
	useRevealOnOpen( editorRef, creating ? 'new' : editingId );
	const [ viewing, setViewing ] = useState< Template | null >( null );
	const viewerRef = useRef< HTMLDivElement >( null );
	useRevealOnOpen( viewerRef, viewing?.id ?? null );
	const [ form, setForm ] = useState( EMPTY_FORM );
	const [ saving, setSaving ] = useState( false );

	const gameSlug =
		new URLSearchParams( window.location.search ).get( 'game_slug' ) ?? '';

	/**
	 * Fetches the global templates, and this chronicle's own when a game_slug is present, storing both in state.
	 */
	function load() {
		setLoading( true );
		// per_page: 100 is the REST route's own hard cap.
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

	function startEdit( template: Template ) {
		setViewing( null );
		setEditingId( template.id );
		setCreating( false );
		setForm( formFrom( template ) );
	}

	function startCreate() {
		setViewing( null );
		setEditingId( null );
		setCreating( true );
		setForm( EMPTY_FORM );
	}

	/**
	 * Opens a create form for this chronicle pre-filled from a shared template.
	 */
	function startOverride( template: Template ) {
		setViewing( null );
		setEditingId( null );
		setCreating( true );
		setForm( formFrom( template ) );
	}

	/**
	 * Opens a shared template read-only.
	 */
	function startView( template: Template ) {
		cancel();
		setViewing( template );
	}

	function cancel() {
		setEditingId( null );
		setCreating( false );
		setForm( EMPTY_FORM );
	}

	/**
	 * Submits the create or edit form to this chronicle's templates.
	 */
	async function save( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! form.name.trim() || ! form.template_type.trim() || ! gameSlug ) {
			return;
		}

		const data = {
			name: form.name.trim(),
			template_type: form.template_type.trim(),
			layout: form.layout,
			stack_slug: form.stack_slug.trim() || undefined,
		};
		const target = api.templates( gameSlug );

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
	 * Whether a chronicle's own template overrides a real shared template with the same type and stack - the same pair
	 * `startOverride()` copies from when creating it - rather than being custom, with no shared counterpart.
	 */
	function isBookOverride( template: Template ): boolean {
		return templates.some(
			( t ) =>
				t.template_type === template.template_type &&
				( t.stack_slug ?? '' ) === ( template.stack_slug ?? '' )
		);
	}

	/**
	 * Deletes one of this chronicle's templates after the user confirms via a browser dialog; a shared override resets
	 * to the shared layout instead of removing something with no shared counterpart.
	 */
	async function remove( template: Template ) {
		const message = isBookOverride( template )
			? // translators: %s: template name.
				__(
					'Reset "%s" to the shared layout? This chronicle\'s own changes to it are lost.',
					'beyond-elysium'
				)
			: // translators: %s: template name.
				__(
					'Delete "%s"? This chronicle\'s sheets go back to the shared layout.',
					'beyond-elysium'
				);

		if (
			! gameSlug ||
			! window.confirm( sprintf( message, template.name ) )
		) {
			return;
		}
		try {
			await api.templates( gameSlug ).delete( template.id );
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
				<p className="be-admin__game-scope-notice">
					{ __(
						"This is the book: the sheet layouts every chronicle uses unless it has its own, read-only here. To customize one for a chronicle you run, open that chronicle's Chronicle Setup and choose Sheet templates.",
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
												startEdit( template )
											}
										>
											{ __( 'Edit', 'beyond-elysium' ) }
										</button>
										<button
											type="button"
											onClick={ () => remove( template ) }
										>
											{ isBookOverride( template )
												? __(
														'Reset to book',
														'beyond-elysium'
													)
												: __(
														'Delete',
														'beyond-elysium'
													) }
										</button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
					{ ! formOpen && (
						<button type="button" onClick={ startCreate }>
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
										'No shared templates - sheets fall back to a generated layout.',
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
									<button
										type="button"
										onClick={ () => startView( template ) }
									>
										{ __( 'View', 'beyond-elysium' ) }
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ viewing && (
				<div
					ref={ viewerRef }
					className="be-admin__form be-admin__form--wide"
					tabIndex={ -1 }
				>
					<h2>
						{ sprintf(
							/* translators: %s: the template's name */
							__( '%s (read-only)', 'beyond-elysium' ),
							viewing.name
						) }
					</h2>
					<fieldset disabled className="be-admin__read-only">
						<label>
							{ __( 'Template Type', 'beyond-elysium' ) }
							<input
								type="text"
								value={ viewing.template_type }
								readOnly
							/>
						</label>
						<label>
							{ __( 'Stack Slug', 'beyond-elysium' ) }
							<input
								type="text"
								value={ viewing.stack_slug ?? '' }
								readOnly
							/>
						</label>
						<TemplateLayoutEditor
							layout={ viewing.layout }
							onChange={ () => undefined }
						/>
					</fieldset>
					<div className="be-admin__form-actions">
						<button
							type="button"
							onClick={ () => setViewing( null ) }
						>
							{ __( 'Close', 'beyond-elysium' ) }
						</button>
					</div>
				</div>
			) }

			{ formOpen && (
				<form
					ref={ editorRef }
					className="be-admin__form be-admin__form--wide"
					onSubmit={ save }
				>
					<h2>
						{ creating
							? __(
									'New template for this chronicle',
									'beyond-elysium'
								)
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
