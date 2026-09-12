/**
 * QueryBuilder renders the clause-editing UI for a saved-character query: pick a
 * field, an operator valid for that field's type, and a value, chained together
 * with AND/OR match logic. Used by QueryTool's Search tab. The operator list
 * narrows automatically to whatever the chosen field's data type supports.
 */
import { __ } from '@wordpress/i18n';
import { useState } from '@wordpress/element';
import type { QueryCondition, QueryField, QueryLogic, QueryOperator } from '../../types/query';
import { FIND_OPERATORS, OPERATOR_LABELS, OPERATORS_BY_TYPE, VALUE_OPERATORS, describeCondition } from '../../types/query';
import './QueryBuilder.css';

export interface QueryBuilderProps {
	/** Fetched and filtered to mapped fields by the caller, which also owns the active inventory. */
	fields: QueryField[];
	conditions: QueryCondition[];
	logic: QueryLogic;
	onChange: ( conditions: QueryCondition[], logic: QueryLogic ) => void;
}

/**
 * Renders the clause editor for a saved query against whichever inventory
 * the caller is currently showing: add or remove clauses, choose AND/OR
 * match logic, and pick a field, operator, and value per clause. The
 * operator list for each clause is filtered to what its chosen field's type
 * actually supports, so the UI never offers an operator the server would
 * reject.
 */
export function QueryBuilder( { fields, conditions, logic, onChange }: QueryBuilderProps ) {
	const [ search, setSearch ] = useState( '' );

	const filteredFields = search
		? fields.filter( ( f ) => f.title.toLowerCase().includes( search.toLowerCase() ) || f.key.toLowerCase().includes( search.toLowerCase() ) )
		: fields;

	function fieldFor( key: string ): QueryField | undefined {
		return fields.find( ( f ) => f.key === key );
	}

	function updateCondition( index: number, patch: Partial<QueryCondition> ) {
		const next = conditions.map( ( c, i ) => ( i === index ? { ...c, ...patch } : c ) );
		onChange( next, logic );
	}

	function addCondition() {
		onChange( [ ...conditions, { field: '', operator: '' } ], logic );
	}

	function removeCondition( index: number ) {
		onChange( conditions.filter( ( _, i ) => i !== index ), logic );
	}

	function onFieldChange( index: number, key: string ) {
		const field = fieldFor( key );
		const firstOperator = field ? OPERATORS_BY_TYPE[ field.type ][ 0 ] : '';
		updateCondition( index, { field: key, operator: firstOperator as QueryOperator, find: undefined, value: undefined } );
	}

	return (
		<div className="be-query-builder">
			<div className="be-query-builder__logic">
				<label>
					<input type="radio" checked={ logic === 'AND' } onChange={ () => onChange( conditions, 'AND' ) } />
					{ __( 'Match ALL clauses (AND)', 'beyond-elysium' ) }
				</label>
				<label>
					<input type="radio" checked={ logic === 'OR' } onChange={ () => onChange( conditions, 'OR' ) } />
					{ __( 'Match ANY clause (OR)', 'beyond-elysium' ) }
				</label>
			</div>

			<input
				type="search"
				className="be-query-builder__field-search"
				placeholder={ __( 'Search fields to narrow the pickers below…', 'beyond-elysium' ) }
				aria-label={ __( 'Search fields to narrow the pickers below', 'beyond-elysium' ) }
				value={ search }
				onChange={ ( e ) => setSearch( e.target.value ) }
			/>

			<ul className="be-query-builder__clauses">
				{ conditions.map( ( condition, index ) => {
					const field         = fieldFor( condition.field );
					const operators     = field ? OPERATORS_BY_TYPE[ field.type ] : [];
					const needsFind     = FIND_OPERATORS.includes( condition.operator as QueryOperator );
					const needsValue    = VALUE_OPERATORS.includes( condition.operator as QueryOperator );
					const description   = field && condition.operator ? describeCondition( condition, field.title ) : null;

					return (
						<li key={ index } className="be-query-builder__clause">
							<select value={ condition.field } onChange={ ( e ) => onFieldChange( index, e.target.value ) } aria-label={ __( 'Field for this clause', 'beyond-elysium' ) }>
								<option value="">{ __( 'Select a field…', 'beyond-elysium' ) }</option>
								{ filteredFields.map( ( f ) => (
									<option key={ f.key } value={ f.key }>
										{ f.title }
									</option>
								) ) }
							</select>

							<select
								value={ condition.operator }
								disabled={ ! field }
								aria-label={ __( 'Comparison operator for this clause', 'beyond-elysium' ) }
								onChange={ ( e ) => updateCondition( index, { operator: e.target.value as QueryOperator } ) }
							>
								<option value="">{ __( 'Select an operator…', 'beyond-elysium' ) }</option>
								{ operators.map( ( op ) => (
									<option key={ op } value={ op }>
										{ OPERATOR_LABELS[ op ] }
									</option>
								) ) }
							</select>

							{ field?.type === 'date' ? (
								<input type="date" value={ condition.find ?? '' } onChange={ ( e ) => updateCondition( index, { find: e.target.value } ) } />
							) : (
								needsFind && (
									<input
										type="text"
										placeholder={ __( 'Name…', 'beyond-elysium' ) }
										value={ condition.find ?? '' }
										onChange={ ( e ) => updateCondition( index, { find: e.target.value } ) }
									/>
								)
							) }
							{ needsValue && field?.type !== 'date' && (
								<input
									type="number"
									placeholder={ __( 'Number…', 'beyond-elysium' ) }
									value={ condition.value ?? '' }
									onChange={ ( e ) => updateCondition( index, { value: e.target.value ? Number( e.target.value ) : undefined } ) }
								/>
							) }

							<label className="be-query-builder__not">
								<input type="checkbox" checked={ !! condition.not } onChange={ ( e ) => updateCondition( index, { not: e.target.checked } ) } />
								{ __( 'NOT', 'beyond-elysium' ) }
							</label>

							<button type="button" onClick={ () => removeCondition( index ) } aria-label={ __( 'Remove clause', 'beyond-elysium' ) }>
								✕
							</button>

							{ description && <p className="be-query-builder__description">{ description }</p> }
						</li>
					);
				} ) }
			</ul>

			<button type="button" onClick={ addCondition }>
				{ __( '+ Add clause', 'beyond-elysium' ) }
			</button>
		</div>
	);
}

export default QueryBuilder;
