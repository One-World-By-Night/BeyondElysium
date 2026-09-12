/**
 * Boon ledger for a game or a single character. Displays outstanding and
 * repaid boons with who owes whom, level, date, status and terms, and
 * provides a form to record a new boon and a control to mark one repaid.
 * When scoped to a character, splits the list into boons owed by them
 * and boons owed to them.
 *
 * The ledger itself is readable by anyone who can view characters - recording and
 * repaying are gated on `be_manage_boons` (the `boons` chronicle role, or any Storyteller),
 * checked here the same way GameDashboard.tsx gates its own manager controls (Decision
 * 057's UI-affordance pattern: the client hides what a viewer cannot use, the server is the
 * real enforcement either way).
 *
 * That flag is a SITE-WIDE capability check with no per-game scoping (it is computed once,
 * for every page, before any game is known - Plugin::enqueue_frontend()) - the same
 * limitation `be_manage_characters`/`be_manage_plots` already accept for the exact same
 * reason. It is more visible here specifically because `be_manage_boons` is deliberately
 * granted broadly (every WP role, not just administrator/editor - see Capabilities.php's own
 * comment), so any logged-in visitor may see these controls even on a game where they hold
 * no boons-managing role at all. Attempting to use them there still gets a real 403 from
 * Authorization::check_request()'s own per-game check - a visible-but-non-functional
 * button, not a security gap.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import type { Boon } from '../../types/world';
import './BoonLedger.css';

export interface BoonLedgerProps {
	gameSlug: string;
	/** 0 (default) shows the whole game's ledger; a character ID scopes to that character. */
	characterId?: number;
}

const BOON_LEVEL_SUGGESTIONS = [ 'trivial', 'minor', 'major', 'life' ];

/**
 * Renders the boon ledger for a game, or for a single character when
 * `characterId` is provided. Loads boon records from the API, offers a
 * form for recording a new boon, and lets any outstanding boon be marked
 * repaid. When scoped to a character, shows two tables - boons owed by
 * them and boons owed to them - otherwise renders one combined table.
 */
export function BoonLedger( { gameSlug, characterId }: BoonLedgerProps ) {
	const [ items, setItems ] = useState<Boon[]>( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );
	const [ showForm, setShowForm ] = useState( false );

	const canManage = window.beyondElysium?.capabilities?.be_manage_boons ?? false;

	function load() {
		setLoading( true );
		setError( null );
		api
			.boons( gameSlug )
			.ledger( characterId ? { character_id: characterId } : {} )
			.then( ( result ) => {
				setItems( result );
				setLoading( false );
			} )
			.catch( () => {
				setError( __( 'Failed to load the ledger.', 'beyond-elysium' ) );
				setLoading( false );
			} );
	}

	useEffect( load, [ gameSlug, characterId ] ); // eslint-disable-line react-hooks/exhaustive-deps

	async function repay( id: number, note: string ) {
		setError( null );
		try {
			await api.boons( gameSlug ).repay( id, note || undefined );
			load();
		} catch {
			setError( __( 'Failed to mark this boon repaid.', 'beyond-elysium' ) );
		}
	}

	const owed = characterId ? items.filter( ( b ) => b.owed_by.id === characterId ) : null;
	const owedTo = characterId ? items.filter( ( b ) => b.owed_to.id === characterId ) : null;

	return (
		<div className="be-boon-ledger">
			{ canManage && (
				<div className="be-boon-ledger__actions">
					<button type="button" onClick={ () => setShowForm( ( s ) => ! s ) }>
						{ showForm ? __( 'Cancel', 'beyond-elysium' ) : __( 'Record a boon', 'beyond-elysium' ) }
					</button>
				</div>
			) }

			{ showForm && canManage && (
				<CreateBoonForm
					gameSlug={ gameSlug }
					onCreated={ () => {
						setShowForm( false );
						load();
					} }
				/>
			) }

			{ error && (
				<div className="be-boon-ledger__error" role="alert">
					{ error }
				</div>
			) }

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : characterId ? (
				<>
					<h4>{ __( 'Boons I Owe', 'beyond-elysium' ) }</h4>
					<BoonTable boons={ owed ?? [] } onRepay={ repay } canManage={ canManage } />
					<h4>{ __( 'Boons Owed to Me', 'beyond-elysium' ) }</h4>
					<BoonTable boons={ owedTo ?? [] } onRepay={ repay } canManage={ canManage } />
				</>
			) : (
				<BoonTable boons={ items } onRepay={ repay } canManage={ canManage } />
			) }
		</div>
	);
}

/**
 * Renders a table of boons with owed-by, owed-to, level, date, status and
 * terms columns, plus a repay action for outstanding entries. Repaid
 * rows get a distinct style and show how they were settled, when recorded.
 * Renders a "None" message when empty.
 */
function BoonTable( {
	boons,
	onRepay,
	canManage,
}: {
	boons: Boon[];
	onRepay: ( id: number, note: string ) => void;
	canManage: boolean;
} ) {
	const [ repayingId, setRepayingId ] = useState<number | null>( null );

	if ( boons.length === 0 ) {
		return <p>{ __( 'None.', 'beyond-elysium' ) }</p>;
	}
	return (
		<table className="be-boon-ledger__table">
			<thead>
				<tr>
					<th>{ __( 'Owed By', 'beyond-elysium' ) }</th>
					<th>{ __( 'Owed To', 'beyond-elysium' ) }</th>
					<th>{ __( 'Level', 'beyond-elysium' ) }</th>
					<th>{ __( 'Date', 'beyond-elysium' ) }</th>
					<th>{ __( 'Status', 'beyond-elysium' ) }</th>
					<th>{ __( 'Terms', 'beyond-elysium' ) }</th>
					{ canManage && <th /> }
				</tr>
			</thead>
			<tbody>
				{ boons.map( ( boon ) => {
					const status = ( boon.properties.status as string ) ?? 'outstanding';
					const repaidNote = boon.properties.repaid_note as string | undefined;
					return (
						<tr key={ boon.id } className={ status === 'repaid' ? 'be-boon-ledger__row--repaid' : '' }>
							<td>{ boon.owed_by.name }</td>
							<td>{ boon.owed_to.name }</td>
							<td>{ String( boon.properties.boon_level ?? '—' ) }</td>
							<td>{ String( boon.properties.boon_date ?? '—' ) }</td>
							<td>
								{ status }
								{ status === 'repaid' && repaidNote && (
									<span className="be-boon-ledger__repaid-note"> — { repaidNote }</span>
								) }
							</td>
							<td>{ String( boon.properties.terms ?? '' ) }</td>
							{ canManage && (
								<td>
									{ status !== 'repaid' && repayingId !== boon.id && (
										<button type="button" onClick={ () => setRepayingId( boon.id ) }>
											{ __( 'Mark repaid', 'beyond-elysium' ) }
										</button>
									) }
									{ status !== 'repaid' && repayingId === boon.id && (
										<RepayControl
											onConfirm={ ( note ) => {
												setRepayingId( null );
												onRepay( boon.id, note );
											} }
											onCancel={ () => setRepayingId( null ) }
										/>
									) }
								</td>
							) }
						</tr>
					);
				} ) }
			</tbody>
		</table>
	);
}

/**
 * The inline "mark repaid" confirmation: an optional note on how it was actually settled
 * ("entered in error" is not a special case - a mistaken entry is repaid with that as the
 * how, BE_PROCESS/0.99.2-workflow.md), then Confirm or Cancel.
 */
function RepayControl( { onConfirm, onCancel }: { onConfirm: ( note: string ) => void; onCancel: () => void } ) {
	const [ note, setNote ] = useState( '' );

	return (
		<span className="be-boon-ledger__repay-control">
			<input
				type="text"
				value={ note }
				onChange={ ( e ) => setNote( e.target.value ) }
				placeholder={ __( 'How was it settled? (optional)', 'beyond-elysium' ) }
				aria-label={ __( 'How this boon was settled', 'beyond-elysium' ) }
			/>
			<button type="button" onClick={ () => onConfirm( note ) }>
				{ __( 'Confirm', 'beyond-elysium' ) }
			</button>
			<button type="button" onClick={ onCancel }>
				{ __( 'Cancel', 'beyond-elysium' ) }
			</button>
		</span>
	);
}

/**
 * Form for recording a new boon between two characters: owed-by and
 * owed-to character IDs, a level (offered as suggestions), and optional
 * terms. Requires both character fields and a level before submitting,
 * and surfaces an error if the create request fails.
 */
function CreateBoonForm( { gameSlug, onCreated }: { gameSlug: string; onCreated: () => void } ) {
	const [ owedBy, setOwedBy ] = useState( '' );
	const [ owedTo, setOwedTo ] = useState( '' );
	const [ level, setLevel ] = useState( '' );
	const [ terms, setTerms ] = useState( '' );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState<string | null>( null );

	async function submit( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! owedBy || ! owedTo || ! level ) {
			return;
		}
		setSubmitting( true );
		setError( null );
		try {
			await api.boons( gameSlug ).create( {
				owed_by_character_id: Number( owedBy ),
				owed_to_character_id: Number( owedTo ),
				boon_level: level,
				terms: terms || undefined,
			} );
			onCreated();
		} catch {
			setError( __( 'Failed to record this boon - check that both characters exist and are different.', 'beyond-elysium' ) );
		} finally {
			setSubmitting( false );
		}
	}

	return (
		<form className="be-boon-ledger__form" onSubmit={ submit }>
			{ error && (
				<div className="be-boon-ledger__error" role="alert">
					{ error }
				</div>
			) }
			<input type="number" value={ owedBy } onChange={ ( e ) => setOwedBy( e.target.value ) } placeholder={ __( 'Owed by (character ID)', 'beyond-elysium' ) } />
			<input type="number" value={ owedTo } onChange={ ( e ) => setOwedTo( e.target.value ) } placeholder={ __( 'Owed to (character ID)', 'beyond-elysium' ) } />
			<input list="be-boon-levels" value={ level } onChange={ ( e ) => setLevel( e.target.value ) } placeholder={ __( 'Level', 'beyond-elysium' ) } />
			<datalist id="be-boon-levels">
				{ BOON_LEVEL_SUGGESTIONS.map( ( l ) => (
					<option key={ l } value={ l } />
				) ) }
			</datalist>
			<input type="text" value={ terms } onChange={ ( e ) => setTerms( e.target.value ) } placeholder={ __( 'Terms (optional)', 'beyond-elysium' ) } />
			<button type="submit" disabled={ submitting }>
				{ __( 'Record', 'beyond-elysium' ) }
			</button>
		</form>
	);
}

export default BoonLedger;
