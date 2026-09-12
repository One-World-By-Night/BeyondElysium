/**
 * A single plot's full detail view - its overview, nested child plots, faction goals and
 * cliffhanger, entry timeline, and the manager action bar. Renders entries created by the
 * action allocator as formatted subaction summaries rather than raw content. The action
 * bar's buttons request the action allocator, rumor generator, or connection manager tool
 * via a callback prop; the caller decides how to present them.
 */
import { createInterpolateElement, useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { pickMediaImage } from '../../lib/pickMediaImage';
import HtmlEditor from '../shared/HtmlEditor';
import type { FactionGoal, Plot } from '../../types/plot';
import { EntryForm } from './EntryForm';
import './PlotThread.css';

export interface PlotThreadProps {
	gameSlug: string;
	plotId: number;
	/** Called with a child plot's id when the user clicks it, to select it instead. */
	onSelectChild?: ( id: number ) => void;
	/** Shows the Faction Goals/Cliffhanger editor and Timeline date field. */
	expandedEnabled?: boolean;
	/** Opens one of the shared tools in the parent's modal. */
	onOpenTool?: ( tool: 'allocate' | 'rumors' | 'connect' ) => void;
}

interface AllocatorEntryData {
	source: 'allocator';
	name: string;
	total: number;
	unused: number;
	growth: number;
	action: string;
	result: string;
}

function isAllocatorEntry( data: unknown ): data is AllocatorEntryData {
	return typeof data === 'object' && data !== null && ( data as { source?: string } ).source === 'allocator';
}

interface LedgerEntryData {
	source: 'ledger';
	name: string;
	cost: number;
	text: string;
	result: string;
}

function isLedgerEntry( data: unknown ): data is LedgerEntryData {
	return typeof data === 'object' && data !== null && ( data as { source?: string } ).source === 'ledger';
}

/**
 * Renders one timeline entry's content. Entries created by the action allocator store
 * structured JSON describing a subaction, which is rendered as a formatted summary.
 * Every other entry is rendered as free-form HTML.
 */
function renderEntryContent( content: string ) {
	try {
		const parsed = JSON.parse( content );
		if ( isAllocatorEntry( parsed ) ) {
			return (
				<div className="be-plot-thread__subaction">
					{ createInterpolateElement(
						sprintf( __( '<name/>: %1$d / %2$d unused', 'beyond-elysium' ), parsed.unused, parsed.total ),
						{ name: <strong>{ parsed.name }</strong> }
					) }
					{ parsed.growth > 0 && (
						<span>{ sprintf( __( ' (+%d growth)', 'beyond-elysium' ), parsed.growth ) }</span>
					) }
					{ parsed.action && <p className="be-plot-thread__subaction-action">{ parsed.action }</p> }
					{ parsed.result && <p className="be-plot-thread__subaction-result">{ parsed.result }</p> }
				</div>
			);
		}
		if ( isLedgerEntry( parsed ) ) {
			return (
				<div className="be-plot-thread__subaction">
					{ createInterpolateElement(
						sprintf( __( '<name/> used, cost %d', 'beyond-elysium' ), parsed.cost ),
						{ name: <strong>{ parsed.name }</strong> }
					) }
					{ parsed.text && <p className="be-plot-thread__subaction-action">{ parsed.text }</p> }
					{ parsed.result && <p className="be-plot-thread__subaction-result">{ parsed.result }</p> }
				</div>
			);
		}
	} catch {
		// Not JSON - an ordinary free-text entry, fall through to the HTML render below.
	}
	return <div className="be-plot-thread__entry-content" dangerouslySetInnerHTML={ { __html: content } } />;
}

/**
 * Renders one plot's full detail view: its own overview and cover image, any plots nested
 * under it, its faction goals and cliffhanger (when enabled), its entry timeline, and a
 * bar of game-night actions for managers. Visibility of manager-only content such as ST
 * notes is determined by what the server includes in the response for this viewer; the
 * component renders exactly what it receives.
 */
export function PlotThread( { gameSlug, plotId, onSelectChild, expandedEnabled, onOpenTool }: PlotThreadProps ) {
	const [ plot, setPlot ] = useState<Plot | null>( null );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );

	const [ editingOverview, setEditingOverview ] = useState( false );
	// Once true, stays true for this plot's whole lifetime; HtmlEditor is never unmounted once opened.
	const [ hasOpenedEditor, setHasOpenedEditor ] = useState( false );
	const overviewDraft = useRef( '' );
	const [ cliffhanger, setCliffhanger ] = useState( '' );
	const [ factionGoals, setFactionGoals ] = useState<FactionGoal[]>( [] );
	const [ saving, setSaving ] = useState( false );
	const [ saveError, setSaveError ] = useState<string | null>( null );

	/**
	 * Fetches this plot from the API and stores it along with its overview draft,
	 * cliffhanger, and faction goals in local state. Runs on mount and whenever the
	 * plot id changes, ready for viewing or editing.
	 */
	function load() {
		setLoading( true );
		setError( null );
		api
			.plots( gameSlug )
			.get( plotId )
			.then( ( result ) => {
				setPlot( result );
				overviewDraft.current = result.description ?? '';
				setCliffhanger( result.cliffhanger ?? '' );
				setFactionGoals( result.faction_goals ?? [] );
				setLoading( false );
			} )
			.catch( () => {
				setError( __( 'Failed to load this plot.', 'beyond-elysium' ) );
				setLoading( false );
			} );
	}

	useEffect( load, [ gameSlug, plotId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	/**
	 * Sends a partial update for this plot to the API and, on success, replaces the local
	 * plot state with the server's updated record. Returns whether the save succeeded so
	 * callers can decide whether to leave edit mode.
	 */
	async function save( patch: Parameters<ReturnType<typeof api.plots>['update']>[1] ) {
		setSaving( true );
		setSaveError( null );
		try {
			const updated = await api.plots( gameSlug ).update( plotId, patch );
			setPlot( updated );
			return true;
		} catch {
			setSaveError( __( 'Failed to save.', 'beyond-elysium' ) );
			return false;
		} finally {
			setSaving( false );
		}
	}

	async function saveOverview() {
		if ( await save( { description: overviewDraft.current } ) ) {
			setEditingOverview( false );
		}
	}

	/**
	 * Opens the media library picker so a manager can choose a cover image for this plot.
	 * If an image is chosen, saves its attachment id immediately via the API; does
	 * nothing if the picker is dismissed without a selection.
	 */
	async function pickCover() {
		const attachment = await pickMediaImage( __( 'Choose a cover image', 'beyond-elysium' ) );
		if ( attachment ) {
			await save( { image_id: attachment.id } );
		}
	}

	function updateFactionGoal( index: number, field: keyof FactionGoal, value: string ) {
		setFactionGoals( ( goals ) => goals.map( ( g, i ) => ( i === index ? { ...g, [ field ]: value } : g ) ) );
	}

	if ( loading ) {
		return <p className="be-plot-manager__empty">{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}
	if ( error || ! plot ) {
		return (
			<div className="be-plot-manager__error" role="alert">
				{ error ?? __( 'Plot not found.', 'beyond-elysium' ) }
			</div>
		);
	}

	// st_notes is present only when the server has decided this viewer may see it.
	const canManage = plot.st_notes !== undefined;
	const resolved = plot.status === 'resolved';

	return (
		<div className="be-plot-thread">
			<header className="be-plot-thread__header">
				{ plot.image_url ? (
					<img className="be-plot-thread__cover" src={ plot.image_url } alt="" />
				) : (
					canManage && <div className="be-plot-thread__cover--empty" />
				) }

				<div className="be-plot-thread__header-text">
					<h2 className="be-plot-thread__title">{ plot.title }</h2>
					<div className="be-plot-thread__meta">
						{ plot.plot_category && (
							<span className="be-st-badge be-st-badge--category">{ plot.plot_category }</span>
						) }
						<span className={ `be-st-badge be-st-badge--${ plot.derived_status }` }>
							{ plot.status } ({ plot.derived_status })
						</span>
						<span>
							{ plot.initiated_by === 'player'
								? __( 'Player-initiated', 'beyond-elysium' )
								: __( 'ST-initiated', 'beyond-elysium' ) }
						</span>
						{ plot.game_date && (
							<span>{ sprintf( __( 'Game date: %s', 'beyond-elysium' ), plot.game_date ) }</span>
						) }
					</div>
					{ canManage && (
						<button type="button" className="be-st-button be-st-button--quiet" onClick={ pickCover }>
							{ plot.image_url
								? __( 'Change cover…', 'beyond-elysium' )
								: __( 'Add a cover image…', 'beyond-elysium' ) }
						</button>
					) }
				</div>
			</header>

			{ saveError && (
				<div className="be-plot-manager__error" role="alert">
					{ saveError }
				</div>
			) }

			<section className="be-st-section">
				<h3 className="be-st-section__title">{ __( 'Overview', 'beyond-elysium' ) }</h3>
				{ /* HtmlEditor stays mounted once opened; `hidden` toggles which view is visible rather than unmounting it. */ }
				{ hasOpenedEditor && (
					<div hidden={ ! editingOverview }>
						<HtmlEditor
							id={ `be-plot-overview-${ plotId }` }
							defaultValue={ plot.description ?? '' }
							onChange={ ( html ) => {
								overviewDraft.current = html;
							} }
							mediaButtons
							rows={ 10 }
						/>
						<div className="be-plot-thread__inline-actions">
							<button type="button" className="be-st-button" onClick={ saveOverview } disabled={ saving }>
								{ saving ? __( 'Saving…', 'beyond-elysium' ) : __( 'Save', 'beyond-elysium' ) }
							</button>
							<button
								type="button"
								className="be-st-button be-st-button--quiet"
								onClick={ () => setEditingOverview( false ) }
							>
								{ __( 'Cancel', 'beyond-elysium' ) }
							</button>
						</div>
					</div>
				) }
				<div hidden={ editingOverview }>
					{ plot.description ? (
						<div
							className="be-plot-thread__prose"
							dangerouslySetInnerHTML={ { __html: plot.description } }
						/>
					) : (
						<p className="be-plot-thread__placeholder">{ __( 'Nothing written yet.', 'beyond-elysium' ) }</p>
					) }
					{ canManage && (
						<button
							type="button"
							className="be-st-button be-st-button--quiet"
							onClick={ () => {
								setHasOpenedEditor( true );
								setEditingOverview( true );
							} }
						>
							{ __( 'Edit overview', 'beyond-elysium' ) }
						</button>
					) }
				</div>
			</section>

			{ canManage && plot.st_notes && (
				<section className="be-st-section be-plot-thread__st-notes">
					<h3 className="be-st-section__title">{ __( 'ST notes', 'beyond-elysium' ) }</h3>
					<div dangerouslySetInnerHTML={ { __html: plot.st_notes } } />
				</section>
			) }

			{ /* Plots nested under this one (e.g. actions under a plot, rumors under an action). */ }
			{ ( plot.children ?? [] ).length > 0 && (
				<section className="be-st-section">
					<h3 className="be-st-section__title">{ __( 'Under this plot', 'beyond-elysium' ) }</h3>
					<div className="be-plot-thread__children">
						{ ( plot.children ?? [] ).map( ( child ) => (
							<button
								type="button"
								className="be-plot-thread__child"
								key={ child.id }
								onClick={ () => onSelectChild?.( child.id ) }
							>
								{ child.image_url && <img src={ child.image_url } alt="" /> }
								<span className="be-plot-thread__child-title">{ child.title }</span>
								<span className="be-plot-card__meta">
									{ child.plot_category && (
										<span className="be-st-badge be-st-badge--category">{ child.plot_category }</span>
									) }
									<span className={ `be-st-badge be-st-badge--${ child.derived_status }` }>
										{ child.status }
									</span>
								</span>
							</button>
						) ) }
					</div>
				</section>
			) }

			{ expandedEnabled && canManage && (
				<section className="be-st-section">
					<h3 className="be-st-section__title">{ __( 'Faction goals', 'beyond-elysium' ) }</h3>
					{ factionGoals.map( ( goal, i ) => (
						<div className="be-plot-thread__faction-goal" key={ i }>
							<input
								type="text"
								placeholder={ __( 'Faction', 'beyond-elysium' ) }
								value={ goal.faction }
								onChange={ ( e ) => updateFactionGoal( i, 'faction', e.target.value ) }
							/>
							<input
								type="text"
								placeholder={ __( 'What they want', 'beyond-elysium' ) }
								value={ goal.goal }
								onChange={ ( e ) => updateFactionGoal( i, 'goal', e.target.value ) }
							/>
							<input
								type="text"
								placeholder={ __( 'Key NPCs (optional)', 'beyond-elysium' ) }
								value={ goal.key_npcs ?? '' }
								onChange={ ( e ) => updateFactionGoal( i, 'key_npcs', e.target.value ) }
							/>
							<button
								type="button"
								className="be-st-button be-st-button--quiet"
								onClick={ () => setFactionGoals( ( goals ) => goals.filter( ( _, gi ) => gi !== i ) ) }
							>
								{ __( 'Remove', 'beyond-elysium' ) }
							</button>
						</div>
					) ) }
					<div className="be-plot-thread__inline-actions">
						<button
							type="button"
							className="be-st-button be-st-button--quiet"
							onClick={ () => setFactionGoals( ( goals ) => [ ...goals, { faction: '', goal: '' } ] ) }
						>
							{ __( '+ Add faction goal', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							className="be-st-button"
							disabled={ saving }
							onClick={ () =>
								save( {
									faction_goals: factionGoals.filter( ( g ) => g.faction.trim() && g.goal.trim() ),
								} )
							}
						>
							{ __( 'Save goals', 'beyond-elysium' ) }
						</button>
					</div>
				</section>
			) }

			<section className="be-st-section">
				<h3 className="be-st-section__title">{ __( 'Timeline', 'beyond-elysium' ) }</h3>
				<ol className="be-plot-thread__entries">
					{ ( plot.entries ?? [] ).map( ( entry ) => (
						<li key={ entry.id } className={ `be-plot-thread__entry be-plot-thread__entry--${ entry.entry_type }` }>
							<div className="be-plot-thread__entry-meta">
								<span className="be-plot-thread__entry-type">{ entry.entry_type }</span>
								{ entry.event_date && (
									<span className="be-plot-thread__entry-event-date">{ entry.event_date }</span>
								) }
								<span className="be-plot-thread__entry-date">{ entry.created_at }</span>
							</div>
							{ renderEntryContent( entry.content ) }
						</li>
					) ) }
					{ ( plot.entries ?? [] ).length === 0 && (
						<li className="be-plot-thread__placeholder">
							{ __( 'Nothing has happened here yet.', 'beyond-elysium' ) }
						</li>
					) }
				</ol>

				<EntryForm
					gameSlug={ gameSlug }
					plotId={ plotId }
					canManage={ canManage }
					onCreated={ load }
					expandedEnabled={ expandedEnabled }
				/>
			</section>

			{ canManage && (
				<section className="be-st-section">
					<h3 className="be-st-section__title">{ __( 'Cliffhanger', 'beyond-elysium' ) }</h3>
					<textarea
						className="be-plot-thread__cliffhanger"
						value={ cliffhanger }
						onChange={ ( e ) => setCliffhanger( e.target.value ) }
						placeholder={ __( "What's left unresolved…", 'beyond-elysium' ) }
						rows={ 2 }
					/>
					<div className="be-plot-thread__inline-actions">
						<button
							type="button"
							className="be-st-button be-st-button--quiet"
							disabled={ saving }
							onClick={ () => save( { cliffhanger } ) }
						>
							{ __( 'Save cliffhanger', 'beyond-elysium' ) }
						</button>
					</div>
				</section>
			) }

			{ /* Game-night actions available to a manager. */ }
			{ canManage && (
				<div className="be-plot-manager__actions">
					<button type="button" className="be-st-button" onClick={ () => onOpenTool?.( 'rumors' ) }>
						{ __( '+ Rumor', 'beyond-elysium' ) }
					</button>
					<button type="button" className="be-st-button" onClick={ () => onOpenTool?.( 'allocate' ) }>
						{ __( '+ Action', 'beyond-elysium' ) }
					</button>
					<button type="button" className="be-st-button" onClick={ () => onOpenTool?.( 'connect' ) }>
						{ __( 'Connect character', 'beyond-elysium' ) }
					</button>
					<button
						type="button"
						className="be-st-button be-st-button--quiet"
						disabled={ saving || resolved }
						onClick={ () => save( { status: 'resolved' } ) }
					>
						{ resolved ? __( 'Resolved', 'beyond-elysium' ) : __( 'Mark resolved', 'beyond-elysium' ) }
					</button>
				</div>
			) }
		</div>
	);
}

export default PlotThread;
