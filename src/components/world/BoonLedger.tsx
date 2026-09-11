/**
 * Boon ledger for a game or a single character. Displays outstanding and
 * repaid boons with who owes whom, level, date, status and terms, and
 * provides a form to record a new boon and a control to mark one repaid.
 * When scoped to a character, splits the list into boons owed by them
 * and boons owed to them.
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

	async function repay( id: number ) {
		setError( null );
		try {
			await api.boons( gameSlug ).repay( id );
			load();
		} catch {
			setError( __( 'Failed to mark this boon repaid.', 'beyond-elysium' ) );
		}
	}

	const owed = characterId ? items.filter( ( b ) => b.owed_by.id === characterId ) : null;
	const owedTo = characterId ? items.filter( ( b ) => b.owed_to.id === characterId ) : null;

	return (
		<div className="be-boon-ledger">
			<div className="be-boon-ledger__actions">
				<button type="button" onClick={ () => setShowForm( ( s ) => ! s ) }>
					{ showForm ? __( 'Cancel', 'beyond-elysium' ) : __( 'Record a boon', 'beyond-elysium' ) }
				</button>
			</div>

			{ showForm && (
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
					<BoonTable boons={ owed ?? [] } onRepay={ repay } />
					<h4>{ __( 'Boons Owed to Me', 'beyond-elysium' ) }</h4>
					<BoonTable boons={ owedTo ?? [] } onRepay={ repay } />
				</>
			) : (
				<BoonTable boons={ items } onRepay={ repay } />
			) }
		</div>
	);
}

/**
 * Renders a table of boons with owed-by, owed-to, level, date, status and
 * terms columns, plus a repay action for outstanding entries. Repaid
 * rows get a distinct style. Renders a "None" message when empty.
 */
function BoonTable( { boons, onRepay }: { boons: Boon[]; onRepay: ( id: number ) => void } ) {
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
					<th />
				</tr>
			</thead>
			<tbody>
				{ boons.map( ( boon ) => {
					const status = ( boon.properties.status as string ) ?? 'outstanding';
					return (
						<tr key={ boon.id } className={ status === 'repaid' ? 'be-boon-ledger__row--repaid' : '' }>
							<td>{ boon.owed_by.name }</td>
							<td>{ boon.owed_to.name }</td>
							<td>{ String( boon.properties.boon_level ?? '—' ) }</td>
							<td>{ String( boon.properties.boon_date ?? '—' ) }</td>
							<td>{ status }</td>
							<td>{ String( boon.properties.terms ?? '' ) }</td>
							<td>
								{ status !== 'repaid' && (
									<button type="button" onClick={ () => onRepay( boon.id ) }>
										{ __( 'Mark repaid', 'beyond-elysium' ) }
									</button>
								) }
							</td>
						</tr>
					);
				} ) }
			</tbody>
		</table>
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
