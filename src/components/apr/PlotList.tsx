/**
 * Storyteller Toolkit plot overview. Renders every plot in a chronicle as a filterable
 * card grid with status/initiator/search controls, plus an inline form for creating a
 * new plot with an optional cover image and category. Paginated server-side, 24 cards
 * per page.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { pickMediaImage } from '../../lib/pickMediaImage';
import type { InitiatedBy, Plot, PlotCategory, PlotStatus } from '../../types/plot';
import './PlotList.css';

export interface PlotListProps {
	gameSlug: string;
	onSelect?: ( plotId: number ) => void;
	defaultStatus?: PlotStatus;
	/** Called after a successful create, with the new plot's id. */
	onCreated?: ( plotId: number ) => void;
	/** Shows the Arc/Subplot/Season/Episode category picker on create. */
	expandedEnabled?: boolean;
}

const STATUSES: PlotStatus[] = [ 'active', 'resolved', 'archived' ];
const INITIATORS: InitiatedBy[] = [ 'player', 'st' ];
const PLOT_CATEGORIES: PlotCategory[] = [ 'arc', 'subplot', 'season', 'episode' ];

/**
 * Renders every plot in the chronicle as a card grid, showing each plot's cover image,
 * title, category, and status. Provides filter controls for status, initiator, and a
 * text search, plus a form for creating a new plot.
 */
export function PlotList( { gameSlug, onSelect, defaultStatus, onCreated, expandedEnabled }: PlotListProps ) {
	const [ items, setItems ] = useState<Plot[]>( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ status, setStatus ] = useState<PlotStatus | ''>( defaultStatus ?? '' );
	const [ initiatedBy, setInitiatedBy ] = useState<InitiatedBy | ''>( '' );
	const [ search, setSearch ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );

	const [ showNewForm, setShowNewForm ] = useState( false );
	const [ newTitle, setNewTitle ] = useState( '' );
	const [ newDescription, setNewDescription ] = useState( '' );
	const [ newCategory, setNewCategory ] = useState<PlotCategory | ''>( '' );
	const [ newParentId, setNewParentId ] = useState<number | ''>( '' );
	const [ newImage, setNewImage ] = useState<{ id: number; url: string } | null>( null );
	const [ allPlots, setAllPlots ] = useState<Plot[]>( [] );
	const [ creating, setCreating ] = useState( false );
	const [ createError, setCreateError ] = useState<string | null>( null );

	/**
	 * Fetches one page of plots matching the current status, initiator, and search
	 * filters from the API and stores the results and total count. Re-runs whenever
	 * the filters or page number change.
	 */
	function load() {
		setLoading( true );
		setError( null );
		api
			.plots( gameSlug )
			.listPaginated( {
				status: status || undefined,
				initiated_by: initiatedBy || undefined,
				search: search || undefined,
				page,
				per_page: 24,
			} )
			.then( ( result ) => {
				setItems( result.items );
				setTotal( result.total );
				setLoading( false );
			} )
			.catch( () => {
				setError( __( 'Failed to load plots.', 'beyond-elysium' ) );
				setLoading( false );
			} );
	}

	useEffect( load, [ gameSlug, status, initiatedBy, search, page ] ); // eslint-disable-line react-hooks/exhaustive-deps

	useEffect( () => {
		if ( ! expandedEnabled ) {
			return;
		}
		// A Subplot/Episode needs a parent, selectable from the full plot list.
		api.plots( gameSlug ).list( { per_page: 100 } ).then( setAllPlots ).catch( () => setAllPlots( [] ) );
	}, [ gameSlug, expandedEnabled ] );

	const categoryNeedsParent = newCategory === 'subplot' || newCategory === 'episode';

	/**
	 * Opens the media library picker so the user can choose a cover image for the new
	 * plot. Stores the chosen attachment's id and URL in local state for the create form
	 * to submit and preview; does nothing if the picker is dismissed without a selection.
	 */
	async function pickCover() {
		const attachment = await pickMediaImage( __( 'Choose a cover image', 'beyond-elysium' ) );
		if ( attachment ) {
			setNewImage( { id: attachment.id, url: attachment.url } );
		}
	}

	/**
	 * Submits the new-plot creation form. Validates that a title is present and that a
	 * Subplot or Episode has a parent selected, then creates the plot via the API, resets
	 * the form fields, reloads the list, and notifies the parent through onCreated.
	 */
	async function createPlot( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! newTitle.trim() ) {
			return;
		}
		if ( categoryNeedsParent && ! newParentId ) {
			setCreateError( sprintf( __( 'A %s needs a parent plot.', 'beyond-elysium' ), newCategory ) );
			return;
		}
		setCreating( true );
		setCreateError( null );
		try {
			const plot = await api.plots( gameSlug ).create( {
				title: newTitle.trim(),
				description: newDescription.trim() || undefined,
				plot_category: newCategory || undefined,
				parent_plot_id: newParentId || undefined,
				image_id: newImage?.id,
			} );
			setNewTitle( '' );
			setNewDescription( '' );
			setNewCategory( '' );
			setNewParentId( '' );
			setNewImage( null );
			setShowNewForm( false );
			load();
			onCreated?.( plot.id );
		} catch {
			setCreateError( __( 'Failed to create this plot.', 'beyond-elysium' ) );
		} finally {
			setCreating( false );
		}
	}

	return (
		<div className="be-plot-list">
			{ showNewForm ? (
				<form onSubmit={ createPlot } className="be-plot-list__new-form">
					<input
						type="text"
						placeholder={ __( 'Plot title…', 'beyond-elysium' ) }
						value={ newTitle }
						onChange={ ( e ) => setNewTitle( e.target.value ) }
					/>
					<textarea
						placeholder={ __( 'What is this about…', 'beyond-elysium' ) }
						value={ newDescription }
						onChange={ ( e ) => setNewDescription( e.target.value ) }
					/>

					<div className="be-plot-list__cover-row">
						{ newImage && <img className="be-plot-list__cover-preview" src={ newImage.url } alt="" /> }
						<button type="button" className="be-st-button be-st-button--quiet" onClick={ pickCover }>
							{ newImage ? __( 'Change cover…', 'beyond-elysium' ) : __( 'Add a cover image…', 'beyond-elysium' ) }
						</button>
						{ newImage && (
							<button
								type="button"
								className="be-st-button be-st-button--quiet"
								onClick={ () => setNewImage( null ) }
							>
								{ __( 'Remove', 'beyond-elysium' ) }
							</button>
						) }
					</div>

					{ expandedEnabled && (
						<>
							<select
								value={ newCategory }
								onChange={ ( e ) => setNewCategory( e.target.value as PlotCategory | '' ) }
								aria-label={ __( 'Category (optional)', 'beyond-elysium' ) }
							>
								<option value="">{ __( 'Ordinary plot', 'beyond-elysium' ) }</option>
								{ PLOT_CATEGORIES.map( ( c ) => (
									<option key={ c } value={ c }>
										{ c }
									</option>
								) ) }
							</select>
							{ categoryNeedsParent && (
								<select
									value={ newParentId }
									onChange={ ( e ) => setNewParentId( e.target.value ? Number( e.target.value ) : '' ) }
									aria-label={ __( 'Parent plot (required for subplot/episode)', 'beyond-elysium' ) }
								>
									<option value="">{ __( 'Select a parent…', 'beyond-elysium' ) }</option>
									{ allPlots.map( ( p ) => (
										<option key={ p.id } value={ p.id }>
											{ p.title }
										</option>
									) ) }
								</select>
							) }
						</>
					) }

					{ createError && (
						<div className="be-plot-manager__error" role="alert">
							{ createError }
						</div>
					) }
					<div className="be-plot-list__new-form-actions">
						<button type="submit" className="be-st-button" disabled={ creating || ! newTitle.trim() }>
							{ __( 'Create plot', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							className="be-st-button be-st-button--quiet"
							onClick={ () => setShowNewForm( false ) }
						>
							{ __( 'Cancel', 'beyond-elysium' ) }
						</button>
					</div>
				</form>
			) : (
				<button type="button" className="be-st-button be-plot-list__new-button" onClick={ () => setShowNewForm( true ) }>
					{ __( '+ New plot', 'beyond-elysium' ) }
				</button>
			) }

			<div className="be-plot-list__filters">
				<select value={ status } onChange={ ( e ) => setStatus( e.target.value as PlotStatus | '' ) }>
					<option value="">{ __( 'All statuses', 'beyond-elysium' ) }</option>
					{ STATUSES.map( ( s ) => (
						<option key={ s } value={ s }>
							{ s }
						</option>
					) ) }
				</select>
				<select value={ initiatedBy } onChange={ ( e ) => setInitiatedBy( e.target.value as InitiatedBy | '' ) }>
					<option value="">{ __( 'Player or ST', 'beyond-elysium' ) }</option>
					{ INITIATORS.map( ( i ) => (
						<option key={ i } value={ i }>
							{ i }
						</option>
					) ) }
				</select>
				<input
					type="search"
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
					placeholder={ __( 'Search title/description…', 'beyond-elysium' ) }
				/>
			</div>

			{ error && (
				<div className="be-plot-manager__error" role="alert">
					{ error }
				</div>
			) }

			{ loading ? (
				<p className="be-plot-manager__empty">{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : items.length === 0 ? (
				<p className="be-plot-manager__empty">{ __( 'No plots match these filters.', 'beyond-elysium' ) }</p>
			) : (
				<div className="be-plot-manager__grid">
					{ items.map( ( plot ) => (
						<button
							type="button"
							className="be-plot-card"
							key={ plot.id }
							onClick={ () => onSelect?.( plot.id ) }
						>
							{ plot.image_url ? (
								<img className="be-plot-card__cover" src={ plot.image_url } alt="" />
							) : (
								<div className="be-plot-card__cover--empty" />
							) }
							<span className="be-plot-card__title">{ plot.title }</span>
							<span className="be-plot-card__meta">
								{ plot.plot_category && (
									<span className="be-st-badge be-st-badge--category">{ plot.plot_category }</span>
								) }
								<span className={ `be-st-badge be-st-badge--${ plot.derived_status }` }>{ plot.status }</span>
								<span>
									{ plot.initiated_by === 'player'
										? __( 'player', 'beyond-elysium' )
										: __( 'ST', 'beyond-elysium' ) }
								</span>
							</span>
						</button>
					) ) }
				</div>
			) }

			<div className="be-plot-list__pagination">
				<button
					type="button"
					className="be-st-button be-st-button--quiet"
					disabled={ page <= 1 }
					onClick={ () => setPage( ( p ) => p - 1 ) }
				>
					{ __( 'Previous', 'beyond-elysium' ) }
				</button>
				<span>
					{ sprintf( __( 'Page %1$d (%2$d total)', 'beyond-elysium' ), page, total ) }
				</span>
				<button
					type="button"
					className="be-st-button be-st-button--quiet"
					disabled={ page * 24 >= total }
					onClick={ () => setPage( ( p ) => p + 1 ) }
				>
					{ __( 'Next', 'beyond-elysium' ) }
				</button>
			</div>
		</div>
	);
}

export default PlotList;
