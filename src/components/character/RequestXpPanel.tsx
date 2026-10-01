/**
 * A player's own request for XP earned outside this chronicle, such as at a game that doesn't run Beyond Elysium.
 * Submitted as an ordinary change, waiting for a Storyteller like any other.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import {
	parseXpRequestAmount,
	todayYmd,
	xpRequestAmountHint,
} from '../../lib/xpEntry';
import CollapsiblePanel from '../shared/CollapsiblePanel';
import type { XpRequestDetails } from '../../types/character';
import './RequestXpPanel.css';

export interface RequestXpPanelProps {
	gameSlug: string;
	characterId: number;
}

const NOTE_MAX = 2000;
const WHERE_MAX = 200;

export function RequestXpPanel( {
	gameSlug,
	characterId,
}: RequestXpPanelProps ) {
	const [ amount, setAmount ] = useState( '' );
	const [ where, setWhere ] = useState( '' );
	const [ date, setDate ] = useState( '' );
	const [ note, setNote ] = useState( '' );
	const [ sending, setSending ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ sent, setSent ] = useState( false );

	const parsedAmount = parseXpRequestAmount( amount );
	const trimmedWhere = where.trim();
	const whereInvalid = trimmedWhere.length > WHERE_MAX;
	const noteInvalid = note.length > NOTE_MAX;
	const dateInvalid = date !== '' && date > todayYmd();

	const canSend =
		parsedAmount.kind === 'amount' &&
		trimmedWhere !== '' &&
		! whereInvalid &&
		! noteInvalid &&
		! dateInvalid &&
		! sending;

	async function send(): Promise< void > {
		if ( parsedAmount.kind !== 'amount' ) {
			return;
		}
		setSending( true );
		setError( null );
		try {
			const request: XpRequestDetails = { where: trimmedWhere };
			if ( date !== '' ) {
				request.date = date;
			}
			if ( note.trim() !== '' ) {
				request.note = note.trim();
			}
			await api.changes( gameSlug ).create( characterId, {
				change_type: 'xp_earn',
				category: 'experience',
				change_data: { amount: parsedAmount.amount, request },
			} );
			setAmount( '' );
			setWhere( '' );
			setDate( '' );
			setNote( '' );
			setSent( true );
		} catch ( err: unknown ) {
			setError(
				err instanceof Error
					? err.message
					: __(
							'Failed to send the request. Try again.',
							'beyond-elysium'
						)
			);
		} finally {
			setSending( false );
		}
	}

	return (
		<CollapsiblePanel
			id={ `request-xp:${ characterId }` }
			className="be-request-xp"
			defaultCollapsed
			heading={ <h4>{ __( 'Request XP', 'beyond-elysium' ) }</h4> }
		>
			<p className="be-request-xp__hint">
				{ __(
					'Earned experience at a game or event outside this chronicle? Ask your Storytellers to add it.',
					'beyond-elysium'
				) }
			</p>

			{ sent ? (
				<p className="be-request-xp__sent" role="status">
					{ __(
						'Sent. Your Storytellers will review it.',
						'beyond-elysium'
					) }
				</p>
			) : (
				<>
					{ error && (
						<p className="be-request-xp__error" role="alert">
							{ error }
						</p>
					) }
					<div className="be-request-xp__field">
						<label htmlFor="be-request-xp-amount">
							{ __( 'Amount', 'beyond-elysium' ) }
						</label>
						<input
							id="be-request-xp-amount"
							type="text"
							inputMode="numeric"
							value={ amount }
							disabled={ sending }
							onChange={ ( e ) => setAmount( e.target.value ) }
						/>
						{ parsedAmount.kind === 'invalid' && (
							<p className="be-request-xp__field-hint">
								{ xpRequestAmountHint() }
							</p>
						) }
					</div>
					<div className="be-request-xp__field">
						<label htmlFor="be-request-xp-where">
							{ __( 'Where you earned it', 'beyond-elysium' ) }
						</label>
						<input
							id="be-request-xp-where"
							type="text"
							value={ where }
							disabled={ sending }
							onChange={ ( e ) => setWhere( e.target.value ) }
						/>
						{ whereInvalid && (
							<p className="be-request-xp__field-hint">
								{ __(
									'Up to 200 characters.',
									'beyond-elysium'
								) }
							</p>
						) }
					</div>
					<div className="be-request-xp__field">
						<label htmlFor="be-request-xp-date">
							{ __( 'Date played', 'beyond-elysium' ) }
						</label>
						<input
							id="be-request-xp-date"
							type="date"
							value={ date }
							max={ todayYmd() }
							disabled={ sending }
							onChange={ ( e ) => setDate( e.target.value ) }
						/>
						{ dateInvalid && (
							<p className="be-request-xp__field-hint">
								{ __(
									"Can't be after today.",
									'beyond-elysium'
								) }
							</p>
						) }
					</div>
					<div className="be-request-xp__field">
						<label htmlFor="be-request-xp-note">
							{ __( 'Details', 'beyond-elysium' ) }
						</label>
						<textarea
							id="be-request-xp-note"
							value={ note }
							disabled={ sending }
							onChange={ ( e ) => setNote( e.target.value ) }
						/>
						{ noteInvalid && (
							<p className="be-request-xp__field-hint">
								{ __(
									'Up to 2,000 characters.',
									'beyond-elysium'
								) }
							</p>
						) }
					</div>
					<button
						type="button"
						className="be-request-xp__send"
						disabled={ ! canSend }
						onClick={ () => send() }
					>
						{ sending
							? __( 'Sending…', 'beyond-elysium' )
							: __( 'Send request', 'beyond-elysium' ) }
					</button>
				</>
			) }
		</CollapsiblePanel>
	);
}

export default RequestXpPanel;
