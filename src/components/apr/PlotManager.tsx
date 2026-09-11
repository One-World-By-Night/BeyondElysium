/**
 * Storyteller Toolkit: the primary console for running a chronicle's plots. Switches
 * between a card-grid overview of all plots and a single plot's detail view, and hosts
 * the Allocate Actions, Generate Rumors, and Connect Character tools in a shared modal.
 * Mounted from both the front-end toolkit page and the wp-admin Plots screen.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import type { Plot } from '../../types/plot';
import { PlotList } from './PlotList';
import { PlotThread } from './PlotThread';
import { ConnectionManager } from './ConnectionManager';
import { ActionAllocator } from './ActionAllocator';
import { RumorPanel } from './RumorPanel';
import Modal from '../shared/Modal';
import './PlotManager.css';

export interface PlotManagerProps {
	gameSlug: string;
	defaultStatus?: 'active' | 'resolved' | 'archived';
}

/** Which standalone tool is open in a modal, if any. */
type Tool = 'allocate' | 'rumors' | 'connect' | null;

/**
 * Renders the Storyteller Toolkit: an overview grid of every plot, or - once a plot is
 * selected - that plot's full detail view with its own content and everything nested
 * under it. Also owns the shared modal that hosts the action allocator, rumor panel, and
 * connection manager tools. Shows a denial message for viewers who lack the manage
 * capability.
 */
export function PlotManager( { gameSlug, defaultStatus }: PlotManagerProps ) {
	const [ selectedPlot, setSelectedPlot ] = useState<number | null>( null );
	const [ expandedEnabled, setExpandedEnabled ] = useState( false );
	const [ tool, setTool ] = useState<Tool>( null );
	const [ refreshKey, setRefreshKey ] = useState( 0 );

	// UI affordance only; every REST route re-checks this capability server-side.
	const canManage = window.beyondElysium?.capabilities?.be_manage_plots ?? false;

	useEffect( () => {
		// Arc/Subplot/Season/Episode structure is opt-in per chronicle.
		api.games
			.get( gameSlug )
			.then( ( game ) => {
				const settings = game.settings as { plots?: { expanded_enabled?: boolean } } | null;
				setExpandedEnabled( Boolean( settings?.plots?.expanded_enabled ) );
			} )
			.catch( () => setExpandedEnabled( false ) );
	}, [ gameSlug ] );

	if ( ! canManage ) {
		return (
			<div className="be-plot-manager">
				<div className="be-plot-manager__denied">
					<h2>{ __( 'Storytellers only', 'beyond-elysium' ) }</h2>
					<p>
						{ __(
							'This is the Storyteller Toolkit. Your own plots and rumors live on the My Plots & Rumors page.',
							'beyond-elysium'
						) }
					</p>
				</div>
			</div>
		);
	}

	function closeToolAndRefresh() {
		setTool( null );
		setRefreshKey( ( k ) => k + 1 );
	}

	return (
		<div className="be-plot-manager">
			<header className="be-plot-manager__header">
				<h2 className="be-plot-manager__title">{ __( 'Storyteller Toolkit', 'beyond-elysium' ) }</h2>

				{ selectedPlot !== null ? (
					<nav className="be-plot-manager__crumbs">
						<button type="button" onClick={ () => setSelectedPlot( null ) }>
							{ __( 'All plots', 'beyond-elysium' ) }
						</button>
						<span>/</span>
						<span>{ __( 'this plot', 'beyond-elysium' ) }</span>
					</nav>
				) : (
					<div className="be-plot-manager__tools">
						<button type="button" className="be-st-button be-st-button--quiet" onClick={ () => setTool( 'allocate' ) }>
							{ __( 'Allocate actions', 'beyond-elysium' ) }
						</button>
						<button type="button" className="be-st-button be-st-button--quiet" onClick={ () => setTool( 'rumors' ) }>
							{ __( 'Generate rumors', 'beyond-elysium' ) }
						</button>
					</div>
				) }
			</header>

			{ selectedPlot === null ? (
				<PlotList
					key={ refreshKey }
					gameSlug={ gameSlug }
					onSelect={ setSelectedPlot }
					defaultStatus={ defaultStatus }
					onCreated={ setSelectedPlot }
					expandedEnabled={ expandedEnabled }
				/>
			) : (
				<div className="be-plot-manager__detail">
					<PlotThread
						key={ `${ selectedPlot }-${ refreshKey }` }
						gameSlug={ gameSlug }
						plotId={ selectedPlot }
						onSelectChild={ setSelectedPlot }
						expandedEnabled={ expandedEnabled }
						onOpenTool={ setTool }
					/>
				</div>
			) }

			{ /* Shared tools reused inside a modal; the selected plot is passed as the default parent. */ }
			{ tool === 'allocate' && (
				<Modal title={ __( 'Allocate actions', 'beyond-elysium' ) } onClose={ closeToolAndRefresh }>
					<ActionAllocator gameSlug={ gameSlug } defaultParentPlotId={ selectedPlot ?? undefined } />
				</Modal>
			) }

			{ tool === 'rumors' && (
				<Modal title={ __( 'Rumors', 'beyond-elysium' ) } onClose={ closeToolAndRefresh }>
					<RumorPanel gameSlug={ gameSlug } defaultParentPlotId={ selectedPlot ?? undefined } />
				</Modal>
			) }

			{ tool === 'connect' && selectedPlot !== null && (
				<Modal title={ __( 'Connect a character to this plot', 'beyond-elysium' ) } onClose={ closeToolAndRefresh }>
					<ConnectionManager gameSlug={ gameSlug } entityType="plot" entityId={ selectedPlot } />
				</Modal>
			) }
		</div>
	);
}

export default PlotManager;
