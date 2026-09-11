/**
 * Structured definition editor for a schema block's section_type payload.
 *
 * Dispatches to one of four type-specific sub-editors (trait list, tiered
 * power, resource pool, identity field) based on the block's section type,
 * with a collapsible raw-JSON fallback for shapes the structured editors
 * don't fully cover. Exports SchemaBlockDefinitionEditor as the entry
 * point used by the Schema Blocks admin page.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type {
	ApprovalLevel,
	CrossBlockRef,
	IdentityField,
	IdentityFieldDefinition,
	PowerLevel,
	ResourcePool,
	ResourcePoolDefinition,
	SectionType,
	TieredPower,
	TieredPowerDefinition,
	TraitListDefinition,
	TraitListItem,
} from '../../types';

const APPROVAL_LEVELS: ApprovalLevel[] = [ 'auto', 'st', 'coordinator' ];
import './Admin.css';

export interface SchemaBlockDefinitionEditorProps {
	sectionType: SectionType;
	definition: Record<string, unknown>;
	onChange: ( definition: Record<string, unknown> ) => void;
}

/**
 * Renders the definition editor for one schema block, choosing the
 * matching structured sub-editor for the block's section type and keeping
 * a raw JSON textarea in sync with whatever the structured editor
 * produces. The raw JSON view stays available behind a collapsible toggle
 * for manual edits.
 */
export function SchemaBlockDefinitionEditor( { sectionType, definition, onChange }: SchemaBlockDefinitionEditorProps ) {
	const [ showRaw, setShowRaw ] = useState( false );
	const [ rawJson, setRawJson ] = useState( () => JSON.stringify( definition, null, 2 ) );
	const [ rawError, setRawError ] = useState<string | null>( null );

	/**
	 * Parses the raw JSON textarea and, if valid, applies it as the new
	 * section definition. Leaves the current definition untouched and shows
	 * an error message when the input isn't valid JSON.
	 */
	function applyRaw() {
		try {
			const parsed = JSON.parse( rawJson );
			setRawError( null );
			onChange( parsed );
		} catch {
			setRawError( __( 'Not valid JSON - not applied.', 'beyond-elysium' ) );
		}
	}

	/**
	 * Applies a definition produced by one of the structured sub-editors and
	 * re-serializes it into the raw JSON textarea, keeping the two views in
	 * sync with each other.
	 */
	function syncRawFromStructured( next: Record<string, unknown> ) {
		onChange( next );
		setRawJson( JSON.stringify( next, null, 2 ) );
	}

	return (
		<div className="be-def-editor">
			{ sectionType === 'trait_list' && (
				<TraitListEditor definition={ definition as unknown as TraitListDefinition } onChange={ syncRawFromStructured } />
			) }
			{ sectionType === 'tiered_power' && (
				<TieredPowerEditor definition={ definition as unknown as TieredPowerDefinition } onChange={ syncRawFromStructured } />
			) }
			{ sectionType === 'resource_pool' && (
				<ResourcePoolEditor definition={ definition as unknown as ResourcePoolDefinition } onChange={ syncRawFromStructured } />
			) }
			{ sectionType === 'identity_field' && (
				<IdentityFieldEditorAdmin definition={ definition as unknown as IdentityFieldDefinition } onChange={ syncRawFromStructured } />
			) }

			<button type="button" className="be-def-editor__raw-toggle" onClick={ () => setShowRaw( ! showRaw ) }>
				{ sprintf(
					// translators: %s: "Hide" or "Show".
					__( '%s advanced (raw JSON)', 'beyond-elysium' ),
					showRaw ? __( 'Hide', 'beyond-elysium' ) : __( 'Show', 'beyond-elysium' )
				) }
			</button>
			{ showRaw && (
				<div className="be-def-editor__raw">
					<textarea rows={ 10 } value={ rawJson } onChange={ ( e ) => setRawJson( e.target.value ) } />
					{ rawError && <p className="be-admin__json-error">{ rawError }</p> }
					<button type="button" onClick={ applyRaw }>
						{ __( 'Apply JSON', 'beyond-elysium' ) }
					</button>
				</div>
			) }
		</div>
	);
}

// ---------------------------------------------------------------------------
// trait_list
// ---------------------------------------------------------------------------

/**
 * Structured editor for a trait_list section definition. Renders the
 * global flags (multiples, custom entries, alphabetize, negative list,
 * atomic, max per item) and an add/edit/remove table of individual trait
 * items, each with a cost, category, and description.
 */
function TraitListEditor( { definition, onChange }: { definition: TraitListDefinition; onChange: ( d: Record<string, unknown> ) => void } ) {
	const items = definition.items ?? [];

	function updateFlag<K extends keyof TraitListDefinition>( key: K, value: TraitListDefinition[ K ] ) {
		onChange( { ...definition, [ key ]: value } as unknown as Record<string, unknown> );
	}

	function updateItem( index: number, patch: Partial<TraitListItem> ) {
		const next = items.map( ( item, i ) => ( i === index ? { ...item, ...patch } : item ) );
		onChange( { ...definition, items: next } as unknown as Record<string, unknown> );
	}

	function addItem() {
		onChange( { ...definition, items: [ ...items, { name: '' } ] } as unknown as Record<string, unknown> );
	}

	function removeItem( index: number ) {
		onChange( { ...definition, items: items.filter( ( _, i ) => i !== index ) } as unknown as Record<string, unknown> );
	}

	return (
		<div className="be-def-editor__section">
			<h3>{ __( 'Global settings', 'beyond-elysium' ) }</h3>
			<div className="be-def-editor__flags">
				<label>
					<input type="checkbox" checked={ !! definition.allow_multiples } onChange={ ( e ) => updateFlag( 'allow_multiples', e.target.checked ) } />
					{ ' ' }{ __( 'Allow multiple selections', 'beyond-elysium' ) }
				</label>
				<label>
					<input type="checkbox" checked={ !! definition.allow_custom } onChange={ ( e ) => updateFlag( 'allow_custom', e.target.checked ) } />
					{ ' ' }{ __( 'Allow custom entries', 'beyond-elysium' ) }
				</label>
				<label>
					<input type="checkbox" checked={ !! definition.alphabetize } onChange={ ( e ) => updateFlag( 'alphabetize', e.target.checked ) } />
					{ ' ' }{ __( 'Alphabetize', 'beyond-elysium' ) }
				</label>
				<label>
					<input type="checkbox" checked={ !! definition.negative } onChange={ ( e ) => updateFlag( 'negative', e.target.checked ) } />
					{ ' ' }{ __( 'Negative list (flaws-style)', 'beyond-elysium' ) }
				</label>
				<label>
					<input type="checkbox" checked={ !! definition.atomic } onChange={ ( e ) => updateFlag( 'atomic', e.target.checked ) } />
					{ ' ' }{ __( "Atomic (re-adding appends, doesn't increment)", 'beyond-elysium' ) }
				</label>
				<label>
					{ __( 'Max per item', 'beyond-elysium' ) }{ ' ' }
					<input
						type="number"
						value={ definition.max_per_item ?? '' }
						onChange={ ( e ) => updateFlag( 'max_per_item', e.target.value ? Number( e.target.value ) : undefined ) }
					/>
				</label>
			</div>

			<h3>{ sprintf( __( 'Items (%d)', 'beyond-elysium' ), items.length ) }</h3>
			<table className="be-def-editor__table">
				<thead>
					<tr>
						<th>{ __( 'Name', 'beyond-elysium' ) }</th>
						<th>{ __( 'Cost', 'beyond-elysium' ) }</th>
						<th>{ __( 'Category', 'beyond-elysium' ) }</th>
						<th>{ __( 'Description', 'beyond-elysium' ) }</th>
						<th>{ __( 'Approval', 'beyond-elysium' ) }</th>
						<th>{ __( 'Reason', 'beyond-elysium' ) }</th>
						<th />
					</tr>
				</thead>
				<tbody>
					{ items.map( ( item, i ) => (
						<tr key={ i }>
							<td>
								<input
									type="text"
									aria-label={ sprintf( __( 'Name for item %d', 'beyond-elysium' ), i + 1 ) }
									value={ item.name }
									onChange={ ( e ) => updateItem( i, { name: e.target.value } ) }
								/>
							</td>
							<td>
								<input
									type="text"
									aria-label={ sprintf( __( 'Cost for %s', 'beyond-elysium' ), item.name ) }
									value={ item.cost ?? '' }
									placeholder={ __( '1, 1-3, 1 or 3…', 'beyond-elysium' ) }
									onChange={ ( e ) => updateItem( i, { cost: e.target.value } ) }
								/>
							</td>
							<td>
								<input
									type="text"
									aria-label={ sprintf( __( 'Category for %s', 'beyond-elysium' ), item.name ) }
									value={ item.category ?? '' }
									onChange={ ( e ) => updateItem( i, { category: e.target.value } ) }
								/>
							</td>
							<td>
								<input
									type="text"
									aria-label={ sprintf( __( 'Description for %s', 'beyond-elysium' ), item.name ) }
									value={ item.description ?? '' }
									onChange={ ( e ) => updateItem( i, { description: e.target.value } ) }
								/>
							</td>
							<td>
								<select
									aria-label={ sprintf( __( 'Approval level for %s', 'beyond-elysium' ), item.name ) }
									value={ item.approval ?? '' }
									onChange={ ( e ) => updateItem( i, { approval: ( e.target.value || undefined ) as ApprovalLevel | undefined } ) }
								>
									<option value="">{ __( 'Block default', 'beyond-elysium' ) }</option>
									{ APPROVAL_LEVELS.map( ( level ) => (
										<option key={ level } value={ level }>{ level }</option>
									) ) }
								</select>
							</td>
							<td>
								<input
									type="text"
									aria-label={ sprintf( __( 'Approval reason for %s', 'beyond-elysium' ), item.name ) }
									value={ item.reason ?? '' }
									placeholder={ __( 'Requires Tremere Coordinator approval…', 'beyond-elysium' ) }
									onChange={ ( e ) => updateItem( i, { reason: e.target.value || undefined } ) }
								/>
							</td>
							<td>
								<button type="button" onClick={ () => removeItem( i ) }>
									{ __( 'Remove', 'beyond-elysium' ) }
								</button>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			<button type="button" onClick={ addItem }>
				{ __( '+ Add item', 'beyond-elysium' ) }
			</button>
		</div>
	);
}

// ---------------------------------------------------------------------------
// tiered_power
// ---------------------------------------------------------------------------

/**
 * Structured editor for a tiered_power section definition. Renders global
 * flags (sequential levels, out-of-type cost modifier) and an add/edit/
 * remove list of power families, each with its own nested table of levels
 * (level number, tier, power name, cost).
 */
function TieredPowerEditor( { definition, onChange }: { definition: TieredPowerDefinition; onChange: ( d: Record<string, unknown> ) => void } ) {
	const powers = definition.powers ?? [];

	function updateFlag<K extends keyof TieredPowerDefinition>( key: K, value: TieredPowerDefinition[ K ] ) {
		onChange( { ...definition, [ key ]: value } as unknown as Record<string, unknown> );
	}

	function updatePower( index: number, patch: Partial<TieredPower> ) {
		const next = powers.map( ( p, i ) => ( i === index ? { ...p, ...patch } : p ) );
		onChange( { ...definition, powers: next } as unknown as Record<string, unknown> );
	}

	function addPower() {
		onChange( { ...definition, powers: [ ...powers, { name: '', levels: [] } ] } as unknown as Record<string, unknown> );
	}

	function removePower( index: number ) {
		onChange( { ...definition, powers: powers.filter( ( _, i ) => i !== index ) } as unknown as Record<string, unknown> );
	}

	function addLevel( powerIndex: number ) {
		const power = powers[ powerIndex ];
		const levels: PowerLevel[] = [ ...power.levels, { level: null, tier: 'basic', power_name: '' } ];
		updatePower( powerIndex, { levels } );
	}

	function updateLevel( powerIndex: number, levelIndex: number, patch: Partial<PowerLevel> ) {
		const power = powers[ powerIndex ];
		const levels = power.levels.map( ( l, i ) => ( i === levelIndex ? { ...l, ...patch } : l ) );
		updatePower( powerIndex, { levels } );
	}

	function removeLevel( powerIndex: number, levelIndex: number ) {
		const power = powers[ powerIndex ];
		updatePower( powerIndex, { levels: power.levels.filter( ( _, i ) => i !== levelIndex ) } );
	}

	return (
		<div className="be-def-editor__section">
			<h3>{ __( 'Global settings', 'beyond-elysium' ) }</h3>
			<div className="be-def-editor__flags">
				<label>
					<input type="checkbox" checked={ !! definition.sequential } onChange={ ( e ) => updateFlag( 'sequential', e.target.checked ) } />
					{ ' ' }{ __( 'Sequential (holding a level implies every level below it)', 'beyond-elysium' ) }
				</label>
				<label>
					{ __( 'Out-of-type cost modifier', 'beyond-elysium' ) }{ ' ' }
					<input
						type="number"
						value={ definition.out_of_type_cost_modifier ?? '' }
						onChange={ ( e ) => updateFlag( 'out_of_type_cost_modifier', e.target.value ? Number( e.target.value ) : undefined ) }
					/>
				</label>
			</div>

			<h3>{ sprintf( __( 'Powers (%d)', 'beyond-elysium' ), powers.length ) }</h3>
			{ powers.map( ( power, pi ) => (
				<div className="be-def-editor__power" key={ pi }>
					<div className="be-def-editor__power-header">
						<input
							type="text"
							value={ power.name }
							placeholder={ __( 'Power family name (e.g. Celerity)', 'beyond-elysium' ) }
							onChange={ ( e ) => updatePower( pi, { name: e.target.value } ) }
						/>
						<select
							aria-label={ sprintf( __( 'Approval override for the whole %s power', 'beyond-elysium' ), power.name ) }
							value={ power.approval_override ?? '' }
							onChange={ ( e ) => updatePower( pi, { approval_override: ( e.target.value || undefined ) as ApprovalLevel | undefined } ) }
						>
							<option value="">{ __( 'Block default', 'beyond-elysium' ) }</option>
							{ APPROVAL_LEVELS.map( ( level ) => (
								<option key={ level } value={ level }>{ level }</option>
							) ) }
						</select>
						<button type="button" onClick={ () => removePower( pi ) }>
							{ __( 'Remove power', 'beyond-elysium' ) }
						</button>
					</div>
					<table className="be-def-editor__table">
						<thead>
							<tr>
								<th>{ __( 'Level (blank = Elder+)', 'beyond-elysium' ) }</th>
								<th>{ __( 'Tier', 'beyond-elysium' ) }</th>
								<th>{ __( 'Power name', 'beyond-elysium' ) }</th>
								<th>{ __( 'Cost', 'beyond-elysium' ) }</th>
								<th>{ __( 'Approval reason', 'beyond-elysium' ) }</th>
								<th />
							</tr>
						</thead>
						<tbody>
							{ power.levels.map( ( level, li ) => (
								<tr key={ li }>
									<td>
										<input
											type="number"
											aria-label={ sprintf( __( 'Level for %s', 'beyond-elysium' ), level.power_name ) }
											value={ level.level ?? '' }
											onChange={ ( e ) =>
												updateLevel( pi, li, { level: e.target.value ? Number( e.target.value ) : null } )
											}
										/>
									</td>
									<td>
										<input
											type="text"
											aria-label={ sprintf( __( 'Tier for %s', 'beyond-elysium' ), level.power_name ) }
											value={ level.tier }
											onChange={ ( e ) => updateLevel( pi, li, { tier: e.target.value } ) }
										/>
									</td>
									<td>
										<input
											type="text"
											aria-label={ sprintf(
												/* translators: 1: power family name, 2: level row position number */
												__( 'Power name for %1$s, level %2$d', 'beyond-elysium' ),
												power.name,
												li + 1
											) }
											value={ level.power_name }
											onChange={ ( e ) => updateLevel( pi, li, { power_name: e.target.value } ) }
										/>
									</td>
									<td>
										<input
											type="text"
											aria-label={ sprintf( __( 'Cost for %s', 'beyond-elysium' ), level.power_name ) }
											value={ level.cost ?? '' }
											onChange={ ( e ) => updateLevel( pi, li, { cost: e.target.value } ) }
										/>
									</td>
									<td>
										<input
											type="text"
											aria-label={ sprintf(
												/* translators: 1: power family name, 2: level row position number */
												__( 'Approval reason for %1$s, level %2$d', 'beyond-elysium' ),
												power.name,
												li + 1
											) }
											value={ level.reason ?? '' }
											placeholder={ __( 'Requires Giovanni Coordinator approval…', 'beyond-elysium' ) }
											onChange={ ( e ) => updateLevel( pi, li, { reason: e.target.value || undefined } ) }
										/>
									</td>
									<td>
										<button type="button" onClick={ () => removeLevel( pi, li ) }>
											{ __( 'Remove', 'beyond-elysium' ) }
										</button>
									</td>
								</tr>
							) ) }
						</tbody>
					</table>
					<button type="button" onClick={ () => addLevel( pi ) }>
						{ __( '+ Add level', 'beyond-elysium' ) }
					</button>
				</div>
			) ) }
			<button type="button" onClick={ addPower }>
				{ __( '+ Add power', 'beyond-elysium' ) }
			</button>
		</div>
	);
}

// ---------------------------------------------------------------------------
// resource_pool
// ---------------------------------------------------------------------------

/**
 * Structured editor for a resource_pool section definition. Renders an
 * add/edit/remove table of pools, each with a value type, default/min/max/
 * step values, and an optional name-lookup table that overrides the pool's
 * displayed name based on another block's field value.
 */
function ResourcePoolEditor( { definition, onChange }: { definition: ResourcePoolDefinition; onChange: ( d: Record<string, unknown> ) => void } ) {
	const pools = definition.pools ?? [];

	function updatePool( index: number, patch: Partial<ResourcePool> ) {
		const next = pools.map( ( p, i ) => ( i === index ? { ...p, ...patch } : p ) );
		onChange( { pools: next } );
	}

	function addPool() {
		onChange( { pools: [ ...pools, { name: '', value_type: 'integer', default_start: 0 } ] } );
	}

	function removePool( index: number ) {
		onChange( { pools: pools.filter( ( _, i ) => i !== index ) } );
	}

	/**
	 * The next four helpers manage a pool's optional name-lookup table, which
	 * overrides the pool's display name by reading a value from another
	 * schema block (for example, showing "Conviction" instead of "Morality"
	 * when a character's identity field selects that option). They keep the
	 * keyed-by block/field reference and the value-to-label table in sync as
	 * rows are added, edited, or removed.
	 */
	function setLookupKeyedBy( index: number, patch: Partial<CrossBlockRef> ) {
		const pool = pools[ index ];
		const keyed_by = { ...( pool.name_lookup?.keyed_by ?? { block_slug: '', field: '' } ), ...patch };
		updatePool( index, { name_lookup: { keyed_by, table: pool.name_lookup?.table ?? {} } } );
	}

	function addLookupRow( index: number ) {
		const pool = pools[ index ];
		const keyed_by = pool.name_lookup?.keyed_by ?? { block_slug: '', field: '' };
		const table = { ...( pool.name_lookup?.table ?? {} ), '': '' };
		updatePool( index, { name_lookup: { keyed_by, table } } );
	}

	function updateLookupRow( index: number, oldKey: string, newKey: string, value: string ) {
		const pool = pools[ index ];
		const table: Record<string, string> = {};
		for ( const [ k, v ] of Object.entries( pool.name_lookup?.table ?? {} ) ) {
			table[ k === oldKey ? newKey : k ] = k === oldKey ? value : v;
		}
		updatePool( index, { name_lookup: { keyed_by: pool.name_lookup?.keyed_by ?? { block_slug: '', field: '' }, table } } );
	}

	function removeLookupRow( index: number, key: string ) {
		const pool = pools[ index ];
		const table = { ...( pool.name_lookup?.table ?? {} ) };
		delete table[ key ];
		if ( Object.keys( table ).length === 0 && ! pool.name_lookup?.keyed_by.block_slug ) {
			updatePool( index, { name_lookup: undefined } );
		} else {
			updatePool( index, { name_lookup: { keyed_by: pool.name_lookup?.keyed_by ?? { block_slug: '', field: '' }, table } } );
		}
	}

	return (
		<div className="be-def-editor__section">
			<h3>{ sprintf( __( 'Pools (%d)', 'beyond-elysium' ), pools.length ) }</h3>
			<table className="be-def-editor__table">
				<thead>
					<tr>
						<th>{ __( 'Name', 'beyond-elysium' ) }</th>
						<th>{ __( 'Value type', 'beyond-elysium' ) }</th>
						<th>{ __( 'Default start', 'beyond-elysium' ) }</th>
						<th>{ __( 'Min', 'beyond-elysium' ) }</th>
						<th>{ __( 'Max', 'beyond-elysium' ) }</th>
						<th>{ __( 'Step', 'beyond-elysium' ) }</th>
						<th />
					</tr>
				</thead>
				<tbody>
					{ pools.map( ( pool, i ) => (
						<tr key={ i }>
							<td>
								<input
									type="text"
									aria-label={ sprintf( __( 'Name for pool %d', 'beyond-elysium' ), i + 1 ) }
									value={ pool.name }
									onChange={ ( e ) => updatePool( i, { name: e.target.value } ) }
								/>

								{ /* Optional lookup that overrides this pool's display name via another block's field. */ }
								<div className="be-def-editor__name-lookup">
									<div className="be-def-editor__inline-row">
										<input
											type="text"
											placeholder={ __( 'Keyed by block', 'beyond-elysium' ) }
											aria-label={ sprintf( __( 'Keyed-by block for %s name lookup', 'beyond-elysium' ), pool.name ) }
											value={ pool.name_lookup?.keyed_by.block_slug ?? '' }
											onChange={ ( e ) => setLookupKeyedBy( i, { block_slug: e.target.value } ) }
										/>
										<input
											type="text"
											placeholder={ __( 'Field name', 'beyond-elysium' ) }
											aria-label={ sprintf( __( 'Keyed-by field for %s name lookup', 'beyond-elysium' ), pool.name ) }
											value={ pool.name_lookup?.keyed_by.field ?? '' }
											onChange={ ( e ) => setLookupKeyedBy( i, { field: e.target.value } ) }
										/>
									</div>
									{ Object.entries( pool.name_lookup?.table ?? {} ).map( ( [ key, value ], ri ) => (
										<div className="be-def-editor__inline-row" key={ ri }>
											<input
												type="text"
												placeholder={ __( 'Value (e.g. Conviction)', 'beyond-elysium' ) }
												aria-label={ sprintf(
													/* translators: 1: pool name, 2: lookup row position number */
													__( 'Lookup value for %1$s, row %2$d', 'beyond-elysium' ),
													pool.name,
													ri + 1
												) }
												value={ key }
												onChange={ ( e ) => updateLookupRow( i, key, e.target.value, value ) }
											/>
											<input
												type="text"
												placeholder={ __( 'Display as', 'beyond-elysium' ) }
												aria-label={ sprintf(
													/* translators: 1: pool name, 2: lookup row position number */
													__( 'Lookup display text for %1$s, row %2$d', 'beyond-elysium' ),
													pool.name,
													ri + 1
												) }
												value={ value }
												onChange={ ( e ) => updateLookupRow( i, key, key, e.target.value ) }
											/>
											<button type="button" onClick={ () => removeLookupRow( i, key ) }>
												×
											</button>
										</div>
									) ) }
									<button type="button" onClick={ () => addLookupRow( i ) }>
										{ __( '+ Name lookup row', 'beyond-elysium' ) }
									</button>
								</div>
							</td>
							<td>
								<select
									aria-label={ sprintf( __( 'Value type for %s', 'beyond-elysium' ), pool.name ) }
									value={ pool.value_type }
									onChange={ ( e ) => updatePool( i, { value_type: e.target.value as ResourcePool[ 'value_type' ] } ) }
								>
									<option value="integer">{ __( 'integer', 'beyond-elysium' ) }</option>
									<option value="decimal">{ __( 'decimal', 'beyond-elysium' ) }</option>
								</select>
							</td>
							<td>
								<input
									type="number"
									aria-label={ sprintf( __( 'Default start for %s', 'beyond-elysium' ), pool.name ) }
									value={ pool.default_start }
									onChange={ ( e ) => updatePool( i, { default_start: Number( e.target.value ) } ) }
								/>
							</td>
							<td>
								<input
									type="number"
									aria-label={ sprintf( __( 'Minimum for %s', 'beyond-elysium' ), pool.name ) }
									value={ pool.min ?? '' }
									onChange={ ( e ) => updatePool( i, { min: e.target.value ? Number( e.target.value ) : undefined } ) }
								/>
							</td>
							<td>
								<input
									type="number"
									aria-label={ sprintf( __( 'Maximum for %s', 'beyond-elysium' ), pool.name ) }
									value={ pool.max ?? '' }
									onChange={ ( e ) => updatePool( i, { max: e.target.value ? Number( e.target.value ) : undefined } ) }
								/>
							</td>
							<td>
								<input
									type="number"
									aria-label={ sprintf( __( 'Step for %s', 'beyond-elysium' ), pool.name ) }
									value={ pool.step ?? '' }
									onChange={ ( e ) => updatePool( i, { step: e.target.value ? Number( e.target.value ) : undefined } ) }
								/>
							</td>
							<td>
								<button type="button" onClick={ () => removePool( i ) }>
									{ __( 'Remove', 'beyond-elysium' ) }
								</button>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			<button type="button" onClick={ addPool }>
				{ __( '+ Add pool', 'beyond-elysium' ) }
			</button>
		</div>
	);
}

// ---------------------------------------------------------------------------
// identity_field
// ---------------------------------------------------------------------------

/**
 * Structured editor for an identity_field section definition. Renders an
 * add/edit/remove table of fields, each with a name, field type, required
 * flag, and a comma-separated options list used by the select and
 * multiselect field types.
 */
function IdentityFieldEditorAdmin( { definition, onChange }: { definition: IdentityFieldDefinition; onChange: ( d: Record<string, unknown> ) => void } ) {
	const fields = definition.fields ?? [];

	function updateField( index: number, patch: Partial<IdentityField> ) {
		const next = fields.map( ( f, i ) => ( i === index ? { ...f, ...patch } : f ) );
		onChange( { ...definition, fields: next } as unknown as Record<string, unknown> );
	}

	function addField() {
		onChange( { ...definition, fields: [ ...fields, { name: '', field_type: 'text', required: false } ] } as unknown as Record<string, unknown> );
	}

	function removeField( index: number ) {
		onChange( { ...definition, fields: fields.filter( ( _, i ) => i !== index ) } as unknown as Record<string, unknown> );
	}

	function updateOptions( index: number, raw: string ) {
		updateField( index, { options: raw.split( ',' ).map( ( s ) => s.trim() ).filter( Boolean ) } );
	}

	return (
		<div className="be-def-editor__section">
			<h3>{ sprintf( __( 'Fields (%d)', 'beyond-elysium' ), fields.length ) }</h3>
			<table className="be-def-editor__table">
				<thead>
					<tr>
						<th>{ __( 'Name', 'beyond-elysium' ) }</th>
						<th>{ __( 'Type', 'beyond-elysium' ) }</th>
						<th>{ __( 'Required', 'beyond-elysium' ) }</th>
						<th>{ __( 'Options (comma-separated)', 'beyond-elysium' ) }</th>
						<th />
					</tr>
				</thead>
				<tbody>
					{ fields.map( ( field, i ) => (
						<tr key={ i }>
							<td>
								<input
									type="text"
									aria-label={ sprintf( __( 'Name for field %d', 'beyond-elysium' ), i + 1 ) }
									value={ field.name }
									onChange={ ( e ) => updateField( i, { name: e.target.value } ) }
								/>
							</td>
							<td>
								<select
									aria-label={ sprintf( __( 'Type for %s', 'beyond-elysium' ), field.name ) }
									value={ field.field_type }
									onChange={ ( e ) => updateField( i, { field_type: e.target.value as IdentityField[ 'field_type' ] } ) }
								>
									<option value="text">{ __( 'text', 'beyond-elysium' ) }</option>
									<option value="select">{ __( 'select', 'beyond-elysium' ) }</option>
									<option value="multiselect">{ __( 'multiselect', 'beyond-elysium' ) }</option>
									<option value="number">{ __( 'number', 'beyond-elysium' ) }</option>
									<option value="textarea">{ __( 'textarea', 'beyond-elysium' ) }</option>
								</select>
							</td>
							<td>
								<input
									type="checkbox"
									aria-label={ sprintf( __( 'Required for %s', 'beyond-elysium' ), field.name ) }
									checked={ field.required }
									onChange={ ( e ) => updateField( i, { required: e.target.checked } ) }
								/>
							</td>
							<td>
								<input
									type="text"
									aria-label={ sprintf( __( 'Options for %s', 'beyond-elysium' ), field.name ) }
									value={ ( field.options ?? [] ).join( ', ' ) }
									onChange={ ( e ) => updateOptions( i, e.target.value ) }
									disabled={ field.field_type !== 'select' && field.field_type !== 'multiselect' }
								/>
							</td>
							<td>
								<button type="button" onClick={ () => removeField( i ) }>
									{ __( 'Remove', 'beyond-elysium' ) }
								</button>
							</td>
						</tr>
					) ) }
				</tbody>
			</table>
			<button type="button" onClick={ addField }>
				{ __( '+ Add field', 'beyond-elysium' ) }
			</button>
		</div>
	);
}

export default SchemaBlockDefinitionEditor;
