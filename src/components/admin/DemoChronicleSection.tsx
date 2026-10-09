/**
 * The "Demo chronicle" section of a chronicle's edit form on the Games admin screen: turning the flag on or off, its
 * cadence, its two accounts, Reset now, and the last reset's time and counts.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type { DemoSettings, DemoStatus, Game } from '../../types';
import type { WpUserSummary } from '../../types/character';
import { errorMessage } from '../../lib/errorMessage';
import { validateDemoChronicleForm } from '../../lib/demoChronicleForm';
import HelpButton from '../shared/HelpButton';

const SEARCH_DEBOUNCE_MS = 300;
const CADENCES: Array< 1 | 3 | 6 | 12 | 24 > = [ 1, 3, 6, 12, 24 ];

function cadenceLabel( hours: number ): string {
	return sprintf(
		/* translators: %d: number of hours */
		__( 'Every %d hours', 'beyond-elysium' ),
		hours
	);
}

/**
 * A single-account picker: a debounced search box that narrows to one chosen user.
 */
function AccountPicker( {
	label,
	value,
	onChange,
}: {
	label: string;
	value: WpUserSummary | null;
	onChange: ( user: WpUserSummary | null ) => void;
} ) {
	const [ search, setSearch ] = useState( '' );
	const [ results, setResults ] = useState< WpUserSummary[] >( [] );

	useEffect( () => {
		if ( search.trim() === '' ) {
			setResults( [] );
			return;
		}
		let cancelled = false;
		const timer = setTimeout( () => {
			api.wpUsers
				.search( search )
				.then( ( found ) => {
					if ( ! cancelled ) {
						setResults( found );
					}
				} )
				.catch( () => undefined );
		}, SEARCH_DEBOUNCE_MS );
		return () => {
			cancelled = true;
			clearTimeout( timer );
		};
	}, [ search ] );

	if ( value ) {
		return (
			<label>
				{ label }
				<span className="be-admin__demo-account-chosen">
					<strong>{ value.display_name }</strong>{ ' ' }
					<button type="button" onClick={ () => onChange( null ) }>
						{ __( 'Change', 'beyond-elysium' ) }
					</button>
				</span>
			</label>
		);
	}

	return (
		<label>
			{ label }
			<input
				type="search"
				placeholder={ __(
					'Search by name or email…',
					'beyond-elysium'
				) }
				value={ search }
				onChange={ ( e ) => setSearch( e.target.value ) }
			/>
			{ results.length > 0 && (
				<ul className="be-admin__demo-account-results">
					{ results.map( ( user ) => (
						<li key={ user.id }>
							<button
								type="button"
								onClick={ () => {
									onChange( user );
									setSearch( '' );
									setResults( [] );
								} }
							>
								{ user.display_name }
								{ user.email ? ` (${ user.email })` : '' }
							</button>
						</li>
					) ) }
				</ul>
			) }
		</label>
	);
}

export default function DemoChronicleSection( {
	game,
	onSaved,
}: {
	game: Game;
	onSaved: () => void;
} ) {
	const settings = ( game.settings?.demo ?? null ) as DemoSettings | null;
	const [ on, setOn ] = useState( Boolean( settings?.on ) );
	const [ resetHours, setResetHours ] = useState< 1 | 3 | 6 | 12 | 24 >(
		settings?.reset_hours ?? 6
	);
	const [ storyteller, setStoryteller ] = useState< WpUserSummary | null >(
		null
	);
	const [ player, setPlayer ] = useState< WpUserSummary | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ resetting, setResetting ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ status, setStatus ] = useState< DemoStatus | null >( null );

	useEffect( () => {
		if ( settings?.on ) {
			api.games
				.demoStatus( game.slug )
				.then( setStatus )
				.catch( () => undefined );
		}
		const ids = [
			settings?.accounts?.storyteller,
			settings?.accounts?.player,
		].filter( ( id ): id is number => typeof id === 'number' );
		if ( ids.length > 0 ) {
			api.wpUsers
				.byIds( ids )
				.then( ( found ) => {
					const byId = new Map( found.map( ( u ) => [ u.id, u ] ) );
					if ( settings?.accounts?.storyteller ) {
						setStoryteller(
							byId.get( settings.accounts.storyteller ) ?? null
						);
					}
					if ( settings?.accounts?.player ) {
						setPlayer(
							byId.get( settings.accounts.player ) ?? null
						);
					}
				} )
				.catch( () => undefined );
		}
		// Reflects only this chronicle's own stored accounts; a fresh search clears when it changes.
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ game.slug ] );

	async function save() {
		const validationError = validateDemoChronicleForm( {
			on,
			storytellerId: storyteller?.id,
			playerId: player?.id,
		} );
		if ( validationError ) {
			setError( validationError );
			return;
		}
		setSaving( true );
		setError( null );
		try {
			await api.games.update( game.slug, {
				settings: {
					demo: on
						? {
								on: true,
								reset_hours: resetHours,
								accounts: {
									storyteller: storyteller?.id,
									player: player?.id,
								},
							}
						: { on: false },
				},
			} );
			onSaved();
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

	async function resetNow() {
		if (
			! window.confirm(
				__(
					'Reset this chronicle to its declared demo content now? Everything in it is replaced.',
					'beyond-elysium'
				)
			)
		) {
			return;
		}
		setResetting( true );
		setError( null );
		try {
			await api.games.resetDemo( game.slug );
			const fresh = await api.games.demoStatus( game.slug );
			setStatus( fresh );
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setResetting( false );
		}
	}

	return (
		<div className="be-admin__demo-section">
			<div className="be-help-heading">
				<h3>{ __( 'Demo chronicle', 'beyond-elysium' ) }</h3>
				<HelpButton helpKey="demo-chronicle" />
			</div>
			<label>
				<input
					type="checkbox"
					checked={ on }
					onChange={ ( e ) => setOn( e.target.checked ) }
				/>{ ' ' }
				{ __( 'Make this chronicle a demo', 'beyond-elysium' ) }
			</label>
			{ on && ! settings?.on && (
				<p className="be-admin__field-warning">
					{ __(
						'Everything in this chronicle is replaced at the next reset, and every reset after that.',
						'beyond-elysium'
					) }
				</p>
			) }
			{ on && (
				<>
					<label>
						{ __( 'Reset cadence', 'beyond-elysium' ) }
						<select
							value={ resetHours }
							onChange={ ( e ) =>
								setResetHours(
									Number( e.target.value ) as
										1 | 3 | 6 | 12 | 24
								)
							}
						>
							{ CADENCES.map( ( hours ) => (
								<option key={ hours } value={ hours }>
									{ cadenceLabel( hours ) }
								</option>
							) ) }
						</select>
					</label>
					<AccountPicker
						label={ __( 'Storyteller account', 'beyond-elysium' ) }
						value={ storyteller }
						onChange={ setStoryteller }
					/>
					<AccountPicker
						label={ __( 'Player account', 'beyond-elysium' ) }
						value={ player }
						onChange={ setPlayer }
					/>
				</>
			) }
			{ error && (
				<div className="be-admin__error" role="alert">
					{ error }
				</div>
			) }
			<div className="be-admin__form-actions">
				<button type="button" disabled={ saving } onClick={ save }>
					{ saving
						? __( 'Saving…', 'beyond-elysium' )
						: __( 'Save demo settings', 'beyond-elysium' ) }
				</button>
				{ settings?.on && (
					<button
						type="button"
						disabled={ resetting }
						onClick={ resetNow }
					>
						{ resetting
							? __( 'Resetting…', 'beyond-elysium' )
							: __( 'Reset now', 'beyond-elysium' ) }
					</button>
				) }
			</div>
			{ status?.last_reset && (
				<p className="be-admin__demo-last-reset">
					{ sprintf(
						/* translators: %s: when the last reset ran */
						__( 'Last reset: %s', 'beyond-elysium' ),
						new Date( status.last_reset.at * 1000 ).toLocaleString()
					) }
				</p>
			) }
		</div>
	);
}
