/**
 * The background-use ledger panel: shared between the Storyteller's
 * Action Allocator tool (after a commit) and a player's own
 * character sheet. Shows each budgeted subaction's spend against
 * its allocation, every other held background as an unbudgeted
 * spend target, and - for a Storyteller - the clear-by-character
 * and clear-by-date operations.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type { BackgroundUse, SpendableBackground } from '../../types/apr';
import type { Subaction } from '../../types/plot';
import './BackgroundLedger.css';

export interface BackgroundLedgerProps {
	gameSlug: string;
	characterId: number;
	gameDate: string;
	/**
	 * The committed allocation's own subactions, when the caller already has
	 * them (the Storyteller's Action Allocator, right after a commit) - used
	 * to show total/growth alongside the spend. Omit entirely on the
	 * player-facing sheet panel, which cannot call the ST-only allocate-
	 * actions route: spendable_for()'s own budget_total/budget_name (always
	 * live-accurate, §5.4's note on staleness) is enough on its own to know
	 * which backgrounds are budgeted.
	 */
	subactions?: Subaction[];
	/** True for a Storyteller/manager view: shows results, edit controls, and the clear operations. */
	canManage: boolean;
}

/**
 * Renders the ledger for one character on one game date: a record-a-use
 * row for every background that currently has a budgeted subaction (known
 * either from the subactions prop or from spendable_for()'s own
 * annotation), an unbudgeted section for every other held background, and
 * (for a manager) the two clear operations. Reloads its own data whenever
 * the character or date changes, independent of whatever re-fetches the
 * allocation itself.
 */
export function BackgroundLedger( { gameSlug, characterId, gameDate, subactions = [], canManage }: BackgroundLedgerProps ) {
	const [ spendable, setSpendable ] = useState<SpendableBackground[]>( [] );
	const [ uses, setUses ] = useState<BackgroundUse[]>( [] );
	const [ drafts, setDrafts ] = useState<Record<string, string>>( {} );
	const [ error, setError ] = useState<string | null>( null );

	function reload() {
		Promise.all( [ api.apr( gameSlug ).spendable( characterId ), api.apr( gameSlug ).backgroundUses( characterId, gameDate ) ] )
			.then( ( [ found, recorded ] ) => {
				setSpendable( found );
				setUses( recorded );
				setError( null );
			} )
			.catch( () => setError( __( 'Failed to load the background ledger.', 'beyond-elysium' ) ) );
	}

	useEffect( reload, [ gameSlug, characterId, gameDate ] );

	const bySubactionName: Record<string, Subaction> = {};
	subactions.forEach( ( s ) => {
		bySubactionName[ s.name ] = s;
	} );
	const budgetedFromSpendable = spendable.filter( ( bg ) => bg.budget_name !== null );
	const budgetedNames = Array.from( new Set( [ ...subactions.map( ( s ) => s.name ), ...budgetedFromSpendable.map( ( bg ) => bg.name ) ] ) );
	const budgetedRows = budgetedNames.map( ( name ) => {
		const sub = bySubactionName[ name ];
		if ( sub ) {
			return { name, total: sub.total, spent: sub.spent, overBudget: sub.over_budget };
		}
		const bg = budgetedFromSpendable.find( ( b ) => b.name === name );
		return { name, total: bg?.budget_total ?? undefined, spent: undefined, overBudget: false };
	} );
	const budgetedNameSet = new Set( budgetedNames );
	const unbudgeted = spendable.filter( ( bg ) => ! budgetedNameSet.has( bg.name ) );

	const usesByName: Record<string, BackgroundUse[]> = {};
	uses.forEach( ( u ) => {
		( usesByName[ u.name ] ??= [] ).push( u );
	} );

	async function record( name: string ) {
		setError( null );
		try {
			await api.apr( gameSlug ).recordUse( characterId, { game_date: gameDate, name, text: drafts[ name ] ?? '' } );
			setDrafts( { ...drafts, [ name ]: '' } );
			reload();
		} catch ( err ) {
			setError( errorMessage( err ) );
		}
	}

	async function setResult( use: BackgroundUse, result: string ) {
		try {
			await api.apr( gameSlug ).updateUse( use.id, { result } );
			reload();
		} catch ( err ) {
			setError( errorMessage( err ) );
		}
	}

	async function clearUse( use: BackgroundUse ) {
		try {
			await api.apr( gameSlug ).deleteUse( use.id );
			reload();
		} catch ( err ) {
			setError( errorMessage( err ) );
		}
	}

	async function clearForCharacter() {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( __( 'Clear every recorded background use for this character, on every game date? This does not touch their action budgets, only the uses recorded against them.', 'beyond-elysium' ) ) ) {
			return;
		}
		await api.apr( gameSlug ).clearForCharacter( characterId );
		reload();
	}

	async function clearForDate() {
		// eslint-disable-next-line no-alert
		if ( ! window.confirm( sprintf( __( 'Clear every recorded background use for every character on %s? This does not touch action budgets, only the uses recorded against them.', 'beyond-elysium' ), gameDate ) ) ) {
			return;
		}
		await api.apr( gameSlug ).clearForDate( gameDate );
		reload();
	}

	function renderUse( use: BackgroundUse ) {
		// A player's own not-yet-adjudicated use (no result recorded yet) stays clearable
		// even outside the manager view - once an ST fills in a result it locks (§5.7).
		const canClearThis = canManage || use.result === '';
		return (
			<li key={ use.id } className="be-background-ledger__use">
				<p className="be-background-ledger__use-text">{ use.text || __( '(no description)', 'beyond-elysium' ) }</p>
				{ canManage ? (
					<input
						type="text"
						placeholder={ __( 'Result…', 'beyond-elysium' ) }
						value={ use.result }
						onChange={ ( e ) => setResult( use, e.target.value ) }
					/>
				) : (
					use.result && <p className="be-background-ledger__use-result">{ use.result }</p>
				) }
				{ canClearThis && (
					<button type="button" onClick={ () => clearUse( use ) }>{ __( 'Clear', 'beyond-elysium' ) }</button>
				) }
			</li>
		);
	}

	return (
		<div className="be-background-ledger">
			{ error && <p className="be-background-ledger__error" role="alert">{ error }</p> }

			{ budgetedRows.map( ( row ) => (
				<div className="be-background-ledger__subaction" key={ row.name }>
					<h4>
						{ row.name }
						{ row.spent !== undefined && row.total !== undefined && (
							<span className={ row.overBudget ? 'be-background-ledger__spent is-over' : 'be-background-ledger__spent' }>
								{ sprintf( __( 'Spent %1$d / %2$d', 'beyond-elysium' ), row.spent, row.total ) }
								{ row.overBudget && ` (${ __( 'over budget', 'beyond-elysium' ) })` }
							</span>
						) }
						{ row.spent === undefined && row.total !== undefined && (
							<span className="be-background-ledger__spent">
								{ sprintf( __( 'Budget: %d', 'beyond-elysium' ), row.total ) }
							</span>
						) }
					</h4>
					<ul>{ ( usesByName[ row.name ] ?? [] ).map( renderUse ) }</ul>
					<div className="be-background-ledger__record">
						<input
							type="text"
							placeholder={ __( 'What did they do?', 'beyond-elysium' ) }
							value={ drafts[ row.name ] ?? '' }
							onChange={ ( e ) => setDrafts( { ...drafts, [ row.name ]: e.target.value } ) }
						/>
						<button type="button" onClick={ () => record( row.name ) }>{ __( 'Record a use', 'beyond-elysium' ) }</button>
					</div>
				</div>
			) ) }

			{ unbudgeted.length > 0 && (
				<div className="be-background-ledger__unbudgeted">
					<h4>{ __( 'Other backgrounds', 'beyond-elysium' ) }</h4>
					{ unbudgeted.map( ( bg ) => (
						<div className="be-background-ledger__subaction" key={ bg.name }>
							<h5>{ bg.name }</h5>
							<p className="be-background-ledger__hint">
								{ __( 'No action budget — set this background under Action & Rumor Settings.', 'beyond-elysium' ) }
							</p>
							<ul>{ ( usesByName[ bg.name ] ?? [] ).map( renderUse ) }</ul>
							<div className="be-background-ledger__record">
								<input
									type="text"
									placeholder={ __( 'What did they do?', 'beyond-elysium' ) }
									value={ drafts[ bg.name ] ?? '' }
									onChange={ ( e ) => setDrafts( { ...drafts, [ bg.name ]: e.target.value } ) }
								/>
								<button type="button" onClick={ () => record( bg.name ) }>{ __( 'Record a use', 'beyond-elysium' ) }</button>
							</div>
						</div>
					) ) }
				</div>
			) }

			{ canManage && (
				<div className="be-background-ledger__clear-actions">
					<button type="button" onClick={ clearForCharacter }>{ __( 'Clear all for this Character', 'beyond-elysium' ) }</button>
					<button type="button" onClick={ clearForDate }>{ __( 'Clear all for this Date', 'beyond-elysium' ) }</button>
				</div>
			) }
		</div>
	);
}

interface RestError {
	message?: string;
}

function errorMessage( error: unknown ): string {
	if ( typeof error === 'object' && error !== null && ( error as RestError ).message ) {
		return ( error as RestError ).message as string;
	}
	return __( 'Something went wrong.', 'beyond-elysium' );
}

export default BackgroundLedger;
