/**
 * ST tool for adding rumors to a chronicle: either generated automatically from existing game data for a chosen date,
 * or written by hand with an optional parent plot.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { everyPage } from '../../lib/everyPage';
import HtmlEditor from '../shared/HtmlEditor';
import type { GenerateRumorsResponse, Plot } from '../../types/plot';
import HelpButton from '../shared/HelpButton';
import './RumorPanel.css';

export interface RumorPanelProps {
	gameSlug: string;
	/**
	 * Pre-fills the parent when opened from inside a plot's own scroll.
	 */
	defaultParentPlotId?: number;
}

/**
 * Renders two ways to add a rumor.
 */
export function RumorPanel( {
	gameSlug,
	defaultParentPlotId,
}: RumorPanelProps ) {
	const [ gameDate, setGameDate ] = useState( '' );
	const [ result, setResult ] = useState< GenerateRumorsResponse | null >(
		null
	);
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	const [ plots, setPlots ] = useState< Plot[] >( [] );
	const [ newTitle, setNewTitle ] = useState( '' );
	const newDescriptionDraft = useRef( '' );
	const newDescriptionId = `be-rumor-description-${ gameSlug }`;
	const [ newParentId, setNewParentId ] = useState< number | '' >(
		defaultParentPlotId ?? ''
	);
	const [ creating, setCreating ] = useState( false );
	const [ createError, setCreateError ] = useState< string | null >( null );
	const [ createdRumor, setCreatedRumor ] = useState< Plot | null >( null );

	useEffect( () => {
		// Any existing plot is a valid parent for a rumor.
		everyPage( ( page ) =>
			api.plots( gameSlug ).listPaginated( { page, per_page: 100 } )
		)
			.then( setPlots )
			.catch( () => setPlots( [] ) );
	}, [ gameSlug ] );

	/**
	 * Requests a preview of the rumors the generator would create for the selected game date, without committing them.
	 */
	async function preview( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! gameDate ) {
			return;
		}
		setLoading( true );
		setError( null );
		try {
			setResult( await api.plots( gameSlug ).generateRumors( gameDate ) );
		} catch {
			setError(
				__(
					'Failed to preview rumors for this date.',
					'beyond-elysium'
				)
			);
		} finally {
			setLoading( false );
		}
	}

	/**
	 * Requests the same generated rumors as the preview, this time committing them to the chronicle.
	 */
	async function commit() {
		if ( ! gameDate ) {
			return;
		}
		setLoading( true );
		setError( null );
		try {
			setResult(
				await api.plots( gameSlug ).generateRumors( gameDate, true )
			);
		} catch {
			setError(
				__( 'Failed to commit rumors for this date.', 'beyond-elysium' )
			);
		} finally {
			setLoading( false );
		}
	}

	/**
	 * Submits the hand-written rumor form.
	 */
	async function createRumor( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! newTitle.trim() ) {
			return;
		}
		setCreating( true );
		setCreateError( null );
		try {
			const rumor = await api.plots( gameSlug ).create( {
				title: newTitle.trim(),
				description: newDescriptionDraft.current.trim() || undefined,
				parent_plot_id: newParentId || undefined,
				is_rumor: true,
			} );
			setCreatedRumor( rumor );
			setNewTitle( '' );
			newDescriptionDraft.current = '';
			// The form stays mounted for writing another rumor.
			tinymce?.get( newDescriptionId )?.setContent( '' );
		} catch {
			setCreateError(
				__( 'Failed to create this rumor.', 'beyond-elysium' )
			);
		} finally {
			setCreating( false );
		}
	}

	return (
		<div className="be-rumor-panel be-st-modal-body">
			<div className="be-help-heading">
				<HelpButton helpKey="rumors" />
			</div>
			<section>
				<h4 className="be-rumor-panel__heading">
					{ __( 'Write one by hand', 'beyond-elysium' ) }
				</h4>
				<form onSubmit={ createRumor } className="be-rumor-panel__form">
					<input
						type="text"
						placeholder={ __( 'Rumor title…', 'beyond-elysium' ) }
						value={ newTitle }
						onChange={ ( e ) => setNewTitle( e.target.value ) }
					/>
					<HtmlEditor
						id={ newDescriptionId }
						defaultValue=""
						onChange={ ( html ) => {
							newDescriptionDraft.current = html;
						} }
						rows={ 4 }
						aiAssist={ {
							capability: 'be_manage_plots',
							fieldContext: 'rumor_description',
							gameSlug,
						} }
					/>
					<select
						value={ newParentId }
						onChange={ ( e ) =>
							setNewParentId(
								e.target.value ? Number( e.target.value ) : ''
							)
						}
						aria-label={ __(
							'Parent plot or action (optional)',
							'beyond-elysium'
						) }
					>
						<option value="">
							{ __( 'No parent', 'beyond-elysium' ) }
						</option>
						{ plots.map( ( p ) => (
							<option key={ p.id } value={ p.id }>
								{ p.title }
							</option>
						) ) }
					</select>
					<button
						type="submit"
						className="be-st-button"
						disabled={ creating || ! newTitle.trim() }
					>
						{ __( 'Add rumor', 'beyond-elysium' ) }
					</button>
				</form>
				{ createError && (
					<div className="be-plot-manager__error" role="alert">
						{ createError }
					</div>
				) }
				{ createdRumor && (
					<p className="be-rumor-panel__ok">
						{ sprintf(
							/* translators: %s: the title of the rumor just added */
							__( 'Added “%s”.', 'beyond-elysium' ),
							createdRumor.title
						) }
					</p>
				) }
			</section>

			<section>
				<h4 className="be-rumor-panel__heading">
					{ __( 'Generate from chronicle data', 'beyond-elysium' ) }
				</h4>
				<form
					onSubmit={ preview }
					className="be-rumor-panel__form be-rumor-panel__form--inline"
				>
					<input
						type="date"
						value={ gameDate }
						onChange={ ( e ) => setGameDate( e.target.value ) }
					/>
					<button
						type="submit"
						className="be-st-button"
						disabled={ loading || ! gameDate }
					>
						{ __( 'Preview', 'beyond-elysium' ) }
					</button>
				</form>

				{ error && (
					<div className="be-plot-manager__error" role="alert">
						{ error }
					</div>
				) }

				{ result && (
					<>
						{ result.rumors.length === 0 ? (
							<p className="be-rumor-panel__none">
								{ __(
									'Nothing new for this date - every title the generator would produce already exists. Write one by hand above if you need another.',
									'beyond-elysium'
								) }
							</p>
						) : (
							<ul className="be-rumor-panel__items">
								{ result.rumors.map( ( rumor ) => (
									<li key={ rumor.title }>
										<strong>{ rumor.title }</strong>{ ' ' }
										<span className="be-st-badge">
											{ rumor.category }
										</span>{ ' ' }
										<span>
											{ sprintf(
												/* translators: %d: number of players who will receive this rumor */
												_n(
													'%d recipient',
													'%d recipients',
													rumor.recipient_count,
													'beyond-elysium'
												),
												rumor.recipient_count
											) }
										</span>
									</li>
								) ) }
							</ul>
						) }
						{ ! result.committed && result.rumors.length > 0 && (
							<button
								type="button"
								className="be-st-button"
								onClick={ commit }
								disabled={ loading }
							>
								{ sprintf(
									/* translators: %d: number of generated rumors that will be committed */
									__( 'Commit %d', 'beyond-elysium' ),
									result.rumors.length
								) }
							</button>
						) }
						{ result.committed && (
							<p className="be-rumor-panel__ok">
								{ __( 'Committed.', 'beyond-elysium' ) }
							</p>
						) }
					</>
				) }
			</section>
		</div>
	);
}

export default RumorPanel;
