/**
 * Storyteller Toolkit: release batches (1.1.0 §3.2) - scheduling rumors and downtime
 * answers to go out together, several between games, rather than the instant a
 * Storyteller writes them. Mounted from both the front-end toolkit page and the wp-admin
 * Plots hub's Releases tab.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import { canIn } from '../../lib/chronicleCapabilities';
import { errorMessage } from '../../lib/errorMessage';
import type { MyCapabilities } from '../../types';
import type { ReleaseBatch, ReleaseBatchItems } from '../../types/releaseBatch';
import type { ReleaseScheduleRule } from '../../types/session';
import HelpButton from '../shared/HelpButton';
import './ReleaseBatches.css';

const WEEKDAYS: { value: string; label: string }[] = [
	{ value: 'sunday', label: __( 'Sunday', 'beyond-elysium' ) },
	{ value: 'monday', label: __( 'Monday', 'beyond-elysium' ) },
	{ value: 'tuesday', label: __( 'Tuesday', 'beyond-elysium' ) },
	{ value: 'wednesday', label: __( 'Wednesday', 'beyond-elysium' ) },
	{ value: 'thursday', label: __( 'Thursday', 'beyond-elysium' ) },
	{ value: 'friday', label: __( 'Friday', 'beyond-elysium' ) },
	{ value: 'saturday', label: __( 'Saturday', 'beyond-elysium' ) },
];

export interface ReleaseBatchesProps {
	gameSlug: string;
	capabilities?: MyCapabilities;
}

/** "Scheduled Fri 7:00pm" - the badge/list label for a batch's release time. */
function formatReleaseAt( releaseAt: string | null ): string {
	if ( ! releaseAt ) {
		return '';
	}
	const date = new Date( releaseAt.replace( ' ', 'T' ) );
	if ( Number.isNaN( date.getTime() ) ) {
		return releaseAt;
	}
	return date.toLocaleString( undefined, {
		weekday: 'short',
		hour: 'numeric',
		minute: '2-digit',
	} );
}

/** Converts a <input type="datetime-local"> value ("2026-09-20T17:00") to a MySQL datetime. */
function toMysqlDatetime( localValue: string ): string {
	return localValue.length === 16
		? `${ localValue.replace( 'T', ' ' ) }:00`
		: localValue.replace( 'T', ' ' );
}

export function ReleaseBatches( {
	gameSlug,
	capabilities,
}: ReleaseBatchesProps ) {
	const canManage = canIn( 'be_manage_plots', capabilities );

	const [ batches, setBatches ] = useState< ReleaseBatch[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

	const [ selected, setSelected ] = useState< ReleaseBatch | null >( null );
	const [ items, setItems ] = useState< ReleaseBatchItems | null >( null );
	const [ itemsLoading, setItemsLoading ] = useState( false );
	const [ busy, setBusy ] = useState( false );

	const [ showNewForm, setShowNewForm ] = useState( false );
	const [ newName, setNewName ] = useState( '' );
	const [ newReleaseAt, setNewReleaseAt ] = useState( '' );
	const [ creating, setCreating ] = useState( false );
	const [ createError, setCreateError ] = useState< string | null >( null );

	const [ rules, setRules ] = useState< ReleaseScheduleRule[] >( [] );
	const [ scheduleOpen, setScheduleOpen ] = useState( false );
	const [ savingSchedule, setSavingSchedule ] = useState( false );
	const [ scheduleError, setScheduleError ] = useState< string | null >(
		null
	);

	useEffect( () => {
		api.games
			.get( gameSlug )
			.then( ( game ) => {
				const settings = game.settings as {
					release_schedule?: { rules?: ReleaseScheduleRule[] };
				} | null;
				setRules( settings?.release_schedule?.rules ?? [] );
			} )
			.catch( () => setRules( [] ) );
	}, [ gameSlug ] );

	async function saveSchedule( nextRules: ReleaseScheduleRule[] ) {
		setSavingSchedule( true );
		setScheduleError( null );
		try {
			const result = await api
				.sessions( gameSlug )
				.updateSettings( { release_schedule: { rules: nextRules } } );
			setRules( result.release_schedule.rules );
		} catch ( err ) {
			setScheduleError(
				errorMessage(
					err,
					__(
						'Failed to save the release schedule.',
						'beyond-elysium'
					)
				)
			);
		} finally {
			setSavingSchedule( false );
		}
	}

	function addRule( type: 'weekly' | 'monthly' ) {
		const rule: ReleaseScheduleRule =
			type === 'weekly'
				? { type: 'weekly', weekday: 'friday', time: '18:00' }
				: { type: 'monthly', day_of_month: 1, time: '09:00' };
		saveSchedule( [ ...rules, rule ] );
	}

	function removeRule( index: number ) {
		saveSchedule( rules.filter( ( _, i ) => i !== index ) );
	}

	function updateRule(
		index: number,
		change: Partial< ReleaseScheduleRule >
	) {
		saveSchedule(
			rules.map( ( rule, i ) =>
				i === index ? { ...rule, ...change } : rule
			)
		);
	}

	function load() {
		setLoading( true );
		setError( null );
		api.releaseBatches( gameSlug )
			.list()
			.then( ( result ) => {
				setBatches( result );
				setLoading( false );
			} )
			.catch( () => {
				setError(
					__( 'Failed to load release batches.', 'beyond-elysium' )
				);
				setLoading( false );
			} );
	}

	useEffect( load, [ gameSlug ] ); // eslint-disable-line react-hooks/exhaustive-deps

	function loadItems( batch: ReleaseBatch ) {
		setItemsLoading( true );
		api.releaseBatches( gameSlug )
			.getItems( batch.id )
			.then( ( result ) => {
				setItems( result );
				setItemsLoading( false );
			} )
			.catch( () => {
				setError(
					__( 'Failed to load this batch’s items.', 'beyond-elysium' )
				);
				setItemsLoading( false );
			} );
	}

	function selectBatch( batch: ReleaseBatch ) {
		setSelected( batch );
		loadItems( batch );
	}

	if ( ! canManage ) {
		return (
			<div className="be-release-batches">
				<div className="be-release-batches__denied">
					<h2>{ __( 'Storytellers only', 'beyond-elysium' ) }</h2>
					<p>
						{ __(
							'Release batches are run by your Storytellers and Narrators.',
							'beyond-elysium'
						) }
					</p>
				</div>
			</div>
		);
	}

	async function createBatch( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! newName ) {
			return;
		}
		setCreating( true );
		setCreateError( null );
		try {
			const batch = await api.releaseBatches( gameSlug ).create( {
				name: newName,
				release_at: newReleaseAt
					? toMysqlDatetime( newReleaseAt )
					: undefined,
			} );
			setNewName( '' );
			setNewReleaseAt( '' );
			setShowNewForm( false );
			load();
			selectBatch( batch );
		} catch ( err ) {
			setCreateError(
				errorMessage(
					err,
					__(
						'Failed to create this release batch.',
						'beyond-elysium'
					)
				)
			);
		} finally {
			setCreating( false );
		}
	}

	async function deleteBatch( batch: ReleaseBatch ) {
		// eslint-disable-next-line no-alert
		if (
			! window.confirm(
				__(
					'Delete this batch? Its items return to draft, hidden until added to another batch.',
					'beyond-elysium'
				)
			)
		) {
			return;
		}
		setBusy( true );
		try {
			await api.releaseBatches( gameSlug ).delete( batch.id );
			setSelected( null );
			setItems( null );
			load();
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__(
						'Failed to delete this release batch.',
						'beyond-elysium'
					)
				)
			);
		} finally {
			setBusy( false );
		}
	}

	async function unscheduleBatch( batch: ReleaseBatch ) {
		setBusy( true );
		try {
			const updated = await api
				.releaseBatches( gameSlug )
				.update( batch.id, { status: 'draft' } );
			setSelected( updated );
			load();
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__( 'Failed to unschedule this batch.', 'beyond-elysium' )
				)
			);
		} finally {
			setBusy( false );
		}
	}

	async function releaseNow( batch: ReleaseBatch ) {
		// eslint-disable-next-line no-alert
		if (
			! window.confirm(
				__(
					'Players will see these and get an email. Release now?',
					'beyond-elysium'
				)
			)
		) {
			return;
		}
		setBusy( true );
		try {
			const released = await api
				.releaseBatches( gameSlug )
				.releaseNow( batch.id );
			setSelected( released );
			load();
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__( 'Failed to release this batch.', 'beyond-elysium' )
				)
			);
		} finally {
			setBusy( false );
		}
	}

	async function removeItem( type: 'plot' | 'entry', itemId: number ) {
		if ( ! selected ) {
			return;
		}
		setBusy( true );
		try {
			await api
				.releaseBatches( gameSlug )
				.removeItem( selected.id, type, itemId );
			loadItems( selected );
			load();
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__( 'Failed to remove this item.', 'beyond-elysium' )
				)
			);
		} finally {
			setBusy( false );
		}
	}

	async function moveItem(
		type: 'plot' | 'entry',
		itemId: number,
		targetBatchId: number
	) {
		if ( ! selected || targetBatchId === selected.id ) {
			return;
		}
		setBusy( true );
		try {
			await api
				.releaseBatches( gameSlug )
				.removeItem( selected.id, type, itemId );
			await api
				.releaseBatches( gameSlug )
				.addItem( targetBatchId, type, itemId );
			loadItems( selected );
			load();
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__( 'Failed to move this item.', 'beyond-elysium' )
				)
			);
		} finally {
			setBusy( false );
		}
	}

	const drafts = batches.filter( ( b ) => b.status === 'draft' );
	const scheduled = batches.filter( ( b ) => b.status === 'scheduled' );
	const released = batches.filter( ( b ) => b.status === 'released' );
	const moveTargets = batches.filter(
		( b ) => b.status !== 'released' && b.id !== selected?.id
	);

	function renderBatchRow( batch: ReleaseBatch ) {
		return (
			<li key={ batch.id }>
				<button
					type="button"
					className="be-release-batches__batch-card"
					onClick={ () => selectBatch( batch ) }
				>
					<strong>{ batch.name }</strong>
					{ batch.status === 'scheduled' && batch.release_at && (
						<span className="be-st-badge">
							{ sprintf(
								/* translators: %s: formatted date/time */
								__( 'Scheduled %s', 'beyond-elysium' ),
								formatReleaseAt( batch.release_at )
							) }
						</span>
					) }
					{ batch.status === 'released' && (
						<span className="be-st-badge">
							{ __( 'Released', 'beyond-elysium' ) }
						</span>
					) }
					<span className="be-release-batches__counts">
						{ sprintf(
							/* translators: 1: rumor count, 2: downtime answer count */
							__( '%1$d rumors, %2$d answers', 'beyond-elysium' ),
							batch.rumor_count,
							batch.entry_count
						) }
					</span>
				</button>
			</li>
		);
	}

	return (
		<div className="be-release-batches">
			<header className="be-release-batches__header">
				<div className="be-help-heading">
					<h2>{ __( 'Releases', 'beyond-elysium' ) }</h2>
					<HelpButton helpKey="release-batches" />
				</div>
				{ selected && (
					<nav className="be-release-batches__crumbs">
						<button
							type="button"
							onClick={ () => {
								setSelected( null );
								setItems( null );
							} }
						>
							{ __( 'All batches', 'beyond-elysium' ) }
						</button>
						<span>/</span>
						<span>{ selected.name }</span>
					</nav>
				) }
			</header>

			{ error && (
				<div className="be-release-batches__error" role="alert">
					{ error }
				</div>
			) }

			{ ! selected && (
				<div className="be-release-batches__schedule">
					<button
						type="button"
						className="be-release-batches__schedule-toggle"
						onClick={ () => setScheduleOpen( ! scheduleOpen ) }
						aria-expanded={ scheduleOpen }
					>
						{ sprintf(
							/* translators: %d: number of recurring release-schedule rules */
							__( 'Release schedule (%d)', 'beyond-elysium' ),
							rules.length
						) }
					</button>
					{ scheduleOpen && (
						<div className="be-release-batches__schedule-body">
							<p>
								{ __(
									"This controls when, not what: on the day(s) below, every batch you've left as a draft goes out as-is. Nothing is created automatically - prepare a draft batch below whenever it's ready, and the schedule releases it for you.",
									'beyond-elysium'
								) }
							</p>
							{ scheduleError && (
								<div
									className="be-release-batches__error"
									role="alert"
								>
									{ scheduleError }
								</div>
							) }
							<ul className="be-release-batches__schedule-list">
								{ rules.map( ( rule, i ) => (
									<li key={ i }>
										<span className="be-st-badge">
											{ rule.type === 'weekly'
												? __(
														'Weekly',
														'beyond-elysium'
												  )
												: __(
														'Monthly',
														'beyond-elysium'
												  ) }
										</span>
										{ rule.type === 'weekly' ? (
											<select
												value={ rule.weekday }
												disabled={ savingSchedule }
												onChange={ ( e ) =>
													updateRule( i, {
														weekday: e.target.value,
													} )
												}
												aria-label={ __(
													'Weekday',
													'beyond-elysium'
												) }
											>
												{ WEEKDAYS.map( ( w ) => (
													<option
														key={ w.value }
														value={ w.value }
													>
														{ w.label }
													</option>
												) ) }
											</select>
										) : (
											<select
												value={ rule.day_of_month }
												disabled={ savingSchedule }
												onChange={ ( e ) =>
													updateRule( i, {
														day_of_month: Number(
															e.target.value
														),
													} )
												}
												aria-label={ __(
													'Day of the month',
													'beyond-elysium'
												) }
											>
												{ Array.from(
													{ length: 28 },
													( _, n ) => n + 1
												).map( ( d ) => (
													<option
														key={ d }
														value={ d }
													>
														{ d }
													</option>
												) ) }
											</select>
										) }
										<input
											type="time"
											value={ rule.time }
											disabled={ savingSchedule }
											onChange={ ( e ) =>
												updateRule( i, {
													time: e.target.value,
												} )
											}
											aria-label={ __(
												'Time of day',
												'beyond-elysium'
											) }
										/>
										<button
											type="button"
											disabled={ savingSchedule }
											onClick={ () => removeRule( i ) }
										>
											{ __( 'Remove', 'beyond-elysium' ) }
										</button>
									</li>
								) ) }
								{ rules.length === 0 && (
									<li>
										{ __(
											'On demand only - no recurring schedule set.',
											'beyond-elysium'
										) }
									</li>
								) }
							</ul>
							<div className="be-release-batches__schedule-add">
								<button
									type="button"
									disabled={ savingSchedule }
									onClick={ () => addRule( 'weekly' ) }
								>
									{ __(
										'+ Add a weekly rule',
										'beyond-elysium'
									) }
								</button>
								<button
									type="button"
									disabled={ savingSchedule }
									onClick={ () => addRule( 'monthly' ) }
								>
									{ __(
										'+ Add a monthly rule',
										'beyond-elysium'
									) }
								</button>
							</div>
						</div>
					) }
				</div>
			) }

			{ ! selected && (
				<>
					{ showNewForm ? (
						<form
							className="be-release-batches__new-form"
							onSubmit={ createBatch }
						>
							<input
								type="text"
								placeholder={ __(
									'Batch name…',
									'beyond-elysium'
								) }
								value={ newName }
								onChange={ ( e ) =>
									setNewName( e.target.value )
								}
								aria-label={ __(
									'Batch name',
									'beyond-elysium'
								) }
								required
							/>
							<input
								type="datetime-local"
								value={ newReleaseAt }
								onChange={ ( e ) =>
									setNewReleaseAt( e.target.value )
								}
								aria-label={ __(
									'Release at (leave blank to save as a draft)',
									'beyond-elysium'
								) }
							/>
							{ createError && (
								<div
									className="be-release-batches__error"
									role="alert"
								>
									{ createError }
								</div>
							) }
							<div className="be-release-batches__new-form-actions">
								<button
									type="submit"
									className="be-st-button"
									disabled={ creating || ! newName }
								>
									{ __( 'Create batch', 'beyond-elysium' ) }
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
						<button
							type="button"
							className="be-st-button"
							onClick={ () => setShowNewForm( true ) }
						>
							{ __( '+ New batch', 'beyond-elysium' ) }
						</button>
					) }

					{ loading ? (
						<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
					) : batches.length === 0 ? (
						<p>
							{ __(
								'No release batches yet.',
								'beyond-elysium'
							) }
						</p>
					) : (
						<>
							<section className="be-release-batches__section">
								<h3>{ __( 'Scheduled', 'beyond-elysium' ) }</h3>
								{ scheduled.length === 0 ? (
									<p>{ __( 'None.', 'beyond-elysium' ) }</p>
								) : (
									<ul className="be-release-batches__list">
										{ scheduled.map( renderBatchRow ) }
									</ul>
								) }
							</section>
							<section className="be-release-batches__section">
								<h3>{ __( 'Draft', 'beyond-elysium' ) }</h3>
								{ drafts.length === 0 ? (
									<p>{ __( 'None.', 'beyond-elysium' ) }</p>
								) : (
									<ul className="be-release-batches__list">
										{ drafts.map( renderBatchRow ) }
									</ul>
								) }
							</section>
							<section className="be-release-batches__section">
								<h3>{ __( 'Released', 'beyond-elysium' ) }</h3>
								{ released.length === 0 ? (
									<p>{ __( 'None.', 'beyond-elysium' ) }</p>
								) : (
									<ul className="be-release-batches__list">
										{ released.map( renderBatchRow ) }
									</ul>
								) }
							</section>
						</>
					) }
				</>
			) }

			{ selected && (
				<div className="be-release-batches__detail">
					<div className="be-release-batches__actions">
						{ selected.status === 'draft' && (
							<button
								type="button"
								className="be-st-button be-st-button--quiet"
								disabled={ busy }
								onClick={ () => deleteBatch( selected ) }
							>
								{ __( 'Delete batch', 'beyond-elysium' ) }
							</button>
						) }
						{ selected.status === 'scheduled' && (
							<button
								type="button"
								className="be-st-button be-st-button--quiet"
								disabled={ busy }
								onClick={ () => unscheduleBatch( selected ) }
							>
								{ __(
									'Unschedule (back to draft)',
									'beyond-elysium'
								) }
							</button>
						) }
						{ selected.status !== 'released' && (
							<button
								type="button"
								className="be-st-button"
								disabled={ busy }
								onClick={ () => releaseNow( selected ) }
							>
								{ __( 'Release now', 'beyond-elysium' ) }
							</button>
						) }
					</div>

					{ itemsLoading || ! items ? (
						<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
					) : (
						<>
							<h3>{ __( 'Rumors', 'beyond-elysium' ) }</h3>
							{ items.rumors.length === 0 ? (
								<p>
									{ __(
										'No rumors in this batch.',
										'beyond-elysium'
									) }
								</p>
							) : (
								<ul className="be-release-batches__item-list">
									{ items.rumors.map( ( item ) => (
										<li key={ `plot-${ item.id }` }>
											<span>{ item.title }</span>
											{ selected.status !==
												'released' && (
												<div className="be-release-batches__item-actions">
													{ moveTargets.length >
														0 && (
														<select
															aria-label={ __(
																'Move to another batch',
																'beyond-elysium'
															) }
															value={
																selected.id
															}
															disabled={ busy }
															onChange={ ( e ) =>
																moveItem(
																	'plot',
																	item.id,
																	Number(
																		e.target
																			.value
																	)
																)
															}
														>
															<option
																value={
																	selected.id
																}
															>
																{
																	selected.name
																}
															</option>
															{ moveTargets.map(
																( b ) => (
																	<option
																		key={
																			b.id
																		}
																		value={
																			b.id
																		}
																	>
																		{
																			b.name
																		}
																	</option>
																)
															) }
														</select>
													) }
													<button
														type="button"
														className="be-st-button be-st-button--quiet"
														disabled={ busy }
														onClick={ () =>
															removeItem(
																'plot',
																item.id
															)
														}
													>
														{ __(
															'Remove',
															'beyond-elysium'
														) }
													</button>
												</div>
											) }
										</li>
									) ) }
								</ul>
							) }

							<h3>
								{ __( 'Downtime answers', 'beyond-elysium' ) }
							</h3>
							{ items.entries.length === 0 ? (
								<p>
									{ __(
										'No downtime answers in this batch.',
										'beyond-elysium'
									) }
								</p>
							) : (
								<ul className="be-release-batches__item-list">
									{ items.entries.map( ( item ) => (
										<li key={ `entry-${ item.id }` }>
											<span>
												{ item.plot_title }
												{ item.content
													? ` — ${ item.content }`
													: '' }
											</span>
											{ selected.status !==
												'released' && (
												<div className="be-release-batches__item-actions">
													{ moveTargets.length >
														0 && (
														<select
															aria-label={ __(
																'Move to another batch',
																'beyond-elysium'
															) }
															value={
																selected.id
															}
															disabled={ busy }
															onChange={ ( e ) =>
																moveItem(
																	'entry',
																	item.id,
																	Number(
																		e.target
																			.value
																	)
																)
															}
														>
															<option
																value={
																	selected.id
																}
															>
																{
																	selected.name
																}
															</option>
															{ moveTargets.map(
																( b ) => (
																	<option
																		key={
																			b.id
																		}
																		value={
																			b.id
																		}
																	>
																		{
																			b.name
																		}
																	</option>
																)
															) }
														</select>
													) }
													<button
														type="button"
														className="be-st-button be-st-button--quiet"
														disabled={ busy }
														onClick={ () =>
															removeItem(
																'entry',
																item.id
															)
														}
													>
														{ __(
															'Remove',
															'beyond-elysium'
														) }
													</button>
												</div>
											) }
										</li>
									) ) }
								</ul>
							) }
						</>
					) }
				</div>
			) }
		</div>
	);
}

export default ReleaseBatches;
