/**
 * Chronicle-to-chronicle character transfer, from the character sheet's own "Transfer" panel.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type { Transfer, TravellingStatus } from '../../types/transfer';
import './TransferPanel.css';

export interface TransferPanelProps {
	gameSlug: string;
	characterId: number;
	characterUuid: string;
	travellingStatus: TravellingStatus | null;
}

/**
 * Renders either the outbound-initiate form (no open transfer yet) or the current transfer's status plus whatever
 * manual actions its state allows.
 */
export function TransferPanel( {
	gameSlug,
	characterId,
	characterUuid,
	travellingStatus,
}: TransferPanelProps ) {
	const [ current, setCurrent ] = useState< Transfer | null >( null );
	const [ loading, setLoading ] = useState( true );
	const [ hostSite, setHostSite ] = useState( '' );
	const [ hostSlug, setHostSlug ] = useState( '' );
	const [ working, setWorking ] = useState( false );
	const [ notice, setNotice ] = useState< string | null >( null );
	const [ downloadXml, setDownloadXml ] = useState< {
		name: string;
		xml: string;
	} | null >( null );

	useEffect( () => {
		if ( ! travellingStatus ) {
			setCurrent( null );
			setLoading( false );
			return;
		}
		setLoading( true );
		api.transfers( gameSlug )
			.list()
			.then( ( rows ) => {
				const match = rows.find(
					( row ) =>
						row.character_uuid === characterUuid &&
						row.direction === travellingStatus.direction
				);
				setCurrent( match ?? null );
			} )
			.finally( () => setLoading( false ) );
	}, [ gameSlug, characterUuid, travellingStatus ] );

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
			setCurrent( result.transfer );
			setDownloadXml( {
				name: `character-${ characterId }`,
				xml: result.xml,
			} );
			if ( result.host?.pending_review ) {
				setNotice(
					sprintf(
						// translators: %s: host chronicle name.
						__(
							'Sent to %s. It waits there until one of its Storytellers accepts it - mark it received abroad once they have.',
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

	async function handleAction(
		action: 'acknowledge' | 'release' | 'decline' | 'sendHome' | 'retain'
	) {
		if ( ! current ) {
			return;
		}
		setWorking( true );
		setNotice( null );
		try {
			const updated = await api
				.transfers( gameSlug )
				[ action ]( current.id );
			setCurrent( updated );
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

	if ( loading ) {
		return <p>{ __( 'Loading…', 'beyond-elysium' ) }</p>;
	}

	if ( travellingStatus?.direction === 'inbound' ) {
		return (
			<div className="be-transfer-panel">
				{ notice && (
					<div className="be-transfer-panel__notice" role="status">
						{ notice }
					</div>
				) }
				<p className="be-transfer-panel__status">
					{ current && current.state !== 'visiting'
						? sprintf(
								/* translators: 1: sending chronicle, 2: transfer state */
								__(
									'Visit from %1$s: %2$s.',
									'beyond-elysium'
								),
								travellingStatus.chronicle ??
									__(
										'an unknown chronicle',
										'beyond-elysium'
									),
								current.state
						  )
						: sprintf(
								/* translators: 1: the home chronicle's name, 2: the date the visit started */
								__(
									'Visiting from %1$s since %2$s.',
									'beyond-elysium'
								),
								travellingStatus.chronicle ??
									__(
										'an unknown chronicle',
										'beyond-elysium'
									),
								travellingStatus.since
						  ) }
				</p>
				{ current?.state === 'visiting' && (
					<div className="be-transfer-panel__actions">
						<button
							type="button"
							disabled={ working }
							onClick={ () => handleAction( 'sendHome' ) }
						>
							{ __( 'Send home', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							disabled={ working }
							onClick={ () => handleAction( 'retain' ) }
						>
							{ __( 'Keep for good', 'beyond-elysium' ) }
						</button>
					</div>
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

			{ current ? (
				<div className="be-transfer-panel__current">
					<p>
						{ sprintf(
							/* translators: 1: transfer state, 2: the other chronicle's name or "no host yet" */
							__( 'Status: %1$s — %2$s', 'beyond-elysium' ),
							current.state,
							current.host_chronicle ??
								__( 'no host confirmed yet', 'beyond-elysium' )
						) }
					</p>
					<div className="be-transfer-panel__actions">
						{ current.state === 'pending' && (
							<>
								<button
									type="button"
									disabled={ working }
									onClick={ () =>
										handleAction( 'acknowledge' )
									}
								>
									{ __(
										'Mark received abroad',
										'beyond-elysium'
									) }
								</button>
								<button
									type="button"
									disabled={ working }
									onClick={ () => handleAction( 'decline' ) }
								>
									{ __(
										'Cancel transfer',
										'beyond-elysium'
									) }
								</button>
							</>
						) }
						{ current.state === 'abroad' && (
							<button
								type="button"
								disabled={ working }
								onClick={ () => handleAction( 'release' ) }
							>
								{ __(
									'Release permanently',
									'beyond-elysium'
								) }
							</button>
						) }
					</div>
					{ downloadXml && (
						<button
							type="button"
							onClick={ () =>
								download( downloadXml.name, downloadXml.xml )
							}
						>
							{ __(
								'Download transfer document',
								'beyond-elysium'
							) }
						</button>
					) }
				</div>
			) : (
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
			) }
		</div>
	);
}

export default TransferPanel;
