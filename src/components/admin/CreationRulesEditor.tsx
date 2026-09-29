/**
 * A structured editor for a creature stack's own creation rules: the ordered steps a build is tallied against, one
 * kind at a time.
 */
import { useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import type {
	CreationCeiling,
	CreationMaxRating,
	CreationRules,
	CreationStep,
	CreationStepWhen,
} from '../../types';
import './Admin.css';

export interface CreationRulesEditorProps {
	value: CreationRules;
	onChange: ( rules: CreationRules ) => void;
}

const KINDS: CreationStep[ 'kind' ][] = [
	'prioritized',
	'budget',
	'free',
	'earned',
	'limit',
	'start',
	'grant',
];

function splitList( value: string ): string[] {
	return value
		.split( ',' )
		.map( ( v ) => v.trim() )
		.filter( ( v ) => v !== '' );
}

function splitNumbers( value: string ): number[] {
	return splitList( value ).map( Number );
}

function defaultStep( kind: CreationStep[ 'kind' ] ): CreationStep {
	switch ( kind ) {
		case 'prioritized':
			return {
				kind,
				label: '',
				sections: [],
				amounts: [],
			};
		case 'budget':
			return { kind, label: '', section: '', count: 0 };
		case 'free':
			return { kind, label: '', pool: '', points: 0, rates: {} };
		case 'earned':
			return { kind, label: '', pool: '', sources: [] };
		case 'limit':
			return { kind, label: '', section: '' };
		case 'start':
			return { kind, label: '', target: '' };
		case 'grant':
		default:
			return { kind: 'grant', label: '', section: '' };
	}
}

/**
 * A single `when`, editable as a field plus `is`, `not` or `set`. A step whose `when` is a list of several is shown
 * read-only, since this codebase's own book has never yet needed more than one.
 */
function WhenEditor( {
	when,
	onChange,
}: {
	when: CreationStepWhen | CreationStepWhen[] | undefined;
	onChange: ( next: CreationStepWhen | undefined ) => void;
} ) {
	if ( Array.isArray( when ) ) {
		return (
			<p className="description">
				{ __(
					'Several conditions are set on this step - edit them in the raw JSON below.',
					'beyond-elysium'
				) }
			</p>
		);
	}

	const mode: 'is' | 'not' | 'set' = when
		? 'is' in when
			? 'is'
			: 'not' in when
				? 'not'
				: 'set'
		: 'is';

	return (
		<div className="be-in-type-editor__when">
			<label>
				<input
					type="checkbox"
					checked={ !! when }
					onChange={ ( e ) =>
						onChange(
							e.target.checked ? { field: '', is: [] } : undefined
						)
					}
				/>{ ' ' }
				{ __( 'Only when', 'beyond-elysium' ) }
			</label>
			{ when && (
				<>
					<input
						type="text"
						aria-label={ __( 'Field', 'beyond-elysium' ) }
						placeholder={ __(
							'block-slug.Field',
							'beyond-elysium'
						) }
						value={ when.field }
						onChange={ ( e ) =>
							onChange( { ...when, field: e.target.value } )
						}
					/>
					<select
						aria-label={ __( 'Condition', 'beyond-elysium' ) }
						value={ mode }
						onChange={ ( e ) => {
							const next = e.target.value as 'is' | 'not' | 'set';
							onChange(
								next === 'set'
									? { field: when.field, set: true }
									: { field: when.field, [ next ]: [] }
							);
						} }
					>
						<option value="is">
							{ __( 'holds one of', 'beyond-elysium' ) }
						</option>
						<option value="not">
							{ __( 'holds none of', 'beyond-elysium' ) }
						</option>
						<option value="set">
							{ __( 'is set or unset', 'beyond-elysium' ) }
						</option>
					</select>
					{ mode === 'set' ? (
						<label>
							<input
								type="checkbox"
								checked={ !! when.set }
								onChange={ ( e ) =>
									onChange( {
										field: when.field,
										set: e.target.checked,
									} )
								}
							/>{ ' ' }
							{ __( 'Set', 'beyond-elysium' ) }
						</label>
					) : (
						<input
							type="text"
							aria-label={ __(
								'Values, comma-separated',
								'beyond-elysium'
							) }
							value={ ( when[ mode ] ?? [] ).join( ', ' ) }
							onChange={ ( e ) =>
								onChange( {
									field: when.field,
									[ mode ]: splitList( e.target.value ),
								} )
							}
						/>
					) }
				</>
			) }
		</div>
	);
}

/**
 * The fields specific to one step's own kind.
 */
function StepFields( {
	step,
	onChange,
}: {
	step: CreationStep;
	onChange: ( next: CreationStep ) => void;
} ) {
	if ( step.kind === 'prioritized' ) {
		return (
			<div className="be-in-type-editor__test-row">
				<input
					type="text"
					aria-label={ __(
						'Sections, largest first, comma-separated',
						'beyond-elysium'
					) }
					placeholder={ __(
						'Sections, comma-separated',
						'beyond-elysium'
					) }
					value={ step.sections.join( ', ' ) }
					onChange={ ( e ) =>
						onChange( {
							...step,
							sections: splitList( e.target.value ),
						} )
					}
				/>
				<input
					type="text"
					aria-label={ __(
						'Amounts, in the same order, comma-separated',
						'beyond-elysium'
					) }
					placeholder={ __(
						'Amounts, comma-separated',
						'beyond-elysium'
					) }
					value={ step.amounts.join( ', ' ) }
					onChange={ ( e ) =>
						onChange( {
							...step,
							amounts: splitNumbers( e.target.value ),
						} )
					}
				/>
			</div>
		);
	}

	if ( step.kind === 'budget' ) {
		return (
			<>
				<div className="be-in-type-editor__test-row">
					<input
						type="text"
						aria-label={ __( 'Section', 'beyond-elysium' ) }
						placeholder={ __( 'Section', 'beyond-elysium' ) }
						value={ step.section }
						onChange={ ( e ) =>
							onChange( { ...step, section: e.target.value } )
						}
					/>
					<input
						type="number"
						aria-label={ __( 'Count', 'beyond-elysium' ) }
						value={ step.count }
						onChange={ ( e ) =>
							onChange( {
								...step,
								count: Number( e.target.value ),
							} )
						}
					/>
					<label>
						<input
							type="checkbox"
							checked={ !! step.filter?.in_type }
							onChange={ ( e ) =>
								onChange( {
									...step,
									filter: {
										...step.filter,
										in_type: e.target.checked,
									},
								} )
							}
						/>{ ' ' }
						{ __( 'In-type only', 'beyond-elysium' ) }
					</label>
					<input
						type="text"
						aria-label={ __(
							'Tier ceiling (optional)',
							'beyond-elysium'
						) }
						placeholder={ __( 'Tier ceiling', 'beyond-elysium' ) }
						value={ step.filter?.tier ?? '' }
						onChange={ ( e ) =>
							onChange( {
								...step,
								filter: {
									...step.filter,
									tier: e.target.value || undefined,
								},
							} )
						}
					/>
				</div>
				<div className="be-in-type-editor__nested">
					<p className="description">
						{ __( 'Quotas', 'beyond-elysium' ) }
					</p>
					{ ( step.quotas ?? [] ).map( ( quota, qi ) => (
						<div className="be-in-type-editor__test-row" key={ qi }>
							<input
								type="text"
								aria-label={ __(
									'Quota label',
									'beyond-elysium'
								) }
								placeholder={ __( 'Label', 'beyond-elysium' ) }
								value={ quota.label }
								onChange={ ( e ) => {
									const quotas = [ ...( step.quotas ?? [] ) ];
									quotas[ qi ] = {
										...quota,
										label: e.target.value,
									};
									onChange( { ...step, quotas } );
								} }
							/>
							<input
								type="number"
								aria-label={ __(
									'Quota minimum',
									'beyond-elysium'
								) }
								value={ quota.min }
								onChange={ ( e ) => {
									const quotas = [ ...( step.quotas ?? [] ) ];
									quotas[ qi ] = {
										...quota,
										min: Number( e.target.value ),
									};
									onChange( { ...step, quotas } );
								} }
							/>
							<button
								type="button"
								onClick={ () =>
									onChange( {
										...step,
										quotas: ( step.quotas ?? [] ).filter(
											( _, i ) => i !== qi
										),
									} )
								}
							>
								{ __( 'Remove', 'beyond-elysium' ) }
							</button>
						</div>
					) ) }
					<button
						type="button"
						onClick={ () =>
							onChange( {
								...step,
								quotas: [
									...( step.quotas ?? [] ),
									{
										label: '',
										min: 1,
										test: {
											kind: 'names',
											values: { field: '' },
										},
									},
								],
							} )
						}
					>
						{ __(
							'+ Add quota (its own test needs the raw JSON below)',
							'beyond-elysium'
						) }
					</button>
				</div>
			</>
		);
	}

	if ( step.kind === 'free' ) {
		const rateEntries = Object.entries( step.rates );
		return (
			<>
				<div className="be-in-type-editor__test-row">
					<input
						type="text"
						aria-label={ __( 'Pool', 'beyond-elysium' ) }
						placeholder={ __( 'Pool', 'beyond-elysium' ) }
						value={ step.pool }
						onChange={ ( e ) =>
							onChange( { ...step, pool: e.target.value } )
						}
					/>
					<input
						type="number"
						aria-label={ __( 'Points', 'beyond-elysium' ) }
						value={ step.points }
						onChange={ ( e ) =>
							onChange( {
								...step,
								points: Number( e.target.value ),
							} )
						}
					/>
				</div>
				<div className="be-in-type-editor__nested">
					<p className="description">
						{ __(
							'Rates - a section, or "section.Name" for one pool or entry',
							'beyond-elysium'
						) }
					</p>
					{ rateEntries.map( ( [ key, rate ], ri ) => (
						<div className="be-in-type-editor__test-row" key={ ri }>
							<input
								type="text"
								aria-label={ __(
									'Section or section.Name',
									'beyond-elysium'
								) }
								value={ key }
								onChange={ ( e ) => {
									const rates = { ...step.rates };
									delete rates[ key ];
									rates[ e.target.value ] = rate;
									onChange( { ...step, rates } );
								} }
							/>
							<input
								type="text"
								aria-label={ __(
									'Rate, or "value" for the entry’s own cost',
									'beyond-elysium'
								) }
								value={ String( rate ) }
								onChange={ ( e ) => {
									const rates = { ...step.rates };
									rates[ key ] =
										e.target.value === 'value'
											? 'value'
											: Number( e.target.value );
									onChange( { ...step, rates } );
								} }
							/>
							<button
								type="button"
								onClick={ () => {
									const rates = { ...step.rates };
									delete rates[ key ];
									onChange( { ...step, rates } );
								} }
							>
								{ __( 'Remove', 'beyond-elysium' ) }
							</button>
						</div>
					) ) }
					<button
						type="button"
						onClick={ () =>
							onChange( {
								...step,
								rates: { ...step.rates, '': 1 },
							} )
						}
					>
						{ __( '+ Add rate', 'beyond-elysium' ) }
					</button>
				</div>
			</>
		);
	}

	if ( step.kind === 'earned' ) {
		return (
			<>
				<div className="be-in-type-editor__test-row">
					<input
						type="text"
						aria-label={ __( 'Pool', 'beyond-elysium' ) }
						placeholder={ __( 'Pool', 'beyond-elysium' ) }
						value={ step.pool }
						onChange={ ( e ) =>
							onChange( { ...step, pool: e.target.value } )
						}
					/>
					<input
						type="number"
						aria-label={ __(
							'Overall maximum (optional)',
							'beyond-elysium'
						) }
						placeholder={ __(
							'Overall maximum',
							'beyond-elysium'
						) }
						value={ step.max ?? '' }
						onChange={ ( e ) =>
							onChange( {
								...step,
								max: e.target.value
									? Number( e.target.value )
									: undefined,
							} )
						}
					/>
				</div>
				<div className="be-in-type-editor__nested">
					<p className="description">
						{ __( 'Sources', 'beyond-elysium' ) }
					</p>
					{ step.sources.map( ( source, si ) => (
						<div className="be-in-type-editor__test-row" key={ si }>
							<input
								type="text"
								aria-label={ __(
									'Source section',
									'beyond-elysium'
								) }
								placeholder={ __(
									'Section',
									'beyond-elysium'
								) }
								value={ source.section }
								onChange={ ( e ) => {
									const sources = [ ...step.sources ];
									sources[ si ] = {
										...source,
										section: e.target.value,
									};
									onChange( { ...step, sources } );
								} }
							/>
							<input
								type="text"
								aria-label={ __(
									'Rate, or "value" for the entry’s own cost',
									'beyond-elysium'
								) }
								value={ String( source.rate ) }
								onChange={ ( e ) => {
									const sources = [ ...step.sources ];
									sources[ si ] = {
										...source,
										rate:
											e.target.value === 'value'
												? 'value'
												: Number( e.target.value ),
									};
									onChange( { ...step, sources } );
								} }
							/>
							<input
								type="number"
								aria-label={ __(
									'This source’s own cap (optional)',
									'beyond-elysium'
								) }
								placeholder={ __( 'Cap', 'beyond-elysium' ) }
								value={ source.max ?? '' }
								onChange={ ( e ) => {
									const sources = [ ...step.sources ];
									sources[ si ] = {
										...source,
										max: e.target.value
											? Number( e.target.value )
											: undefined,
									};
									onChange( { ...step, sources } );
								} }
							/>
							<button
								type="button"
								onClick={ () =>
									onChange( {
										...step,
										sources: step.sources.filter(
											( _, i ) => i !== si
										),
									} )
								}
							>
								{ __( 'Remove', 'beyond-elysium' ) }
							</button>
						</div>
					) ) }
					<button
						type="button"
						onClick={ () =>
							onChange( {
								...step,
								sources: [
									...step.sources,
									{ section: '', rate: 1 },
								],
							} )
						}
					>
						{ __( '+ Add source', 'beyond-elysium' ) }
					</button>
				</div>
			</>
		);
	}

	if ( step.kind === 'limit' ) {
		const ratingMode: 'plain' | 'computed' =
			typeof step.max_rating === 'object' ? 'computed' : 'plain';
		const ceilingMode: 'block' | 'named_by' =
			step.ceiling && typeof step.ceiling === 'object'
				? 'named_by'
				: 'block';
		return (
			<div className="be-in-type-editor__test-row">
				<input
					type="text"
					aria-label={ __(
						'Section, or "block.Name" for one pool or entry',
						'beyond-elysium'
					) }
					placeholder={ __( 'Section', 'beyond-elysium' ) }
					value={ step.section }
					onChange={ ( e ) =>
						onChange( { ...step, section: e.target.value } )
					}
				/>
				<input
					type="number"
					aria-label={ __(
						'Max points (optional)',
						'beyond-elysium'
					) }
					placeholder={ __( 'Max points', 'beyond-elysium' ) }
					value={ step.max_points ?? '' }
					onChange={ ( e ) =>
						onChange( {
							...step,
							max_points: e.target.value
								? Number( e.target.value )
								: undefined,
						} )
					}
				/>
				<input
					type="number"
					aria-label={ __(
						'Min rating (optional)',
						'beyond-elysium'
					) }
					placeholder={ __( 'Min rating', 'beyond-elysium' ) }
					value={ step.min_rating ?? '' }
					onChange={ ( e ) =>
						onChange( {
							...step,
							min_rating: e.target.value
								? Number( e.target.value )
								: undefined,
						} )
					}
				/>
				<select
					aria-label={ __( 'Max rating kind', 'beyond-elysium' ) }
					value={ ratingMode }
					onChange={ ( e ) =>
						onChange( {
							...step,
							max_rating:
								e.target.value === 'computed' ? { base: 0 } : 0,
						} )
					}
				>
					<option value="plain">
						{ __( 'No max rating', 'beyond-elysium' ) }
					</option>
					<option value="computed">
						{ __( 'Max rating (computed)', 'beyond-elysium' ) }
					</option>
				</select>
				{ ratingMode === 'computed' ? (
					<>
						<input
							type="number"
							aria-label={ __( 'Base', 'beyond-elysium' ) }
							placeholder={ __( 'Base', 'beyond-elysium' ) }
							value={
								( step.max_rating as { base: number } ).base
							}
							onChange={ ( e ) =>
								onChange( {
									...step,
									max_rating: {
										...( step.max_rating as CreationMaxRating ),
										base: Number( e.target.value ),
									},
								} )
							}
						/>
						<input
							type="text"
							aria-label={ __(
								'Field to add (optional)',
								'beyond-elysium'
							) }
							placeholder={ __( 'Plus field', 'beyond-elysium' ) }
							value={
								( step.max_rating as { plus?: string } ).plus ??
								''
							}
							onChange={ ( e ) =>
								onChange( {
									...step,
									max_rating: {
										...( step.max_rating as CreationMaxRating ),
										plus: e.target.value || undefined,
									},
								} )
							}
						/>
						<input
							type="number"
							aria-label={ __(
								'Cap (optional)',
								'beyond-elysium'
							) }
							placeholder={ __( 'Cap', 'beyond-elysium' ) }
							value={
								( step.max_rating as { cap?: number } ).cap ??
								''
							}
							onChange={ ( e ) =>
								onChange( {
									...step,
									max_rating: {
										...( step.max_rating as CreationMaxRating ),
										cap: e.target.value
											? Number( e.target.value )
											: undefined,
									},
								} )
							}
						/>
					</>
				) : (
					<input
						type="number"
						aria-label={ __( 'Max rating', 'beyond-elysium' ) }
						value={
							typeof step.max_rating === 'number'
								? step.max_rating
								: ''
						}
						onChange={ ( e ) =>
							onChange( {
								...step,
								max_rating: e.target.value
									? Number( e.target.value )
									: undefined,
							} )
						}
					/>
				) }
				<select
					aria-label={ __( 'Ceiling kind', 'beyond-elysium' ) }
					value={ step.ceiling === undefined ? 'none' : ceilingMode }
					onChange={ ( e ) => {
						const next = e.target.value;
						let ceiling: CreationCeiling | undefined;
						if ( next === 'block' ) {
							ceiling = '';
						} else if ( next === 'named_by' ) {
							ceiling = { named_by: '' };
						}
						onChange( { ...step, ceiling } );
					} }
				>
					<option value="none">
						{ __( 'No ceiling', 'beyond-elysium' ) }
					</option>
					<option value="block">
						{ __( 'A fixed entry', 'beyond-elysium' ) }
					</option>
					<option value="named_by">
						{ __( 'The entry a field names', 'beyond-elysium' ) }
					</option>
				</select>
				{ step.ceiling !== undefined &&
					( ceilingMode === 'block' ? (
						<input
							type="text"
							aria-label={ __(
								'Ceiling, block.Name',
								'beyond-elysium'
							) }
							placeholder={ __( 'block.Name', 'beyond-elysium' ) }
							value={ step.ceiling as string }
							onChange={ ( e ) =>
								onChange( { ...step, ceiling: e.target.value } )
							}
						/>
					) : (
						<input
							type="text"
							aria-label={ __(
								'The field naming the ceiling entry',
								'beyond-elysium'
							) }
							placeholder={ __(
								'block-slug.Field',
								'beyond-elysium'
							) }
							value={
								( step.ceiling as { named_by: string } )
									.named_by
							}
							onChange={ ( e ) =>
								onChange( {
									...step,
									ceiling: { named_by: e.target.value },
								} )
							}
						/>
					) ) }
			</div>
		);
	}

	if ( step.kind === 'start' ) {
		const mode: 'value' | 'lookup' | 'formula' = step.lookup
			? 'lookup'
			: step.formula
				? 'formula'
				: 'value';
		return (
			<div className="be-in-type-editor__test-row">
				<input
					type="text"
					aria-label={ __( 'Target, block.Name', 'beyond-elysium' ) }
					placeholder={ __( 'block.Name', 'beyond-elysium' ) }
					value={ step.target }
					onChange={ ( e ) =>
						onChange( { ...step, target: e.target.value } )
					}
				/>
				<select
					aria-label={ __( 'Start kind', 'beyond-elysium' ) }
					value={ mode }
					onChange={ ( e ) => {
						const next = e.target.value as
							'value' | 'lookup' | 'formula';
						if ( next === 'value' ) {
							onChange( {
								...step,
								value: 0,
								lookup: undefined,
								formula: undefined,
								of: undefined,
							} );
						} else if ( next === 'lookup' ) {
							onChange( {
								...step,
								value: undefined,
								lookup: { map: '', by: [] },
								formula: undefined,
								of: undefined,
							} );
						} else {
							onChange( {
								...step,
								value: undefined,
								lookup: undefined,
								formula: 'average_up',
								of: [],
							} );
						}
					} }
				>
					<option value="value">
						{ __( 'A fixed value', 'beyond-elysium' ) }
					</option>
					<option value="lookup">
						{ __( 'A map lookup', 'beyond-elysium' ) }
					</option>
					<option value="formula">
						{ __( 'A formula', 'beyond-elysium' ) }
					</option>
				</select>
				{ mode === 'value' && (
					<input
						type="number"
						aria-label={ __( 'Value', 'beyond-elysium' ) }
						value={ step.value ?? 0 }
						onChange={ ( e ) =>
							onChange( {
								...step,
								value: Number( e.target.value ),
							} )
						}
					/>
				) }
				{ mode === 'lookup' && (
					<>
						<input
							type="text"
							aria-label={ __(
								'Map, block-slug.key',
								'beyond-elysium'
							) }
							placeholder={ __(
								'block-slug.key',
								'beyond-elysium'
							) }
							value={ step.lookup?.map ?? '' }
							onChange={ ( e ) =>
								onChange( {
									...step,
									lookup: {
										map: e.target.value,
										by: step.lookup?.by ?? [],
									},
								} )
							}
						/>
						<input
							type="text"
							aria-label={ __(
								'Fields the map is keyed by, comma-separated',
								'beyond-elysium'
							) }
							value={ ( step.lookup?.by ?? [] ).join( ', ' ) }
							onChange={ ( e ) =>
								onChange( {
									...step,
									lookup: {
										map: step.lookup?.map ?? '',
										by: splitList( e.target.value ),
									},
								} )
							}
						/>
					</>
				) }
				{ mode === 'formula' && (
					<>
						<select
							aria-label={ __( 'Formula', 'beyond-elysium' ) }
							value={ step.formula }
							onChange={ ( e ) =>
								onChange( {
									...step,
									formula: e.target.value as
										'average_up' | 'sum_top_two' | 'equal',
								} )
							}
						>
							<option value="average_up">
								{ __(
									'average, rounded up',
									'beyond-elysium'
								) }
							</option>
							<option value="sum_top_two">
								{ __(
									'sum of the two highest',
									'beyond-elysium'
								) }
							</option>
							<option value="equal">
								{ __( 'equal to', 'beyond-elysium' ) }
							</option>
						</select>
						<input
							type="text"
							aria-label={ __(
								'Of these, comma-separated',
								'beyond-elysium'
							) }
							placeholder={ __(
								'block.Name, block.Name…',
								'beyond-elysium'
							) }
							value={ ( step.of ?? [] ).join( ', ' ) }
							onChange={ ( e ) =>
								onChange( {
									...step,
									of: splitList( e.target.value ),
								} )
							}
						/>
					</>
				) }
			</div>
		);
	}

	// grant
	const mode: 'entries' | 'from' = step.from ? 'from' : 'entries';
	return (
		<>
			<div className="be-in-type-editor__test-row">
				<input
					type="text"
					aria-label={ __( 'Section', 'beyond-elysium' ) }
					placeholder={ __( 'Section', 'beyond-elysium' ) }
					value={ step.section }
					onChange={ ( e ) =>
						onChange( { ...step, section: e.target.value } )
					}
				/>
				<select
					aria-label={ __( 'Grant kind', 'beyond-elysium' ) }
					value={ mode }
					onChange={ ( e ) => {
						if ( e.target.value === 'from' ) {
							onChange( {
								...step,
								entries: undefined,
								from: { map: '', by: [] },
							} );
						} else {
							onChange( {
								...step,
								from: undefined,
								level: undefined,
								entries: [],
							} );
						}
					} }
				>
					<option value="entries">
						{ __( 'Fixed entries', 'beyond-elysium' ) }
					</option>
					<option value="from">
						{ __( 'From a map', 'beyond-elysium' ) }
					</option>
				</select>
				{ mode === 'from' && (
					<>
						<input
							type="text"
							aria-label={ __(
								'Map, block-slug.key',
								'beyond-elysium'
							) }
							placeholder={ __(
								'block-slug.key',
								'beyond-elysium'
							) }
							value={ step.from?.map ?? '' }
							onChange={ ( e ) =>
								onChange( {
									...step,
									from: {
										map: e.target.value,
										by: step.from?.by ?? [],
									},
								} )
							}
						/>
						<input
							type="text"
							aria-label={ __(
								'Fields the map is keyed by, comma-separated',
								'beyond-elysium'
							) }
							value={ ( step.from?.by ?? [] ).join( ', ' ) }
							onChange={ ( e ) =>
								onChange( {
									...step,
									from: {
										map: step.from?.map ?? '',
										by: splitList( e.target.value ),
									},
								} )
							}
						/>
						<input
							type="number"
							aria-label={ __(
								'Level (optional)',
								'beyond-elysium'
							) }
							placeholder={ __( 'Level', 'beyond-elysium' ) }
							value={ step.level ?? '' }
							onChange={ ( e ) =>
								onChange( {
									...step,
									level: e.target.value
										? Number( e.target.value )
										: undefined,
								} )
							}
						/>
					</>
				) }
			</div>
			{ mode === 'entries' && (
				<div className="be-in-type-editor__nested">
					{ ( step.entries ?? [] ).map( ( entry, ei ) => (
						<div className="be-in-type-editor__test-row" key={ ei }>
							<input
								type="text"
								aria-label={ __(
									'Entry name',
									'beyond-elysium'
								) }
								placeholder={ __( 'Name', 'beyond-elysium' ) }
								value={ entry.name }
								onChange={ ( e ) => {
									const entries = [
										...( step.entries ?? [] ),
									];
									entries[ ei ] = {
										...entry,
										name: e.target.value,
									};
									onChange( { ...step, entries } );
								} }
							/>
							<input
								type="number"
								aria-label={ __(
									'Level (optional)',
									'beyond-elysium'
								) }
								placeholder={ __( 'Level', 'beyond-elysium' ) }
								value={ entry.level ?? '' }
								onChange={ ( e ) => {
									const entries = [
										...( step.entries ?? [] ),
									];
									entries[ ei ] = {
										...entry,
										level: e.target.value
											? Number( e.target.value )
											: undefined,
									};
									onChange( { ...step, entries } );
								} }
							/>
							<input
								type="text"
								aria-label={ __(
									'Power name (optional)',
									'beyond-elysium'
								) }
								placeholder={ __(
									'Power name',
									'beyond-elysium'
								) }
								value={ entry.power_name ?? '' }
								onChange={ ( e ) => {
									const entries = [
										...( step.entries ?? [] ),
									];
									entries[ ei ] = {
										...entry,
										power_name: e.target.value || undefined,
									};
									onChange( { ...step, entries } );
								} }
							/>
							<button
								type="button"
								onClick={ () =>
									onChange( {
										...step,
										entries: ( step.entries ?? [] ).filter(
											( _, i ) => i !== ei
										),
									} )
								}
							>
								{ __( 'Remove', 'beyond-elysium' ) }
							</button>
						</div>
					) ) }
					<button
						type="button"
						onClick={ () =>
							onChange( {
								...step,
								entries: [
									...( step.entries ?? [] ),
									{ name: '' },
								],
							} )
						}
					>
						{ __( '+ Add entry', 'beyond-elysium' ) }
					</button>
				</div>
			) }
		</>
	);
}

/**
 * Renders the full creation-rules step list: reorder, add, remove, and each step's own kind and fields.
 */
export function CreationRulesEditor( {
	value,
	onChange,
}: CreationRulesEditorProps ) {
	const steps = value.steps ?? [];
	const [ showRaw, setShowRaw ] = useState( false );
	const [ rawJson, setRawJson ] = useState( () =>
		JSON.stringify( value, null, 2 )
	);
	const [ rawError, setRawError ] = useState< string | null >( null );

	function updateSteps( next: CreationStep[] ) {
		onChange( { ...value, steps: next } );
		setRawJson( JSON.stringify( { ...value, steps: next }, null, 2 ) );
	}

	function updateStep( i: number, next: CreationStep ) {
		updateSteps( steps.map( ( s, si ) => ( si === i ? next : s ) ) );
	}

	function move( i: number, delta: number ) {
		const j = i + delta;
		if ( j < 0 || j >= steps.length ) {
			return;
		}
		const next = [ ...steps ];
		[ next[ i ], next[ j ] ] = [ next[ j ], next[ i ] ];
		updateSteps( next );
	}

	function applyRaw() {
		try {
			const parsed = JSON.parse( rawJson );
			setRawError( null );
			onChange( parsed );
		} catch {
			setRawError(
				__( 'Not valid JSON - not applied.', 'beyond-elysium' )
			);
		}
	}

	return (
		<div className="be-def-editor__section">
			<h4>
				{ __( 'Creation rules', 'beyond-elysium' ) } ({ steps.length })
			</h4>
			{ steps.map( ( step, i ) => (
				<div className="be-in-type-editor__test" key={ i }>
					<div className="be-in-type-editor__test-row">
						<select
							aria-label={ __( 'Step kind', 'beyond-elysium' ) }
							value={ step.kind }
							onChange={ ( e ) =>
								updateStep(
									i,
									defaultStep(
										e.target.value as CreationStep[ 'kind' ]
									)
								)
							}
						>
							{ KINDS.map( ( k ) => (
								<option key={ k } value={ k }>
									{ k }
								</option>
							) ) }
						</select>
						<input
							type="text"
							aria-label={ __( 'Step label', 'beyond-elysium' ) }
							placeholder={ __( 'Label', 'beyond-elysium' ) }
							value={ step.label }
							onChange={ ( e ) =>
								updateStep( i, {
									...step,
									label: e.target.value,
								} )
							}
						/>
						<button
							type="button"
							onClick={ () => move( i, -1 ) }
							disabled={ i === 0 }
						>
							{ __( 'Up', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							onClick={ () => move( i, 1 ) }
							disabled={ i === steps.length - 1 }
						>
							{ __( 'Down', 'beyond-elysium' ) }
						</button>
						<button
							type="button"
							onClick={ () =>
								updateSteps(
									steps.filter( ( _, si ) => si !== i )
								)
							}
						>
							{ __( 'Remove', 'beyond-elysium' ) }
						</button>
					</div>
					<StepFields
						step={ step }
						onChange={ ( next ) => updateStep( i, next ) }
					/>
					<WhenEditor
						when={ step.when }
						onChange={ ( next ) =>
							updateStep( i, { ...step, when: next } )
						}
					/>
				</div>
			) ) }
			<button
				type="button"
				onClick={ () =>
					updateSteps( [ ...steps, defaultStep( 'budget' ) ] )
				}
			>
				{ __( '+ Add step', 'beyond-elysium' ) }
			</button>

			<button
				type="button"
				className="be-def-editor__raw-toggle"
				onClick={ () => {
					if ( ! showRaw ) {
						setRawJson( JSON.stringify( value, null, 2 ) );
					}
					setShowRaw( ! showRaw );
				} }
			>
				{ showRaw
					? __(
							'Hide creation rules (advanced, JSON)',
							'beyond-elysium'
						)
					: __(
							'Show creation rules (advanced, JSON)',
							'beyond-elysium'
						) }
			</button>
			{ showRaw && (
				<div className="be-def-editor__raw">
					<textarea
						rows={ 10 }
						value={ rawJson }
						onChange={ ( e ) => setRawJson( e.target.value ) }
					/>
					{ rawError && (
						<p className="be-admin__json-error">{ rawError }</p>
					) }
					<button type="button" onClick={ applyRaw }>
						{ __( 'Apply JSON', 'beyond-elysium' ) }
					</button>
				</div>
			) }
		</div>
	);
}

export default CreationRulesEditor;
