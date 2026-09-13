/**
 * Public, unauthenticated verification page (GX-7). Resolves a short
 * attestation code - minted when a character is exported with the
 * `verify` option - to what was attested at issue time, plus whether
 * it still matches the character today. Reads `?code=` off its own
 * URL the same way CharacterSheet.tsx reads `?character_id=`, and also
 * accepts one typed in by hand, since the codes are deliberately
 * human-typeable (no 0/O/1/I/L in the alphabet).
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type { VerifyResponse } from '../../types/verify';
import './VerifyCharacter.css';

type State =
	| { status: 'idle' }
	| { status: 'loading' }
	| { status: 'not_found' }
	| { status: 'rate_limited' }
	| { status: 'error' }
	| { status: 'result'; data: VerifyResponse };

const KIND_LABELS: Record<string, string> = {
	gex: __( 'Grapevine export', 'beyond-elysium' ),
	pdf: __( 'Signed PDF', 'beyond-elysium' ),
	transfer: __( 'Chronicle transfer', 'beyond-elysium' ),
};

const MATCH_LABELS: Array<{ key: keyof NonNullable<VerifyResponse[ 'still_matches' ]>; label: string }> = [
	{ key: 'name', label: __( 'Name', 'beyond-elysium' ) },
	{ key: 'status', label: __( 'Status', 'beyond-elysium' ) },
	{ key: 'xp_earned', label: __( 'XP earned', 'beyond-elysium' ) },
	{ key: 'xp_unspent', label: __( 'XP unspent', 'beyond-elysium' ) },
	{ key: 'sheet', label: __( 'Full sheet', 'beyond-elysium' ) },
];

function codeFromUrl(): string {
	return new URLSearchParams( window.location.search ).get( 'code' ) ?? '';
}

interface RestError {
	code?: string;
	message?: string;
	data?: { status?: number };
}

function isRestError( error: unknown ): error is RestError {
	return typeof error === 'object' && error !== null;
}

/**
 * Renders the outcome of checking one verification code: not found,
 * rate limited, a real error, or - when the code resolves - what was
 * attested and, unless the attestation was revoked, whether it still
 * matches the character today.
 */
export function VerifyCharacter() {
	const [ code, setCode ] = useState( () => codeFromUrl() );
	const [ input, setInput ] = useState( () => codeFromUrl() );
	const [ state, setState ] = useState<State>( { status: 'idle' } );

	useEffect( () => {
		if ( ! code ) {
			setState( { status: 'idle' } );
			return;
		}

		let cancelled = false;
		setState( { status: 'loading' } );

		api
			.verification()
			.resolve( code )
			.then( ( data ) => {
				if ( ! cancelled ) {
					setState( { status: 'result', data } );
				}
			} )
			.catch( ( err: unknown ) => {
				if ( cancelled ) {
					return;
				}
				const restError = isRestError( err ) ? err : null;
				if ( restError?.code === 'rate_limited' ) {
					setState( { status: 'rate_limited' } );
				} else if ( restError?.data?.status === 404 ) {
					setState( { status: 'not_found' } );
				} else {
					setState( { status: 'error' } );
				}
			} );

		return () => {
			cancelled = true;
		};
	}, [ code ] );

	function handleSubmit( e: React.FormEvent ) {
		e.preventDefault();
		const trimmed = input.trim().toUpperCase();
		if ( ! trimmed ) {
			return;
		}
		const params = new URLSearchParams( window.location.search );
		params.set( 'code', trimmed );
		window.history.replaceState( null, '', `?${ params.toString() }` );
		setCode( trimmed );
	}

	return (
		<div className="be-verify">
			<h1 className="be-verify__title">{ __( 'Verify a Character', 'beyond-elysium' ) }</h1>
			<p className="be-verify__intro">
				{ __(
					'Enter the code printed on an exported or signed character document to confirm it is genuine and see whether it still matches the character today.',
					'beyond-elysium'
				) }
			</p>

			<form className="be-verify__form" onSubmit={ handleSubmit }>
				<label className="be-verify__label" htmlFor="be-verify-code">
					{ __( 'Verification code', 'beyond-elysium' ) }
				</label>
				<div className="be-verify__form-row">
					<input
						id="be-verify-code"
						type="text"
						className="be-verify__input"
						placeholder="XXXX-XXXX"
						value={ input }
						onChange={ ( e ) => setInput( e.target.value ) }
					/>
					<button type="submit" className="be-verify__submit">
						{ __( 'Check', 'beyond-elysium' ) }
					</button>
				</div>
			</form>

			{ state.status === 'loading' && <p className="be-verify__status">{ __( 'Checking…', 'beyond-elysium' ) }</p> }

			{ state.status === 'not_found' && (
				<div className="be-verify__banner be-verify__banner--error" role="alert">
					{ __( "This code doesn't match any verification record. Check it was typed correctly.", 'beyond-elysium' ) }
				</div>
			) }

			{ state.status === 'rate_limited' && (
				<div className="be-verify__banner be-verify__banner--warning" role="alert">
					{ __( 'Too many checks from this connection. Please try again in a minute.', 'beyond-elysium' ) }
				</div>
			) }

			{ state.status === 'error' && (
				<div className="be-verify__banner be-verify__banner--error" role="alert">
					{ __( 'Something went wrong checking that code. Please try again.', 'beyond-elysium' ) }
				</div>
			) }

			{ state.status === 'result' && <VerifyResult data={ state.data } /> }
		</div>
	);
}

/**
 * Renders one resolved attestation: the revoked banner (which
 * suppresses still_matches entirely) or the snapshot plus live
 * match checklist.
 */
function VerifyResult( { data }: { data: VerifyResponse } ) {
	const kindLabel = KIND_LABELS[ data.kind ] ?? data.kind;

	return (
		<div className="be-verify__result">
			{ data.revoked ? (
				<div className="be-verify__banner be-verify__banner--error" role="alert">
					{ __( 'This attestation has been revoked by its issuing chronicle. It should no longer be treated as valid.', 'beyond-elysium' ) }
				</div>
			) : (
				<div className="be-verify__banner be-verify__banner--success" role="status">
					{ __( 'This is a genuine attestation.', 'beyond-elysium' ) }
				</div>
			) }

			<dl className="be-verify__facts">
				<div className="be-verify__fact">
					<dt>{ __( 'Character', 'beyond-elysium' ) }</dt>
					<dd>{ data.attested.name }</dd>
				</div>
				<div className="be-verify__fact">
					<dt>{ __( 'Creature type', 'beyond-elysium' ) }</dt>
					<dd>{ data.attested.stack }</dd>
				</div>
				<div className="be-verify__fact">
					<dt>{ __( 'Status', 'beyond-elysium' ) }</dt>
					<dd>{ data.attested.status }</dd>
				</div>
				<div className="be-verify__fact">
					<dt>{ __( 'Experience', 'beyond-elysium' ) }</dt>
					<dd>
						{ sprintf(
							/* translators: 1: earned XP total, 2: unspent XP total */
							__( '%1$d earned / %2$d unspent', 'beyond-elysium' ),
							data.attested.xp_earned,
							data.attested.xp_unspent
						) }
					</dd>
				</div>
				<div className="be-verify__fact">
					<dt>{ __( 'Issued by', 'beyond-elysium' ) }</dt>
					<dd>
						<a href={ data.issuer.site } target="_blank" rel="noreferrer">
							{ data.issuer.chronicle }
						</a>
					</dd>
				</div>
				<div className="be-verify__fact">
					<dt>{ __( 'Issued on', 'beyond-elysium' ) }</dt>
					<dd>{ data.issued_at }</dd>
				</div>
				<div className="be-verify__fact">
					<dt>{ __( 'Document type', 'beyond-elysium' ) }</dt>
					<dd>{ kindLabel }</dd>
				</div>
			</dl>

			{ data.still_matches && (
				<div className="be-verify__matches">
					<h2 className="be-verify__matches-title">{ __( 'Still matches the character today?', 'beyond-elysium' ) }</h2>
					<ul className="be-verify__match-list">
						{ MATCH_LABELS.map( ( { key, label } ) => {
							const matches = data.still_matches![ key ];
							return (
								<li
									key={ key }
									className={ `be-verify__match-item ${ matches ? 'be-verify__match-item--yes' : 'be-verify__match-item--no' }` }
								>
									<span className="be-verify__match-icon" aria-hidden="true">
										{ matches ? '✓' : '✗' }
									</span>
									{ label }
								</li>
							);
						} ) }
					</ul>
					<p className="be-verify__as-of">
						{ __( 'Checked', 'beyond-elysium' ) } { data.as_of }
					</p>
				</div>
			) }
		</div>
	);
}

export default VerifyCharacter;
