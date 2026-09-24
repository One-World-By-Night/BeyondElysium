/**
 * Boon ledger for a game or a single character.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import { canIn } from '../../lib/chronicleCapabilities';
import type { MyCapabilities } from '../../types';
import type { Boon } from '../../types/world';
import HelpButton from '../shared/HelpButton';
import './BoonLedger.css';

export interface BoonLedgerProps {
	gameSlug: string;
	/**
	 * 0 (default) shows the whole game's ledger.
	 */
	characterId?: number;
	/**
	 * What the person can do in this chronicle, when the page resolved it.
	 */
	capabilities?: MyCapabilities;
}

const BOON_LEVEL_SUGGESTIONS = [ 'trivial', 'minor', 'major', 'life' ];

/**
 * Renders the boon ledger for a game, or for a single character when `characterId` is provided.
 */
export function BoonLedger( {
	gameSlug,
	characterId,
	capabilities,
}: BoonLedgerProps ) {
	const [ items, setItems ] = useState< Boon[] >( [] );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );
	const [ showForm, setShowForm ] = useState( false );

	const canManage = canIn( 'be_manage_boons', capabilities );

	function load() {
		setLoading( true );
		setError( null );
		api.boons( gameSlug )
			.ledger( characterId ? { character_id: characterId } : {} )
			.then( ( result ) => {
				setItems( result );
				setLoading( false );
			} )
			.catch( () => {
				setError(
					__( 'Failed to load the ledger.', 'beyond-elysium' )
				);
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
			setError(
				__( 'Failed to mark this boon repaid.', 'beyond-elysium' )
			);
		}
	}

	const owed = characterId
		? items.filter( ( b ) => b.owed_by.id === characterId )
		: null;
	const owedTo = characterId
		? items.filter( ( b ) => b.owed_to.id === characterId )
		: null;

	return (
		<div className="be-boon-ledger">
			<div className="be-help-heading">
				<h2>{ __( 'Boon Ledger', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="boon-ledger" />
			</div>
			{ canManage && (
				<div className="be-boon-ledger__actions">
					<button
						type="button"
						onClick={ () => setShowForm( ( s ) => ! s ) }
					>
						{ showForm
							? __( 'Cancel', 'beyond-elysium' )
							: __( 'Record a boon', 'beyond-elysium' ) }
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
					<BoonTable
						boons={ owed ?? [] }
						onRepay={ repay }
						canManage={ canManage }
					/>
					<h4>{ __( 'Boons Owed to Me', 'beyond-elysium' ) }</h4>
					<BoonTable
						boons={ owedTo ?? [] }
						onRepay={ repay }
						canManage={ canManage }
					/>
				</>
			) : (
				<BoonTable
					boons={ items }
					onRepay={ repay }
					canManage={ canManage }
				/>
			) }
		</div>
	);
}

/**
 * Renders a table of boons with owed-by, owed-to, level, date, status and terms columns, plus a repay action for
 * outstanding entries.
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
	const [ repayingId, setRepayingId ] = useState< number | null >( null );

	if ( boons.length === 0 ) {
		return <p>{ __( 'None.', 'beyond-elysium' ) }</p>;
	}
	return (
		<div className="be-table-box">
			<table className="be-boon-ledger__table be-responsive-table">
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
						const status =
							( boon.properties.status as string ) ??
							'outstanding';
						const repaidNote = boon.properties.repaid_note as
							| string
							| undefined;
						return (
							<tr
								key={ boon.id }
								className={
									status === 'repaid'
										? 'be-boon-ledger__row--repaid'
										: ''
								}
							>
								<td
									data-label={ __(
										'Owed By',
										'beyond-elysium'
									) }
								>
									{ boon.owed_by.name }
								</td>
								<td
									data-label={ __(
										'Owed To',
										'beyond-elysium'
									) }
								>
									{ boon.owed_to.name }
								</td>
								<td
									data-label={ __(
										'Level',
										'beyond-elysium'
									) }
								>
									{ String(
										boon.properties.boon_level ?? '—'
									) }
								</td>
								<td
									data-label={ __(
										'Date',
										'beyond-elysium'
									) }
								>
									{ String(
										boon.properties.boon_date ?? '—'
									) }
								</td>
								<td
									data-label={ __(
										'Status',
										'beyond-elysium'
									) }
								>
									{ status }
									{ status === 'repaid' && repaidNote && (
										<span className="be-boon-ledger__repaid-note">
											{ ' ' }
											— { repaidNote }
										</span>
									) }
								</td>
								<td
									data-label={ __(
										'Terms',
										'beyond-elysium'
									) }
								>
									{ String( boon.properties.terms ?? '' ) }
								</td>
								{ canManage && (
									<td
										data-label={ __(
											'Actions',
											'beyond-elysium'
										) }
									>
										{ status !== 'repaid' &&
											repayingId !== boon.id && (
												<button
													type="button"
													onClick={ () =>
														setRepayingId( boon.id )
													}
												>
													{ __(
														'Mark repaid',
														'beyond-elysium'
													) }
												</button>
											) }
										{ status !== 'repaid' &&
											repayingId === boon.id && (
												<RepayControl
													onConfirm={ ( note ) => {
														setRepayingId( null );
														onRepay(
															boon.id,
															note
														);
													} }
													onCancel={ () =>
														setRepayingId( null )
													}
												/>
											) }
									</td>
								) }
							</tr>
						);
					} ) }
				</tbody>
			</table>
		</div>
	);
}

/**
 * The inline "mark repaid" confirmation: an optional note on how it was actually settled ("entered in error" is not a
 * special case - a mistaken entry is repaid with that as the how).
 */
function RepayControl( {
	onConfirm,
	onCancel,
}: {
	onConfirm: ( note: string ) => void;
	onCancel: () => void;
} ) {
	const [ note, setNote ] = useState( '' );

	return (
		<span className="be-boon-ledger__repay-control">
			<input
				type="text"
				value={ note }
				onChange={ ( e ) => setNote( e.target.value ) }
				placeholder={ __(
					'How was it settled? (optional)',
					'beyond-elysium'
				) }
				aria-label={ __(
					'How this boon was settled',
					'beyond-elysium'
				) }
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
 * Form for recording a new boon between two characters: owed-by and owed-to character IDs, a level (offered as
 * suggestions), and optional terms.
 */
function CreateBoonForm( {
	gameSlug,
	onCreated,
}: {
	gameSlug: string;
	onCreated: () => void;
} ) {
	const [ owedBy, setOwedBy ] = useState( '' );
	const [ owedTo, setOwedTo ] = useState( '' );
	const [ level, setLevel ] = useState( '' );
	const [ terms, setTerms ] = useState( '' );
	const [ submitting, setSubmitting ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );

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
			setError(
				__(
					'Failed to record this boon - check that both characters exist and are different.',
					'beyond-elysium'
				)
			);
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
			<input
				type="number"
				value={ owedBy }
				onChange={ ( e ) => setOwedBy( e.target.value ) }
				placeholder={ __( 'Owed by (character ID)', 'beyond-elysium' ) }
			/>
			<input
				type="number"
				value={ owedTo }
				onChange={ ( e ) => setOwedTo( e.target.value ) }
				placeholder={ __( 'Owed to (character ID)', 'beyond-elysium' ) }
			/>
			<input
				list="be-boon-levels"
				value={ level }
				onChange={ ( e ) => setLevel( e.target.value ) }
				placeholder={ __( 'Level', 'beyond-elysium' ) }
			/>
			<datalist id="be-boon-levels">
				{ BOON_LEVEL_SUGGESTIONS.map( ( l ) => (
					<option key={ l } value={ l } />
				) ) }
			</datalist>
			<input
				type="text"
				value={ terms }
				onChange={ ( e ) => setTerms( e.target.value ) }
				placeholder={ __( 'Terms (optional)', 'beyond-elysium' ) }
			/>
			<button type="submit" disabled={ submitting }>
				{ __( 'Record', 'beyond-elysium' ) }
			</button>
		</form>
	);
}

export default BoonLedger;
