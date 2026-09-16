/**
 * Storyteller tool for allocating game-night actions to a character. Presents a form to
 * pick a character and game date, requests the computed subaction allocation from the
 * server, and displays it in a preview table with a commit action. Can pre-fill a parent
 * plot so a committed action lands directly under it.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { everyPage } from '../../lib/everyPage';
import type { Character } from '../../types/character';
import type { Plot, Subaction } from '../../types/plot';
import { BackgroundLedger } from './BackgroundLedger';
import HelpButton from '../shared/HelpButton';
import './ActionAllocator.css';

export interface ActionAllocatorProps {
	gameSlug: string;
	/** Pre-fills the parent plot when opened from inside a plot's own scroll. */
	defaultParentPlotId?: number;
}

/**
 * Lets a Storyteller choose a character and game date, preview the resulting action
 * allocation, and commit it as a plot entry. The allocation totals are computed entirely
 * server-side from the character's traits and game configuration; this component only
 * displays the preview and sends the commit request, it does not allow editing individual
 * subaction totals before saving.
 */
export function ActionAllocator( {
	gameSlug,
	defaultParentPlotId,
}: ActionAllocatorProps ) {
	const [ characters, setCharacters ] = useState< Character[] >( [] );
	const [ characterId, setCharacterId ] = useState< number | '' >( '' );
	const [ gameDate, setGameDate ] = useState( '' );
	const [ topLevelPlots, setTopLevelPlots ] = useState< Plot[] >( [] );
	const [ parentPlotId, setParentPlotId ] = useState< number | '' >(
		defaultParentPlotId ?? ''
	);
	const [ subactions, setSubactions ] = useState< Subaction[] | null >(
		null
	);
	const [ committedPlotId, setCommittedPlotId ] = useState< number | null >(
		null
	);
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

	useEffect( () => {
		// Every page, not the first 100 (1.0.0-review F-080).
		everyPage( ( page ) =>
			api.characters( gameSlug ).listPaginated( { page, per_page: 100 } )
		)
			.then( setCharacters )
			.catch( () => {
				setCharacters( [] );
				setError(
					__( 'Failed to load the character list.', 'beyond-elysium' )
				);
			} );
	}, [ gameSlug ] );

	useEffect( () => {
		// Only top-level plots are offered as a parent option.
		everyPage( ( page ) =>
			api.plots( gameSlug ).listPaginated( { page, per_page: 100 } )
		)
			.then( ( items ) =>
				setTopLevelPlots( items.filter( ( p ) => ! p.parent_plot_id ) )
			)
			.catch( () => setTopLevelPlots( [] ) );
	}, [ gameSlug ] );

	/**
	 * Submits the preview form. Requests the computed action allocation for the selected
	 * character and game date from the API without committing it, and stores the result
	 * in the preview table.
	 */
	async function preview( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! characterId || ! gameDate ) {
			return;
		}
		setLoading( true );
		setError( null );
		setCommittedPlotId( null );
		try {
			const result = await api
				.plots( gameSlug )
				.allocateActions( characterId, gameDate );
			setSubactions( result.subactions );
		} catch {
			setError(
				__( 'Failed to compute this allocation.', 'beyond-elysium' )
			);
		} finally {
			setLoading( false );
		}
	}

	/**
	 * Commits the previewed action allocation for the selected character and game date,
	 * optionally under the selected parent plot. Stores the resulting subactions and the
	 * new plot's id so the form can show a committed confirmation.
	 */
	async function commit() {
		if ( ! characterId || ! gameDate ) {
			return;
		}
		setLoading( true );
		setError( null );
		try {
			const result = await api
				.plots( gameSlug )
				.allocateActions(
					characterId,
					gameDate,
					true,
					parentPlotId || undefined
				);
			setSubactions( result.subactions );
			setCommittedPlotId( result.plot_id ?? null );
		} catch {
			setError(
				__( 'Failed to commit this allocation.', 'beyond-elysium' )
			);
		} finally {
			setLoading( false );
		}
	}

	return (
		<div className="be-action-allocator">
			<div className="be-help-heading">
				<HelpButton helpKey="allocate-actions" />
			</div>
			<form className="be-action-allocator__form" onSubmit={ preview }>
				<select
					value={ characterId }
					onChange={ ( e ) =>
						setCharacterId(
							e.target.value ? Number( e.target.value ) : ''
						)
					}
				>
					<option value="">
						{ __( 'Select a character…', 'beyond-elysium' ) }
					</option>
					{ characters.map( ( c ) => (
						<option key={ c.id } value={ c.id }>
							{ c.name }
						</option>
					) ) }
				</select>
				<input
					type="date"
					value={ gameDate }
					onChange={ ( e ) => setGameDate( e.target.value ) }
				/>
				<select
					value={ parentPlotId }
					onChange={ ( e ) =>
						setParentPlotId(
							e.target.value ? Number( e.target.value ) : ''
						)
					}
					aria-label={ __(
						'Parent plot (optional)',
						'beyond-elysium'
					) }
				>
					<option value="">
						{ __( 'No parent plot', 'beyond-elysium' ) }
					</option>
					{ topLevelPlots.map( ( p ) => (
						<option key={ p.id } value={ p.id }>
							{ p.title }
						</option>
					) ) }
				</select>
				<button
					type="submit"
					disabled={ loading || ! characterId || ! gameDate }
				>
					{ __( 'Preview', 'beyond-elysium' ) }
				</button>
			</form>

			{ error && (
				<div className="be-action-allocator__error" role="alert">
					{ error }
				</div>
			) }

			{ subactions && (
				<>
					<div className="be-table-box">
						<table className="be-action-allocator__table be-responsive-table">
							<thead>
								<tr>
									<th>
										{ __( 'Subaction', 'beyond-elysium' ) }
									</th>
									<th>{ __( 'Level', 'beyond-elysium' ) }</th>
									<th>{ __( 'Total', 'beyond-elysium' ) }</th>
									<th>
										{ __( 'Unused', 'beyond-elysium' ) }
									</th>
									<th>
										{ __( 'Growth', 'beyond-elysium' ) }
									</th>
								</tr>
							</thead>
							<tbody>
								{ subactions.map( ( s ) => (
									<tr
										key={ s.name }
										className={
											s.over_budget
												? 'is-over-budget'
												: ''
										}
									>
										<td
											data-label={ __(
												'Subaction',
												'beyond-elysium'
											) }
										>
											{ s.name }
										</td>
										<td
											data-label={ __(
												'Level',
												'beyond-elysium'
											) }
										>
											{ s.level }
										</td>
										<td
											data-label={ __(
												'Total',
												'beyond-elysium'
											) }
										>
											{ s.total }
										</td>
										<td
											data-label={ __(
												'Unused',
												'beyond-elysium'
											) }
										>
											{ s.unused }
										</td>
										<td
											data-label={ __(
												'Growth',
												'beyond-elysium'
											) }
										>
											{ s.growth }
										</td>
									</tr>
								) ) }
							</tbody>
						</table>
					</div>

					{ committedPlotId ? (
						<>
							<p className="be-action-allocator__committed">
								{ sprintf(
									/* translators: %d: the numeric id of the plot this allocation was committed as */
									__(
										'Committed as plot #%d.',
										'beyond-elysium'
									),
									committedPlotId
								) }
							</p>
							<BackgroundLedger
								gameSlug={ gameSlug }
								characterId={ characterId as number }
								gameDate={ gameDate }
								subactions={ subactions }
								canManage
							/>
						</>
					) : (
						<button
							type="button"
							onClick={ commit }
							disabled={ loading }
						>
							{ __( 'Commit', 'beyond-elysium' ) }
						</button>
					) }
				</>
			) }
		</div>
	);
}

export default ActionAllocator;
