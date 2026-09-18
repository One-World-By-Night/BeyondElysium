/**
 * WaitingForReview lists everything a chronicle's Storyteller hasn't yet acted on, above the
 * Import page's own wizard: an incoming transfer offer or visit, and a player-sent Grapevine
 * file (F-122) - both reviewed the same way an uploaded file is, and both add nothing until a
 * Storyteller accepts (1.0.0-review F-003, F-006; player-grapevine-file-design.md §10.2).
 */
import { useEffect, useState } from '@wordpress/element';
import type { ReactNode } from 'react';
import { __, _n, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type { Transfer, TransferReview } from '../../types/transfer';
import type {
	Submission,
	SubmissionReview,
	SubmissionVerification,
} from '../../types/submission';
import type { DuplicateAction, TraitResolution } from '../../types/import';
import { ImportPreview } from './ImportPreview';
import { blockingCount } from '../../lib/importDecisions';
import { mergeWaitingRows } from '../../lib/waitingForReview';
import type { WaitingRow as Row } from '../../lib/waitingForReview';
import { errorMessage } from '../../lib/errorMessage';
import './ImportTool.css';

export interface WaitingForReviewProps {
	gameSlug: string;
}

interface RestError {
	message?: string;
	data?: { status?: number };
}

/**
 * Renders nothing while nothing is waiting, so the Import page looks exactly as it did for a
 * chronicle that never receives either kind of offer.
 */
export function WaitingForReview( { gameSlug }: WaitingForReviewProps ) {
	const [ rows, setRows ] = useState< Row[] >( [] );
	const [ transferReview, setTransferReview ] =
		useState< TransferReview | null >( null );
	const [ submissionReview, setSubmissionReview ] =
		useState< SubmissionReview | null >( null );
	const [ verification, setVerification ] =
		useState< SubmissionVerification | null >( null );
	const [ arrivalOverride, setArrivalOverride ] = useState<
		'joining' | 'visiting' | null
	>( null );
	const [ refusingId, setRefusingId ] = useState< number | null >( null );
	const [ refuseNote, setRefuseNote ] = useState( '' );
	const [ error, setError ] = useState< string | null >( null );
	const [ notice, setNotice ] = useState< string | null >( null );
	const [ working, setWorking ] = useState( false );
	const [ traitResolutions, setTraitResolutions ] = useState<
		Record< string, TraitResolution >
	>( {} );
	const [ duplicateActions, setDuplicateActions ] = useState<
		Record< string, DuplicateAction >
	>( {} );
	const [ worldObjectActions, setWorldObjectActions ] = useState<
		Record< string, DuplicateAction >
	>( {} );

	function load() {
		Promise.all( [
			api
				.transfers( gameSlug )
				.list()
				.then(
					( all ) =>
						all.filter(
							( row ) =>
								row.direction === 'inbound' &&
								[ 'offered', 'visiting' ].includes( row.state )
						),
					( err: unknown ) => {
						if ( ( err as RestError )?.data?.status !== 403 ) {
							throw err;
						}
						return [];
					}
				),
			api
				.submissions( gameSlug )
				.list()
				.then(
					( all ) => all,
					( err: unknown ) => {
						if ( ( err as RestError )?.data?.status !== 403 ) {
							throw err;
						}
						return [];
					}
				),
		] )
			.then( ( [ transferRows, submissionRows ] ) =>
				setRows( mergeWaitingRows( transferRows, submissionRows ) )
			)
			.catch( ( err: unknown ) =>
				setError(
					errorMessage(
						err,
						__( 'Something went wrong.', 'beyond-elysium' )
					)
				)
			);
	}

	useEffect( () => {
		load();
	}, [ gameSlug ] ); // eslint-disable-line react-hooks/exhaustive-deps

	function nameOf( row: Transfer | Submission ): string {
		return (
			row.character_name || __( 'An unnamed character', 'beyond-elysium' )
		);
	}

	function fromLabel( entry: Row ): ReactNode {
		if ( entry.kind === 'transfer' ) {
			return (
				<>
					{ entry.row.home_chronicle ||
						__( 'an unnamed chronicle', 'beyond-elysium' ) }{ ' ' }
					{ entry.row.home_site && (
						<small>({ entry.row.home_site })</small>
					) }
				</>
			);
		}
		const sender =
			entry.row.sender_name ?? __( 'Someone', 'beyond-elysium' );
		if ( entry.row.arrival === 'joining' ) {
			return sprintf(
				/* translators: %s: sender's display name */
				__( '%s - joining', 'beyond-elysium' ),
				sender
			);
		}
		return entry.row.home_chronicle
			? sprintf(
					/* translators: 1: sender's display name, 2: the chronicle they typed as their home */
					__( '%1$s - visiting from %2$s', 'beyond-elysium' ),
					sender,
					entry.row.home_chronicle
			  )
			: sprintf(
					/* translators: %s: sender's display name */
					__( '%s - visiting', 'beyond-elysium' ),
					sender
			  );
	}

	function statusLabel( entry: Row ): string {
		if ( entry.kind === 'transfer' ) {
			return entry.row.state === 'offered'
				? __( 'Waiting for review', 'beyond-elysium' )
				: __( 'Visiting', 'beyond-elysium' );
		}
		return __( 'Waiting for review', 'beyond-elysium' );
	}

	function resetReviewState() {
		setTraitResolutions( {} );
		setDuplicateActions( {} );
		setWorldObjectActions( {} );
		setVerification( null );
		setArrivalOverride( null );
		setRefusingId( null );
	}

	async function openTransfer( row: Transfer ) {
		setError( null );
		setNotice( null );
		setSubmissionReview( null );
		resetReviewState();
		try {
			setTransferReview(
				await api.transfers( gameSlug ).review( row.id )
			);
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		}
	}

	async function openSubmission( row: Submission ) {
		setError( null );
		setNotice( null );
		setTransferReview( null );
		resetReviewState();
		try {
			const found = await api.submissions( gameSlug ).review( row.id );
			setSubmissionReview( found );
			setArrivalOverride( found.submission.arrival );
			api.submissions( gameSlug )
				.verification( row.id )
				.then( setVerification )
				.catch( () => setVerification( null ) );
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		}
	}

	function update< T >(
		setter: (
			fn: ( prev: Record< string, T > ) => Record< string, T >
		) => void,
		key: string,
		value: T | null
	) {
		setter( ( prev ) => {
			const next = { ...prev };
			if ( value ) {
				next[ key ] = value;
			} else {
				delete next[ key ];
			}
			return next;
		} );
	}

	async function acceptTransfer() {
		if ( ! transferReview ) {
			return;
		}
		setWorking( true );
		setError( null );
		try {
			const result = await api
				.transfers( gameSlug )
				.accept( transferReview.transfer.id, {
					duplicates: duplicateActions,
					world_objects: worldObjectActions,
					traits: Object.values( traitResolutions ),
				} );
			setNotice(
				sprintf(
					/* translators: %s: character name. */
					__(
						'%s accepted - now visiting this chronicle.',
						'beyond-elysium'
					),
					result.character.name
				)
			);
			setTransferReview( null );
			load();
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setWorking( false );
		}
	}

	async function acceptSubmission() {
		if ( ! submissionReview ) {
			return;
		}
		setWorking( true );
		setError( null );
		try {
			const result = await api.submissions( gameSlug ).accept(
				submissionReview.submission.id,
				{
					duplicates: duplicateActions,
					world_objects: worldObjectActions,
					traits: Object.values( traitResolutions ),
				},
				arrivalOverride ?? undefined
			);
			setNotice(
				arrivalOverride === 'visiting'
					? sprintf(
							/* translators: %s: character name */
							__(
								'%s accepted - now visiting this chronicle.',
								'beyond-elysium'
							),
							result.character.name
					  )
					: sprintf(
							/* translators: 1: character name, 2: sender's display name */
							__(
								'%1$s accepted - %2$s is now a player in this chronicle.',
								'beyond-elysium'
							),
							result.character.name,
							submissionReview.submission.sender_name ??
								__( 'the sender', 'beyond-elysium' )
					  )
			);
			setSubmissionReview( null );
			load();
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setWorking( false );
		}
	}

	async function act(
		row: Transfer,
		action: 'refuse' | 'sendHome' | 'retain',
		question: string
	) {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( question ) ) {
			return;
		}
		setWorking( true );
		setError( null );
		setNotice( null );
		try {
			await api.transfers( gameSlug )[ action ]( row.id );
			if ( transferReview?.transfer.id === row.id ) {
				setTransferReview( null );
			}
			load();
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setWorking( false );
		}
	}

	async function refuseSubmission( row: Submission ) {
		setWorking( true );
		setError( null );
		setNotice( null );
		try {
			await api
				.submissions( gameSlug )
				.refuse( row.id, refuseNote || undefined );
			if ( submissionReview?.submission.id === row.id ) {
				setSubmissionReview( null );
			}
			setRefusingId( null );
			setRefuseNote( '' );
			load();
		} catch ( err: unknown ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setWorking( false );
		}
	}

	if ( rows.length === 0 && ! error && ! notice ) {
		return null;
	}

	const remaining = transferReview
		? blockingCount(
				transferReview.preview,
				traitResolutions,
				duplicateActions,
				worldObjectActions
		  )
		: submissionReview
		? blockingCount(
				submissionReview.preview,
				traitResolutions,
				duplicateActions,
				worldObjectActions
		  )
		: 0;

	return (
		<section className="be-incoming-transfers">
			<h2>{ __( 'Waiting for Review', 'beyond-elysium' ) }</h2>
			{ notice && (
				<div className="be-import-tool__notice" role="status">
					{ notice }
				</div>
			) }
			{ error && (
				<div className="be-import-tool__error" role="alert">
					{ error }
				</div>
			) }

			{ rows.length > 0 && (
				<div className="be-table-box">
					<table className="be-admin__table be-responsive-table">
						<thead>
							<tr>
								<th>{ __( 'Character', 'beyond-elysium' ) }</th>
								<th>{ __( 'From', 'beyond-elysium' ) }</th>
								<th>{ __( 'Status', 'beyond-elysium' ) }</th>
								<th>{ __( 'Actions', 'beyond-elysium' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ rows.map( ( entry ) => (
								<tr key={ `${ entry.kind }-${ entry.row.id }` }>
									<td
										data-label={ __(
											'Character',
											'beyond-elysium'
										) }
									>
										{ nameOf( entry.row ) }
									</td>
									<td
										data-label={ __(
											'From',
											'beyond-elysium'
										) }
									>
										{ fromLabel( entry ) }
									</td>
									<td
										data-label={ __(
											'Status',
											'beyond-elysium'
										) }
									>
										{ statusLabel( entry ) }
									</td>
									<td
										data-label={ __(
											'Actions',
											'beyond-elysium'
										) }
									>
										{ entry.kind === 'transfer' &&
										entry.row.state === 'offered' ? (
											<>
												<button
													type="button"
													disabled={ working }
													onClick={ () =>
														openTransfer(
															entry.row as Transfer
														)
													}
												>
													{ __(
														'Review',
														'beyond-elysium'
													) }
												</button>{ ' ' }
												<button
													type="button"
													disabled={ working }
													onClick={ () =>
														act(
															entry.row as Transfer,
															'refuse',
															sprintf(
																/* translators: %s: character name. */
																__(
																	'Refuse %s? Nothing will be added to this chronicle.',
																	'beyond-elysium'
																),
																nameOf(
																	entry.row
																)
															)
														)
													}
												>
													{ __(
														'Refuse',
														'beyond-elysium'
													) }
												</button>
											</>
										) : entry.kind === 'transfer' ? (
											<>
												<button
													type="button"
													disabled={ working }
													onClick={ () =>
														act(
															entry.row as Transfer,
															'sendHome',
															sprintf(
																/* translators: %s: character name. */
																__(
																	'Send %s home? The visit ends; the sheet in this chronicle stays as it is.',
																	'beyond-elysium'
																),
																nameOf(
																	entry.row
																)
															)
														)
													}
												>
													{ __(
														'Send home',
														'beyond-elysium'
													) }
												</button>{ ' ' }
												<button
													type="button"
													disabled={ working }
													onClick={ () =>
														act(
															entry.row as Transfer,
															'retain',
															sprintf(
																/* translators: %s: character name. */
																__(
																	'Keep %s in this chronicle for good?',
																	'beyond-elysium'
																),
																nameOf(
																	entry.row
																)
															)
														)
													}
												>
													{ __(
														'Keep for good',
														'beyond-elysium'
													) }
												</button>
											</>
										) : (
											<>
												<button
													type="button"
													disabled={ working }
													onClick={ () =>
														openSubmission(
															entry.row as Submission
														)
													}
												>
													{ __(
														'Review',
														'beyond-elysium'
													) }
												</button>{ ' ' }
												<button
													type="button"
													disabled={ working }
													onClick={ () =>
														setRefusingId(
															(
																entry.row as Submission
															 ).id
														)
													}
												>
													{ __(
														'Refuse',
														'beyond-elysium'
													) }
												</button>
												{ refusingId ===
													( entry.row as Submission )
														.id && (
													<div className="be-incoming-transfers__refuse-form">
														<label>
															{ __(
																'Note to the player (optional)',
																'beyond-elysium'
															) }
															<textarea
																value={
																	refuseNote
																}
																onChange={ (
																	e
																) =>
																	setRefuseNote(
																		e.target
																			.value
																	)
																}
															/>
														</label>
														<button
															type="button"
															disabled={ working }
															onClick={ () =>
																refuseSubmission(
																	entry.row as Submission
																)
															}
														>
															{ __(
																'Refuse sheet',
																'beyond-elysium'
															) }
														</button>{ ' ' }
														<button
															type="button"
															onClick={ () => {
																setRefusingId(
																	null
																);
																setRefuseNote(
																	''
																);
															} }
														>
															{ __(
																'Cancel',
																'beyond-elysium'
															) }
														</button>
													</div>
												) }
											</>
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			{ transferReview && (
				<div className="be-incoming-transfers__review">
					<h3>
						{ sprintf(
							/* translators: 1: character name, 2: sending chronicle. */
							__( 'Reviewing %1$s from %2$s', 'beyond-elysium' ),
							nameOf( transferReview.transfer ),
							transferReview.transfer.home_chronicle
						) }
					</h3>
					<p>
						{ __(
							'Nothing is added to this chronicle until you accept. Make every decision below first.',
							'beyond-elysium'
						) }
					</p>
					<ImportPreview
						preview={ transferReview.preview }
						traitResolutions={ traitResolutions }
						onTraitResolutionChange={ ( key, value ) =>
							update( setTraitResolutions, key, value )
						}
						duplicateActions={ duplicateActions }
						onDuplicateActionChange={ ( key, value ) =>
							update( setDuplicateActions, key, value )
						}
						worldObjectActions={ worldObjectActions }
						onWorldObjectActionChange={ ( key, value ) =>
							update( setWorldObjectActions, key, value )
						}
						allowSkip={ false }
					/>
					{ remaining > 0 && (
						<p className="be-import-tool__blocking-note">
							{ sprintf(
								/* translators: %d: number of decisions still needed. */
								_n(
									'%d decision still needed before you can accept.',
									'%d decisions still needed before you can accept.',
									remaining,
									'beyond-elysium'
								),
								remaining
							) }
						</p>
					) }
					<div className="be-import-tool__nav-row">
						<button
							type="button"
							onClick={ () => setTransferReview( null ) }
						>
							{ __( 'Close', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							disabled={ working || remaining > 0 }
							onClick={ acceptTransfer }
						>
							{ working
								? __( 'Accepting…', 'beyond-elysium' )
								: __( 'Accept Transfer', 'beyond-elysium' ) }
						</button>
					</div>
				</div>
			) }

			{ submissionReview && (
				<div className="be-incoming-transfers__review">
					<h3>
						{ sprintf(
							/* translators: 1: character name, 2: sender's display name. */
							__(
								'Reviewing %1$s, sent by %2$s',
								'beyond-elysium'
							),
							nameOf( submissionReview.submission ),
							submissionReview.submission.sender_name ??
								__( 'someone', 'beyond-elysium' )
						) }
					</h3>
					<p>
						{ sprintf(
							/* translators: 1: sender's display name, 2: sender's email, 3: source file name */
							__(
								'Sent by %1$s (%2$s) - %3$s',
								'beyond-elysium'
							),
							submissionReview.submission.sender_name ?? '',
							submissionReview.submission.sender_email ?? '',
							submissionReview.submission.source_file
						) }
					</p>
					{ verification && (
						<VerificationNotice verification={ verification } />
					) }
					<p>
						<label>
							<input
								type="radio"
								name="arrival"
								checked={ arrivalOverride === 'joining' }
								onChange={ () =>
									setArrivalOverride( 'joining' )
								}
							/>{ ' ' }
							{ __( 'Joining', 'beyond-elysium' ) }
						</label>{ ' ' }
						<label>
							<input
								type="radio"
								name="arrival"
								checked={ arrivalOverride === 'visiting' }
								onChange={ () =>
									setArrivalOverride( 'visiting' )
								}
							/>{ ' ' }
							{ __( 'Visiting', 'beyond-elysium' ) }
						</label>
					</p>
					<p>
						{ __(
							'Nothing is added to this chronicle until you accept. Make every decision below first.',
							'beyond-elysium'
						) }
					</p>
					<ImportPreview
						preview={ submissionReview.preview }
						traitResolutions={ traitResolutions }
						onTraitResolutionChange={ ( key, value ) =>
							update( setTraitResolutions, key, value )
						}
						duplicateActions={ duplicateActions }
						onDuplicateActionChange={ ( key, value ) =>
							update( setDuplicateActions, key, value )
						}
						worldObjectActions={ worldObjectActions }
						onWorldObjectActionChange={ ( key, value ) =>
							update( setWorldObjectActions, key, value )
						}
						allowSkip={ false }
					/>
					{ remaining > 0 && (
						<p className="be-import-tool__blocking-note">
							{ sprintf(
								/* translators: %d: number of decisions still needed. */
								_n(
									'%d decision still needed before you can accept.',
									'%d decisions still needed before you can accept.',
									remaining,
									'beyond-elysium'
								),
								remaining
							) }
						</p>
					) }
					<div className="be-import-tool__nav-row">
						<button
							type="button"
							onClick={ () => setSubmissionReview( null ) }
						>
							{ __( 'Close', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							disabled={ working || remaining > 0 }
							onClick={ acceptSubmission }
						>
							{ working
								? __( 'Accepting…', 'beyond-elysium' )
								: __( 'Accept Sheet', 'beyond-elysium' ) }
						</button>
					</div>
				</div>
			) }
		</section>
	);
}

function VerificationNotice( {
	verification,
}: {
	verification: SubmissionVerification;
} ) {
	if ( verification.status === 'none' ) {
		return (
			<p className="be-incoming-transfers__verification">
				{ __(
					'No verification code in this file. Review it like any Grapevine file.',
					'beyond-elysium'
				) }
			</p>
		);
	}
	if ( verification.status === 'unreachable' ) {
		return (
			<p className="be-incoming-transfers__verification" role="alert">
				{ sprintf(
					/* translators: %s: the site the code claims to be issued by */
					__(
						"Couldn't reach %s to check this code.",
						'beyond-elysium'
					),
					verification.base ?? ''
				) }
			</p>
		);
	}
	if ( verification.status === 'unknown' ) {
		return (
			<p className="be-incoming-transfers__verification" role="alert">
				{ sprintf(
					/* translators: %s: the site the code claims to be issued by */
					__(
						"%s doesn't recognize this code. It may be expired or made up.",
						'beyond-elysium'
					),
					verification.base ?? ''
				) }
			</p>
		);
	}
	if ( verification.status === 'issuer_mismatch' ) {
		return (
			<p className="be-incoming-transfers__verification" role="alert">
				{ __(
					'The code was answered by a different site than the file names. Treat this file as unverified.',
					'beyond-elysium'
				) }
			</p>
		);
	}
	if ( verification.status === 'revoked' ) {
		return (
			<p className="be-incoming-transfers__verification" role="alert">
				{ sprintf(
					/* translators: %s: the chronicle that issued the code */
					__(
						'%s has cancelled this verification code. The file is no longer vouched for.',
						'beyond-elysium'
					),
					verification.issuer?.chronicle ?? ''
				) }
			</p>
		);
	}
	if ( verification.status === 'unchanged' ) {
		return (
			<p className="be-incoming-transfers__verification">
				{ sprintf(
					/* translators: 1: issuing chronicle, 2: issuing site, 3: when it was issued */
					__(
						'Verified by %1$s (%2$s), exported %3$s. This file matches what they exported.',
						'beyond-elysium'
					),
					verification.issuer?.chronicle ?? '',
					verification.issuer?.site ?? '',
					verification.issued_at ?? ''
				) }
				{ verification.home_changed && (
					<>
						{ ' ' }
						{ __(
							'Their copy has changed since then.',
							'beyond-elysium'
						) }
					</>
				) }
			</p>
		);
	}
	// 'changed'
	return (
		<div className="be-incoming-transfers__verification" role="alert">
			<p>
				{ sprintf(
					/* translators: 1: issuing chronicle, 2: when it was issued */
					__(
						"This file doesn't match what %1$s exported on %2$s - it was changed after export.",
						'beyond-elysium'
					),
					verification.issuer?.chronicle ?? '',
					verification.issued_at ?? ''
				) }
			</p>
			{ verification.compared_with === 'sheet' && (
				<p>
					{ __(
						'This code was issued before verification recorded the sheet as handed over, so hidden Storyteller notes alone can cause this. Check the facts below.',
						'beyond-elysium'
					) }
				</p>
			) }
			{ verification.file && verification.attested && (
				<div className="be-table-box">
					<table className="be-admin__table be-responsive-table">
						<thead>
							<tr>
								<th>{ __( 'Fact', 'beyond-elysium' ) }</th>
								<th>{ __( 'This file', 'beyond-elysium' ) }</th>
								<th>
									{ verification.issuer?.chronicle ?? '' }
								</th>
							</tr>
						</thead>
						<tbody>
							{ (
								[
									[ __( 'Name', 'beyond-elysium' ), 'name' ],
									[
										__( 'XP earned', 'beyond-elysium' ),
										'xp_earned',
									],
									[
										__( 'XP unspent', 'beyond-elysium' ),
										'xp_unspent',
									],
								] as const
							 ).map( ( [ label, key ] ) => (
								<tr key={ key }>
									<td
										data-label={ __(
											'Fact',
											'beyond-elysium'
										) }
									>
										{ label }
									</td>
									<td
										data-label={ __(
											'This file',
											'beyond-elysium'
										) }
									>
										{ String(
											verification.file?.[ key ] ?? ''
										) }
									</td>
									<td
										data-label={
											verification.issuer?.chronicle ?? ''
										}
									>
										{ String(
											verification.attested?.[ key ] ?? ''
										) }
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }
		</div>
	);
}

export default WaitingForReview;
