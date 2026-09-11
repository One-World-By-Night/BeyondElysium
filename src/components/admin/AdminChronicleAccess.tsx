/**
 * Admin page for chronicle-scoped access control.
 * Covers chronicle membership and per-member roles, the site-wide
 * accessSchema toggle, each chronicle's accessSchema role path, and
 * per-chronicle notification settings.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type { AuthorizationSettings, Game, GameMember, GameMemberRole } from '../../types';
import type { DataManagementSettings } from '../../api/client';
import type { WpUserSummary } from '../../types/character';
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

const ROLES: GameMemberRole[] = [ 'hst', 'ast', 'narrator', 'player' ];
const SEARCH_DEBOUNCE_MS = 300;

/**
 * Renders the Chronicle Access admin screen.
 * Lists chronicles and their members, and lets staff toggle accessSchema,
 * edit a chronicle's accessSchema role path, toggle its notification
 * setting, change or remove a member's role, and add new members.
 */
export function AdminChronicleAccess() {
	const [ games, setGames ] = useState<Game[]>( [] );
	const [ selectedSlug, setSelectedSlug ] = useState<string>( '' );
	const [ members, setMembers ] = useState<GameMember[]>( [] );
	const [ loadingMembers, setLoadingMembers ] = useState( false );
	const [ error, setError ] = useState<string | null>( null );

	const [ ascSettings, setAscSettings ] = useState<AuthorizationSettings | null>( null );
	const [ ascSaving, setAscSaving ] = useState( false );

	const [ dataSettings, setDataSettings ] = useState<DataManagementSettings | null>( null );
	const [ dataSaving, setDataSaving ] = useState( false );
	const [ exporting, setExporting ] = useState( false );

	const [ roleEditingSlug, setRoleEditingSlug ] = useState( '' );
	const [ ascRolePathDraft, setAscRolePathDraft ] = useState( '' );
	const [ ascRolePathSaving, setAscRolePathSaving ] = useState( false );
	const [ notificationsSaving, setNotificationsSaving ] = useState( false );

	const [ addingMember, setAddingMember ] = useState( false );

	/**
	 * Fetches the list of chronicles from the API.
	 * Populates the chronicle dropdown and, when nothing is selected yet,
	 * defaults the selection to the first chronicle returned.
	 */
	function loadGames() {
		api.games
			.list()
			.then( ( result ) => {
				setGames( result );
				if ( ! selectedSlug && result.length > 0 ) {
					setSelectedSlug( result[ 0 ].slug );
				}
			} )
			.catch( ( err: unknown ) => setError( errorMessage( err ) ) );
	}

	/**
	 * Fetches the membership list for one chronicle.
	 * Does nothing when no chronicle slug is given, and tracks a loading
	 * flag and any error while the request is in flight.
	 */
	function loadMembers( gameSlug: string ) {
		if ( ! gameSlug ) {
			return;
		}
		setLoadingMembers( true );
		api.gameMembers( gameSlug )
			.list()
			.then( ( result ) => {
				setMembers( result );
				setLoadingMembers( false );
			} )
			.catch( ( err: unknown ) => {
				setError( errorMessage( err ) );
				setLoadingMembers( false );
			} );
	}

	useEffect( loadGames, [] );
	useEffect( () => loadMembers( selectedSlug ), [ selectedSlug ] );

	useEffect( () => {
		api.authorizationSettings
			.get()
			.then( setAscSettings )
			.catch( ( err: unknown ) => setError( errorMessage( err ) ) );
		api.dataManagement
			.get()
			.then( setDataSettings )
			.catch( ( err: unknown ) => setError( errorMessage( err ) ) );
	}, [] );

	const selectedGame = games.find( ( g ) => g.slug === selectedSlug ) ?? null;

	/**
	 * Enables or disables the site-wide accessSchema integration.
	 * Sends the new setting to the API and stores the returned settings
	 * object, tracking a saving flag and any error along the way.
	 */
	async function toggleAsc( enabled: boolean ) {
		setAscSaving( true );
		setError( null );
		try {
			const result = await api.authorizationSettings.update( enabled );
			setAscSettings( result );
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		} finally {
			setAscSaving( false );
		}
	}

	/**
	 * Turns the uninstall delete-data behavior on or off, site-wide.
	 * Off (the default) means a plain uninstall keeps every chronicle's
	 * data untouched.
	 */
	async function toggleDeleteOnUninstall( enabled: boolean ) {
		setDataSaving( true );
		setError( null );
		try {
			setDataSettings( await api.dataManagement.update( enabled ) );
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		} finally {
			setDataSaving( false );
		}
	}

	/**
	 * Fetches a full export of every plugin table and hands it to the
	 * browser as a downloaded JSON file, timestamped in its name.
	 */
	async function exportData() {
		setExporting( true );
		setError( null );
		try {
			const data = await api.dataManagement.export();
			const blob = new Blob( [ JSON.stringify( data, null, 2 ) ], { type: 'application/json' } );
			const url = URL.createObjectURL( blob );
			const link = document.createElement( 'a' );
			link.href = url;
			link.download = `beyond-elysium-export-${ data.exported_at.replace( /[^0-9]/g, '' ) }.json`;
			link.click();
			URL.revokeObjectURL( url );
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		} finally {
			setExporting( false );
		}
	}

	function startEditRolePath( game: Game ) {
		setRoleEditingSlug( game.slug );
		setAscRolePathDraft( game.asc_role_path ?? '' );
	}

	/**
	 * Saves the edited accessSchema role path for the chronicle being edited.
	 * Trims the draft value, persists it through the API, closes the edit
	 * form on success, and reloads the chronicle list to reflect the change.
	 */
	async function saveRolePath() {
		setAscRolePathSaving( true );
		setError( null );
		try {
			await api.games.update( roleEditingSlug, { asc_role_path: ascRolePathDraft.trim() } );
			setRoleEditingSlug( '' );
			loadGames();
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		} finally {
			setAscRolePathSaving( false );
		}
	}

	/**
	 * Turns a chronicle's approval/rejection email notifications on or off.
	 * Persists the new setting through the API and reloads the chronicle
	 * list so the change is reflected immediately.
	 */
	async function toggleNotifications( game: Game, enabled: boolean ) {
		setNotificationsSaving( true );
		setError( null );
		try {
			await api.games.update( game.slug, { notifications_enabled: enabled } );
			loadGames();
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		} finally {
			setNotificationsSaving( false );
		}
	}

	/**
	 * Changes one member's role within the selected chronicle.
	 * Persists the new role through the API and reloads the member list
	 * to reflect the change.
	 */
	async function changeRole( wpUserId: number, role: GameMemberRole ) {
		setError( null );
		try {
			await api.gameMembers( selectedSlug ).set( wpUserId, role );
			loadMembers( selectedSlug );
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		}
	}

	/**
	 * Removes one member from the selected chronicle after confirmation.
	 * Prompts the viewer to confirm, then calls the API to drop the
	 * member's chronicle-scoped access and reloads the member list.
	 */
	async function removeMember( wpUserId: number, name: string | null ) {
		// eslint-disable-next-line no-alert
		if (
			! window.confirm(
				sprintf(
					// translators: %s: member's display name, or a generic fallback if unknown.
					__( 'Remove %s from this chronicle? They keep their WordPress account and any characters - only chronicle-scoped access is removed.', 'beyond-elysium' ),
					name ?? __( 'this user', 'beyond-elysium' )
				)
			)
		) {
			return;
		}
		setError( null );
		try {
			await api.gameMembers( selectedSlug ).remove( wpUserId );
			loadMembers( selectedSlug );
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		}
	}

	return (
		<div className="be-admin">
			<h1>{ __( 'Chronicle Access', 'beyond-elysium' ) }</h1>
			{ error && (
				<div className="be-admin__error" role="alert">
					{ error }
				</div>
			) }

			<div className="be-admin__form" style={ { maxWidth: '100%' } }>
				<h2>{ __( 'accessSchema', 'beyond-elysium' ) }</h2>
				<label>
					<input
						type="checkbox"
						checked={ ascSettings?.asc_enabled ?? false }
						disabled={ ! ascSettings || ascSaving }
						onChange={ ( e ) => toggleAsc( e.target.checked ) }
					/>{ ' ' }
					{ __( 'Use accessSchema role paths (falls back to chronicle membership below whenever it denies, is unreachable, or is off)', 'beyond-elysium' ) }
				</label>
				{ ascSettings && (
					<p>
						{ sprintf(
							// translators: %s: "detected" or "NOT detected".
							__( 'Client %s on this install.', 'beyond-elysium' ),
							ascSettings.client_detected
								? __( 'detected', 'beyond-elysium' )
								: __( 'NOT detected', 'beyond-elysium' )
						) }
						{ ascSettings.asc_enabled && ! ascSettings.client_detected && (
							<strong>
								{ ' ' }
								{ __( 'accessSchema is enabled but no client is installed - every chronicle-scoped request is falling through to membership below.', 'beyond-elysium' ) }
							</strong>
						) }
					</p>
				) }
			</div>

			<div className="be-admin__form" style={ { maxWidth: '100%' } }>
				<h2>{ __( 'Data Management', 'beyond-elysium' ) }</h2>
				<label>
					<input
						type="checkbox"
						checked={ dataSettings?.delete_on_uninstall ?? false }
						disabled={ ! dataSettings || dataSaving }
						onChange={ ( e ) => toggleDeleteOnUninstall( e.target.checked ) }
					/>{ ' ' }
					{ __( 'Delete all Beyond Elysium data when the plugin is uninstalled (off by default - deactivating or uninstalling otherwise keeps every chronicle intact)', 'beyond-elysium' ) }
				</label>
				<p>
					<button type="button" disabled={ exporting } onClick={ exportData }>
						{ exporting ? __( 'Exporting…', 'beyond-elysium' ) : __( 'Export all data', 'beyond-elysium' ) }
					</button>{ ' ' }
					{ __( 'Downloads every chronicle, character, and catalog as one JSON file - a manual backup, available any time, not only before an uninstall.', 'beyond-elysium' ) }
				</p>
			</div>

			<div className="be-admin__filters">
				<label>
					{ __( 'Chronicle', 'beyond-elysium' ) }{ ' ' }
					<select value={ selectedSlug } onChange={ ( e ) => setSelectedSlug( e.target.value ) }>
						{ games.map( ( g ) => (
							<option key={ g.slug } value={ g.slug }>
								{ g.name }
							</option>
						) ) }
					</select>
				</label>
			</div>

			{ selectedGame && (
				<div className="be-admin__form" style={ { maxWidth: '100%' } }>
					<h2>{ sprintf( __( "%s's accessSchema path", 'beyond-elysium' ), selectedGame.name ) }</h2>
					{ roleEditingSlug === selectedGame.slug ? (
						<>
							<input
								type="text"
								aria-label={ __( 'accessSchema role path', 'beyond-elysium' ) }
								placeholder={ __( 'Chronicle/KONY', 'beyond-elysium' ) }
								value={ ascRolePathDraft }
								onChange={ ( e ) => setAscRolePathDraft( e.target.value ) }
							/>
							<div className="be-admin__form-actions">
								<button type="button" disabled={ ascRolePathSaving } onClick={ saveRolePath }>
									{ ascRolePathSaving ? __( 'Saving…', 'beyond-elysium' ) : __( 'Save', 'beyond-elysium' ) }
								</button>
								<button type="button" onClick={ () => setRoleEditingSlug( '' ) }>
									{ __( 'Cancel', 'beyond-elysium' ) }
								</button>
							</div>
						</>
					) : (
						<p>
							<code>{ selectedGame.asc_role_path || __( '(not set)', 'beyond-elysium' ) }</code>{ ' ' }
							<button type="button" onClick={ () => startEditRolePath( selectedGame ) }>
								{ __( 'Edit', 'beyond-elysium' ) }
							</button>
						</p>
					) }
				</div>
			) }

			{ selectedGame && (
				<div className="be-admin__form" style={ { maxWidth: '100%' } }>
					<h2>{ sprintf( __( "%s's notifications", 'beyond-elysium' ), selectedGame.name ) }</h2>
					<label>
						<input
							type="checkbox"
							checked={ !! selectedGame.notifications_enabled }
							disabled={ notificationsSaving }
							onChange={ ( e ) => toggleNotifications( selectedGame, e.target.checked ) }
						/>{ ' ' }
						{ __( 'Email a player when their submitted change is approved or rejected', 'beyond-elysium' ) }
					</label>
				</div>
			) }

			<h2>{ __( 'Members', 'beyond-elysium' ) }</h2>
			{ loadingMembers ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : (
				<table className="be-admin__table">
					<thead>
						<tr>
							<th>{ __( 'Name', 'beyond-elysium' ) }</th>
							<th>{ __( 'Email', 'beyond-elysium' ) }</th>
							<th>{ __( 'Role', 'beyond-elysium' ) }</th>
							<th>{ __( 'Actions', 'beyond-elysium' ) }</th>
						</tr>
					</thead>
					<tbody>
						{ members.length === 0 && (
							<tr>
								<td colSpan={ 4 }>{ __( 'No members yet.', 'beyond-elysium' ) }</td>
							</tr>
						) }
						{ members.map( ( member ) => (
							<tr key={ member.wp_user_id }>
								<td>{ member.name ?? sprintf( __( 'User #%d', 'beyond-elysium' ), member.wp_user_id ) }</td>
								<td>{ member.user_email }</td>
								<td>
									<select
										value={ member.role }
										onChange={ ( e ) => changeRole( member.wp_user_id, e.target.value as GameMemberRole ) }
									>
										{ ROLES.map( ( r ) => (
											<option key={ r } value={ r }>
												{ r }
											</option>
										) ) }
									</select>
								</td>
								<td>
									<button type="button" onClick={ () => removeMember( member.wp_user_id, member.name ) }>
										{ __( 'Remove', 'beyond-elysium' ) }
									</button>
								</td>
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			{ ! addingMember ? (
				<button type="button" disabled={ ! selectedSlug } onClick={ () => setAddingMember( true ) }>
					{ __( '+ Add Member', 'beyond-elysium' ) }
				</button>
			) : (
				<AddMemberForm
					onClose={ () => setAddingMember( false ) }
					onAdded={ () => {
						setAddingMember( false );
						loadMembers( selectedSlug );
					} }
					onError={ ( message ) => setError( message ) }
					gameSlug={ selectedSlug }
				/>
			) }
		</div>
	);
}

/**
 * Form for searching WordPress users and adding one to a chronicle.
 * Debounces a search query against the user search API, lets the viewer
 * pick a role, and adds the selected user to the chronicle at that role.
 */
function AddMemberForm( {
	gameSlug,
	onClose,
	onAdded,
	onError,
}: {
	gameSlug: string;
	onClose: () => void;
	onAdded: () => void;
	onError: ( message: string ) => void;
} ) {
	const [ search, setSearch ] = useState( '' );
	const [ users, setUsers ] = useState<WpUserSummary[]>( [] );
	const [ loading, setLoading ] = useState( true );
	const [ role, setRole ] = useState<GameMemberRole>( 'player' );
	const [ submitting, setSubmitting ] = useState( false );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		const timer = setTimeout( () => {
			api.wpUsers
				.search( search )
				.then( ( result ) => {
					if ( ! cancelled ) {
						setUsers( result );
						setLoading( false );
					}
				} )
				.catch( () => {
					if ( ! cancelled ) {
						setLoading( false );
					}
				} );
		}, SEARCH_DEBOUNCE_MS );
		return () => {
			cancelled = true;
			clearTimeout( timer );
		};
	}, [ search ] );

	/**
	 * Adds the given WordPress user to the chronicle at the selected role.
	 * Calls the API and notifies the parent on success, or reports the
	 * error and re-enables the form on failure.
	 */
	async function add( wpUserId: number ) {
		setSubmitting( true );
		try {
			await api.gameMembers( gameSlug ).set( wpUserId, role );
			onAdded();
		} catch ( err: unknown ) {
			onError( errorMessage( err ) );
			setSubmitting( false );
		}
	}

	return (
		<div className="be-admin__form" style={ { maxWidth: '100%' } }>
			<h2>{ __( 'Add member', 'beyond-elysium' ) }</h2>
			<label>
				{ __( 'Role', 'beyond-elysium' ) }{ ' ' }
				<select value={ role } onChange={ ( e ) => setRole( e.target.value as GameMemberRole ) }>
					{ ROLES.map( ( r ) => (
						<option key={ r } value={ r }>
							{ r }
						</option>
					) ) }
				</select>
			</label>
			<input
				type="search"
				aria-label={ __( 'Search by name or email…', 'beyond-elysium' ) }
				placeholder={ __( 'Search by name or email…', 'beyond-elysium' ) }
				value={ search }
				onChange={ ( e ) => setSearch( e.target.value ) }
				autoFocus
			/>
			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : users.length === 0 ? (
				<p>{ __( 'No users found.', 'beyond-elysium' ) }</p>
			) : (
				<ul>
					{ users.map( ( user ) => (
						<li key={ user.id }>
							{ user.display_name } ({ user.email }){ ' ' }
							<button type="button" disabled={ submitting } onClick={ () => add( user.id ) }>
								{ sprintf( __( 'Add as %s', 'beyond-elysium' ), role ) }
							</button>
						</li>
					) ) }
				</ul>
			) }
			<div className="be-admin__form-actions">
				<button type="button" onClick={ onClose }>
					{ __( 'Cancel', 'beyond-elysium' ) }
				</button>
			</div>
		</div>
	);
}

export default AdminChronicleAccess;
