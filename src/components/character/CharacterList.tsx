/**
 * CharacterList renders the character roster: a sortable, filterable, paginated
 * table with per-row player assignment and delete controls for managers. Also
 * exports AssignPlayerModal, the modal used to link or unlink a character's
 * WordPress account.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type { Character, CharacterCollectionParams, WpUserSummary } from '../../types/character';
import type { CreatureStack } from '../../types';
import Modal from '../shared/Modal';
import './CharacterList.css';

export interface CharacterListProps {
	gameSlug: string;
	stackSlug?: string;
	status?: string;
	showNpcs?: boolean;
	/** Base URL of the page that renders CharacterSheet; with none given, names render as plain text. */
	sheetPageUrl?: string;
	perPage?: number;
}

type SortableColumn = 'name' | 'stack_slug' | 'status' | 'xp_earned' | 'xp_unspent' | 'player_name';

const COLUMNS: Array<{ key: SortableColumn; label: string }> = [
	{ key: 'name', label: __( 'Name', 'beyond-elysium' ) },
	{ key: 'stack_slug', label: __( 'Type', 'beyond-elysium' ) },
	{ key: 'status', label: __( 'Status', 'beyond-elysium' ) },
	{ key: 'xp_earned', label: __( 'XP Earned', 'beyond-elysium' ) },
	{ key: 'xp_unspent', label: __( 'XP Unspent', 'beyond-elysium' ) },
	{ key: 'player_name', label: __( 'Player', 'beyond-elysium' ) },
];

// 'pending' is only ever assigned server-side, when a game requires approval for new characters.
const STATUS_OPTIONS = [ 'active', 'inactive', 'retired', 'dead', 'pending' ];

const SEARCH_DEBOUNCE_MS = 300;

function sheetLink( sheetPageUrl: string | undefined, characterId: number, gameSlug: string ): string | null {
	if ( ! sheetPageUrl ) {
		return null;
	}
	const separator = sheetPageUrl.includes( '?' ) ? '&' : '?';
	return `${ sheetPageUrl }${ separator }character_id=${ characterId }&game_slug=${ encodeURIComponent( gameSlug ) }`;
}

/**
 * Character roster: sortable, filterable, and paginated, with search-by-name and
 * filters for creature type and status. All sorting, filtering, and paging happens
 * server-side against the current query, never client-side over a single fetched page.
 */
export function CharacterList( {
	gameSlug,
	stackSlug,
	status,
	showNpcs,
	sheetPageUrl,
	perPage = 20,
}: CharacterListProps ) {
	const [ items, setItems ] = useState<Character[]>( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ page, setPage ] = useState( 1 );
	const [ orderby, setOrderby ] = useState<SortableColumn>( 'name' );
	const [ order, setOrder ] = useState<'ASC' | 'DESC'>( 'ASC' );
	const [ stackFilter, setStackFilter ] = useState( stackSlug ?? '' );
	const [ statusFilter, setStatusFilter ] = useState( status ?? '' );
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ stacks, setStacks ] = useState<CreatureStack[]>( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );
	const [ refreshCount, setRefreshCount ] = useState( 0 );
	const [ assigning, setAssigning ] = useState<Character | null>( null );

	// UI-only gate; the DELETE route itself re-checks be_manage_characters server-side.
	const canManageCharacters = window.beyondElysium?.capabilities?.be_manage_characters ?? false;

	// Debounce the search box; the request only fires 300ms after typing stops.
	useEffect( () => {
		const timer = setTimeout( () => setSearch( searchInput ), SEARCH_DEBOUNCE_MS );
		return () => clearTimeout( timer );
	}, [ searchInput ] );

	useEffect( () => {
		api.creatureStacks
			.list()
			.then( setStacks )
			.catch( () => {
				setStacks( [] );
				setError( __( 'Failed to load character types for the filter list.', 'beyond-elysium' ) );
			} );
	}, [] );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );

		const params: CharacterCollectionParams = {
			page,
			per_page: perPage,
			orderby,
			order,
			stack_slug: stackFilter || undefined,
			status: statusFilter || undefined,
			search: search || undefined,
			// The server forces is_npc=0 for anyone without be_manage_characters regardless of this value.
			is_npc: showNpcs ? true : undefined,
		};

		api.characters( gameSlug )
			.listPaginated( params )
			.then( ( result ) => {
				if ( cancelled ) {
					return;
				}
				setError( null );
				setItems( result.items );
				setTotal( result.total );
				setTotalPages( Math.max( 1, result.totalPages ) );
			} )
			.catch( () => {
				// Shows an explicit error instead of silently rendering an empty roster.
				if ( ! cancelled ) {
					setError( __( 'Failed to load characters. Try refreshing the page.', 'beyond-elysium' ) );
				}
			} )
			.finally( () => {
				if ( ! cancelled ) {
					setLoading( false );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ gameSlug, page, perPage, orderby, order, stackFilter, statusFilter, search, showNpcs, refreshCount ] );

	function toggleSort( column: SortableColumn ): void {
		if ( column === orderby ) {
			setOrder( order === 'ASC' ? 'DESC' : 'ASC' );
		} else {
			setOrderby( column );
			setOrder( 'ASC' );
		}
		setPage( 1 );
	}

	async function remove( character: Character ): Promise<void> {
		// eslint-disable-next-line no-alert
		if (
			! window.confirm(
				sprintf(
					__(
						'Delete "%s"? This permanently removes the character, its change history, snapshots, sheet style, and connections. This cannot be undone.',
						'beyond-elysium'
					),
					character.name
				)
			)
		) {
			return;
		}
		try {
			await api.characters( gameSlug ).delete( character.id );
			setRefreshCount( ( n ) => n + 1 );
		} catch ( err: unknown ) {
			setError( err instanceof Error ? err.message : __( 'Failed to delete the character.', 'beyond-elysium' ) );
		}
	}

	return (
		<div className="be-character-list">
			{ error && (
				<p className="be-character-list__error" role="alert">
					{ error }
				</p>
			) }

			<div className="be-character-list__filters">
				<select
					value={ stackFilter }
					onChange={ ( e ) => {
						setStackFilter( e.target.value );
						setPage( 1 );
					} }
				>
					<option value="">{ __( 'All types', 'beyond-elysium' ) }</option>
					{ stacks.map( ( stack ) => (
						<option value={ stack.slug } key={ stack.slug }>
							{ stack.name }
						</option>
					) ) }
				</select>

				<select
					value={ statusFilter }
					onChange={ ( e ) => {
						setStatusFilter( e.target.value );
						setPage( 1 );
					} }
				>
					<option value="">{ __( 'All statuses', 'beyond-elysium' ) }</option>
					{ STATUS_OPTIONS.map( ( option ) => (
						<option value={ option } key={ option }>
							{ option }
						</option>
					) ) }
				</select>

				<input
					type="search"
					placeholder={ __( 'Search by name…', 'beyond-elysium' ) }
					value={ searchInput }
					onChange={ ( e ) => {
						setSearchInput( e.target.value );
						setPage( 1 );
					} }
				/>
			</div>

			<table className="be-character-list__table">
				<thead>
					<tr>
						{ /* Not part of COLUMNS - a thumbnail column has no orderby key to sort by. */ }
						<th aria-label={ __( 'Portrait', 'beyond-elysium' ) } />
						{ COLUMNS.map( ( column ) => (
							<th key={ column.key }>
								<button
									type="button"
									className="be-character-list__sort-button"
									onClick={ () => toggleSort( column.key ) }
								>
									{ column.label }
									{ orderby === column.key ? ( order === 'ASC' ? ' ▲' : ' ▼' ) : '' }
								</button>
							</th>
						) ) }
						{ canManageCharacters && (
							<th className="be-character-list__actions-cell" aria-label={ __( 'Actions', 'beyond-elysium' ) } />
						) }
					</tr>
				</thead>
				<tbody>
					{ ! loading && ! error && items.length === 0 && (
						<tr>
							<td colSpan={ COLUMNS.length + 1 + ( canManageCharacters ? 1 : 0 ) }>{ __( 'No characters found.', 'beyond-elysium' ) }</td>
						</tr>
					) }
					{ items.map( ( character ) => {
						const link = sheetLink( sheetPageUrl, character.id, gameSlug );
						return (
							<tr key={ character.id }>
								<td>
									{ character.image_url && (
										<img
											className="be-character-list__thumb"
											src={ character.image_url }
											alt=""
										/>
									) }
								</td>
								<td>
									{ link ? <a href={ link }>{ character.name }</a> : character.name }
									{ character.travelling_status && (
										<span
											className={ `be-st-badge be-st-badge--${ character.travelling_status.direction === 'outbound' ? 'travelling' : 'visiting' }` }
											title={ sprintf(
												/* translators: %s: the other chronicle's name */
												character.travelling_status.direction === 'outbound'
													? __( 'Travelling - %s', 'beyond-elysium' )
													: __( 'Visiting from %s', 'beyond-elysium' ),
												character.travelling_status.chronicle ?? __( 'no host confirmed yet', 'beyond-elysium' )
											) }
										>
											{ character.travelling_status.direction === 'outbound' ? __( 'Travelling', 'beyond-elysium' ) : __( 'Visiting', 'beyond-elysium' ) }
										</span>
									) }
								</td>
								<td>{ character.stack_slug }</td>
								<td>{ character.status }</td>
								<td>{ character.xp_earned }</td>
								<td>{ character.xp_unspent }</td>
								<td>
									{ character.player_name ?? '—' }
									{ /* wp_user_id is the real account link; player_name is only ever a free-text label. */ }
									{ character.wp_user_id == null && (
										<span className="be-character-list__unassigned">{ __( ' (unassigned)', 'beyond-elysium' ) }</span>
									) }
									{ /* Confirms a pending player match without opening the assign-player modal. */ }
									{ character.pending_match && (
										<div className="be-character-list__pending-match">
											{ sprintf( __( '%s now has an account -', 'beyond-elysium' ), character.pending_match.display_name ) }
											{ ' ' }
											<button
												type="button"
												onClick={ () =>
													api
														.characters( character.owner_slug )
														.update( character.id, { wp_user_id: character.pending_match!.id } )
														.then( () => setRefreshCount( ( n ) => n + 1 ) )
												}
											>
												{ __( 'Confirm', 'beyond-elysium' ) }
											</button>
										</div>
									) }
								</td>
								{ canManageCharacters && (
									<td className="be-character-list__actions-cell">
										<button
											type="button"
											className="be-character-list__assign"
											onClick={ () => setAssigning( character ) }
										>
											{ character.wp_user_id == null
												? __( 'Assign player', 'beyond-elysium' )
												: __( 'Change player', 'beyond-elysium' ) }
										</button>
										<button
											type="button"
											className="be-character-list__delete"
											onClick={ () => remove( character ) }
										>
											{ __( 'Delete', 'beyond-elysium' ) }
										</button>
									</td>
								) }
							</tr>
						);
					} ) }
				</tbody>
			</table>

			<div className="be-character-list__pagination">
				<button type="button" disabled={ page <= 1 } onClick={ () => setPage( page - 1 ) }>
					{ __( 'Previous', 'beyond-elysium' ) }
				</button>
				<span>
					{ sprintf( __( 'Page %1$d of %2$d (%3$d total)', 'beyond-elysium' ), page, totalPages, total ) }
				</span>
				<button type="button" disabled={ page >= totalPages } onClick={ () => setPage( page + 1 ) }>
					{ __( 'Next', 'beyond-elysium' ) }
				</button>
			</div>

			{ assigning && (
				<AssignPlayerModal
					character={ assigning }
					onClose={ () => setAssigning( null ) }
					onAssigned={ () => {
						setAssigning( null );
						setRefreshCount( ( n ) => n + 1 );
					} }
				/>
			) }
		</div>
	);
}

/**
 * Modal for assigning or changing a character's linked WordPress user account.
 * Searches users by name or email as the query changes, and offers unassigning
 * the current player. When no account exists yet, records a pending email so the
 * character can later be confirmed once that person registers.
 */
function AssignPlayerModal( {
	character,
	onClose,
	onAssigned,
}: {
	character: Character;
	onClose: () => void;
	onAssigned: () => void;
} ) {
	const [ search, setSearch ] = useState( '' );
	const [ users, setUsers ] = useState<WpUserSummary[]>( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );
	const [ submitting, setSubmitting ] = useState( false );
	// Recorded so a character can later be matched once this email registers an account.
	const [ pendingEmail, setPendingEmail ] = useState( character.pending_player_email ?? '' );
	const [ pendingSaved, setPendingSaved ] = useState( false );

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
						setError( __( 'Failed to load users.', 'beyond-elysium' ) );
						setLoading( false );
					}
				} );
		}, SEARCH_DEBOUNCE_MS );
		return () => {
			cancelled = true;
			clearTimeout( timer );
		};
	}, [ search ] );

	async function assign( userId: number | null ): Promise<void> {
		setSubmitting( true );
		setError( null );
		try {
			await api.characters( character.owner_slug ).update( character.id, { wp_user_id: userId } );
			onAssigned();
		} catch {
			setError( __( 'Failed to update this character.', 'beyond-elysium' ) );
			setSubmitting( false );
		}
	}

	async function savePendingEmail(): Promise<void> {
		setSubmitting( true );
		setError( null );
		setPendingSaved( false );
		try {
			await api.characters( character.owner_slug ).update( character.id, { pending_player_email: pendingEmail.trim() } );
			setPendingSaved( true );
		} catch {
			setError( __( 'Failed to save the pending email - check it is a valid address.', 'beyond-elysium' ) );
		} finally {
			setSubmitting( false );
		}
	}

	return (
		<Modal title={ sprintf( __( 'Assign a player - %s', 'beyond-elysium' ), character.name ) } onClose={ onClose }>
			<input
				type="search"
				placeholder={ __( 'Search by name or email…', 'beyond-elysium' ) }
				value={ search }
				onChange={ ( e ) => setSearch( e.target.value ) }
				autoFocus
			/>

			{ error && (
				<p className="be-character-list__error" role="alert">
					{ error }
				</p>
			) }

			{ character.wp_user_id != null && (
				<p>
					<button type="button" disabled={ submitting } onClick={ () => assign( null ) }>
						{ __( 'Unassign current player', 'beyond-elysium' ) }
					</button>
				</p>
			) }

			{ character.wp_user_id == null && (
				<p className="be-character-list__pending-email">
					{ __(
						"No account to connect yet? Record their email - once they register, you'll see a confirm button here instead of guessing.",
						'beyond-elysium'
					) }
					<br />
					<input
						type="email"
						placeholder={ __( 'their-email@example.com', 'beyond-elysium' ) }
						value={ pendingEmail }
						onChange={ ( e ) => {
							setPendingEmail( e.target.value );
							setPendingSaved( false );
						} }
					/>
					<button type="button" disabled={ submitting } onClick={ savePendingEmail }>
						{ __( 'Save', 'beyond-elysium' ) }
					</button>
					{ pendingSaved && <span>{ __( ' Saved.', 'beyond-elysium' ) }</span> }
				</p>
			) }

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : users.length === 0 ? (
				<p>{ __( 'No users found.', 'beyond-elysium' ) }</p>
			) : (
				<ul className="be-character-list__user-results">
					{ users.map( ( user ) => (
						<li key={ user.id }>
							<span>
								{ user.display_name } <span className="be-st-badge">{ user.email }</span>
							</span>
							<button type="button" disabled={ submitting } onClick={ () => assign( user.id ) }>
								{ user.id === character.wp_user_id ? __( 'Current', 'beyond-elysium' ) : __( 'Assign', 'beyond-elysium' ) }
							</button>
						</li>
					) ) }
				</ul>
			) }
		</Modal>
	);
}

export default CharacterList;
