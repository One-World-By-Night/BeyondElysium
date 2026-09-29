/**
 * A per-section trigger that opens a modal editor for a creature stack section's own in-type tests: `names`, `facet`,
 * `chosen` or `all`, each reading its values from a field, a map, or a constant list, with an optional `when`.
 */
import { useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import type { InTypeSource, InTypeTest, InTypeWhen } from '../../types';
import Modal from '../shared/Modal';
import './Admin.css';

export interface InTypeTestEditorProps {
	label: string;
	value?: InTypeTest[];
	onSave: ( tests: InTypeTest[] ) => void;
}

const KINDS: InTypeTest[ 'kind' ][] = [ 'names', 'facet', 'chosen', 'all' ];
const COMMON_FACETS = [ 'group', 'subgroup' ];
const SOURCE_KINDS = [ 'field', 'map', 'constant' ] as const;
type SourceKind = ( typeof SOURCE_KINDS )[ number ];

function sourceKind( source: InTypeSource | undefined ): SourceKind {
	if ( ! source ) {
		return 'field';
	}
	if ( 'map' in source ) {
		return 'map';
	}
	if ( 'constant' in source ) {
		return 'constant';
	}
	return 'field';
}

function splitList( value: string ): string[] {
	return value
		.split( ',' )
		.map( ( v ) => v.trim() )
		.filter( ( v ) => v !== '' );
}

/**
 * Renders one test row, including its own `values` source and optional `when`, and - for `all` - its nested tests.
 */
function TestRow( {
	test,
	onChange,
	onRemove,
	depth,
}: {
	test: InTypeTest;
	onChange: ( next: InTypeTest ) => void;
	onRemove: () => void;
	depth: number;
} ) {
	const kind = test.kind;
	const values: InTypeSource | undefined =
		kind === 'chosen'
			? undefined
			: ( test as { values?: InTypeSource } ).values;
	const source = sourceKind( values );
	const when = ( test as { when?: InTypeWhen } ).when;

	function setKind( nextKind: InTypeTest[ 'kind' ] ) {
		if ( nextKind === 'chosen' ) {
			onChange( { kind: 'chosen', values: { field: '' } } );
		} else if ( nextKind === 'all' ) {
			onChange( { kind: 'all', tests: [] } );
		} else if ( nextKind === 'facet' ) {
			onChange( {
				kind: 'facet',
				facet: 'group',
				values: { field: '' },
			} );
		} else {
			onChange( { kind: 'names', values: { field: '' } } );
		}
	}

	function setSourceKind( nextSource: SourceKind ) {
		const next: InTypeSource =
			nextSource === 'map'
				? { map: '', by: [] }
				: nextSource === 'constant'
					? { constant: [] }
					: { field: '' };
		onChange( { ...test, values: next } as InTypeTest );
	}

	function setWhen( next: InTypeWhen | undefined ) {
		onChange( { ...test, when: next } as InTypeTest );
	}

	return (
		<div
			className="be-in-type-editor__test"
			style={ { marginLeft: depth * 24 } }
		>
			<div className="be-in-type-editor__test-row">
				<select
					aria-label={ __( 'Test kind', 'beyond-elysium' ) }
					value={ kind }
					onChange={ ( e ) =>
						setKind( e.target.value as InTypeTest[ 'kind' ] )
					}
				>
					{ KINDS.map( ( k ) => (
						<option key={ k } value={ k }>
							{ k }
						</option>
					) ) }
				</select>

				{ kind === 'facet' && (
					<>
						<input
							type="text"
							aria-label={ __( 'Facet', 'beyond-elysium' ) }
							placeholder={ __(
								'group, subgroup, or a tiered_power category axis…',
								'beyond-elysium'
							) }
							list="be-in-type-editor__facets"
							value={ ( test as { facet: string } ).facet }
							onChange={ ( e ) =>
								onChange( {
									...test,
									facet: e.target.value,
								} as InTypeTest )
							}
						/>
						<datalist id="be-in-type-editor__facets">
							{ COMMON_FACETS.map( ( f ) => (
								<option key={ f } value={ f } />
							) ) }
						</datalist>
					</>
				) }

				{ kind === 'chosen' && (
					<input
						type="text"
						aria-label={ __(
							'Field holding the character’s own picks',
							'beyond-elysium'
						) }
						placeholder={ __(
							'block-slug.Field',
							'beyond-elysium'
						) }
						value={
							( test as { values: { field: string } } ).values
								.field
						}
						onChange={ ( e ) =>
							onChange( {
								...test,
								values: { field: e.target.value },
							} as InTypeTest )
						}
					/>
				) }

				{ ( kind === 'names' || kind === 'facet' ) && (
					<>
						<select
							aria-label={ __(
								'Values source',
								'beyond-elysium'
							) }
							value={ source }
							onChange={ ( e ) =>
								setSourceKind( e.target.value as SourceKind )
							}
						>
							{ SOURCE_KINDS.map( ( s ) => (
								<option key={ s } value={ s }>
									{ s }
								</option>
							) ) }
						</select>

						{ source === 'field' && (
							<input
								type="text"
								aria-label={ __(
									'Field(s), comma-separated',
									'beyond-elysium'
								) }
								placeholder={ __(
									'block-slug.Field',
									'beyond-elysium'
								) }
								value={
									Array.isArray(
										(
											values as {
												field: string | string[];
											}
										 )?.field
									)
										? (
												values as {
													field: string[];
												}
											 ).field.join( ', ' )
										: ( ( values as { field?: string } )
												?.field ?? '' )
								}
								onChange={ ( e ) => {
									const list = splitList( e.target.value );
									onChange( {
										...test,
										values: {
											field:
												list.length === 1
													? list[ 0 ]
													: list,
										},
									} as InTypeTest );
								} }
							/>
						) }

						{ source === 'map' && (
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
									value={
										( values as { map?: string } )?.map ??
										''
									}
									onChange={ ( e ) =>
										onChange( {
											...test,
											values: {
												...( values as {
													map: string;
													by: string[];
												} ),
												map: e.target.value,
											},
										} as InTypeTest )
									}
								/>
								<input
									type="text"
									aria-label={ __(
										'Fields the map is keyed by, comma-separated',
										'beyond-elysium'
									) }
									placeholder={ __(
										'Field, another field…',
										'beyond-elysium'
									) }
									value={ (
										( values as { by?: string[] } )?.by ??
										[]
									).join( ', ' ) }
									onChange={ ( e ) =>
										onChange( {
											...test,
											values: {
												...( values as {
													map: string;
													by: string[];
												} ),
												by: splitList( e.target.value ),
											},
										} as InTypeTest )
									}
								/>
							</>
						) }

						{ source === 'constant' && (
							<input
								type="text"
								aria-label={ __(
									'Constant values, comma-separated',
									'beyond-elysium'
								) }
								value={ (
									( values as { constant?: string[] } )
										?.constant ?? []
								).join( ', ' ) }
								onChange={ ( e ) =>
									onChange( {
										...test,
										values: {
											constant: splitList(
												e.target.value
											),
										},
									} as InTypeTest )
								}
							/>
						) }
					</>
				) }

				<button type="button" onClick={ onRemove }>
					{ __( 'Remove', 'beyond-elysium' ) }
				</button>
			</div>

			{ kind !== 'all' && (
				<div className="be-in-type-editor__when">
					<label>
						<input
							type="checkbox"
							checked={ !! when }
							onChange={ ( e ) =>
								setWhen(
									e.target.checked
										? { field: '', is: [] }
										: undefined
								)
							}
						/>{ ' ' }
						{ __( 'Limit to a field', 'beyond-elysium' ) }
					</label>
					{ when && (
						<>
							<input
								type="text"
								aria-label={ __(
									'The field this test is limited to',
									'beyond-elysium'
								) }
								placeholder={ __(
									'block-slug.Field',
									'beyond-elysium'
								) }
								value={ when.field }
								onChange={ ( e ) =>
									setWhen( {
										...when,
										field: e.target.value,
									} )
								}
							/>
							{ 'set' in when ? (
								<label>
									<input
										type="checkbox"
										checked={ when.set }
										onChange={ ( e ) =>
											setWhen( {
												field: when.field,
												set: e.target.checked,
											} )
										}
									/>{ ' ' }
									{ __(
										'Holds any value',
										'beyond-elysium'
									) }
								</label>
							) : (
								<input
									type="text"
									aria-label={ __(
										'Values this field must hold one of, comma-separated',
										'beyond-elysium'
									) }
									value={ when.is.join( ', ' ) }
									onChange={ ( e ) =>
										setWhen( {
											field: when.field,
											is: splitList( e.target.value ),
										} )
									}
								/>
							) }
							<button
								type="button"
								onClick={ () =>
									setWhen(
										'set' in when
											? { field: when.field, is: [] }
											: { field: when.field, set: true }
									)
								}
							>
								{ 'set' in when
									? __( 'Use a value list', 'beyond-elysium' )
									: __( 'Use set/unset', 'beyond-elysium' ) }
							</button>
						</>
					) }
				</div>
			) }

			{ kind === 'all' && (
				<div className="be-in-type-editor__nested">
					{ ( test as { tests: InTypeTest[] } ).tests.map(
						( sub, si ) => (
							<TestRow
								key={ si }
								test={ sub }
								depth={ depth + 1 }
								onChange={ ( next ) => {
									const tests = [
										...( test as { tests: InTypeTest[] } )
											.tests,
									];
									tests[ si ] = next;
									onChange( {
										...test,
										tests,
									} as InTypeTest );
								} }
								onRemove={ () => {
									const tests = (
										test as { tests: InTypeTest[] }
									 ).tests.filter( ( _, i ) => i !== si );
									onChange( {
										...test,
										tests,
									} as InTypeTest );
								} }
							/>
						)
					) }
					<button
						type="button"
						onClick={ () =>
							onChange( {
								...test,
								tests: [
									...( test as { tests: InTypeTest[] } )
										.tests,
									{ kind: 'names', values: { field: '' } },
								],
							} as InTypeTest )
						}
					>
						{ __( '+ Add nested test', 'beyond-elysium' ) }
					</button>
				</div>
			) }
		</div>
	);
}

/**
 * Renders the in-type button and, once opened, its modal editor for one section's own `in_type` list.
 */
export function InTypeTestEditor( {
	label,
	value,
	onSave,
}: InTypeTestEditorProps ) {
	const [ isOpen, setIsOpen ] = useState( false );
	const [ draft, setDraft ] = useState< InTypeTest[] >( value ?? [] );

	function open() {
		setDraft( value ?? [] );
		setIsOpen( true );
	}

	function save() {
		onSave( draft );
		setIsOpen( false );
	}

	function addTest() {
		setDraft( [ ...draft, { kind: 'names', values: { field: '' } } ] );
	}

	return (
		<>
			<button type="button" onClick={ open }>
				{ value?.length
					? sprintf(
							/* translators: %d: number of in-type tests already set on this section */
							__( 'In-type (%d)', 'beyond-elysium' ),
							value.length
						)
					: __( 'In-type', 'beyond-elysium' ) }
			</button>
			{ isOpen && (
				<Modal
					title={ sprintf(
						/* translators: %s: the section's own label */
						__( 'In-type tests - %s', 'beyond-elysium' ),
						label
					) }
					onClose={ () => setIsOpen( false ) }
					footer={
						<>
							<button
								type="button"
								onClick={ () => setIsOpen( false ) }
							>
								{ __( 'Cancel', 'beyond-elysium' ) }
							</button>
							<button type="button" onClick={ save }>
								{ __( 'Save', 'beyond-elysium' ) }
							</button>
						</>
					}
				>
					<p className="description">
						{ __(
							'A purchase from this section is in-type when any one test passes. No tests at all means every purchase is in-type.',
							'beyond-elysium'
						) }
					</p>
					{ draft.map( ( test, i ) => (
						<TestRow
							key={ i }
							test={ test }
							depth={ 0 }
							onChange={ ( next ) => {
								const tests = [ ...draft ];
								tests[ i ] = next;
								setDraft( tests );
							} }
							onRemove={ () =>
								setDraft(
									draft.filter( ( _, di ) => di !== i )
								)
							}
						/>
					) ) }
					<button type="button" onClick={ addTest }>
						{ __( '+ Add test', 'beyond-elysium' ) }
					</button>
				</Modal>
			) }
		</>
	);
}

export default InTypeTestEditor;
