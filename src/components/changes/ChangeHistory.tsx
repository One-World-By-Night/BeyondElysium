/**
 * A character's change history read as orders: each set sent together under its own head, every line with its
 * status, price, notes and, while it waits, a way to withdraw it.
 */
import { useEffect, useMemo, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import Modal from '../shared/Modal';
import { activeRequest, requestParts } from '../../lib/coordinatorSend';
import { errorMessage } from '../../lib/errorMessage';
import {
	describeChange,
	describeChangeCost,
	describeChangeDetail,
} from '../../lib/describeChange';
import {
	filterChanges,
	groupOrders,
	lineStatus,
	lineStatusLabel,
	orderHead,
	orderSummary,
	reviewerNote,
	undoableCount,
	undoQuestion,
	undoSetQuestion,
	waitingCount,
	type HistoryFilter,
	type HistoryOrder,
} from '../../lib/historyOrders';
import { isCostPending } from '../../lib/queuePrice';
import type { CharacterChange } from '../../types/character';
import './ChangeHistory.css';
import { formatStoredTime } from '../../lib/storedTime';

export interface ChangeHistoryProps {
	characterId: number;
	gameSlug: string;
	/**
	 * Whether the viewer may withdraw a change that is still waiting: the character's own player or a Storyteller.
	 */
	canWithdraw?: boolean;
	/**
	 * Whether the viewer is a Storyteller of the chronicle, who may undo an approved change.
	 */
	canUndo?: boolean;
	/**
	 * The character's name, for the question asked before an undo.
	 */
	characterName?: string;
	/**
	 * Called after a change is withdrawn or undone, so the header can read the character again.
	 */
	onChanged?: () => void;
}

const FILTERS: Array< { key: HistoryFilter; label: string } > = [
	{ key: 'all', label: __( 'All', 'beyond-elysium' ) },
	{ key: 'purchases', label: __( 'Purchases', 'beyond-elysium' ) },
	{ key: 'play', label: __( 'Play', 'beyond-elysium' ) },
];

/**
 * What a change cost or refunded.
 */
function ChangeCost( { cost }: { cost: number | string } ) {
	const text = describeChangeCost( cost );
	return text ? (
		<span className="be-change-history__cost">{ text }</span>
	) : null;
}

/**
 * The entries a catalog update matched, folded away under its one-line description.
 */
function ChangeDetailList( { change }: { change: CharacterChange } ) {
	const lines = describeChangeDetail(
		change.change_type,
		change.change_data
	);
	if ( lines.length === 0 ) {
		return null;
	}
	return (
		<details className="be-change-history__detail">
			<summary>{ __( 'Matched entries', 'beyond-elysium' ) }</summary>
			<ul>
				{ lines.map( ( line, index ) => (
					<li key={ index }>{ line }</li>
				) ) }
			</ul>
		</details>
	);
}

interface LineProps {
	item: CharacterChange;
	canWithdraw: boolean;
	canUndo: boolean;
	busy: boolean;
	onWithdraw: ( id: number ) => void;
	onUndo: ( item: CharacterChange ) => void;
}

/**
 * One line of an order: its status, what it does, what it cost, when it was sent and decided, and any notes.
 */
function HistoryLine( {
	item,
	canWithdraw,
	canUndo,
	busy,
	onWithdraw,
	onUndo,
}: LineProps ) {
	const note = reviewerNote( item );
	return (
		<li
			className={ `be-change-history__row be-change-history__row--${ lineStatus(
				item
			) }` }
		>
			<span className="be-change-history__status">
				{ lineStatusLabel( item ) }
			</span>
			<span className="be-change-history__description">
				{ describeChange( item.change_type, item.change_data ) }
			</span>
			{ item.status === 'pending' && isCostPending( item ) ? (
				<span className="be-change-history__cost">
					{ __( 'Price set on approval', 'beyond-elysium' ) }
				</span>
			) : (
				<ChangeCost cost={ item.xp_cost } />
			) }
			<span className="be-change-history__meta">
				{ sprintf(
					/* translators: %s: date and time the change was submitted */
					__( 'submitted %s', 'beyond-elysium' ),
					formatStoredTime( item.submitted_at, { time: true } )
				) }
				{ item.reviewed_at &&
					' ' +
						sprintf(
							/* translators: %s: date and time the change was reviewed */
							__( '· reviewed %s', 'beyond-elysium' ),
							formatStoredTime( item.reviewed_at, {
								time: true,
							} )
						) }
			</span>
			{ item.notes && (
				<p className="be-change-history__notes">{ item.notes }</p>
			) }
			{ item.status === 'pending' && activeRequest( item ) && (
				<p className="be-change-history__notes">
					{ requestParts( activeRequest( item )! ).join( ' · ' ) }
				</p>
			) }
			{ item.change_data?.st_edit !== undefined && (
				<p className="be-change-history__notes">
					{ __( 'Changed by a Storyteller', 'beyond-elysium' ) }
				</p>
			) }
			{ note && (
				<p className="be-change-history__notes">
					{ sprintf(
						// translators: %s: the Storyteller's note on this change.
						__( 'Storyteller: %s', 'beyond-elysium' ),
						note
					) }
				</p>
			) }
			{ item.status === 'undone' && (
				<p className="be-change-history__notes">
					{ sprintf(
						/* translators: 1: who undid the change, 2: the date they did */
						__( 'Undone by %1$s on %2$s', 'beyond-elysium' ),
						item.undone_by_name ??
							__( 'a Storyteller', 'beyond-elysium' ),
						formatStoredTime( item.undone_at ?? '', {
							time: false,
						} )
					) }
					{ item.undone_note && ` · ${ item.undone_note }` }
				</p>
			) }
			{ canWithdraw &&
				item.status === 'pending' &&
				! activeRequest( item ) && (
					<button
						type="button"
						className="be-change-history__withdraw"
						disabled={ busy }
						onClick={ () => onWithdraw( item.id ) }
					>
						{ __( 'Withdraw', 'beyond-elysium' ) }
					</button>
				) }
			{ canUndo && item.can_undo && (
				<button
					type="button"
					className="be-change-history__withdraw"
					disabled={ busy }
					onClick={ () => onUndo( item ) }
				>
					{ __( 'Undo', 'beyond-elysium' ) }
				</button>
			) }
			<ChangeDetailList change={ item } />
		</li>
	);
}

/**
 * Renders the change history for one character: orders newest first, each with its head and summary, and a filter
 * between all of it, purchases and play.
 */
export function ChangeHistory( {
	characterId,
	gameSlug,
	canWithdraw = false,
	canUndo = false,
	characterName = '',
	onChanged,
}: ChangeHistoryProps ) {
	const [ items, setItems ] = useState< CharacterChange[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ filter, setFilter ] = useState< HistoryFilter >( 'all' );
	const [ busy, setBusy ] = useState( false );
	const [ actionError, setActionError ] = useState< string | null >( null );
	const [ undoing, setUndoing ] = useState<
		| { kind: 'line'; item: CharacterChange }
		| { kind: 'set'; order: HistoryOrder }
		| null
	>( null );
	const [ undoNote, setUndoNote ] = useState( '' );

	const load = () =>
		api
			.changes( gameSlug )
			.list( characterId, { per_page: 100, order: 'DESC' } )
			.then( ( result ) => {
				setItems( result );
				setLoading( false );
			} );

	useEffect( () => {
		setLoading( true );
		setError( null );
		load().catch( () => {
			// Sets an explicit error.
			setError(
				__( 'Failed to load change history.', 'beyond-elysium' )
			);
			setLoading( false );
		} );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ characterId, gameSlug ] );

	const orders = useMemo(
		() => groupOrders( filterChanges( items, filter ) ),
		[ items, filter ]
	);

	/**
	 * Runs a withdrawal, then reads the history and the header again; a refusal reads as the history now stands.
	 */
	const act = (
		run: () => Promise< unknown >,
		failure: string = __(
			'The change could not be withdrawn.',
			'beyond-elysium'
		)
	) => {
		setBusy( true );
		setActionError( null );
		run()
			.then( () => load() )
			.then( () => onChanged?.() )
			.catch( ( err: unknown ) => {
				setActionError( errorMessage( err, failure ) );
				return load().catch( () => undefined );
			} )
			.finally( () => setBusy( false ) );
	};

	const withdraw = ( changeId: number ) =>
		act( () => api.changes( gameSlug ).withdraw( changeId ) );

	const confirmUndo = () => {
		const target = undoing;
		const note = undoNote.trim();
		setUndoing( null );
		setUndoNote( '' );
		if ( target?.kind === 'line' ) {
			act(
				() => api.changes( gameSlug ).undo( target.item.id, note ),
				__( 'The change could not be undone.', 'beyond-elysium' )
			);
		} else if ( target?.kind === 'set' && target.order.submissionId ) {
			act(
				() =>
					api
						.changes( gameSlug )
						.undoSet(
							characterId,
							target.order.submissionId as string,
							note
						),
				__( 'The set could not be undone.', 'beyond-elysium' )
			);
		}
	};

	const withdrawAll = ( order: HistoryOrder ) =>
		order.submissionId
			? act( () =>
					api
						.changes( gameSlug )
						.withdrawSet(
							characterId,
							order.submissionId as string
						)
				)
			: undefined;

	if ( loading ) {
		return <p>{ __( 'Loading history…', 'beyond-elysium' ) }</p>;
	}

	if ( error ) {
		return (
			<p className="be-change-history__error" role="alert">
				{ error }
			</p>
		);
	}

	if ( items.length === 0 ) {
		return <p>{ __( 'No changes yet.', 'beyond-elysium' ) }</p>;
	}

	return (
		<>
			<div
				className="be-change-history__filters"
				role="group"
				aria-label={ __( 'Show', 'beyond-elysium' ) }
			>
				{ FILTERS.map( ( choice ) => (
					<button
						type="button"
						key={ choice.key }
						className="be-change-history__filter"
						aria-pressed={ filter === choice.key }
						onClick={ () => setFilter( choice.key ) }
					>
						{ choice.label }
					</button>
				) ) }
			</div>
			{ actionError && (
				<p className="be-change-history__error" role="alert">
					{ actionError }
				</p>
			) }
			{ orders.length === 0 && (
				<p>{ __( 'Nothing of that kind yet.', 'beyond-elysium' ) }</p>
			) }
			<ul className="be-change-history">
				{ orders.map( ( order ) => (
					<li key={ order.key } className="be-change-history__order">
						{ order.submissionId && (
							<div className="be-change-history__order-head">
								<p className="be-change-history__order-title">
									{ orderHead( order ) }
								</p>
								<p className="be-change-history__order-summary">
									{ orderSummary( order ) }
								</p>
								{ canWithdraw && waitingCount( order ) > 1 && (
									<button
										type="button"
										className="be-change-history__withdraw"
										disabled={ busy }
										onClick={ () => withdrawAll( order ) }
									>
										{ __(
											'Withdraw all waiting',
											'beyond-elysium'
										) }
									</button>
								) }
								{ canUndo && undoableCount( order ) > 1 && (
									<button
										type="button"
										className="be-change-history__withdraw"
										disabled={ busy }
										onClick={ () =>
											setUndoing( { kind: 'set', order } )
										}
									>
										{ __(
											'Undo this set',
											'beyond-elysium'
										) }
									</button>
								) }
							</div>
						) }
						<ul className="be-change-history__lines">
							{ order.items.map( ( item ) => (
								<HistoryLine
									key={ item.id }
									item={ item }
									canWithdraw={ canWithdraw }
									canUndo={ canUndo }
									busy={ busy }
									onWithdraw={ withdraw }
									onUndo={ ( line ) =>
										setUndoing( {
											kind: 'line',
											item: line,
										} )
									}
								/>
							) ) }
						</ul>
					</li>
				) ) }
			</ul>
			{ undoing && (
				<Modal
					title={ __( 'Undo', 'beyond-elysium' ) }
					onClose={ () => setUndoing( null ) }
					footer={
						<>
							<button
								type="button"
								onClick={ () => setUndoing( null ) }
							>
								{ __( 'Cancel', 'beyond-elysium' ) }
							</button>
							<button type="button" onClick={ confirmUndo }>
								{ __( 'Undo', 'beyond-elysium' ) }
							</button>
						</>
					}
				>
					<p>
						{ undoing.kind === 'line'
							? undoQuestion( undoing.item, characterName )
							: undoSetQuestion( undoing.order ) }
					</p>
					<label className="be-change-history__undo-note">
						<span>
							{ __(
								'Note to the player (optional)',
								'beyond-elysium'
							) }
						</span>
						<textarea
							value={ undoNote }
							maxLength={ 1000 }
							rows={ 3 }
							onChange={ ( e ) => setUndoNote( e.target.value ) }
						/>
					</label>
				</Modal>
			) }
		</>
	);
}

export default ChangeHistory;
