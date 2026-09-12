/**
 * Admin page for managing chronicles (games) site-wide.
 * The only screen for creating a chronicle from scratch; lists every
 * chronicle regardless of scope and provides create, edit, and delete
 * actions for each.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type { Game } from '../../types';
import './Admin.css';

interface RestError {
	message?: string;
}

/**
 * Extracts a human-readable message from a caught error value.
 * Falls back to a generic message when the error has no usable
 * `message` property.
 */
function errorMessage( error: unknown ): string {
	if ( typeof error === 'object' && error !== null && ( error as RestError ).message ) {
		return ( error as RestError ).message as string;
	}
	return __( 'Something went wrong.', 'beyond-elysium' );
}

const EMPTY_FORM = { name: '', slug: '', game_type: 'met', description: '' };

/**
 * Renders the Games admin screen.
 * Lists all chronicles (games) site-wide and provides a form to create,
 * edit, and delete them, independent of any single chronicle's scope.
 */
export function AdminGames() {
	const [ games, setGames ] = useState<Game[]>( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );
	const [ editingSlug, setEditingSlug ] = useState<string | null>( null );
	const [ form, setForm ] = useState( EMPTY_FORM );
	const [ saving, setSaving ] = useState( false );
	const [ creating, setCreating ] = useState( false );
	const [ renameNotice, setRenameNotice ] = useState<string | null>( null );

	/**
	 * Fetches the list of chronicles from the API.
	 * Populates the game list on success and records the error message
	 * on failure, tracking a loading flag throughout.
	 */
	function load() {
		setLoading( true );
		api.games
			.list()
			.then( ( result ) => {
				setGames( result );
				setLoading( false );
			} )
			.catch( ( err: unknown ) => {
				setError( errorMessage( err ) );
				setLoading( false );
			} );
	}

	useEffect( load, [] );

	function startEdit( game: Game ) {
		setEditingSlug( game.slug );
		setForm( {
			name: game.name,
			slug: game.slug,
			game_type: game.game_type,
			description: game.description ?? '',
		} );
		setCreating( false );
		setRenameNotice( null );
	}

	function startCreate() {
		setEditingSlug( null );
		setForm( EMPTY_FORM );
		setCreating( true );
		setRenameNotice( null );
	}

	function cancel() {
		setEditingSlug( null );
		setCreating( false );
		setForm( EMPTY_FORM );
	}

	/**
	 * Creates or updates a chronicle from the current form state.
	 * Validates that a name is present, calls the appropriate create or
	 * update API endpoint, then closes the form and reloads the list.
	 */
	async function save( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! form.name.trim() ) {
			return;
		}
		setSaving( true );
		setError( null );
		setRenameNotice( null );
		try {
			if ( creating ) {
				await api.games.create( {
					name: form.name.trim(),
					slug: form.slug.trim() || undefined,
					game_type: form.game_type,
					description: form.description,
				} );
			} else if ( editingSlug ) {
				const updated = await api.games.update( editingSlug, {
					name: form.name.trim(),
					slug: form.slug.trim(),
					game_type: form.game_type,
					description: form.description,
				} );
				if ( updated.rename_report ) {
					const r = updated.rename_report;
					setRenameNotice(
						sprintf(
							// translators: 1: new slug, 2: character count, 3: schema block count, 4: page count, 5: Elementor widget count.
							__( 'Renamed to "%1$s" - moved %2$d character(s), %3$d schema block fork(s), %4$d page reference(s), %5$d Elementor widget(s).', 'beyond-elysium' ),
							updated.slug,
							r.characters,
							r.schema_blocks,
							r.pages,
							r.elementor
						)
					);
				}
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
	 * Deletes a chronicle after confirmation.
	 * Prompts the viewer to confirm, then calls the API to delete the
	 * chronicle and reloads the list.
	 */
	async function remove( game: Game ) {
		// eslint-disable-next-line no-alert
		if (
			! window.confirm(
				sprintf(
					// translators: %s: game/chronicle name.
					__( 'Delete "%s"? This does not delete its characters, but they become unreachable through this game.', 'beyond-elysium' ),
					game.name
				)
			)
		) {
			return;
		}
		try {
			await api.games.delete( game.slug );
			load();
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		}
	}

	return (
		<div className="be-admin">
			<h1>{ __( 'Games', 'beyond-elysium' ) }</h1>
			{ error && (
				<div className="be-admin__error" role="alert">
					{ error }
				</div>
			) }
			{ renameNotice && (
				<div className="be-admin__game-scope-notice" role="status">
					{ renameNotice }
				</div>
			) }

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : (
				<table className="be-admin__table">
					<thead>
						<tr>
							<th>{ __( 'Name', 'beyond-elysium' ) }</th>
							<th>{ __( 'Slug', 'beyond-elysium' ) }</th>
							<th>{ __( 'Type', 'beyond-elysium' ) }</th>
							<th>{ __( 'Created', 'beyond-elysium' ) }</th>
							<th>{ __( 'Actions', 'beyond-elysium' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ games.length === 0 && (
							<tr>
								<td colSpan={ 5 }>{ __( 'No games yet. Create the first one below.', 'beyond-elysium' ) }</td>
							</tr>
						) }
						{ games.map( ( game ) => (
							<tr key={ game.slug }>
								<td>{ game.name }</td>
								<td>
									<code>{ game.slug }</code>
								</td>
								<td>{ game.game_type }</td>
								<td>{ game.created_at }</td>
								<td>
									<button type="button" onClick={ () => startEdit( game ) }>
										{ __( 'Edit', 'beyond-elysium' ) }
									</button>
									<button type="button" onClick={ () => remove( game ) }>
										{ __( 'Delete', 'beyond-elysium' ) }
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ ! creating && editingSlug === null && (
				<button type="button" onClick={ startCreate }>
					{ __( '+ New Game', 'beyond-elysium' ) }
				</button>
			) }

			{ ( creating || editingSlug !== null ) && (
				<form className="be-admin__form" onSubmit={ save }>
					<h2>{ creating ? __( 'New Game', 'beyond-elysium' ) : sprintf( __( 'Edit %s', 'beyond-elysium' ), editingSlug as string ) }</h2>
					<label>
						{ __( 'Name', 'beyond-elysium' ) }
						<input
							type="text"
							value={ form.name }
							onChange={ ( e ) => setForm( { ...form, name: e.target.value } ) }
							required
						/>
					</label>
					<label>
						{ __( 'Slug', 'beyond-elysium' ) } { creating && __( '(optional - derived from name if left blank)', 'beyond-elysium' ) }
						<input type="text" value={ form.slug } onChange={ ( e ) => setForm( { ...form, slug: e.target.value } ) } />
					</label>
					{ editingSlug !== null && form.slug.trim() !== editingSlug && (
						<p className="be-admin__field-warning">
							{ __(
								'Changing the slug renames this chronicle everywhere it is referenced - every character, any customized schema block, and every page or widget that names it. A slug already used by another chronicle, or one left behind by a deleted chronicle, is rejected before anything moves.',
								'beyond-elysium'
							) }
						</p>
					) }
					<label>
						{ __( 'Game Type', 'beyond-elysium' ) }
						<input
							type="text"
							value={ form.game_type }
							onChange={ ( e ) => setForm( { ...form, game_type: e.target.value } ) }
						/>
					</label>
					<label>
						{ __( 'Description', 'beyond-elysium' ) }
						<textarea
							value={ form.description }
							onChange={ ( e ) => setForm( { ...form, description: e.target.value } ) }
						/>
					</label>
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

export default AdminGames;
