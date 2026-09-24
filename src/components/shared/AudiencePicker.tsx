/**
 * Who may see a plot, item, or location: everyone in the chronicle, Storytellers/Narrators only, or a rule set
 * matching specific characters.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import { QueryBuilder } from '../query/QueryBuilder';
import type { AudienceValue, AudienceRules } from '../../types/plot';
import type {
	QueryCondition,
	QueryField,
	QueryLogic,
	QueryOperator,
} from '../../types/query';
import { FIND_OPERATORS, VALUE_OPERATORS } from '../../types/query';
import './AudiencePicker.css';

export interface AudiencePickerProps {
	gameSlug: string;
	audience: AudienceValue;
	audienceRules: AudienceRules | null;
	onChange: (
		audience: AudienceValue,
		audienceRules: AudienceRules | null
	) => void;
	disabled?: boolean;
}

const EMPTY_RULES: AudienceRules = { conditions: [], logic: 'AND' };

/**
 * Whether one condition has everything Query_Engine::validate_conditions() itself requires before it will run: a
 * field, an operator that applies to it, and whichever of find/value that operator needs.
 */
function isConditionComplete(
	condition: QueryCondition,
	fields: QueryField[]
): boolean {
	if ( ! condition.field || ! condition.operator ) {
		return false;
	}
	const field = fields.find( ( f ) => f.key === condition.field );
	if ( ! field ) {
		return false;
	}
	if ( field.type === 'date' ) {
		return !! condition.find;
	}
	const operator = condition.operator as QueryOperator;
	if ( FIND_OPERATORS.includes( operator ) && ! condition.find ) {
		return false;
	}
	if (
		VALUE_OPERATORS.includes( operator ) &&
		condition.value === undefined
	) {
		return false;
	}
	return true;
}

/**
 * Whether a rule set has at least one condition a query could actually run.
 */
function isRunnable(
	conditions: QueryCondition[],
	fields: QueryField[]
): boolean {
	return conditions.some( ( c ) => isConditionComplete( c, fields ) );
}

export function AudiencePicker( {
	gameSlug,
	audience,
	audienceRules,
	onChange,
	disabled,
}: AudiencePickerProps ) {
	const [ fields, setFields ] = useState< QueryField[] >( [] );
	const [ fieldsLoading, setFieldsLoading ] = useState( true );
	// Remembers rules typed while `restricted` was selected.
	const [ draftRules, setDraftRules ] = useState< AudienceRules >(
		audienceRules ?? EMPTY_RULES
	);
	const [ matchCount, setMatchCount ] = useState< number | null >( null );
	const [ countLoading, setCountLoading ] = useState( false );

	useEffect( () => {
		let cancelled = false;
		api.queryFields
			.list( 'char' )
			.then( ( all ) => {
				if ( ! cancelled ) {
					setFields( all.filter( ( f ) => f.mapped ) );
					setFieldsLoading( false );
				}
			} )
			.catch( () => {
				if ( ! cancelled ) {
					setFieldsLoading( false );
				}
			} );
		return () => {
			cancelled = true;
		};
	}, [] );

	const rules =
		audience === 'restricted' ? audienceRules ?? draftRules : draftRules;

	useEffect( () => {
		if (
			audience !== 'restricted' ||
			! isRunnable( rules.conditions, fields )
		) {
			setMatchCount( null );
			return;
		}
		let cancelled = false;
		setCountLoading( true );
		const timer = setTimeout( () => {
			api.query( gameSlug )
				.run( { conditions: rules.conditions, logic: rules.logic } )
				.then( ( results ) => {
					if ( ! cancelled ) {
						setMatchCount( results.length );
						setCountLoading( false );
					}
				} )
				.catch( () => {
					if ( ! cancelled ) {
						setMatchCount( null );
						setCountLoading( false );
					}
				} );
		}, 400 );
		return () => {
			cancelled = true;
			clearTimeout( timer );
		};
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ audience, rules.conditions, rules.logic, gameSlug, fields ] );

	/**
	 * Reports a rule set upward only once it has at least one complete condition.
	 */
	function reportRules( conditions: QueryCondition[], logic: QueryLogic ) {
		onChange(
			'restricted',
			isRunnable( conditions, fields ) ? { conditions, logic } : null
		);
	}

	function selectAudience( value: AudienceValue ) {
		if ( value !== 'restricted' ) {
			onChange( value, null );
			return;
		}
		reportRules( draftRules.conditions, draftRules.logic );
	}

	function handleRulesChange(
		conditions: QueryCondition[],
		logic: QueryLogic
	) {
		setDraftRules( { conditions, logic } );
		reportRules( conditions, logic );
	}

	return (
		<div className="be-audience-picker">
			<div className="be-audience-picker__options">
				<label>
					<input
						type="radio"
						name="be-audience"
						checked={ audience === 'everyone' }
						disabled={ disabled }
						onChange={ () => selectAudience( 'everyone' ) }
					/>
					{ __( 'Everyone in the chronicle', 'beyond-elysium' ) }
				</label>
				<label>
					<input
						type="radio"
						name="be-audience"
						checked={ audience === 'storytellers' }
						disabled={ disabled }
						onChange={ () => selectAudience( 'storytellers' ) }
					/>
					{ __(
						'Storytellers and Narrators only',
						'beyond-elysium'
					) }
				</label>
				<label>
					<input
						type="radio"
						name="be-audience"
						checked={ audience === 'restricted' }
						disabled={ disabled }
						onChange={ () => selectAudience( 'restricted' ) }
					/>
					{ __(
						'Only characters matching rules I set',
						'beyond-elysium'
					) }
				</label>
			</div>

			{ audience === 'restricted' && (
				<div className="be-audience-picker__rules">
					{ fieldsLoading ? (
						<p>
							{ __(
								'Loading queryable fields…',
								'beyond-elysium'
							) }
						</p>
					) : (
						<QueryBuilder
							fields={ fields }
							conditions={ rules.conditions }
							logic={ rules.logic }
							onChange={ handleRulesChange }
						/>
					) }
					<p className="be-audience-picker__preview">
						{ countLoading &&
							__( 'Checking who matches…', 'beyond-elysium' ) }
						{ ! countLoading && matchCount !== null && (
							<>
								{ matchCount === 1
									? __(
											'1 character currently matches these rules.',
											'beyond-elysium'
									  )
									: matchCount +
									  ' ' +
									  __(
											'characters currently match these rules.',
											'beyond-elysium'
									  ) }{ ' ' }
								{ __(
									'A character directly connected to this also always sees it, whether or not it matches.',
									'beyond-elysium'
								) }
							</>
						) }
						{ ! countLoading &&
							matchCount === null &&
							isRunnable( rules.conditions, fields ) === false &&
							__(
								'Add at least one complete rule to preview who it matches.',
								'beyond-elysium'
							) }
					</p>
				</div>
			) }
		</div>
	);
}

export default AudiencePicker;
