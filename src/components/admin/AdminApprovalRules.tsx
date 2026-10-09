/**
 * Admin page for a chronicle's approval rules.
 */
import { useEffect, useRef, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import AiAssistButton from '../shared/AiAssistButton';
import HelpButton from '../shared/HelpButton';
import type {
	ApprovalRule,
	ApprovalRuleOptions,
	ApprovalRuleRequest,
	ApprovalRuleTargetType,
	BylawRule,
} from '../../api/client';
import type {
	CreatureStack,
	Game,
	IdentityField,
	ResourcePool,
	SchemaBlock,
	TieredPower,
	TraitListItem,
} from '../../types';
import { errorMessage } from '../../lib/errorMessage';
import {
	blockChoices,
	blockLabel,
	creatureTypesOfBlock,
	groupBlocksByCreatureType,
} from '../../lib/blockGroups';
import { SearchableSelect } from '../shared/SearchableSelect';
import { revealEditor, useRevealOnOpen } from '../../lib/revealEditor';
import { everyPage } from '../../lib/everyPage';
import { preselectedChronicle, writeGameToUrl } from '../../lib/pluginPages';
import './Admin.css';

const BYLAW_PAGE_SIZE = 50;

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
 * True for the two block-level scopes: the whole block, or its in-type/out-of-type split.
 */
function isBlockLevelType( type: ApprovalRuleTargetType ): boolean {
	return type === 'block_default' || type === 'block_in_type';
}

/**
 * Every block slug that at least one of this chronicle's creature types tests for in-type on.
 */
function blockSlugsWithInTypeTest( stacks: CreatureStack[] ): Set< string > {
	const slugs = new Set< string >();
	for ( const stack of stacks ) {
		for ( const section of stack.stack_definition?.sections ?? [] ) {
			if ( ( section.in_type ?? [] ).length > 0 ) {
				slugs.add( section.block_slug );
			}
		}
	}
	return slugs;
}

/**
 * Renders one rule's target column.
 */
function describeTarget( rule: ApprovalRule ): string {
	if ( rule.target_type === 'block_default' ) {
		return __( 'The whole block', 'beyond-elysium' );
	}
	if ( rule.target_type === 'block_in_type' && Array.isArray( rule.extra ) ) {
		return __( 'In-type / out-of-type', 'beyond-elysium' );
	}
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
 * Renders one rule's approval column: a single level, or the in-type/out-of-type pair.
 */
function describeApproval( rule: ApprovalRule ): string {
	if ( rule.target_type === 'block_in_type' && Array.isArray( rule.extra ) ) {
		const [ inType, outOfType ] = rule.extra;
		return (
			__( 'in-type', 'beyond-elysium' ) +
			`: ${ inType ?? '—' } / ` +
			__( 'out-of-type', 'beyond-elysium' ) +
			`: ${ outOfType ?? '—' }`
		);
	}
	return rule.approval ?? '—';
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
	const [ stacks, setStacks ] = useState< CreatureStack[] >( [] );
	const [ allStacks, setAllStacks ] = useState< CreatureStack[] >( [] );
	const [ activeBlock, setActiveBlock ] = useState< SchemaBlock | null >(
		null
	);
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState< string | null >( null );
	const [ saving, setSaving ] = useState( false );
	const [ savingDefault, setSavingDefault ] = useState( false );
	const [ autoApproveByDefault, setAutoApproveByDefault ] = useState( false );
	const [ approvalOnRemoval, setApprovalOnRemoval ] = useState( false );
	const [ owbnBylaws, setOwbnBylaws ] = useState( false );
	const [ bylawRows, setBylawRows ] = useState< BylawRule[] >( [] );
	const [ bylawSearch, setBylawSearch ] = useState( '' );
	const [ bylawTier, setBylawTier ] = useState( '' );
	const [ bylawAttachedOnly, setBylawAttachedOnly ] = useState<
		'' | 'true' | 'false'
	>( '' );
	const [ loadingBylaws, setLoadingBylaws ] = useState( false );
	const [ refreshingBylaws, setRefreshingBylaws ] = useState( false );
	const [ bylawMessage, setBylawMessage ] = useState< string | null >( null );
	const [ bylawRefreshToken, setBylawRefreshToken ] = useState( 0 );
	const [ bylawLimit, setBylawLimit ] = useState( BYLAW_PAGE_SIZE );

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
			api.creatureStacks.list( { game_slug: gameSlug } ),
			api.creatureStacks.list( {
				game_slug: gameSlug,
				include_disabled: true,
			} ),
		] )
			.then(
				( [
					ruleList,
					opts,
					blockList,
					policy,
					stackList,
					everyStack,
				] ) => {
					setRules( ruleList );
					setOptions( opts );
					setAutoApproveByDefault( policy.auto_approve );
					setApprovalOnRemoval( policy.approval_on_removal );
					setOwbnBylaws( policy.owbn_bylaws );
					setStacks( stackList );
					setAllStacks( everyStack );
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
				}
			)
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
					? ( rule.extra[ 0 ] as number )
					: undefined,
			to:
				isRangeType( rule.target_type ) && Array.isArray( rule.extra )
					? ( rule.extra[ 1 ] as number )
					: undefined,
			option:
				rule.target_type === 'field_option' &&
				typeof rule.extra === 'string'
					? rule.extra
					: undefined,
			in_type:
				rule.target_type === 'block_in_type' &&
				Array.isArray( rule.extra )
					? ( ( rule.extra[ 0 ] as string ) ?? undefined )
					: undefined,
			out_of_type:
				rule.target_type === 'block_in_type' &&
				Array.isArray( rule.extra )
					? ( ( rule.extra[ 1 ] as string ) ?? undefined )
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
				.setDefaultPolicy( { auto_approve: auto } );
			setAutoApproveByDefault( policy.auto_approve );
			setApprovalOnRemoval( policy.approval_on_removal );
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

	/**
	 * Saves the chronicle's removal/lowering switch: once on, a removal, a lower rating, a relabel or a rename always
	 * waits for a Storyteller, and anything submitted together with one waits with it.
	 */
	async function saveApprovalOnRemoval( on: boolean ) {
		if ( ! gameSlug ) {
			return;
		}
		setSavingDefault( true );
		try {
			const policy = await api
				.approvalRules( gameSlug )
				.setDefaultPolicy( { approval_on_removal: on } );
			setAutoApproveByDefault( policy.auto_approve );
			setApprovalOnRemoval( policy.approval_on_removal );
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

	/**
	 * Saves the chronicle's OWBN Character Bylaws switch: once on, every bylaw attached to a purchased entry adds
	 * its own reason, on top of whatever this chronicle's own rules already say.
	 */
	async function saveOwbnBylaws( on: boolean ) {
		if ( ! gameSlug ) {
			return;
		}
		setSavingDefault( true );
		try {
			const policy = await api
				.approvalRules( gameSlug )
				.setDefaultPolicy( { owbn_bylaws: on } );
			setAutoApproveByDefault( policy.auto_approve );
			setApprovalOnRemoval( policy.approval_on_removal );
			setOwbnBylaws( policy.owbn_bylaws );
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

	// Loads the bylaws reference list for the current filters, once a chronicle is picked.
	useEffect( () => {
		if ( ! gameSlug ) {
			return;
		}
		setLoadingBylaws( true );
		setBylawLimit( BYLAW_PAGE_SIZE );
		api.approvalRules( gameSlug )
			.bylaws( {
				search: bylawSearch || undefined,
				tier: bylawTier || undefined,
				attached:
					bylawAttachedOnly === ''
						? undefined
						: bylawAttachedOnly === 'true',
			} )
			.then( setBylawRows )
			.catch( () => setBylawRows( [] ) )
			.finally( () => setLoadingBylaws( false ) );
	}, [
		gameSlug,
		bylawSearch,
		bylawTier,
		bylawAttachedOnly,
		bylawRefreshToken,
	] );

	/**
	 * Re-pulls every Character Bylaw rule from council.owbn.net, keeping this chronicle's own attachments for every
	 * clause that still exists.
	 */
	async function refreshBylaws() {
		if ( ! gameSlug ) {
			return;
		}
		setRefreshingBylaws( true );
		setBylawMessage( null );
		try {
			const result = await api.approvalRules( gameSlug ).refreshBylaws();
			setBylawMessage(
				sprintf(
					/* translators: 1: number of rules now known, 2: number newly added, 3: number removed */
					__(
						'%1$d rules, %2$d added, %3$d removed since the last refresh.',
						'beyond-elysium'
					),
					result.rule_count,
					result.added,
					result.removed
				)
			);
			setBylawRefreshToken( ( t ) => t + 1 );
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setRefreshingBylaws( false );
		}
	}

	/**
	 * Uploads an already-built bylaws file, for a site that can't reach council.owbn.net directly.
	 */
	async function uploadBylawsFile( file: File ) {
		setRefreshingBylaws( true );
		setBylawMessage( null );
		try {
			const result = await api.uploadBylaws( file );
			setBylawMessage(
				sprintf(
					/* translators: 1: number of rules, 2: number of attachments */
					__( '%1$d rules, %2$d attachments.', 'beyond-elysium' ),
					result.rule_count,
					result.attachment_count
				)
			);
			setBylawRefreshToken( ( t ) => t + 1 );
		} catch ( err ) {
			setError(
				errorMessage(
					err,
					__( 'Something went wrong.', 'beyond-elysium' )
				)
			);
		} finally {
			setRefreshingBylaws( false );
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
		if (
			form.target_type === 'block_in_type' &&
			( ! form.in_type || ! form.out_of_type )
		) {
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
			? ( ( activeBlock.definition as { items: TraitListItem[] } )
					.items ?? [] )
			: [];
	const powers: TieredPower[] =
		activeBlock?.section_type === 'tiered_power'
			? ( ( activeBlock.definition as { powers: TieredPower[] } )
					.powers ?? [] )
			: [];
	const pools: ResourcePool[] =
		activeBlock?.section_type === 'resource_pool'
			? ( ( activeBlock.definition as { pools: ResourcePool[] } ).pools ??
				[] )
			: [];
	const fields: IdentityField[] =
		activeBlock?.section_type === 'identity_field'
			? ( ( activeBlock.definition as { fields: IdentityField[] } )
					.fields ?? [] )
			: [];
	const selectedPower = powers.find( ( p ) => p.name === form.target_name );
	const selectedField = fields.find( ( f ) => f.name === form.target_name );

	// Whether the current block/target combination shows an "Approval level" dropdown at all.

	const creatureTypeOwners = creatureTypesOfBlock( allStacks, blocks );
	const enabledStackSlugs = new Set( stacks.map( ( stack ) => stack.slug ) );
	const blockGroups = groupBlocksByCreatureType(
		blocks,
		allStacks,
		enabledStackSlugs,
		{
			shared: __( 'Used by several creature types', 'beyond-elysium' ),
			other: __( 'Not part of a creature type', 'beyond-elysium' ),
			notEnabled: ( name ) =>
				sprintf(
					/* translators: %s: creature type name */
					__(
						'%s (not enabled in this chronicle)',
						'beyond-elysium'
					),
					name
				),
		}
	);
	const blockPick = blockChoices( blockGroups, creatureTypeOwners, blocks );

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

					<h2>
						{ __(
							'Removals, lower ratings, relabels and renames',
							'beyond-elysium'
						) }
					</h2>
					<p className="description">
						{ __(
							'Once on, a removal, a lower rating, a relabel or a rename always waits for a Storyteller, whatever a rule above says - and anything submitted together with one waits with it, as one set.',
							'beyond-elysium'
						) }
					</p>
					<label>
						<input
							type="checkbox"
							checked={ approvalOnRemoval }
							disabled={ savingDefault }
							onChange={ ( e ) =>
								saveApprovalOnRemoval( e.target.checked )
							}
						/>{ ' ' }
						{ __(
							'Always wait for a Storyteller on a removal, a lower rating, a relabel or a rename',
							'beyond-elysium'
						) }
					</label>

					<h2>{ __( 'OWBN Character Bylaws', 'beyond-elysium' ) }</h2>
					<p className="description">
						{ __(
							'Once on, every OWBN Character Bylaw attached to a purchased merit, flaw, background, ability, or power adds its own reason, on top of anything this chronicle already requires.',
							'beyond-elysium'
						) }
					</p>
					<label>
						<input
							type="checkbox"
							checked={ owbnBylaws }
							disabled={ savingDefault }
							onChange={ ( e ) =>
								saveOwbnBylaws( e.target.checked )
							}
						/>{ ' ' }
						{ __(
							'Require OWBN Character Bylaw approval',
							'beyond-elysium'
						) }
					</label>
				</div>
			) }

			<p>
				<button
					type="button"
					onClick={ () => {
						startCreate();
						window.requestAnimationFrame( () =>
							revealEditor( editorRef.current )
						);
					} }
				>
					{ __( 'New Rule', 'beyond-elysium' ) }
				</button>
			</p>

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
								<td>
									{ blockLabel(
										rule.block_name,
										rule.block_slug,
										creatureTypeOwners,
										blocks
									) }
								</td>
								<td>{ describeTarget( rule ) }</td>
								<td>{ describeApproval( rule ) }</td>
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
					<SearchableSelect
						key={ `${ editingId ?? 'new' }-${ form.block_slug }-${ blockPick.labelOf( form.block_slug ) }` }
						groups={ blockPick.groups }
						value={ blockPick.labelOf( form.block_slug ) }
						placeholder={ __(
							'Choose a block…',
							'beyond-elysium'
						) }
						ariaLabel={ __( 'Block', 'beyond-elysium' ) }
						disabled={ !! editingId }
						onChange={ ( label ) => {
							const slug = blockPick.slugOf( label );
							if ( ! slug || slug === form.block_slug ) {
								return;
							}
							setForm( {
								...form,
								block_slug: slug,
								target_name: '',
								level: undefined,
								from: undefined,
								to: undefined,
								option: undefined,
							} );
						} }
					/>
				</label>

				{ activeBlock && (
					<label>
						{ __( 'Scope', 'beyond-elysium' ) }
						<select
							value={
								isBlockLevelType( form.target_type )
									? form.target_type
									: 'specific'
							}
							disabled={ !! editingId }
							onChange={ ( e ) => {
								const value = e.target.value;
								if ( value === 'specific' ) {
									setForm( {
										...form,
										target_type: 'item',
										target_name: '',
										level: undefined,
										from: undefined,
										to: undefined,
										option: undefined,
										in_type: undefined,
										out_of_type: undefined,
									} );
									return;
								}
								setForm( {
									...form,
									target_type:
										value as ApprovalRuleTargetType,
									target_name: form.block_slug,
									level: undefined,
									from: undefined,
									to: undefined,
									option: undefined,
									in_type: undefined,
									out_of_type: undefined,
								} );
							} }
						>
							<option value="specific">
								{ __(
									'A specific item, power, pool, or field…',
									'beyond-elysium'
								) }
							</option>
							<option value="block_default">
								{ __( 'The whole block', 'beyond-elysium' ) }
							</option>
							{ activeBlock.section_type === 'tiered_power' &&
								blockSlugsWithInTypeTest( stacks ).has(
									activeBlock.slug
								) && (
									<option value="block_in_type">
										{ __(
											'In-type / out-of-type',
											'beyond-elysium'
										) }
									</option>
								) }
						</select>
					</label>
				) }

				{ form.target_type === 'block_in_type' && (
					<>
						<label>
							{ __( 'In-type approval level', 'beyond-elysium' ) }
							<select
								value={ form.in_type ?? '' }
								disabled={ !! editingId }
								onChange={ ( e ) =>
									setForm( {
										...form,
										in_type: e.target.value,
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
								{ options?.approval_levels.map( ( level ) => (
									<option key={ level } value={ level }>
										{ level }
									</option>
								) ) }
							</select>
						</label>
						<label>
							{ __(
								'Out-of-type approval level',
								'beyond-elysium'
							) }
							<select
								value={ form.out_of_type ?? '' }
								disabled={ !! editingId }
								onChange={ ( e ) =>
									setForm( {
										...form,
										out_of_type: e.target.value,
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
								{ options?.approval_levels.map( ( level ) => (
									<option key={ level } value={ level }>
										{ level }
									</option>
								) ) }
							</select>
						</label>
					</>
				) }

				{ ! isBlockLevelType( form.target_type ) &&
					activeBlock?.section_type === 'trait_list' && (
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
													from: Number(
														e.target.value
													),
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
													to: Number(
														e.target.value
													),
												} )
											}
											required
										/>
									</label>
								</>
							) }
						</>
					) }

				{ ! isBlockLevelType( form.target_type ) &&
					activeBlock?.section_type === 'resource_pool' && (
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
										{ __(
											'Choose a pool…',
											'beyond-elysium'
										) }
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
													from: Number(
														e.target.value
													),
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
													to: Number(
														e.target.value
													),
												} )
											}
											required
										/>
									</label>
								</>
							) }
						</>
					) }

				{ ! isBlockLevelType( form.target_type ) &&
					activeBlock?.section_type === 'identity_field' && (
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
											( f ) =>
												( f.options ?? [] ).length > 0
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

				{ ! isBlockLevelType( form.target_type ) &&
					activeBlock?.section_type === 'tiered_power' && (
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
													{ l.level } —{ ' ' }
													{ l.power_name }
												</option>
											) ) }
									</select>
								</label>
							) }
						</>
					) }

				{ form.target_type !== 'block_in_type' && (
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
				) }

				{ form.target_type !== 'block_in_type' && (
					<>
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
									{ __(
										'Insert a preset…',
										'beyond-elysium'
									) }
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
									setForm( {
										...form,
										reason: e.target.value,
									} )
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
					</>
				) }

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

			{ gameSlug && (
				<details className="be-admin__bylaws-panel">
					<summary>
						{ sprintf(
							/* translators: %d: number of OWBN Character Bylaw rules listed */
							__(
								'OWBN Character Bylaws reference (%d)',
								'beyond-elysium'
							),
							bylawRows.length
						) }
					</summary>
					<div className="be-admin__bylaws">
						<div className="be-admin__bylaws-toolbar">
							<input
								type="search"
								placeholder={ __(
									'Search by subject…',
									'beyond-elysium'
								) }
								value={ bylawSearch }
								onChange={ ( e ) =>
									setBylawSearch( e.target.value )
								}
							/>
							<input
								type="text"
								placeholder={ __(
									'Tier (e.g. Disallowed)',
									'beyond-elysium'
								) }
								value={ bylawTier }
								onChange={ ( e ) =>
									setBylawTier( e.target.value )
								}
							/>
							<select
								value={ bylawAttachedOnly }
								onChange={ ( e ) =>
									setBylawAttachedOnly(
										e.target.value as '' | 'true' | 'false'
									)
								}
							>
								<option value="">
									{ __(
										'Attached or not',
										'beyond-elysium'
									) }
								</option>
								<option value="true">
									{ __( 'Attached only', 'beyond-elysium' ) }
								</option>
								<option value="false">
									{ __(
										'Unattached only',
										'beyond-elysium'
									) }
								</option>
							</select>
							<button
								type="button"
								disabled={ refreshingBylaws }
								onClick={ refreshBylaws }
							>
								{ __(
									'Refresh from council.owbn.net',
									'beyond-elysium'
								) }
							</button>
							<label className="be-admin__bylaws-upload">
								{ __( 'Upload a file', 'beyond-elysium' ) }
								<input
									type="file"
									accept="application/json"
									disabled={ refreshingBylaws }
									onChange={ ( e ) => {
										const file = e.target.files?.[ 0 ];
										if ( file ) {
											uploadBylawsFile( file );
										}
										e.target.value = '';
									} }
								/>
							</label>
						</div>
						{ bylawMessage && (
							<p className="description">{ bylawMessage }</p>
						) }
						{ loadingBylaws ? (
							<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
						) : (
							<table className="be-admin__table">
								<thead>
									<tr>
										<th>
											{ __( 'Clause', 'beyond-elysium' ) }
										</th>
										<th>
											{ __(
												'Subject',
												'beyond-elysium'
											) }
										</th>
										<th>
											{ __( 'PC', 'beyond-elysium' ) }
										</th>
										<th>
											{ __( 'NPC', 'beyond-elysium' ) }
										</th>
										<th>
											{ __(
												'Coordinator(s)',
												'beyond-elysium'
											) }
										</th>
										<th>
											{ __(
												'Attached to',
												'beyond-elysium'
											) }
										</th>
									</tr>
								</thead>
								<tbody>
									{ bylawRows
										.slice( 0, bylawLimit )
										.map( ( rule ) => (
											<tr key={ rule.clause_id }>
												<td>
													<a
														href={ rule.link }
														target="_blank"
														rel="noreferrer"
													>
														{ rule.path }
													</a>
												</td>
												<td>{ rule.subject }</td>
												<td>{ rule.pc ?? '—' }</td>
												<td>{ rule.npc ?? '—' }</td>
												<td>
													{ rule.coordinators.join(
														', '
													) || '—' }
												</td>
												<td>
													{ rule.attachments
														.length === 0
														? '—'
														: rule.attachments
																.map(
																	( a ) =>
																		`${ a.family }: ${ a.name }`
																)
																.join( ', ' ) }
												</td>
											</tr>
										) ) }
								</tbody>
							</table>
						) }
						{ ! loadingBylaws && bylawRows.length > bylawLimit && (
							<p className="be-admin__bylaws-more">
								{ sprintf(
									/* translators: 1: how many bylaw rules are listed, 2: how many match */
									__(
										'Showing %1$d of %2$d.',
										'beyond-elysium'
									),
									bylawLimit,
									bylawRows.length
								) }{ ' ' }
								<button
									type="button"
									onClick={ () =>
										setBylawLimit(
											( n ) => n + BYLAW_PAGE_SIZE
										)
									}
								>
									{ __( 'Show more', 'beyond-elysium' ) }
								</button>
							</p>
						) }
					</div>
				</details>
			) }
		</div>
	);
}

export default AdminApprovalRules;
