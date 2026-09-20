/**
 * The Chronicle Setup checklist (GS-5, guided-chronicle-setup-design.md §6.4):
 * a chronicle picker plus ten status rows, each computed live server-side.
 * No completion state of any kind is stored here or anywhere else (§5.5) -
 * every render re-fetches, and a row that goes from green to amber (an AST
 * removed, say) is reflected immediately, not on some later "recheck."
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import api from '../../api/client';
import EnabledStacksPicker from './EnabledStacksPicker';
import FactionRestrictionsPicker from './FactionRestrictionsPicker';
import type { Game, SetupStatus, SetupStatusItem } from '../../types';
import HelpButton from '../shared/HelpButton';
import { sendFileLinkUrl } from '../../lib/pluginPages';
import './AdminChronicleSetup.css';

const STATUS_LABEL: Record< SetupStatusItem[ 'status' ], string > = {
	attention: __( 'Needs attention', 'beyond-elysium' ),
	ok: __( 'Ready', 'beyond-elysium' ),
	info: __( 'Info', 'beyond-elysium' ),
};

/**
 * Renders one checklist row: its status pill, title and detail, and either
 * an inline control (rows 1/3/10) or a plain link to the page that fixes it.
 * A row the viewer cannot act on renders greyed with its status still
 * visible - useful even when it cannot be fixed from here.
 */
function StatusRow( {
	item,
	children,
}: {
	item: SetupStatusItem;
	children?: ReactNode;
} ) {
	return (
		<tr
			className={ `be-chronicle-setup__row be-chronicle-setup__row--${ item.status }` }
		>
			<td>
				<span className="be-chronicle-setup__pill">
					{ STATUS_LABEL[ item.status ] }
				</span>
			</td>
			<td>
				<strong>{ item.title }</strong>
				<p>{ item.detail }</p>
			</td>
			<td
				className={
					item.actionable ? '' : 'be-chronicle-setup__not-actionable'
				}
			>
				{ children ??
					( item.fix.kind === 'link' && item.fix.href ? (
						<a className="button" href={ item.fix.href }>
							{ __( 'Go', 'beyond-elysium' ) }
						</a>
					) : null ) }
			</td>
		</tr>
	);
}

export function AdminChronicleSetup() {
	const [ games, setGames ] = useState< Game[] >( [] );
	const [ gameSlug, setGameSlug ] = useState( '' );
	const [ status, setStatus ] = useState< SetupStatus | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ savingRow, setSavingRow ] = useState< string | null >( null );
	const [ saveError, setSaveError ] = useState< string | null >( null );
	const [ linkCopied, setLinkCopied ] = useState( false );

	function copySendFileLink( url: string, inputEl: HTMLInputElement | null ) {
		const done = () => {
			setLinkCopied( true );
			setTimeout( () => setLinkCopied( false ), 3000 );
		};
		if ( navigator.clipboard?.writeText ) {
			navigator.clipboard.writeText( url ).then( done, () => {
				inputEl?.select();
			} );
		} else {
			inputEl?.select();
			document.execCommand( 'copy' );
			done();
		}
	}

	useEffect( () => {
		api.games
			.list()
			.then( ( result ) => {
				setGames( result );
				if ( result.length > 0 ) {
					const fromUrl = new URLSearchParams(
						window.location.search
					).get( 'game' );
					setGameSlug(
						result.find( ( g ) => g.slug === fromUrl )?.slug ??
							result[ 0 ].slug
					);
				}
				setLoading( false );
			} )
			.catch( () => setLoading( false ) );
	}, [] );

	function reload() {
		if ( ! gameSlug ) {
			return;
		}
		api.setupStatus( gameSlug )
			.get()
			.then( setStatus )
			.catch( () => setStatus( null ) );
	}

	useEffect( reload, [ gameSlug ] );

	const currentGame = games.find( ( g ) => g.slug === gameSlug ) ?? null;
	const item = ( id: string ) =>
		status?.items.find( ( i ) => i.id === id ) ?? null;

	/**
	 * Keeps this page's copy of a chronicle current with what the server just saved, so the
	 * next save and the pickers never work from the settings the page first loaded with
	 * (1.0.0-review F-066).
	 */
	function applySaved( saved: Game ) {
		setGames( ( prev ) =>
			prev.map( ( g ) => ( g.slug === saved.slug ? saved : g ) )
		);
		setSavingRow( null );
		reload();
	}

	/** A save that failed says so, rather than leaving the control as if it took (1.0.0-review F-106). */
	function saveFailed( error: unknown ) {
		setSavingRow( null );
		setSaveError(
			( error as { message?: string } | null )?.message ??
				__( 'That change could not be saved.', 'beyond-elysium' )
		);
	}

	function saveStacks( slugs: string[] ) {
		setSavingRow( 'enabled_stacks' );
		setSaveError( null );
		api.games
			.updateChronicleSetup( gameSlug, { enabled_stacks: slugs } )
			.then( applySaved )
			.catch( saveFailed );
	}

	function saveApproval( required: boolean ) {
		setSavingRow( 'require_new_character_approval' );
		setSaveError( null );
		api.games
			.updateChronicleSetup( gameSlug, {
				require_new_character_approval: required,
			} )
			.then( applySaved )
			.catch( saveFailed );
	}

	// 1.2.7-design-workflow.md §E5 - same narrow be_manage_chronicle_setup route as
	// saveStacks()/saveApproval() above, not saveExpandedPlots()'s full be_manage_games
	// update(). An empty string clears the override (falls through to the site default).
	function saveAccentColor( color: string ) {
		setSavingRow( 'accent_color' );
		setSaveError( null );
		api.games
			.updateChronicleSetup( gameSlug, { accent_color: color } )
			.then( applySaved )
			.catch( saveFailed );
	}

	function saveExpandedPlots( enabled: boolean ) {
		setSavingRow( 'plots_expanded_enabled' );
		setSaveError( null );
		api.games
			.update( gameSlug, {
				settings: { plots: { expanded_enabled: enabled } },
			} )
			.then( applySaved )
			.catch( saveFailed );
	}

	function saveFactionRestriction(
		stackSlug: string,
		fieldName: string,
		allowed: string[]
	) {
		const key = `faction:${ stackSlug }:${ fieldName }`;
		setSavingRow( key );
		setSaveError( null );
		// Only the field being saved: the server keeps every other stack's and field's restriction.
		api.games
			.updateChronicleSetup( gameSlug, {
				enabled_factions: {
					[ stackSlug ]: { [ fieldName ]: allowed },
				},
			} )
			.then( applySaved )
			.catch( saveFailed );
	}

	function deleteDemo() {
		if (
			! window.confirm(
				__(
					'Delete the demo chronicle and all 22 sample characters? This cannot be undone.',
					'beyond-elysium'
				)
			)
		) {
			return;
		}
		api.games.delete( gameSlug, true ).then( () => {
			window.location.reload();
		} );
	}

	if ( loading ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}
	if ( games.length === 0 ) {
		return (
			<p>
				{ __(
					'No chronicles exist yet - create one under Beyond Elysium → System Config → Games first.',
					'beyond-elysium'
				) }
			</p>
		);
	}

	const enabledStacksItem = item( 'enabled_stacks' );
	const approvalItem = item( 'require_new_character_approval' );
	const demoItem = item( 'demo_chronicle' );

	return (
		<div className="be-admin be-chronicle-setup">
			<div className="be-help-heading">
				<h1>{ __( 'Chronicle Setup', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="chronicle-setup" />
			</div>

			{ saveError && (
				<div className="be-admin__error" role="alert">
					{ saveError }
				</div>
			) }

			<div className="be-admin__filters">
				<label>
					{ __( 'Chronicle', 'beyond-elysium' ) }{ ' ' }
					<select
						value={ gameSlug }
						onChange={ ( e ) => setGameSlug( e.target.value ) }
					>
						{ games.map( ( g ) => (
							<option key={ g.slug } value={ g.slug }>
								{ g.name }
							</option>
						) ) }
					</select>
				</label>
			</div>

			{ status && (
				<p className="be-chronicle-setup__summary">
					{ status.summary.attention > 0
						? sprintf(
								/* translators: %d: number of checklist items needing attention */
								_n(
									'%d item needs attention.',
									'%d items need attention.',
									status.summary.attention,
									'beyond-elysium'
								),
								status.summary.attention
						  )
						: __( 'Nothing needs attention.', 'beyond-elysium' ) }
				</p>
			) }

			{ status && (
				<table className="be-admin__table be-chronicle-setup__table">
					<tbody>
						{ status.items.map( ( row ) => {
							if (
								row.id === 'enabled_stacks' &&
								enabledStacksItem
							) {
								return (
									<StatusRow item={ row } key={ row.id }>
										{ row.actionable ? (
											<EnabledStacksPicker
												enabled={
													( currentGame?.settings
														?.enabled_stacks as
														| string[]
														| undefined ) ?? null
												}
												onSave={ saveStacks }
												saving={
													savingRow ===
													'enabled_stacks'
												}
											/>
										) : null }
									</StatusRow>
								);
							}
							if (
								row.id === 'require_new_character_approval' &&
								approvalItem
							) {
								const current = currentGame?.settings
									?.require_new_character_approval as
									| boolean
									| undefined;
								return (
									<StatusRow item={ row } key={ row.id }>
										{ row.actionable ? (
											<div className="be-chronicle-setup__approval-toggle">
												<label>
													<input
														type="radio"
														name="require_new_character_approval"
														checked={
															current === true
														}
														onChange={ () =>
															saveApproval( true )
														}
														disabled={
															savingRow ===
															'require_new_character_approval'
														}
													/>
													{ __(
														'Require approval',
														'beyond-elysium'
													) }
												</label>
												<label>
													<input
														type="radio"
														name="require_new_character_approval"
														checked={
															current === false
														}
														onChange={ () =>
															saveApproval(
																false
															)
														}
														disabled={
															savingRow ===
															'require_new_character_approval'
														}
													/>
													{ __(
														'Active immediately',
														'beyond-elysium'
													) }
												</label>
											</div>
										) : null }
									</StatusRow>
								);
							}
							if ( row.id === 'demo_chronicle' && demoItem ) {
								return (
									<StatusRow item={ row } key={ row.id }>
										{ row.actionable ? (
											<button
												type="button"
												className="button"
												onClick={ deleteDemo }
											>
												{ __(
													'Delete demo chronicle',
													'beyond-elysium'
												) }
											</button>
										) : null }
									</StatusRow>
								);
							}
							return <StatusRow item={ row } key={ row.id } />;
						} ) }
					</tbody>
				</table>
			) }

			<h2>{ __( 'Plot Features', 'beyond-elysium' ) }</h2>
			<p className="description">
				{ __(
					'Off by default. On adds Faction Goals to a plot, the Arc/Subplot/Season/Episode categories when creating one, and an optional date on a timeline entry - extra structure most chronicles never need.',
					'beyond-elysium'
				) }
			</p>
			{ /* Deliberately still be_manage_games only, unlike Creature types and
			 * New-character approval above (1.0.0-checklist.md item 18 names only those two
			 * plus Sub-Faction Restrictions below - not this). Checked directly rather than
			 * reused from enabledStacksItem's own actionable flag, which item 18 broadened to
			 * include an HST - be_manage_games is the one capability with no chronicle-scoped
			 * narrowing at all (Authorization::check_request()'s own site-administrator
			 * bypass), so the global, chronicle-blind snapshot is exactly right for it. */ }
			{ window.beyondElysium?.capabilities?.be_manage_games ? (
				<label className="be-chronicle-setup__checkbox">
					<input
						type="checkbox"
						checked={ Boolean(
							(
								currentGame?.settings?.plots as
									| { expanded_enabled?: boolean }
									| undefined
							 )?.expanded_enabled
						) }
						onChange={ ( e ) =>
							saveExpandedPlots( e.target.checked )
						}
						disabled={ savingRow === 'plots_expanded_enabled' }
					/>
					{ __(
						'Turn on Faction Goals, plot categories, and timeline dates',
						'beyond-elysium'
					) }
				</label>
			) : (
				<p className="be-chronicle-setup__not-actionable">
					{ __(
						'A site administrator sets this.',
						'beyond-elysium'
					) }
				</p>
			) }

			<h2>{ __( 'Branding', 'beyond-elysium' ) }</h2>
			<p className="description">
				{ __(
					"This chronicle's own accent color, used for the Storyteller Toolkit's and My Chronicle's own chrome (buttons, highlights). Leave unset to use the site-wide default.",
					'beyond-elysium'
				) }
			</p>
			<div className="be-chronicle-setup__branding">
				<input
					type="color"
					aria-label={ __( 'Accent color', 'beyond-elysium' ) }
					value={
						( currentGame?.settings?.accent_color as
							| string
							| undefined ) || '#8b0000'
					}
					disabled={ savingRow === 'accent_color' }
					onChange={ ( e ) => saveAccentColor( e.target.value ) }
				/>
				<button
					type="button"
					disabled={
						savingRow === 'accent_color' ||
						! currentGame?.settings?.accent_color
					}
					onClick={ () => saveAccentColor( '' ) }
				>
					{ __( 'Use site default', 'beyond-elysium' ) }
				</button>
			</div>

			<h2>{ __( 'Sub-Faction Restrictions', 'beyond-elysium' ) }</h2>
			<p className="description">
				{ __(
					'Beneath the whole-creature-type toggle above: narrow a real catalog field within an enabled creature type - a Vampire Sect or Clan, a Werewolf Tribe, and similar. Absent or fully-checked means every option stays open, same as the toggle above.',
					'beyond-elysium'
				) }
			</p>
			{ /* Saved with the creature types above, so offered to whoever that row lets act (F-106). */ }
			{ status &&
				( enabledStacksItem?.actionable ? (
					<FactionRestrictionsPicker
						gameSlug={ gameSlug }
						enabledStacks={
							( currentGame?.settings?.enabled_stacks as
								| string[]
								| undefined ) ?? null
						}
						restrictions={
							( currentGame?.settings?.enabled_factions as
								| Record< string, Record< string, string[] > >
								| undefined ) ?? {}
						}
						onSave={ saveFactionRestriction }
						savingKey={
							savingRow?.startsWith( 'faction:' )
								? savingRow.slice( 'faction:'.length )
								: null
						}
					/>
				) : (
					<p className="be-chronicle-setup__not-actionable">
						{ __(
							"Your chronicle's HST sets these.",
							'beyond-elysium'
						) }
					</p>
				) ) }

			<h2>{ __( "Players' Grapevine Files", 'beyond-elysium' ) }</h2>
			<p className="description">
				{ sprintf(
					/* translators: %s: chronicle name */
					__(
						"Share this link so players can send their character's Grapevine file to %s. Anyone signed in can use it; nothing is added until a Storyteller accepts.",
						'beyond-elysium'
					),
					currentGame?.name ?? gameSlug
				) }
			</p>
			{ gameSlug && (
				<p className="be-chronicle-setup__send-file-link">
					<input
						type="text"
						readOnly
						id="be-send-file-link"
						value={ sendFileLinkUrl( gameSlug ) }
						onFocus={ ( e ) => e.currentTarget.select() }
					/>{ ' ' }
					<button
						type="button"
						onClick={ () =>
							copySendFileLink(
								sendFileLinkUrl( gameSlug ),
								document.getElementById(
									'be-send-file-link'
								) as HTMLInputElement | null
							)
						}
					>
						{ __( 'Copy link', 'beyond-elysium' ) }
					</button>
					{ linkCopied && (
						<span role="status">
							{ __( 'Link copied.', 'beyond-elysium' ) }
						</span>
					) }
				</p>
			) }
		</div>
	);
}

export default AdminChronicleSetup;
