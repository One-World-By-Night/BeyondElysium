/**
 * Chronicle-to-chronicle character transfer, from the character sheet's own "Transfer" panel.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type { Transfer, Visit, VisitingFrom } from '../../types/transfer';
import { visitSummary, visitingFromSummary } from '../../lib/visitStatus';
import './TransferPanel.css';

export interface TransferPanelProps {
	gameSlug: string;
	characterId: number;
	characterUuid: string;
	/**
	 * This character's own open outbound visits - any number, one per host.
	 */
	visits: Visit[];
	/**
	 * Set only when this character row is itself a visitor here, from an open inbound row.
	 */
	visitingFrom: VisitingFrom | null;
}

/**
 * One line in a visit's own update log: when a kept-current sheet update landed, and what it touched.
 */
export function updateLogLine(
	entry: Transfer[ 'update_log' ][ number ]
): string {
	const changed = entry.changed.length
		? entry.changed.join( ', ' )
		: __( 'nothing new', 'beyond-elysium' );
	const custom = entry.custom.length
		? ' - ' +
			sprintf(
				/* translators: %s: a comma-separated list of entries that landed as custom */
				__( 'landed custom: %s', 'beyond-elysium' ),
				entry.custom.join( ', ' )
			)
		: '';
	return `${ entry.when } — ${ changed }${ custom }`;
}

/**
 * Renders either the outbound-initiate form (no open visit yet) or every open outbound visit's own status and
 * actions, for the home side; the host side's own single inbound visit and its keep-current/note controls.
 */
export function TransferPanel( {
	gameSlug,
	characterId,
	characterUuid,
	visits,
	visitingFrom,
}: TransferPanelProps ) {
	const direction: 'outbound' | 'inbound' | null = visitingFrom
		? 'inbound'
		: visits.length > 0
			? 'outbound'
			: null;
	const [ outboundRows, setOutboundRows ] = useState< Transfer[] >( [] );
	const [ inboundRow, setInboundRow ] = useState< Transfer | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ hostSite, setHostSite ] = useState( '' );
	const [ hostSlug, setHostSlug ] = useState( '' );
	const [ working, setWorking ] = useState( false );
	const [ notice, setNotice ] = useState< string | null >( null );
	const [ noteText, setNoteText ] = useState( '' );
	const [ downloadXml, setDownloadXml ] = useState< {
		name: string;
		xml: string;
	} | null >( null );

	useEffect( () => {
		if ( ! direction ) {
			setOutboundRows( [] );
			setInboundRow( null );
			setLoading( false );
			return;
		}
		setLoading( true );
		api.transfers( gameSlug )
			.list()
			.then( ( rows ) => {
				const matches = rows.filter(
					( row ) =>
						row.character_uuid === characterUuid &&
						row.direction === direction
				);
				if ( direction === 'inbound' ) {
					setInboundRow( matches[ 0 ] ?? null );
				} else {
					setOutboundRows( matches );
				}
			} )
			.finally( () => setLoading( false ) );
	}, [ gameSlug, characterUuid, direction ] );

	function download( name: string, xml: string ) {
		const blob = new Blob( [ xml ], { type: 'application/xml' } );
		const url = URL.createObjectURL( blob );
		const link = document.createElement( 'a' );
		link.href = url;
		link.download = `${ name.replace( /[^a-z0-9]+/gi, '_' ) }.gex`;
		document.body.appendChild( link );
		link.click();
		document.body.removeChild( link );
		URL.revokeObjectURL( url );
	}

	async function handleInitiate( e: React.FormEvent ) {
		e.preventDefault();
		setWorking( true );
		setNotice( null );
		try {
			const result = await api
				.transfers( gameSlug )
				.initiate(
					characterId,
					hostSite.trim() || undefined,
					hostSlug.trim() || undefined
				);
			setOutboundRows( ( rows ) => [ ...rows, result.transfer ] );
			setDownloadXml( {
				name: `character-${ characterId }`,
				xml: result.xml,
			} );
			if ( result.host?.pending_review ) {
				setNotice(
					sprintf(
						// translators: %s: host chronicle name.
						__(
							'Sent to %s. It waits there until one of its Storytellers accepts it - no further action needed here once they do.',
							'beyond-elysium'
						),
						result.host.host_chronicle ??
							__( 'the host chronicle', 'beyond-elysium' )
					)
				);
			} else if ( result.host?.accepted ) {
				setNotice(
					sprintf(
						/* translators: %s: the host chronicle's name */
						__( 'Accepted by %s.', 'beyond-elysium' ),
						result.host.host_chronicle ??
							__( 'the host chronicle', 'beyond-elysium' )
					)
				);
			} else if ( result.host?.message ) {
				setNotice(
					sprintf(
						/* translators: %s: the reason the host chronicle gave for refusing */
						__(
							'The host chronicle did not take this transfer: %s',
							'beyond-elysium'
						),
						result.host.message
					)
				);
			} else {
				setNotice(
					__(
						'Transfer document ready - download it below, or wait for the host to confirm.',
						'beyond-elysium'
					)
				);
			}
		} catch {
			setNotice(
				__(
					'Could not start the transfer. Please try again.',
					'beyond-elysium'
				)
			);
		} finally {
			setWorking( false );
		}
	}

	async function handleOutboundAction(
		transferId: number,
		action: 'release' | 'decline'
	) {
		setWorking( true );
		setNotice( null );
		try {
			const updated = await api
				.transfers( gameSlug )
				[ action ]( transferId );
			setOutboundRows( ( rows ) =>
				rows.map( ( row ) => ( row.id === transferId ? updated : row ) )
			);
		} catch {
			setNotice(
				__(
					'That action could not be completed. Please try again.',
					'beyond-elysium'
				)
			);
		} finally {
			setWorking( false );
		}
	}

	async function handleOutboundKeepCurrent(
		transferId: number,
		on: boolean
	) {
		setWorking( true );
		setNotice( null );
		try {
			const updated = await api
				.transfers( gameSlug )
				.keepCurrent( transferId, on );
			setOutboundRows( ( rows ) =>
				rows.map( ( row ) => ( row.id === transferId ? updated : row ) )
			);
		} catch {
			setNotice(
				__(
					'That action could not be completed. Please try again.',
					'beyond-elysium'
				)
			);
		} finally {
			setWorking( false );
		}
	}

	async function handleInboundAction( action: 'sendHome' | 'retain' ) {
		if ( ! inboundRow ) {
			return;
		}
		setWorking( true );
		setNotice( null );
		try {
			const updated = await api
				.transfers( gameSlug )
				[ action ]( inboundRow.id );
			setInboundRow( updated );
		} catch {
			setNotice(
				__(
					'That action could not be completed. Please try again.',
					'beyond-elysium'
				)
			);
		} finally {
			setWorking( false );
		}
	}

	async function handleInboundKeepCurrent( on: boolean ) {
		if ( ! inboundRow ) {
			return;
		}
		setWorking( true );
		setNotice( null );
		try {
			const updated = await api
				.transfers( gameSlug )
				.keepCurrent( inboundRow.id, on );
			setInboundRow( updated );
		} catch {
			setNotice(
				__(
					'That action could not be completed. Please try again.',
					'beyond-elysium'
				)
			);
		} finally {
			setWorking( false );
		}
	}

	async function handleInboundKeepCurrentAccept() {
		if ( ! inboundRow ) {
			return;
		}
		setWorking( true );
		setNotice( null );
		try {
			const updated = await api
				.transfers( gameSlug )
				.keepCurrentAccept( inboundRow.id );
			setInboundRow( updated );
		} catch {
			setNotice(
				__(
					'That action could not be completed. Please try again.',
					'beyond-elysium'
				)
			);
		} finally {
			setWorking( false );
		}
	}

	async function handleSendNote( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! inboundRow || ! noteText.trim() ) {
			return;
		}
		setWorking( true );
		setNotice( null );
		try {
			await api
				.transfers( gameSlug )
				.note( inboundRow.id, noteText.trim() );
			setNoteText( '' );
			setNotice( __( 'Sent to home.', 'beyond-elysium' ) );
		} catch {
			setNotice(
				__(
					'That note could not be sent. Please try again.',
					'beyond-elysium'
				)
			);
		} finally {
			setWorking( false );
		}
	}

	if ( loading ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}

	if ( direction === 'inbound' && visitingFrom ) {
		const agreed = Boolean(
			inboundRow?.keep_current && inboundRow?.keep_current_accepted
		);
		const awaitingAccept = Boolean(
			inboundRow?.keep_current && ! inboundRow?.keep_current_accepted
		);
		return (
			<div className="be-transfer-panel">
				{ notice && (
					<div className="be-transfer-panel__notice" role="status">
						{ notice }
					</div>
				) }
				<p className="be-transfer-panel__status">
					{ inboundRow && inboundRow.state !== 'visiting'
						? sprintf(
								/* translators: 1: sending chronicle, 2: transfer state */
								__(
									'Visit from %1$s: %2$s.',
									'beyond-elysium'
								),
								visitingFrom.home_chronicle ??
									__(
										'an unknown chronicle',
										'beyond-elysium'
									),
								inboundRow.state
							)
						: visitingFromSummary( visitingFrom ) }
				</p>
				{ inboundRow?.state === 'visiting' && (
					<>
						<div className="be-transfer-panel__keep-current">
							{ awaitingAccept && (
								<>
									<p>
										{ sprintf(
											/* translators: %s: the home chronicle's name */
											__(
												'%s has asked to keep this character current here.',
												'beyond-elysium'
											),
											visitingFrom.home_chronicle ??
												__(
													'The home chronicle',
													'beyond-elysium'
												)
										) }
									</p>
									<button
										type="button"
										disabled={ working }
										onClick={
											handleInboundKeepCurrentAccept
										}
									>
										{ __(
											'Agree to keep current',
											'beyond-elysium'
										) }
									</button>
								</>
							) }
							{ ! inboundRow.keep_current && (
								<button
									type="button"
									disabled={ working }
									onClick={ () =>
										handleInboundKeepCurrent( true )
									}
								>
									{ __(
										'Ask to keep current',
										'beyond-elysium'
									) }
								</button>
							) }
							{ agreed && (
								<button
									type="button"
									disabled={ working }
									onClick={ () =>
										handleInboundKeepCurrent( false )
									}
								>
									{ __(
										'Turn off keep current',
										'beyond-elysium'
									) }
								</button>
							) }
						</div>

						{ agreed && (
							<form
								className="be-transfer-panel__note-form"
								onSubmit={ handleSendNote }
							>
								<label>
									{ __(
										'Share a note with home',
										'beyond-elysium'
									) }
									<textarea
										value={ noteText }
										onChange={ ( e ) =>
											setNoteText( e.target.value )
										}
										rows={ 2 }
									/>
								</label>
								<button
									type="submit"
									disabled={ working || ! noteText.trim() }
								>
									{ __( 'Send note', 'beyond-elysium' ) }
								</button>
							</form>
						) }

						{ inboundRow.update_log.length > 0 && (
							<div className="be-transfer-panel__update-log">
								<p>{ __( 'Update log', 'beyond-elysium' ) }</p>
								<ul>
									{ inboundRow.update_log.map(
										( entry, i ) => (
											<li key={ i }>
												{ updateLogLine( entry ) }
											</li>
										)
									) }
								</ul>
							</div>
						) }

						<div className="be-transfer-panel__actions">
							<button
								type="button"
								disabled={ working }
								onClick={ () =>
									handleInboundAction( 'sendHome' )
								}
							>
								{ __( 'Send home', 'beyond-elysium' ) }
							</button>
							<button
								type="button"
								disabled={ working }
								onClick={ () =>
									handleInboundAction( 'retain' )
								}
							>
								{ __( 'Keep for good', 'beyond-elysium' ) }
							</button>
						</div>
					</>
				) }
			</div>
		);
	}

	return (
		<div className="be-transfer-panel">
			{ notice && (
				<div className="be-transfer-panel__notice" role="status">
					{ notice }
				</div>
			) }

			{ outboundRows.length > 0 && (
				<ul className="be-transfer-panel__visit-list">
					{ outboundRows.map( ( row ) => (
						<li key={ row.id } className="be-transfer-panel__visit">
							<p>
								{ row.state === 'visiting'
									? visitSummary( {
											host_chronicle: row.host_chronicle,
											host_site: row.host_site,
											keep_current: row.keep_current,
											delivered_at: row.delivered_at,
											unreachable_since:
												row.unreachable_since,
										} )
									: sprintf(
											/* translators: 1: transfer state, 2: the other chronicle's name or "no host yet" */
											__(
												'Status: %1$s — %2$s',
												'beyond-elysium'
											),
											row.state,
											row.host_chronicle ??
												__(
													'no host confirmed yet',
													'beyond-elysium'
												)
										) }
							</p>
							<div className="be-transfer-panel__actions">
								{ row.state === 'offered' && (
									<button
										type="button"
										disabled={ working }
										onClick={ () =>
											handleOutboundAction(
												row.id,
												'decline'
											)
										}
									>
										{ __(
											'Cancel transfer',
											'beyond-elysium'
										) }
									</button>
								) }
								{ row.state === 'visiting' && (
									<>
										<button
											type="button"
											disabled={ working }
											onClick={ () =>
												handleOutboundKeepCurrent(
													row.id,
													! row.keep_current
												)
											}
										>
											{ row.keep_current
												? __(
														'Keep current: off',
														'beyond-elysium'
													)
												: __(
														'Keep current: on',
														'beyond-elysium'
													) }
										</button>
										<button
											type="button"
											disabled={ working }
											onClick={ () =>
												handleOutboundAction(
													row.id,
													'release'
												)
											}
										>
											{ __(
												'End visit',
												'beyond-elysium'
											) }
										</button>
									</>
								) }
							</div>
						</li>
					) ) }
				</ul>
			) }

			{ downloadXml && (
				<button
					type="button"
					onClick={ () =>
						download( downloadXml.name, downloadXml.xml )
					}
				>
					{ __( 'Download transfer document', 'beyond-elysium' ) }
				</button>
			) }

			<form
				className="be-transfer-panel__form"
				onSubmit={ handleInitiate }
			>
				<p>
					{ __(
						'Leave both fields blank to just download the document and send it yourself.',
						'beyond-elysium'
					) }
				</p>
				<label>
					{ __( 'Host site URL', 'beyond-elysium' ) }
					<input
						type="url"
						placeholder="https://example-chronicle.org"
						value={ hostSite }
						onChange={ ( e ) => setHostSite( e.target.value ) }
					/>
				</label>
				<label>
					{ __( 'Host chronicle slug', 'beyond-elysium' ) }
					<input
						type="text"
						placeholder="host-chronicle"
						value={ hostSlug }
						onChange={ ( e ) => setHostSlug( e.target.value ) }
					/>
				</label>
				<button type="submit" disabled={ working }>
					{ working
						? __( 'Sending…', 'beyond-elysium' )
						: __( 'Initiate Transfer', 'beyond-elysium' ) }
				</button>
			</form>
		</div>
	);
}

export default TransferPanel;
