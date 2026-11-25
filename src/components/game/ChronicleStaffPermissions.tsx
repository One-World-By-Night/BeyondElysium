/**
 * Players tab: Staff permissions. An HST gives an AST or a Narrator any of the five areas their role leaves out, one
 * person at a time, and sees who changed what.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { errorMessage } from '../../lib/errorMessage';
import {
	STAFF_AREAS,
	areaLabel,
	areaNote,
	areaTickable,
	logLine,
	roleLabel,
	sameAreas,
	toggledAreas,
} from '../../lib/staffGrants';
import type { WpUserSummary } from '../../types/character';
import type {
	StaffGrantArea,
	StaffGrantsResponse,
} from '../../types/staffGrants';
import './ChronicleStaffPermissions.css';

const SEARCH_DEBOUNCE_MS = 300;

interface PersonBoxesProps {
	name: string;
	chronicleRole: string | null;
	showRole: boolean;
	eligible: boolean;
	held: StaffGrantArea[];
	busy: boolean;
	onSave: ( areas: StaffGrantArea[] ) => void;
	onRemoveAll?: () => void;
}

/**
 * One person's name, their role here and the five boxes, with Save.
 */
function PersonBoxes( {
	name,
	chronicleRole,
	showRole,
	eligible,
	held,
	busy,
	onSave,
	onRemoveAll,
}: PersonBoxesProps ) {
	const [ draft, setDraft ] = useState< StaffGrantArea[] >( held );
	const heldKey = held.join( ',' );

	useEffect( () => {
		setDraft( held );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ heldKey ] );

	return (
		<li className="be-staff-permissions__person">
			<div className="be-staff-permissions__who">
				<strong>{ name }</strong>
				{ showRole && (
					<span className="be-staff-permissions__role">
						{ roleLabel( chronicleRole ) }
					</span>
				) }
			</div>
			{ ! eligible && (
				<p className="be-staff-permissions__hint">
					{ __(
						'Not an AST or Narrator here now, so these do nothing.',
						'beyond-elysium'
					) }
				</p>
			) }
			<ul className="be-staff-permissions__areas">
				{ STAFF_AREAS.map( ( info ) => (
					<li key={ info.area }>
						<label className="be-staff-permissions__area">
							<input
								type="checkbox"
								checked={ draft.includes( info.area ) }
								disabled={
									busy ||
									! areaTickable(
										info.area,
										chronicleRole,
										held
									)
								}
								onChange={ () =>
									setDraft( toggledAreas( draft, info.area ) )
								}
							/>
							<span>{ areaLabel( info.area ) }</span>
						</label>
						<span className="be-staff-permissions__note">
							{ areaNote( info.place ) }
						</span>
					</li>
				) ) }
			</ul>
			<div className="be-staff-permissions__actions">
				<button
					type="button"
					className="be-staff-permissions__button"
					disabled={ busy || sameAreas( draft, held ) }
					onClick={ () => onSave( draft ) }
				>
					{ __( 'Save', 'beyond-elysium' ) }
				</button>
				{ onRemoveAll && held.length > 0 && (
					<button
						type="button"
						className="be-staff-permissions__button be-staff-permissions__button--remove"
						disabled={ busy }
						onClick={ onRemoveAll }
					>
						{ __( 'Remove all', 'beyond-elysium' ) }
					</button>
				) }
			</div>
		</li>
	);
}

export interface ChronicleStaffPermissionsProps {
	gameSlug: string;
}

/**
 * The Staff permissions section: the people holding more than their role, a way to give someone more, and the log.
 */
export function ChronicleStaffPermissions( {
	gameSlug,
}: ChronicleStaffPermissionsProps ) {
	const [ data, setData ] = useState< StaffGrantsResponse | null >( null );
	const [ error, setError ] = useState< string | null >( null );
	const [ notice, setNotice ] = useState< string | null >( null );
	const [ busy, setBusy ] = useState( false );
	const [ search, setSearch ] = useState( '' );
	const [ found, setFound ] = useState< WpUserSummary[] >( [] );
	const [ chosen, setChosen ] = useState< WpUserSummary | null >( null );

	function load() {
		api.staffGrants( gameSlug )
			.list()
			.then( ( loaded ) => {
				setData( loaded );
				setError( null );
			} )
			.catch( ( err ) =>
				setError(
					errorMessage(
						err,
						__(
							'Failed to load the staff permissions.',
							'beyond-elysium'
						)
					)
				)
			);
	}

	useEffect( load, [ gameSlug ] );

	useEffect( () => {
		const term = search.trim();
		if ( term.length < 3 ) {
			setFound( [] );
			return;
		}
		const timer = window.setTimeout( () => {
			api.wpUsers
				.searchForChronicle( gameSlug, term )
				.then( ( users ) => setFound( users ) )
				.catch( () => setFound( [] ) );
		}, SEARCH_DEBOUNCE_MS );
		return () => window.clearTimeout( timer );
	}, [ search, gameSlug ] );

	function save( wpUserId: number, name: string, areas: StaffGrantArea[] ) {
		setBusy( true );
		setNotice( null );
		api.staffGrants( gameSlug )
			.set( wpUserId, areas )
			.then( () => {
				setNotice(
					sprintf(
						/* translators: %s: a member's name */
						__( 'Saved for %s.', 'beyond-elysium' ),
						name
					)
				);
				setError( null );
				setChosen( null );
				setSearch( '' );
				load();
			} )
			.catch( ( err ) =>
				setError(
					errorMessage(
						err,
						__(
							'Failed to save the staff permissions.',
							'beyond-elysium'
						)
					)
				)
			)
			.finally( () => setBusy( false ) );
	}

	const holders = new Set( data?.people.map( ( p ) => p.wp_user_id ) );
	const candidates = found.filter( ( user ) => ! holders.has( user.id ) );

	return (
		<section className="be-staff-permissions">
			<h3>{ __( 'Staff permissions', 'beyond-elysium' ) }</h3>
			<p className="be-staff-permissions__hint">
				{ __(
					'Give an AST or a Narrator more than their role holds, one person at a time. Only an HST gives or takes these away, and every change is recorded.',
					'beyond-elysium'
				) }
			</p>
			{ error && (
				<p className="be-staff-permissions__error" role="alert">
					{ error }
				</p>
			) }
			{ notice && (
				<p className="be-staff-permissions__notice" role="status">
					{ notice }
				</p>
			) }

			{ data && data.people.length === 0 && (
				<p className="be-staff-permissions__hint">
					{ __(
						'Nobody has been given more than their role yet.',
						'beyond-elysium'
					) }
				</p>
			) }
			{ data && data.people.length > 0 && (
				<ul className="be-staff-permissions__people">
					{ data.people.map( ( person ) => (
						<PersonBoxes
							key={ person.wp_user_id }
							name={ person.display_name }
							chronicleRole={ person.role }
							showRole
							eligible={ person.eligible }
							held={ person.areas.map( ( a ) => a.area ) }
							busy={ busy }
							onSave={ ( areas ) =>
								save(
									person.wp_user_id,
									person.display_name,
									areas
								)
							}
							onRemoveAll={ () =>
								save(
									person.wp_user_id,
									person.display_name,
									[]
								)
							}
						/>
					) ) }
				</ul>
			) }

			<h4>{ __( 'Give someone more', 'beyond-elysium' ) }</h4>
			{ ! chosen && (
				<div className="be-staff-permissions__search">
					<label>
						<span>
							{ __(
								'Find an account by name',
								'beyond-elysium'
							) }
						</span>
						<input
							type="search"
							className="be-staff-permissions__input"
							value={ search }
							onChange={ ( event ) =>
								setSearch( event.target.value )
							}
						/>
					</label>
					{ candidates.length > 0 && (
						<ul className="be-staff-permissions__found">
							{ candidates.map( ( user ) => (
								<li key={ user.id }>
									<button
										type="button"
										className="be-staff-permissions__button"
										onClick={ () => setChosen( user ) }
									>
										{ user.display_name }
									</button>
								</li>
							) ) }
						</ul>
					) }
				</div>
			) }
			{ chosen && (
				<ul className="be-staff-permissions__people">
					<PersonBoxes
						name={ chosen.display_name }
						chronicleRole={ null }
						showRole={ false }
						eligible
						held={ [] }
						busy={ busy }
						onSave={ ( areas ) =>
							save( chosen.id, chosen.display_name, areas )
						}
					/>
					<li>
						<button
							type="button"
							className="be-staff-permissions__button"
							onClick={ () => setChosen( null ) }
						>
							{ __( 'Choose someone else', 'beyond-elysium' ) }
						</button>
					</li>
				</ul>
			) }

			{ data && data.log.length > 0 && (
				<>
					<h4>{ __( 'Recent changes', 'beyond-elysium' ) }</h4>
					<ul className="be-staff-permissions__log">
						{ data.log.map( ( entry, index ) => (
							<li key={ index }>{ logLine( entry ) }</li>
						) ) }
					</ul>
				</>
			) }
		</section>
	);
}
