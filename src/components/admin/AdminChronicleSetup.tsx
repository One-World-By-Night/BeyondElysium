/**
 * The Chronicle Setup checklist (GS-5, guided-chronicle-setup-design.md §6.4; one folding list
 * since 1.3.6): a chronicle picker plus one status row per thing to set up, each computed live
 * server-side. No completion state of any kind is stored here or anywhere else (§5.5) - every
 * render re-fetches, and a row that goes from green to amber (an AST removed, say) is reflected
 * immediately, not on some later "recheck."
 *
 * A row for a page elsewhere links to it. A row for a setting on this page opens its controls
 * beneath it: open while the row needs attention, folded otherwise, and the viewer's own toggle
 * wins from then on.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf, _n } from '@wordpress/i18n';
import type { ReactNode } from 'react';
import api from '../../api/client';
import EnabledStacksPicker from './EnabledStacksPicker';
import FactionRestrictionsPicker from './FactionRestrictionsPicker';
import PurchaseListsPicker from './PurchaseListsPicker';
import type { Game, SetupStatus, SetupStatusItem } from '../../types';
import { isRowOpen } from '../../lib/setupRows';
import HelpButton from '../shared/HelpButton';
import {
	preselectedChronicle,
	sendFileLinkUrl,
	writeGameToUrl,
} from '../../lib/pluginPages';
import {
	purchaseScopeChange,
	type PurchaseArea,
} from '../../lib/purchaseScope';
import './AdminChronicleSetup.css';

const STATUS_LABEL: Record< SetupStatusItem[ 'status' ], string > = {
	attention: __( 'Needs attention', 'beyond-elysium' ),
	ok: __( 'Done', 'beyond-elysium' ),
	info: __( 'Info', 'beyond-elysium' ),
};

/**
 * Renders one checklist row: its status pill, title and detail, and one action - a **Go** link to
 * the page that does the job, or a button that opens this row's own controls (`panel`) beneath it.
 * A row the viewer cannot act on renders greyed, with its status still visible - useful even when
 * it cannot be changed from here. `children`, when given, replaces the action outright.
 */
function StatusRow( {
	item,
	panel,
	note,
	open,
	onToggle,
	children,
}: {
	item: SetupStatusItem;
	panel?: ReactNode;
	note?: string;
	open: boolean;
	onToggle: () => void;
	children?: ReactNode;
} ) {
	const panelId = `be-setup-panel-${ item.id }`;
	let action: ReactNode = children ?? null;
	if ( children === undefined ) {
		if ( panel !== undefined ) {
			action = item.actionable ? (
				<button
					type="button"
					className="button"
					aria-expanded={ open }
					aria-controls={ panelId }
					onClick={ onToggle }
				>
					{ open && __( 'Close', 'beyond-elysium' ) }
					{ ! open &&
						item.status === 'ok' &&
						__( 'Change', 'beyond-elysium' ) }
					{ ! open &&
						item.status !== 'ok' &&
						__( 'Set up', 'beyond-elysium' ) }
				</button>
			) : (
				note ?? null
			);
		} else if ( item.fix.kind === 'link' && item.fix.href ) {
			action = (
				<a className="button" href={ item.fix.href }>
					{ __( 'Go', 'beyond-elysium' ) }
				</a>
			);
		}
	}

	return (
		<tbody>
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
						item.actionable
							? ''
							: 'be-chronicle-setup__not-actionable'
					}
				>
					{ action }
				</td>
			</tr>
			{ panel !== undefined && item.actionable && open && (
				<tr
					id={ panelId }
					className={ `be-chronicle-setup__panel be-chronicle-setup__row--${ item.status }` }
				>
					<td colSpan={ 3 }>{ panel }</td>
				</tr>
			) }
		</tbody>
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
	const [ openRows, setOpenRows ] = useState< Record< string, boolean > >(
		{}
	);

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
				setGameSlug( preselectedChronicle( result )?.slug ?? '' );
				setLoading( false );
			} )
			.catch( () => setLoading( false ) );
	}, [] );

	useEffect( () => {
		if ( gameSlug ) {
			writeGameToUrl( gameSlug );
			setOpenRows( {} );
		}
	}, [ gameSlug ] );

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

	// 1.3.4: one purchase-list switch. Only the area being switched is sent, so the server keeps the
	// other two exactly as they were.
	function savePurchaseScope( area: PurchaseArea, on: boolean ) {
		setSavingRow( `purchase:${ area }` );
		setSaveError( null );
		api.games
			.updateChronicleSetup( gameSlug, purchaseScopeChange( area, on ) )
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

	const isOpen = ( row: SetupStatusItem ) => isRowOpen( openRows, row );
	const toggleRow = ( row: SetupStatusItem ) =>
		setOpenRows( ( prev ) => ( { ...prev, [ row.id ]: ! isOpen( row ) } ) );

	const settings = currentGame?.settings;
	const approvalChoice = settings?.require_new_character_approval as
		| boolean
		| undefined;

	const panels: Record< string, ReactNode > = {
		enabled_stacks: (
			<EnabledStacksPicker
				enabled={
					( settings?.enabled_stacks as string[] | undefined ) ?? null
				}
				onSave={ saveStacks }
				saving={ savingRow === 'enabled_stacks' }
			/>
		),
		require_new_character_approval: (
			<div className="be-chronicle-setup__approval-toggle">
				<label>
					<input
						type="radio"
						name="require_new_character_approval"
						checked={ approvalChoice === true }
						onChange={ () => saveApproval( true ) }
						disabled={
							savingRow === 'require_new_character_approval'
						}
					/>
					{ __( 'Require approval', 'beyond-elysium' ) }
				</label>
				<label>
					<input
						type="radio"
						name="require_new_character_approval"
						checked={ approvalChoice === false }
						onChange={ () => saveApproval( false ) }
						disabled={
							savingRow === 'require_new_character_approval'
						}
					/>
					{ __( 'Active immediately', 'beyond-elysium' ) }
				</label>
			</div>
		),
		plot_features: (
			<>
				<p className="description">
					{ __(
						'Off by default. On adds Faction Goals to a plot, the Arc/Subplot/Season/Episode categories when creating one, and an optional date on a timeline entry - extra structure most chronicles never need.',
						'beyond-elysium'
					) }
				</p>
				<label className="be-chronicle-setup__checkbox">
					<input
						type="checkbox"
						checked={ Boolean(
							(
								settings?.plots as
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
			</>
		),
		branding: (
			<>
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
							( settings?.accent_color as string | undefined ) ||
							'#8b0000'
						}
						disabled={ savingRow === 'accent_color' }
						onChange={ ( e ) => saveAccentColor( e.target.value ) }
					/>
					<button
						type="button"
						disabled={
							savingRow === 'accent_color' ||
							! settings?.accent_color
						}
						onClick={ () => saveAccentColor( '' ) }
					>
						{ __( 'Use site default', 'beyond-elysium' ) }
					</button>
				</div>
			</>
		),
		faction_restrictions: (
			<>
				<p className="description">
					{ __(
						'Beneath the whole-creature-type toggle above: narrow a real catalog field within an enabled creature type - a Vampire Sect or Clan, a Werewolf Tribe, and similar. Absent or fully-checked means every option stays open, same as the toggle above.',
						'beyond-elysium'
					) }
				</p>
				<FactionRestrictionsPicker
					gameSlug={ gameSlug }
					enabledStacks={
						( settings?.enabled_stacks as string[] | undefined ) ??
						null
					}
					restrictions={
						( settings?.enabled_factions as
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
			</>
		),
		purchase_lists: (
			<>
				<p className="description">
					{ __(
						"Each creature type buys from its own lists: its own Abilities, Backgrounds, Merits and Flaws. Turn a list on to let every creature type in this chronicle buy from every creature type's entries for it, priced from the list each entry comes from. The lists themselves stay separate. All off by default.",
						'beyond-elysium'
					) }
				</p>
				<PurchaseListsPicker
					scope={ settings?.purchase_scope }
					savingArea={
						savingRow?.startsWith( 'purchase:' )
							? ( savingRow.slice(
									'purchase:'.length
							  ) as PurchaseArea )
							: null
					}
					onChange={ savePurchaseScope }
				/>
			</>
		),
		grapevine_files: (
			<>
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
			</>
		),
	};

	const noteFor = ( id: string ) =>
		id === 'plot_features'
			? __( 'A site administrator sets this.', 'beyond-elysium' )
			: __( "Your chronicle's HST sets these.", 'beyond-elysium' );

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
					{ sprintf(
						/* translators: 1: number of checklist items done, 2: number of checklist items in all */
						__( '%1$d of %2$d done.', 'beyond-elysium' ),
						status.summary.ok,
						status.summary.total
					) }{ ' ' }
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
					{ status.items.map( ( row ) => (
						<StatusRow
							key={ row.id }
							item={ row }
							panel={ panels[ row.id ] }
							note={ noteFor( row.id ) }
							open={ isOpen( row ) }
							onToggle={ () => toggleRow( row ) }
						>
							{ row.id === 'demo_chronicle' && row.actionable ? (
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
							) : undefined }
						</StatusRow>
					) ) }
				</table>
			) }
		</div>
	);
}

export default AdminChronicleSetup;
