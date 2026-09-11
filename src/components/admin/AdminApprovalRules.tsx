/**
 * Admin page for a chronicle's approval rules.
 * Lists every override currently set on that chronicle's trait_list
 * items and tiered_power powers/levels, and lets a Storyteller
 * create, edit, and delete one against a picked catalog target.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import type { ApprovalRule, ApprovalRuleOptions, ApprovalRuleRequest } from '../../api/client';
import type { Game, SchemaBlock, TieredPower, TraitListItem } from '../../types';
import './Admin.css';

interface RestError {
	message?: string;
}

/**
 * Extracts a human-readable message from a caught error value.
 * Falls back to a generic message when the error has no usable
 * `message` property.
 */
function errorMessage( error: unknown ): string {
	if ( typeof error === 'object' && error !== null && ( error as RestError ).message ) {
		return ( error as RestError ).message as string;
	}
	return __( 'Something went wrong.', 'beyond-elysium' );
}

const EMPTY_FORM: ApprovalRuleRequest = {
	block_slug: '',
	target_type: 'item',
	target_name: '',
	approval: '',
	reason: '',
};

/**
 * Renders the Approval Rules admin screen: a chronicle picker, the
 * flat list of every rule currently set for it, and a create/edit
 * form whose target pickers (block, then item or power, then level)
 * cascade against that block's real catalog rather than free text.
 */
export function AdminApprovalRules() {
	const [ games, setGames ] = useState<Game[]>( [] );
	const [ gameSlug, setGameSlug ] = useState( '' );
	const [ rules, setRules ] = useState<ApprovalRule[]>( [] );
	const [ options, setOptions ] = useState<ApprovalRuleOptions | null>( null );
	const [ blocks, setBlocks ] = useState<SchemaBlock[]>( [] );
	const [ activeBlock, setActiveBlock ] = useState<SchemaBlock | null>( null );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState<string | null>( null );
	const [ saving, setSaving ] = useState( false );

	const [ editingId, setEditingId ] = useState<string | null>( null );
	const [ form, setForm ] = useState<ApprovalRuleRequest>( EMPTY_FORM );

	useEffect( () => {
		api.games
			.list()
			.then( ( found ) => {
				setGames( found );
				if ( ! gameSlug && found.length > 0 ) {
					setGameSlug( found[ 0 ].slug );
				}
			} )
			.catch( ( err: unknown ) => setError( errorMessage( err ) ) );
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	useEffect( () => {
		if ( ! gameSlug ) {
			return;
		}
		setLoading( true );
		Promise.all( [
			api.approvalRules( gameSlug ).list(),
			api.approvalRules( gameSlug ).options(),
			api.schemaBlocks.list( { per_page: 100 } ),
		] )
			.then( ( [ ruleList, opts, blockList ] ) => {
				setRules( ruleList );
				setOptions( opts );
				setBlocks( blockList.filter( ( b ) => b.section_type === 'trait_list' || b.section_type === 'tiered_power' ) );
				setError( null );
			} )
			.catch( ( err: unknown ) => setError( errorMessage( err ) ) )
			.finally( () => setLoading( false ) );
	}, [ gameSlug ] );

	// Fetches the picked block's own resolved definition, so target_name/level offer real catalog values.
	useEffect( () => {
		if ( ! form.block_slug || ! gameSlug ) {
			setActiveBlock( null );
			return;
		}
		api.schemaBlocks
			.get( form.block_slug, gameSlug )
			.then( setActiveBlock )
			.catch( () => setActiveBlock( null ) );
	}, [ form.block_slug, gameSlug ] );

	function startCreate() {
		setEditingId( null );
		setForm( EMPTY_FORM );
	}

	function startEdit( rule: ApprovalRule ) {
		setEditingId( rule.id );
		setForm( {
			block_slug: rule.block_slug,
			target_type: rule.target_type,
			target_name: rule.target_name,
			level: rule.level ?? undefined,
			approval: rule.approval ?? '',
			reason: rule.reason ?? '',
		} );
	}

	async function save( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! form.block_slug || ! form.target_name ) {
			return;
		}
		setSaving( true );
		setError( null );
		try {
			if ( editingId ) {
				await api.approvalRules( gameSlug ).update( editingId, form );
			} else {
				await api.approvalRules( gameSlug ).create( form );
			}
			const refreshed = await api.approvalRules( gameSlug ).list();
			setRules( refreshed );
			startCreate();
		} catch ( err ) {
			setError( errorMessage( err ) );
		} finally {
			setSaving( false );
		}
	}

	async function remove( rule: ApprovalRule ) {
		if ( ! window.confirm( __( 'Delete this approval rule?', 'beyond-elysium' ) ) ) {
			return;
		}
		try {
			await api.approvalRules( gameSlug ).remove( rule.id );
			setRules( ( prev ) => prev.filter( ( r ) => r.id !== rule.id ) );
		} catch ( err ) {
			setError( errorMessage( err ) );
		}
	}

	const items: TraitListItem[] = activeBlock?.section_type === 'trait_list' ? ( activeBlock.definition as { items: TraitListItem[] } ).items ?? [] : [];
	const powers: TieredPower[] = activeBlock?.section_type === 'tiered_power' ? ( activeBlock.definition as { powers: TieredPower[] } ).powers ?? [] : [];
	const selectedPower = powers.find( ( p ) => p.name === form.target_name );

	return (
		<div className="be-admin">
			<h1>{ __( 'Approval Rules', 'beyond-elysium' ) }</h1>
			<p>
				{ __(
					'Flag a specific trait, power, or power level as needing Storyteller (or higher) review, and name the real-world authority a player still needs to satisfy.',
					'beyond-elysium'
				) }
			</p>

			{ error && <p className="be-admin__error" role="alert">{ error }</p> }

			<label>
				{ __( 'Chronicle', 'beyond-elysium' ) }
				<select value={ gameSlug } onChange={ ( e ) => setGameSlug( e.target.value ) }>
					{ games.map( ( g ) => (
						<option key={ g.slug } value={ g.slug }>{ g.name }</option>
					) ) }
				</select>
			</label>

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : (
				<table className="be-admin__table">
					<thead>
						<tr>
							<th>{ __( 'Block', 'beyond-elysium' ) }</th>
							<th>{ __( 'Target', 'beyond-elysium' ) }</th>
							<th>{ __( 'Approval', 'beyond-elysium' ) }</th>
							<th>{ __( 'Reason', 'beyond-elysium' ) }</th>
							<th></th>
						</tr>
					</thead>
					<tbody>
						{ rules.map( ( rule ) => (
							<tr key={ rule.id }>
								<td>{ rule.block_name }</td>
								<td>
									{ rule.target_name }
									{ rule.level !== null && ` (${ __( 'level', 'beyond-elysium' ) } ${ rule.level })` }
								</td>
								<td>{ rule.approval ?? '—' }</td>
								<td>{ rule.reason ?? '—' }</td>
								<td>
									<button type="button" onClick={ () => startEdit( rule ) }>{ __( 'Edit', 'beyond-elysium' ) }</button>
									<button type="button" onClick={ () => remove( rule ) }>{ __( 'Delete', 'beyond-elysium' ) }</button>
								</td>
							</tr>
						) ) }
						{ rules.length === 0 && (
							<tr><td colSpan={ 5 }>{ __( 'No approval rules set for this chronicle yet.', 'beyond-elysium' ) }</td></tr>
						) }
					</tbody>
				</table>
			) }

			<form className="be-admin__form" onSubmit={ save }>
				<h2>{ editingId ? __( 'Edit Rule', 'beyond-elysium' ) : __( 'New Rule', 'beyond-elysium' ) }</h2>

				<label>
					{ __( 'Block', 'beyond-elysium' ) }
					<select
						value={ form.block_slug }
						disabled={ !! editingId }
						onChange={ ( e ) => setForm( { ...form, block_slug: e.target.value, target_name: '', level: undefined } ) }
						required
					>
						<option value="">{ __( 'Choose a block…', 'beyond-elysium' ) }</option>
						{ blocks.map( ( b ) => (
							<option key={ b.slug } value={ b.slug }>{ b.name }</option>
						) ) }
					</select>
				</label>

				{ activeBlock?.section_type === 'trait_list' && (
					<label>
						{ __( 'Item', 'beyond-elysium' ) }
						<select
							value={ form.target_name }
							disabled={ !! editingId }
							onChange={ ( e ) => setForm( { ...form, target_type: 'item', target_name: e.target.value } ) }
							required
						>
							<option value="">{ __( 'Choose an item…', 'beyond-elysium' ) }</option>
							{ items.map( ( item ) => (
								<option key={ item.name } value={ item.name }>{ item.name }</option>
							) ) }
						</select>
					</label>
				) }

				{ activeBlock?.section_type === 'tiered_power' && (
					<>
						<label>
							{ __( 'Power', 'beyond-elysium' ) }
							<select
								value={ form.target_name }
								disabled={ !! editingId }
								onChange={ ( e ) => setForm( { ...form, target_name: e.target.value, level: undefined } ) }
								required
							>
								<option value="">{ __( 'Choose a power…', 'beyond-elysium' ) }</option>
								{ powers.map( ( power ) => (
									<option key={ power.name } value={ power.name }>{ power.name }</option>
								) ) }
							</select>
						</label>

						{ form.target_name && (
							<label>
								{ __( 'Scope', 'beyond-elysium' ) }
								<select
									value={ form.target_type }
									disabled={ !! editingId }
									onChange={ ( e ) => setForm( { ...form, target_type: e.target.value as ApprovalRuleRequest[ 'target_type' ] } ) }
								>
									<option value="power">{ __( 'The whole power', 'beyond-elysium' ) }</option>
									<option value="level">{ __( 'One level only', 'beyond-elysium' ) }</option>
								</select>
							</label>
						) }

						{ form.target_type === 'level' && selectedPower && (
							<label>
								{ __( 'Level', 'beyond-elysium' ) }
								<select
									value={ form.level ?? '' }
									disabled={ !! editingId }
									onChange={ ( e ) => setForm( { ...form, level: Number( e.target.value ) } ) }
									required
								>
									<option value="">{ __( 'Choose a level…', 'beyond-elysium' ) }</option>
									{ selectedPower.levels
										.filter( ( l ) => l.level !== null )
										.map( ( l ) => (
											<option key={ l.level } value={ l.level as number }>
												{ l.level } — { l.power_name }
											</option>
										) ) }
								</select>
							</label>
						) }
					</>
				) }

				{ form.target_type !== 'level' && (
					<label>
						{ __( 'Approval level', 'beyond-elysium' ) }
						<select value={ form.approval } onChange={ ( e ) => setForm( { ...form, approval: e.target.value } ) }>
							<option value="">{ __( '(unset)', 'beyond-elysium' ) }</option>
							{ options?.approval_levels.map( ( level ) => (
								<option key={ level } value={ level }>{ level }</option>
							) ) }
						</select>
					</label>
				) }

				<label>
					{ __( 'Reason preset', 'beyond-elysium' ) }
					<select
						value=""
						onChange={ ( e ) => {
							if ( e.target.value ) {
								setForm( { ...form, reason: form.reason ? `${ form.reason } — ${ e.target.value }` : e.target.value } );
							}
						} }
					>
						<option value="">{ __( 'Insert a preset…', 'beyond-elysium' ) }</option>
						{ options?.reason_presets.map( ( preset ) => (
							<option key={ preset } value={ preset }>{ preset }</option>
						) ) }
					</select>
				</label>

				<label>
					{ __( 'Reason', 'beyond-elysium' ) }
					<textarea
						value={ form.reason }
						placeholder={ __( 'e.g. Coordinator Approval — Tremere', 'beyond-elysium' ) }
						onChange={ ( e ) => setForm( { ...form, reason: e.target.value } ) }
					/>
				</label>

				<div className="be-admin__form-actions">
					<button type="submit" disabled={ saving }>
						{ saving ? __( 'Saving…', 'beyond-elysium' ) : __( 'Save', 'beyond-elysium' ) }
					</button>
					{ editingId && (
						<button type="button" disabled={ saving } onClick={ startCreate }>
							{ __( 'Cancel', 'beyond-elysium' ) }
						</button>
					) }
				</div>
			</form>
		</div>
	);
}

export default AdminApprovalRules;
