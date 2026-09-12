/**
 * Admin page for a chronicle's Action & Rumor configuration -
 * Grapevine's own frmGameInfo.frm screen. Two tabs: the five
 * action-allocation knobs (personal actions, carry-forward,
 * common actions, the actions-per-level table, and which
 * backgrounds grant an action), and the eight rumor-generation
 * toggles.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import type { AprBackgroundOption, AprSettings } from '../../types/apr';
import type { Game } from '../../types';
import './Admin.css';
import './AdminAprSettings.css';

interface RestError {
	message?: string;
}

function errorMessage( error: unknown ): string {
	if ( typeof error === 'object' && error !== null && ( error as RestError ).message ) {
		return ( error as RestError ).message as string;
	}
	return __( 'Something went wrong.', 'beyond-elysium' );
}

/** Grapevine's real defaults (APREngineClass.cls:69-84), offered only via Restore Grapevine defaults. */
const GV_DEFAULTS: AprSettings = {
	personal_actions: 0,
	carry_unused: false,
	add_common: false,
	background_actions: [ 'Contacts', 'Resources' ],
	actions_per_level: { '1': 2, '2': 4, '3': 6, '4': 8, '5': 10, '6': 12, '7': 14, '8': 16, '9': 18, '10': 20 },
	public_rumors: true,
	personal_rumors: false,
	race_rumors: false,
	group_rumors: false,
	subgroup_rumors: false,
	influence_rumors: true,
	previous_rumors: true,
	copy_previous: false,
};

type Tab = 'actions' | 'rumors';

/**
 * Renders the Action & Rumor Settings admin screen: a chronicle
 * picker, an Actions tab (personal actions, carry/common toggles,
 * the actions-per-level table, the background_actions picker) and
 * a Rumors tab (the eight generation toggles), plus a one-click
 * restore of Grapevine's own original defaults.
 */
export function AdminAprSettings() {
	const [ games, setGames ] = useState<Game[]>( [] );
	const [ gameSlug, setGameSlug ] = useState( '' );
	const [ settings, setSettings ] = useState<AprSettings | null>( null );
	const [ options, setOptions ] = useState<AprBackgroundOption[]>( [] );
	const [ tab, setTab ] = useState<Tab>( 'actions' );
	const [ loading, setLoading ] = useState( false );
	const [ saving, setSaving ] = useState( false );
	const [ error, setError ] = useState<string | null>( null );
	const [ savedNotice, setSavedNotice ] = useState( false );

	useEffect( () => {
		api.games
			.list()
			.then( ( found ) => {
				setGames( found );
				if ( ! gameSlug && found.length > 0 ) {
					setGameSlug( found[ 0 ].slug );
				}
			} )
			.catch( ( err: unknown ) => setError( errorMessage( err ) ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	useEffect( () => {
		if ( ! gameSlug ) {
			return;
		}
		setLoading( true );
		Promise.all( [ api.apr( gameSlug ).getSettings(), api.apr( gameSlug ).backgroundOptions() ] )
			.then( ( [ found, opts ] ) => {
				setSettings( found );
				setOptions( opts );
				setError( null );
			} )
			.catch( ( err: unknown ) => setError( errorMessage( err ) ) )
			.finally( () => setLoading( false ) );
	}, [ gameSlug ] );

	/** Saves the whole current settings object, so any tab's edits (already applied to local state) persist together. */
	async function save() {
		if ( ! settings ) {
			return;
		}
		setSaving( true );
		setError( null );
		setSavedNotice( false );
		try {
			const updated = await api.apr( gameSlug ).updateSettings( settings );
			setSettings( updated );
			setSavedNotice( true );
		} catch ( err ) {
			setError( errorMessage( err ) );
		} finally {
			setSaving( false );
		}
	}

	function restoreGrapevineDefaults() {
		// eslint-disable-next-line no-alert
		if (
			! window.confirm(
				__(
					'Restore Grapevine\'s original defaults? This replaces personal actions, carry-forward, common actions, the actions-per-level table, and the background list with Grapevine\'s own 1998 values - not what Beyond Elysium normally ships with. Rumor toggles are unaffected.',
					'beyond-elysium'
				)
			)
		) {
			return;
		}
		setSettings( ( prev ) => ( prev ? { ...prev, ...GV_DEFAULTS, ...rumorTogglesOf( prev ) } : prev ) );
	}

	function rumorTogglesOf( current: AprSettings ) {
		return {
			public_rumors: current.public_rumors,
			personal_rumors: current.personal_rumors,
			race_rumors: current.race_rumors,
			group_rumors: current.group_rumors,
			subgroup_rumors: current.subgroup_rumors,
			influence_rumors: current.influence_rumors,
			previous_rumors: current.previous_rumors,
			copy_previous: current.copy_previous,
		};
	}

	function toggleBackgroundAction( name: string, checked: boolean ) {
		setSettings( ( prev ) => {
			if ( ! prev ) {
				return prev;
			}
			const next = checked
				? [ ...prev.background_actions, name ]
				: prev.background_actions.filter( ( n ) => n !== name );
			return { ...prev, background_actions: next };
		} );
	}

	function setLevel( level: string, value: number ) {
		setSettings( ( prev ) => prev && { ...prev, actions_per_level: { ...prev.actions_per_level, [ level ]: value } } );
	}

	function addLevel() {
		const level = window.prompt( __( 'Which level (1-20)?', 'beyond-elysium' ) );
		if ( ! level ) {
			return;
		}
		const n = Number( level );
		if ( ! Number.isInteger( n ) || n < 1 || n > 20 ) {
			return;
		}
		setLevel( String( n ), 0 );
	}

	function removeLevel( level: string ) {
		setSettings( ( prev ) => {
			if ( ! prev ) {
				return prev;
			}
			const next = { ...prev.actions_per_level };
			delete next[ level ];
			return { ...prev, actions_per_level: next };
		} );
	}

	if ( ! settings && loading ) {
		return (
			<div className="be-admin">
				<h1>{ __( 'Action & Rumor Settings', 'beyond-elysium' ) }</h1>
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			</div>
		);
	}

	return (
		<div className="be-admin be-apr-settings">
			<h1>{ __( 'Action & Rumor Settings', 'beyond-elysium' ) }</h1>
			<p>
				{ __(
					'Decides how many downtime actions a character receives each game date, and which rumors are generated automatically. Every value shown is Beyond Elysium\'s own default unless this chronicle has changed it.',
					'beyond-elysium'
				) }
			</p>

			{ error && <p className="be-admin__error" role="alert">{ error }</p> }
			{ savedNotice && <p className="be-apr-settings__saved" role="status">{ __( 'Saved.', 'beyond-elysium' ) }</p> }

			<label>
				{ __( 'Chronicle', 'beyond-elysium' ) }
				<select value={ gameSlug } onChange={ ( e ) => setGameSlug( e.target.value ) }>
					{ games.map( ( g ) => (
						<option key={ g.slug } value={ g.slug }>{ g.name }</option>
					) ) }
				</select>
			</label>

			{ settings && (
				<>
					<div className="be-apr-settings__tabs" role="tablist">
						<button type="button" role="tab" aria-selected={ tab === 'actions' } className={ tab === 'actions' ? 'is-active' : '' } onClick={ () => setTab( 'actions' ) }>
							{ __( 'Actions', 'beyond-elysium' ) }
						</button>
						<button type="button" role="tab" aria-selected={ tab === 'rumors' } className={ tab === 'rumors' ? 'is-active' : '' } onClick={ () => setTab( 'rumors' ) }>
							{ __( 'Rumors', 'beyond-elysium' ) }
						</button>
					</div>

					{ tab === 'actions' && (
						<div className="be-apr-settings__panel">
							<label>
								{ __( 'Personal actions per character', 'beyond-elysium' ) }
								<input
									type="number" min={ 0 } max={ 100 }
									value={ settings.personal_actions }
									onChange={ ( e ) => setSettings( { ...settings, personal_actions: Number( e.target.value ) } ) }
								/>
							</label>

							<label className="be-apr-settings__checkbox">
								<input
									type="checkbox"
									checked={ settings.carry_unused }
									onChange={ ( e ) => setSettings( { ...settings, carry_unused: e.target.checked } ) }
								/>
								{ __( 'Copy Unused Values from Previous Action', 'beyond-elysium' ) }
							</label>

							<label className="be-apr-settings__checkbox">
								<input
									type="checkbox"
									checked={ settings.add_common }
									onChange={ ( e ) => setSettings( { ...settings, add_common: e.target.checked } ) }
								/>
								{ __( 'Always add Common Actions', 'beyond-elysium' ) }
							</label>

							<h2>{ __( 'Actions per level', 'beyond-elysium' ) }</h2>
							<p className="be-apr-settings__hint">
								{ __( 'Overrides the default of 2 actions per dot for a specific rating. A rating with no row here uses the default.', 'beyond-elysium' ) }
							</p>
							<table className="be-admin__table">
								<thead>
									<tr>
										<th>{ __( 'Rating', 'beyond-elysium' ) }</th>
										<th>{ __( 'Actions granted', 'beyond-elysium' ) }</th>
										<th></th>
									</tr>
								</thead>
								<tbody>
									{ Object.keys( settings.actions_per_level )
										.sort( ( a, b ) => Number( a ) - Number( b ) )
										.map( ( level ) => (
											<tr key={ level }>
												<td>{ level }</td>
												<td>
													<input
														type="number" min={ 0 } max={ 999 }
														value={ settings.actions_per_level[ level ] }
														onChange={ ( e ) => setLevel( level, Number( e.target.value ) ) }
													/>
												</td>
												<td>
													<button type="button" onClick={ () => removeLevel( level ) }>{ __( 'Remove', 'beyond-elysium' ) }</button>
												</td>
											</tr>
										) ) }
								</tbody>
							</table>
							<button type="button" onClick={ addLevel }>{ __( '+ Add a level', 'beyond-elysium' ) }</button>

							<h2>{ __( 'Backgrounds that grant an action', 'beyond-elysium' ) }</h2>
							<p className="be-apr-settings__hint">
								{ __( 'An Influence always grants an action and is shown for reference only. A Background grants one only when checked here.', 'beyond-elysium' ) }
							</p>
							<ul className="be-apr-settings__background-list">
								{ options.map( ( opt ) => (
									<li key={ opt.name }>
										<label className={ opt.is_influence ? 'be-apr-settings__checkbox is-disabled' : 'be-apr-settings__checkbox' }>
											<input
												type="checkbox"
												checked={ opt.is_influence || settings.background_actions.includes( opt.name ) }
												disabled={ opt.is_influence }
												onChange={ ( e ) => toggleBackgroundAction( opt.name, e.target.checked ) }
											/>
											{ opt.name }
											{ opt.is_influence && ` — ${ __( 'always granted (Influence)', 'beyond-elysium' ) }` }
											<span className="be-apr-settings__stacks"> ({ opt.stacks.join( ', ' ) })</span>
										</label>
									</li>
								) ) }
							</ul>
						</div>
					) }

					{ tab === 'rumors' && (
						<div className="be-apr-settings__panel">
							{ ( [
								[ 'public_rumors', __( 'Public rumors', 'beyond-elysium' ) ],
								[ 'personal_rumors', __( 'Personal rumors', 'beyond-elysium' ) ],
								[ 'race_rumors', __( 'Race rumors', 'beyond-elysium' ) ],
								[ 'influence_rumors', __( 'Influence rumors', 'beyond-elysium' ) ],
								[ 'previous_rumors', __( 'Carry forward previous rumors', 'beyond-elysium' ) ],
								[ 'copy_previous', __( 'Copy previous rumor descriptions', 'beyond-elysium' ) ],
							] as const ).map( ( [ key, label ] ) => (
								<label className="be-apr-settings__checkbox" key={ key }>
									<input
										type="checkbox"
										checked={ settings[ key ] }
										onChange={ ( e ) => setSettings( { ...settings, [ key ]: e.target.checked } ) }
									/>
									{ label }
								</label>
							) ) }

							{ ( [
								[ 'group_rumors', __( 'Group rumors', 'beyond-elysium' ) ],
								[ 'subgroup_rumors', __( 'Subgroup rumors', 'beyond-elysium' ) ],
							] as const ).map( ( [ key, label ] ) => (
								<label className="be-apr-settings__checkbox is-disabled" key={ key } title={ __( 'Recognized but inert: no character carries group/subgroup data to generate a rumor from.', 'beyond-elysium' ) }>
									<input type="checkbox" checked={ settings[ key ] } disabled />
									{ label } — { __( 'not yet functional', 'beyond-elysium' ) }
								</label>
							) ) }
						</div>
					) }

					<div className="be-admin__form-actions">
						<button type="button" onClick={ save } disabled={ saving }>
							{ saving ? __( 'Saving…', 'beyond-elysium' ) : __( 'Save', 'beyond-elysium' ) }
						</button>
						<button type="button" onClick={ restoreGrapevineDefaults }>
							{ __( 'Restore Grapevine defaults', 'beyond-elysium' ) }
						</button>
					</div>
				</>
			) }
		</div>
	);
}

export default AdminAprSettings;
