/**
 * A Storyteller's view of a chronicle's mail log: every email Beyond Elysium sent, failed to send, held for a daily
 * digest or chose not to send, newest first, with filters for who, what kind, the outcome and the period.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, _n, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import {
	describeResult,
	entityFilterFromSearch,
	entityHref,
	shortTime,
} from '../../lib/mailLog';
import type { EntityFilter } from '../../lib/mailLog';
import HelpButton from '../shared/HelpButton';
import type { MailLogEntry, MailLogOptions } from '../../types/mailLog';
import './EmailLog.css';

export interface EmailLogProps {
	gameSlug: string;
}

const PER_PAGE = 20;
const SEARCH_DELAY_MS = 300;

/**
 * Takes the "about this thing" filter out of the address bar, so it doesn't come back on the next visit to the tab.
 */
function forgetEntityInUrl(): void {
	const url = new URL( window.location.href );
	url.searchParams.delete( 'entity_type' );
	url.searchParams.delete( 'entity_id' );
	window.history.replaceState( {}, '', url.toString() );
}

export function EmailLog( { gameSlug }: EmailLogProps ) {
	const [ options, setOptions ] = useState< MailLogOptions | null >( null );
	const [ entries, setEntries ] = useState< MailLogEntry[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ totalPages, setTotalPages ] = useState( 1 );
	const [ page, setPage ] = useState( 1 );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ searchInput, setSearchInput ] = useState( '' );
	const [ search, setSearch ] = useState( '' );
	const [ kind, setKind ] = useState( '' );
	const [ result, setResult ] = useState( '' );
	const [ since, setSince ] = useState( 'all' );
	const [ entity, setEntity ] = useState< EntityFilter | null >( () =>
		entityFilterFromSearch( window.location.search )
	);

	useEffect( () => forgetEntityInUrl, [] );

	useEffect( () => {
		let cancelled = false;
		api.mailLog( gameSlug )
			.options()
			.then( ( loaded ) => {
				if ( ! cancelled ) {
					setOptions( loaded );
				}
			} )
			.catch( () => undefined );
		return () => {
			cancelled = true;
		};
	}, [ gameSlug ] );

	useEffect( () => {
		const timer = window.setTimeout( () => {
			setSearch( searchInput.trim() );
			setPage( 1 );
		}, SEARCH_DELAY_MS );
		return () => window.clearTimeout( timer );
	}, [ searchInput ] );

	useEffect( () => {
		let cancelled = false;
		setLoading( true );
		setError( null );
		api.mailLog( gameSlug )
			.list( {
				page,
				per_page: PER_PAGE,
				search,
				kind,
				result,
				since,
				entity_type: entity?.entity_type,
				entity_id: entity?.entity_id,
			} )
			.then( ( loaded ) => {
				if ( cancelled ) {
					return;
				}
				setEntries( loaded.items );
				setTotal( loaded.total );
				setTotalPages( Math.max( 1, loaded.totalPages ) );
				setLoading( false );
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setError(
						__( 'Failed to load the email log.', 'beyond-elysium' )
					);
					setLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [ gameSlug, page, search, kind, result, since, entity ] );

	const filtering =
		search !== '' || kind !== '' || result !== '' || since !== 'all';
	const entityName = entries[ 0 ]?.entity_label
		? entries[ 0 ].entity_label
		: entity
			? `${ entity.entity_type } ${ entity.entity_id }`
			: '';

	function change( setter: ( value: string ) => void ) {
		return ( e: { target: { value: string } } ) => {
			setter( e.target.value );
			setPage( 1 );
		};
	}

	return (
		<div className="be-email-log">
			<div className="be-help-heading">
				<h2>{ __( 'Email Log', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="email-log" />
			</div>

			<p className="description">
				{ sprintf(
					/* translators: %d: how many days a row is kept */
					_n(
						'Every email Beyond Elysium sends for this chronicle, and every one it decides not to send, with the reason. A row says who it was for and what it was about, never what it said. It shows the mail system took a message, not that it reached an inbox. Rows are kept %d day.',
						'Every email Beyond Elysium sends for this chronicle, and every one it decides not to send, with the reason. A row says who it was for and what it was about, never what it said. It shows the mail system took a message, not that it reached an inbox. Rows are kept %d days.',
						options?.retention_days ?? 90,
						'beyond-elysium'
					),
					options?.retention_days ?? 90
				) }
			</p>

			<div className="be-email-log__filters">
				<label>
					{ __( 'Search', 'beyond-elysium' ) }
					<input
						type="search"
						value={ searchInput }
						placeholder={ __(
							'Name, email or subject…',
							'beyond-elysium'
						) }
						onChange={ ( e ) => setSearchInput( e.target.value ) }
					/>
				</label>
				<label>
					{ __( 'Kind', 'beyond-elysium' ) }
					<select value={ kind } onChange={ change( setKind ) }>
						<option value="">
							{ __( 'All kinds', 'beyond-elysium' ) }
						</option>
						{ ( options?.kinds ?? [] ).map( ( option ) => (
							<option key={ option.key } value={ option.key }>
								{ option.label }
							</option>
						) ) }
					</select>
				</label>
				<label>
					{ __( 'Result', 'beyond-elysium' ) }
					<select value={ result } onChange={ change( setResult ) }>
						<option value="">
							{ __( 'Any result', 'beyond-elysium' ) }
						</option>
						{ ( options?.results ?? [] ).map( ( option ) => (
							<option key={ option.key } value={ option.key }>
								{ option.label }
							</option>
						) ) }
					</select>
				</label>
				<label>
					{ __( 'Period', 'beyond-elysium' ) }
					<select value={ since } onChange={ change( setSince ) }>
						{ ( options?.periods ?? [] ).map( ( option ) => (
							<option key={ option.key } value={ option.key }>
								{ option.label }
							</option>
						) ) }
					</select>
				</label>
			</div>

			{ entity && (
				<p className="be-email-log__entity">
					{ sprintf(
						/* translators: %s: what the emails were about, such as a plot's title */
						__( 'Only emails about %s.', 'beyond-elysium' ),
						entityName
					) }{ ' ' }
					<button
						type="button"
						className="be-email-log__clear"
						onClick={ () => {
							forgetEntityInUrl();
							setEntity( null );
							setPage( 1 );
						} }
					>
						{ __( 'Show every email', 'beyond-elysium' ) }
					</button>
				</p>
			) }

			{ error && (
				<p className="be-email-log__error" role="alert">
					{ error }
				</p>
			) }

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : entries.length === 0 ? (
				<p>
					{ filtering || entity
						? __(
								'No emails match these filters.',
								'beyond-elysium'
							)
						: __(
								'No emails yet. When Beyond Elysium sends one for this chronicle, or decides not to, it shows up here.',
								'beyond-elysium'
							) }
				</p>
			) : (
				<div className="be-table-box">
					<table className="be-email-log__table be-responsive-table">
						<thead>
							<tr>
								<th>{ __( 'When', 'beyond-elysium' ) }</th>
								<th>{ __( 'Recipient', 'beyond-elysium' ) }</th>
								<th>{ __( 'What', 'beyond-elysium' ) }</th>
								<th>{ __( 'Result', 'beyond-elysium' ) }</th>
							</tr>
						</thead>
						<tbody>
							{ entries.map( ( entry ) => {
								const href = entityHref( entry, gameSlug );
								return (
									<tr key={ entry.id }>
										<td
											data-label={ __(
												'When',
												'beyond-elysium'
											) }
										>
											{ shortTime( entry.created_at ) }
										</td>
										<td
											data-label={ __(
												'Recipient',
												'beyond-elysium'
											) }
										>
											<strong>
												{ entry.recipient_name ||
													entry.recipient_email }
											</strong>
											{ entry.recipient_name &&
												entry.recipient_email && (
													<span className="be-email-log__email">
														{
															entry.recipient_email
														}
													</span>
												) }
										</td>
										<td
											data-label={ __(
												'What',
												'beyond-elysium'
											) }
										>
											<span className="be-email-log__kind">
												{ entry.kind_label }
											</span>
											{ entry.subject && (
												<span className="be-email-log__subject">
													{ entry.subject }
												</span>
											) }
											{ entry.entity_label && (
												<span className="be-email-log__about">
													{ href ? (
														<a href={ href }>
															{
																entry.entity_label
															}
														</a>
													) : (
														entry.entity_label
													) }
												</span>
											) }
										</td>
										<td
											data-label={ __(
												'Result',
												'beyond-elysium'
											) }
										>
											<span
												className={ `be-email-log__result be-email-log__result--${ entry.result }` }
											>
												{ describeResult( entry ) }
											</span>
										</td>
									</tr>
								);
							} ) }
						</tbody>
					</table>
				</div>
			) }

			{ totalPages > 1 && (
				<div className="be-email-log__pagination">
					<button
						type="button"
						disabled={ page <= 1 || loading }
						onClick={ () => setPage( ( p ) => p - 1 ) }
					>
						{ __( 'Previous', 'beyond-elysium' ) }
					</button>
					<span>
						{ sprintf(
							/* translators: 1: this page, 2: how many pages, 3: how many emails in all */
							__(
								'Page %1$d of %2$d (%3$d emails)',
								'beyond-elysium'
							),
							page,
							totalPages,
							total
						) }
					</span>
					<button
						type="button"
						disabled={ page >= totalPages || loading }
						onClick={ () => setPage( ( p ) => p + 1 ) }
					>
						{ __( 'Next', 'beyond-elysium' ) }
					</button>
				</div>
			) }
		</div>
	);
}

export default EmailLog;
