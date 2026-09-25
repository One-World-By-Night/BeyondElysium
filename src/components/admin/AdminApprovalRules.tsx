/**
 * Admin page for a chronicle's approval rules.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import AiAssistButton from '../shared/AiAssistButton';
import HelpButton from '../shared/HelpButton';
import type {
	ApprovalRule,
	ApprovalRuleOptions,
	ApprovalRuleRequest,
	ApprovalRuleTargetType,
} from '../../api/client';
import type {
	Game,
	IdentityField,
	ResourcePool,
	SchemaBlock,
	TieredPower,
	TraitListItem,
} from '../../types';
import { errorMessage } from '../../lib/errorMessage';
import { useRevealOnOpen } from '../../lib/revealEditor';
import { everyPage } from '../../lib/everyPage';
import { preselectedChronicle, writeGameToUrl } from '../../lib/pluginPages';
import './Admin.css';

const EMPTY_FORM: ApprovalRuleRequest = {
	block_slug: '',
	target_type: 'item',
	target_name: '',
	approval: '',
	reason: '',
};

/**
 * True for the two section types whose rules are always addressed by a [from, to] range.
 */
function isRangeType( type: ApprovalRuleTargetType ): boolean {
	return type === 'item_range' || type === 'pool_range';
}

/**
 * Renders one rule's target column.
 */
function describeTarget( rule: ApprovalRule ): string {
	if ( rule.level !== null ) {
		return `${ rule.target_name } (${ __( 'level', 'beyond-elysium' ) } ${
			rule.level
		})`;
	}
	if ( isRangeType( rule.target_type ) && Array.isArray( rule.extra ) ) {
		return `${ rule.target_name } (${ rule.extra[ 0 ] }–${ rule.extra[ 1 ] })`;
	}
	if (
		rule.target_type === 'field_option' &&
		typeof rule.extra === 'string'
	) {
		return `${ rule.target_name }: ${ rule.extra }`;
	}
	return rule.target_name;
}

/**
 * Renders the Approval Rules admin screen.
 */
export function AdminApprovalRules() {
	const [ games, setGames ] = useState< Game[] >( [] );
	const [ gameSlug, setGameSlug ] = useState( '' );
	const [ rules, setRules ] = useState< ApprovalRule[] >( [] );
	const [ options, setOptions ] = useState< ApprovalRuleOptions | null >(
		null
	);
	const [ blocks, setBlocks ] = useState< SchemaBlock[] >( [] );
	const [ activeBlock, setActiveBlock ] = useState< SchemaBlock | null >(
		null
	);
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ savingDefault, setSavingDefault ] = useState( false );
	const [ autoApproveByDefault, setAutoApproveByDefault ] = useState( false );

	const [ editingId, setEditingId ] = useState< string | null >( null );
	const editorRef = useRef< HTMLFormElement >( null );
	useRevealOnOpen( editorRef, editingId );
	const [ form, setForm ] = useState< ApprovalRuleRequest >( EMPTY_FORM );

	useEffect( () => {
		api.games
			.list()
			.then( ( found ) => {
				setGames( found );
				if ( gameSlug || found.length === 0 ) {
					return;
				}
				setGameSlug( preselectedChronicle( found )?.slug ?? '' );
			} )
			.catch( ( err: unknown ) =>
				setError(
					errorMessage(
						err,
						__( 'Something went wrong.', 'beyond-elysium' )
					)
				)
			);
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [] );

	useEffect( () => {
		if ( ! gameSlug ) {
			return;
		}
		writeGameToUrl( gameSlug );
		setLoading( true );
		Promise.all( [
			api.approvalRules( gameSlug ).list(),
			api.approvalRules( gameSlug ).options(),
			everyPage( ( page ) =>
				api.schemaBlocks.listPaginated( { page, per_page: 100 } )
			),
			api.approvalRules( gameSlug ).defaultPolicy(),
		] )
			.then( ( [ ruleList, opts, blockList, policy ] ) => {
				setRules( ruleList );
				setOptions( opts );
				setAutoApproveByDefault( policy.auto_approve );
				setBlocks(
					blockList.filter( ( b ) =>
						[
							'trait_list',
							'tiered_power',
							'resource_pool',
							'identity_field',
						].includes( b.section_type )
					)
				);
				setError( null );
			} )
			.catch( ( err: unknown ) =>
				setError(
					errorMessage(
						err,
						__( 'Something went wrong.', 'beyond-elysium' )
					)
				)
			)
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
			from:
				isRangeType( rule.target_type ) && Array.isArray( rule.extra )
					? rule.extra[ 0 ]
					: undefined,
			to:
				isRangeType( rule.target_type ) && Array.isArray( rule.extra )
					? rule.extra[ 1 ]
					: undefined,
			option:
				rule.target_type === 'field_option' &&
				typeof rule.extra === 'string'
					? rule.extra
					: undefined,
			approval: rule.approval ?? '',
			reason: rule.reason ?? '',
		} );
	}

	/**
	 * Saves the chronicle-wide default approval policy: auto-approve unless a rule below says.
	 */
	async function saveDefaultPolicy( auto: boolean ) {
		if ( ! gameSlug ) {
			return;
		}
		setSavingDefault( true );
		try {
			const policy = await api
				.approvalRules( gameSlug )
				.setDefaultPolicy( auto );
			setAutoApproveByDefault( policy.auto_approve );
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setSavingDefault( false );
		}
	}

	async function save( e: React.FormEvent ) {
		e.preventDefault();
		if ( ! form.block_slug || ! form.target_name ) {
			return;
		}
		if (
			isRangeType( form.target_type ) &&
			( form.from === undefined || form.to === undefined )
		) {
			return;
		}
		if ( form.target_type === 'field_option' && ! form.option ) {
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
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setSaving( false );
		}
	}

	async function remove( rule: ApprovalRule ) {
		if (
			! window.confirm(
				__( 'Delete this approval rule?', 'beyond-elysium' )
			)
		) {
			return;
		}
		try {
			await api.approvalRules( gameSlug ).remove( rule.id );
			setRules( ( prev ) => prev.filter( ( r ) => r.id !== rule.id ) );
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		}
	}

	const items: TraitListItem[] =
		activeBlock?.section_type === 'trait_list'
			? ( activeBlock.definition as { items: TraitListItem[] } ).items ??
			  []
			: [];
	const powers: TieredPower[] =
		activeBlock?.section_type === 'tiered_power'
			? ( activeBlock.definition as { powers: TieredPower[] } ).powers ??
			  []
			: [];
	const pools: ResourcePool[] =
		activeBlock?.section_type === 'resource_pool'
			? ( activeBlock.definition as { pools: ResourcePool[] } ).pools ??
			  []
			: [];
	const fields: IdentityField[] =
		activeBlock?.section_type === 'identity_field'
			? ( activeBlock.definition as { fields: IdentityField[] } )
					.fields ?? []
			: [];
	const selectedPower = powers.find( ( p ) => p.name === form.target_name );
	const selectedField = fields.find( ( f ) => f.name === form.target_name );

	// Whether the current block/target combination shows an "Approval level" dropdown at all.

	return (
		<div className="be-admin">
			<div className="be-help-heading">
				<h1>{ __( 'Approval Rules', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="approval-rules" />
			</div>
			<p>
				{ __(
					'Flag a specific trait, power, power level, resource pool value, or identity field option as needing Storyteller (or higher) review, and name the real-world authority a player still needs to satisfy.',
					'beyond-elysium'
				) }
			</p>

			{ error && (
				<p className="be-admin__error" role="alert">
					{ error }
				</p>
			) }

			<label>
				{ __( 'Chronicle', 'beyond-elysium' ) }
				<select
					value={ gameSlug }
					onChange={ ( e ) => setGameSlug( e.target.value ) }
				>
					{ games.map( ( g ) => (
						<option key={ g.slug } value={ g.slug }>
							{ g.name }
						</option>
					) ) }
				</select>
			</label>

			{ gameSlug && (
				<div className="be-admin__form">
					<h2>
						{ __( 'Default Approval Policy', 'beyond-elysium' ) }
					</h2>
					<p className="description">
						{ __(
							'What happens when nothing below has an opinion. Rules always win over this default, in either direction.',
							'beyond-elysium'
						) }
					</p>
					<label>
						<input
							type="radio"
							name="default-approval-policy"
							checked={ ! autoApproveByDefault }
							disabled={ savingDefault }
							onChange={ () => saveDefaultPolicy( false ) }
						/>{ ' ' }
						{ __(
							'Pending by default - a rule below can mark something Auto',
							'beyond-elysium'
						) }
					</label>
					<label>
						<input
							type="radio"
							name="default-approval-policy"
							checked={ autoApproveByDefault }
							disabled={ savingDefault }
							onChange={ () => saveDefaultPolicy( true ) }
						/>{ ' ' }
						{ __(
							'Auto-approve by default - a rule below can require Storyteller review',
							'beyond-elysium'
						) }
					</label>
				</div>
			) }

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
								<td>{ describeTarget( rule ) }</td>
								<td>{ rule.approval ?? '—' }</td>
								<td>{ rule.reason ?? '—' }</td>
								<td>
									<button
										type="button"
										onClick={ () => startEdit( rule ) }
									>
										{ __( 'Edit', 'beyond-elysium' ) }
									</button>
									<button
										type="button"
										onClick={ () => remove( rule ) }
									>
										{ __( 'Delete', 'beyond-elysium' ) }
									</button>
								</td>
							</tr>
						) ) }
						{ rules.length === 0 && (
							<tr>
								<td colSpan={ 5 }>
									{ __(
										'No approval rules set for this chronicle yet.',
										'beyond-elysium'
									) }
								</td>
							</tr>
						) }
					</tbody>
				</table>
			) }

			<form
				ref={ editorRef }
				className="be-admin__form"
				onSubmit={ save }
			>
				<h2>
					{ editingId
						? __( 'Edit Rule', 'beyond-elysium' )
						: __( 'New Rule', 'beyond-elysium' ) }
				</h2>

				<label>
					{ __( 'Block', 'beyond-elysium' ) }
					<select
						value={ form.block_slug }
						disabled={ !! editingId }
						onChange={ ( e ) =>
							setForm( {
								...form,
								block_slug: e.target.value,
								target_name: '',
								level: undefined,
								from: undefined,
								to: undefined,
								option: undefined,
							} )
						}
						required
					>
						<option value="">
							{ __( 'Choose a block…', 'beyond-elysium' ) }
						</option>
						{ blocks.map( ( b ) => (
							<option key={ b.slug } value={ b.slug }>
								{ b.name }
							</option>
						) ) }
					</select>
				</label>

				{ activeBlock?.section_type === 'trait_list' && (
					<>
						<label>
							{ __( 'Item', 'beyond-elysium' ) }
							<select
								value={ form.target_name }
								disabled={ !! editingId }
								onChange={ ( e ) =>
									setForm( {
										...form,
										target_type: 'item',
										target_name: e.target.value,
										from: undefined,
										to: undefined,
									} )
								}
								required
							>
								<option value="">
									{ __(
										'Choose an item…',
										'beyond-elysium'
									) }
								</option>
								{ items.map( ( item ) => (
									<option
										key={ item.name }
										value={ item.name }
									>
										{ item.name }
									</option>
								) ) }
							</select>
						</label>

						{ form.target_name && (
							<label>
								{ __( 'Scope', 'beyond-elysium' ) }
								<select
									value={ form.target_type }
									disabled={ !! editingId }
									onChange={ ( e ) =>
										setForm( {
											...form,
											target_type: e.target
												.value as ApprovalRuleTargetType,
										} )
									}
								>
									<option value="item">
										{ __(
											'The whole item',
											'beyond-elysium'
										) }
									</option>
									<option value="item_range">
										{ __(
											'A specific value range',
											'beyond-elysium'
										) }
									</option>
								</select>
							</label>
						) }

						{ form.target_type === 'item_range' && (
							<>
								<label>
									{ __( 'From', 'beyond-elysium' ) }
									<input
										type="number"
										value={ form.from ?? '' }
										disabled={ !! editingId }
										onChange={ ( e ) =>
											setForm( {
												...form,
												from: Number( e.target.value ),
											} )
										}
										required
									/>
								</label>
								<label>
									{ __( 'To', 'beyond-elysium' ) }
									<input
										type="number"
										value={ form.to ?? '' }
										disabled={ !! editingId }
										onChange={ ( e ) =>
											setForm( {
												...form,
												to: Number( e.target.value ),
											} )
										}
										required
									/>
								</label>
							</>
						) }
					</>
				) }

				{ activeBlock?.section_type === 'resource_pool' && (
					<>
						<label>
							{ __( 'Pool', 'beyond-elysium' ) }
							<select
								value={ form.target_name }
								disabled={ !! editingId }
								onChange={ ( e ) =>
									setForm( {
										...form,
										target_type: 'pool_range',
										target_name: e.target.value,
									} )
								}
								required
							>
								<option value="">
									{ __( 'Choose a pool…', 'beyond-elysium' ) }
								</option>
								{ pools.map( ( pool ) => (
									<option
										key={ pool.name }
										value={ pool.name }
									>
										{ pool.name }
									</option>
								) ) }
							</select>
						</label>
						{ form.target_name && (
							<>
								<label>
									{ __(
										'From (permanent value)',
										'beyond-elysium'
									) }
									<input
										type="number"
										value={ form.from ?? '' }
										disabled={ !! editingId }
										onChange={ ( e ) =>
											setForm( {
												...form,
												from: Number( e.target.value ),
											} )
										}
										required
									/>
								</label>
								<label>
									{ __(
										'To (permanent value)',
										'beyond-elysium'
									) }
									<input
										type="number"
										value={ form.to ?? '' }
										disabled={ !! editingId }
										onChange={ ( e ) =>
											setForm( {
												...form,
												to: Number( e.target.value ),
											} )
										}
										required
									/>
								</label>
							</>
						) }
					</>
				) }

				{ activeBlock?.section_type === 'identity_field' && (
					<>
						<label>
							{ __( 'Field', 'beyond-elysium' ) }
							<select
								value={ form.target_name }
								disabled={ !! editingId }
								onChange={ ( e ) =>
									setForm( {
										...form,
										target_type: 'field_option',
										target_name: e.target.value,
										option: undefined,
									} )
								}
								required
							>
								<option value="">
									{ __(
										'Choose a field…',
										'beyond-elysium'
									) }
								</option>
								{ fields
									.filter(
										( f ) => ( f.options ?? [] ).length > 0
									)
									.map( ( field ) => (
										<option
											key={ field.name }
											value={ field.name }
										>
											{ field.name }
										</option>
									) ) }
							</select>
						</label>
						{ selectedField && (
							<label>
								{ __( 'Option', 'beyond-elysium' ) }
								<select
									value={ form.option ?? '' }
									disabled={ !! editingId }
									onChange={ ( e ) =>
										setForm( {
											...form,
											option: e.target.value,
										} )
									}
									required
								>
									<option value="">
										{ __(
											'Choose an option…',
											'beyond-elysium'
										) }
									</option>
									{ ( selectedField.options ?? [] ).map(
										( option ) => (
											<option
												key={ option }
												value={ option }
											>
												{ option }
											</option>
										)
									) }
								</select>
							</label>
						) }
					</>
				) }

				{ activeBlock?.section_type === 'tiered_power' && (
					<>
						<label>
							{ __( 'Power', 'beyond-elysium' ) }
							<select
								value={ form.target_name }
								disabled={ !! editingId }
								onChange={ ( e ) =>
									setForm( {
										...form,
										target_name: e.target.value,
										level: undefined,
									} )
								}
								required
							>
								<option value="">
									{ __(
										'Choose a power…',
										'beyond-elysium'
									) }
								</option>
								{ powers.map( ( power ) => (
									<option
										key={ power.name }
										value={ power.name }
									>
										{ power.name }
									</option>
								) ) }
							</select>
						</label>

						{ form.target_name && (
							<label>
								{ __( 'Scope', 'beyond-elysium' ) }
								<select
									value={ form.target_type }
									disabled={ !! editingId }
									onChange={ ( e ) =>
										setForm( {
											...form,
											target_type: e.target
												.value as ApprovalRuleTargetType,
										} )
									}
								>
									<option value="power">
										{ __(
											'The whole power',
											'beyond-elysium'
										) }
									</option>
									<option value="level">
										{ __(
											'One level only',
											'beyond-elysium'
										) }
									</option>
								</select>
							</label>
						) }

						{ form.target_type === 'level' && selectedPower && (
							<label>
								{ __( 'Level', 'beyond-elysium' ) }
								<select
									value={ form.level ?? '' }
									disabled={ !! editingId }
									onChange={ ( e ) =>
										setForm( {
											...form,
											level: Number( e.target.value ),
										} )
									}
									required
								>
									<option value="">
										{ __(
											'Choose a level…',
											'beyond-elysium'
										) }
									</option>
									{ selectedPower.levels
										.filter( ( l ) => l.level !== null )
										.map( ( l ) => (
											<option
												key={ l.level }
												value={ l.level as number }
											>
												{ l.level } — { l.power_name }
											</option>
										) ) }
								</select>
							</label>
						) }
					</>
				) }

				<label>
					{ __( 'Approval level', 'beyond-elysium' ) }
					<select
						value={ form.approval }
						onChange={ ( e ) =>
							setForm( { ...form, approval: e.target.value } )
						}
					>
						<option value="">
							{ __(
								'(unset - a Storyteller decides)',
								'beyond-elysium'
							) }
						</option>
						{ options?.approval_levels.map( ( level ) => (
							<option key={ level } value={ level }>
								{ level }
							</option>
						) ) }
					</select>
				</label>

				<label>
					{ __( 'Reason preset', 'beyond-elysium' ) }
					<select
						value=""
						onChange={ ( e ) => {
							if ( e.target.value ) {
								setForm( {
									...form,
									reason: form.reason
										? `${ form.reason } — ${ e.target.value }`
										: e.target.value,
								} );
							}
						} }
					>
						<option value="">
							{ __( 'Insert a preset…', 'beyond-elysium' ) }
						</option>
						{ options?.reason_presets.map( ( preset ) => (
							<option key={ preset } value={ preset }>
								{ preset }
							</option>
						) ) }
					</select>
				</label>

				<label>
					{ __( 'Reason', 'beyond-elysium' ) }
					<textarea
						value={ form.reason }
						placeholder={ __(
							'e.g. Coordinator Approval — Tremere',
							'beyond-elysium'
						) }
						onChange={ ( e ) =>
							setForm( { ...form, reason: e.target.value } )
						}
					/>
					<AiAssistButton
						capability="be_manage_approval_rules"
						fieldContext="approval_reason"
						gameSlug={ gameSlug }
						currentValue={ form.reason ?? '' }
						onAccept={ ( reason ) =>
							setForm( { ...form, reason } )
						}
					/>
				</label>

				<div className="be-admin__form-actions">
					<button type="submit" disabled={ saving }>
						{ saving
							? __( 'Saving…', 'beyond-elysium' )
							: __( 'Save', 'beyond-elysium' ) }
					</button>
					{ editingId && (
						<button
							type="button"
							disabled={ saving }
							onClick={ startCreate }
						>
							{ __( 'Cancel', 'beyond-elysium' ) }
						</button>
					) }
				</div>
			</form>
		</div>
	);
}

export default AdminApprovalRules;
