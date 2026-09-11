/**
 * Type definitions and supporting lookup tables for the ad hoc
 * character query builder. Covers field and operator types, the
 * operator label and applicability tables, query condition and
 * request shapes, statistics requests, and saved queries.
 */

/**
 * The kind of value a queryable field holds: a plain text field,
 * a number, a date, a boolean, or a list of items. Determines
 * which operators are applicable to that field.
 */
export type FieldType = 'field' | 'num' | 'date' | 'bool' | 'list';

/**
 * A single comparison operator usable in a query condition, such
 * as "contains" or "at least". Different operators apply to
 * different field types, and some expect a find value to search
 * for while others expect a numeric value to compare against.
 */
export type QueryOperator =
	| 'contains' | 'equals' | 'at_least' | 'greater' | 'less' | 'no_more'
	| 'contains_exactly' | 'contains_at_least' | 'contains_more' | 'contains_less' | 'contains_no_more'
	| 'totals' | 'totals_at_least' | 'totals_more' | 'totals_no_more' | 'totals_less'
	| 'contains_note' | 'is_true' | 'is_false';

/**
 * The set of operators applicable to each field type. Used to
 * populate the operator picker in the query builder once a field
 * of a given type has been selected.
 */
export const OPERATORS_BY_TYPE: Record<FieldType, QueryOperator[]> = {
	field: [ 'contains', 'equals' ],
	num: [ 'equals', 'at_least', 'greater', 'less', 'no_more' ],
	date: [ 'equals', 'at_least', 'greater', 'less', 'no_more' ],
	bool: [ 'is_true', 'is_false' ],
	list: [
		'contains', 'contains_note',
		'contains_exactly', 'contains_at_least', 'contains_more', 'contains_less', 'contains_no_more',
		'totals', 'totals_at_least', 'totals_more', 'totals_no_more', 'totals_less',
	],
};

/**
 * The human-readable label shown for each query operator in the
 * query builder UI, such as "is at least" for at_least.
 */
export const OPERATOR_LABELS: Record<QueryOperator, string> = {
	contains: 'contains',
	equals: 'is equal to',
	at_least: 'is at least',
	greater: 'is greater than',
	less: 'is less than',
	no_more: 'is no more than',
	contains_exactly: 'contains exactly',
	contains_at_least: 'contains at least',
	contains_more: 'contains more than',
	contains_less: 'contains less than',
	contains_no_more: 'contains no more than',
	totals: 'totals',
	totals_at_least: 'totals at least',
	totals_more: 'totals more than',
	totals_no_more: 'totals no more than',
	totals_less: 'totals less than',
	contains_note: 'has a note containing',
	is_true: 'is true',
	is_false: 'is false',
};

/**
 * Operators whose condition needs a find value: a name or string
 * to search for, rather than a number to compare against.
 */
export const FIND_OPERATORS: QueryOperator[] = [
	'contains', 'equals', 'contains_note',
	'contains_exactly', 'contains_at_least', 'contains_more', 'contains_less', 'contains_no_more',
];

/**
 * Operators whose condition needs a numeric value to compare
 * against, rather than a find string to search for.
 */
export const VALUE_OPERATORS: QueryOperator[] = [
	'at_least', 'greater', 'less', 'no_more',
	'contains_exactly', 'contains_at_least', 'contains_more', 'contains_less', 'contains_no_more',
	'totals', 'totals_at_least', 'totals_more', 'totals_no_more', 'totals_less',
];

/**
 * A single queryable field exposed by the query builder, such as
 * a trait or identity value. Records its key, display title, and
 * value type, and whether it is currently mapped to real sheet
 * data.
 */
export interface QueryField {
	key: string;
	title: string;
	type: FieldType;
	mapped: boolean;
}

/**
 * A single condition within a query: which field to test, which
 * operator to apply, the find or value operand it needs, and
 * whether the result should be negated.
 */
export interface QueryCondition {
	field: string;
	operator: QueryOperator | '';
	find?: string;
	value?: number;
	not?: boolean;
}

/**
 * Whether a query's conditions must all match (AND) or any one of
 * them may match (OR).
 */
export type QueryLogic = 'AND' | 'OR';

/**
 * A single character returned by a query. Carries the core
 * identifying fields plus an optional human-readable match reason,
 * and allows arbitrary additional fields requested by the query
 * itself.
 */
export interface QueryResultCharacter {
	id: number;
	uuid: string;
	name: string;
	stack_slug: string;
	status: string;
	match_reason?: string;
	[ key: string ]: unknown;
}

/**
 * Request body for running a query against a chronicle's
 * characters. Specifies which inventory to search, the conditions
 * and logic to apply, optional sorting, and pagination.
 */
export interface RunQueryRequest {
	inventory?: string;
	conditions: QueryCondition[];
	logic: QueryLogic;
	sort?: { field: string; direction: 'asc' | 'desc' };
	page?: number;
	per_page?: number;
}

/**
 * The kind of aggregate a statistics request computes: a value
 * distribution, a distinct-value distribution, a distribution
 * over one specific value, the maximum found, or a sum total.
 */
export type StatisticType = 'distribution' | 'distinct_distribution' | 'specific_distribution' | 'maxima' | 'sums';

/**
 * Request body for running a statistics aggregate over the
 * characters matching a set of query conditions. Names the field
 * and trait to aggregate and which kind of statistic to compute.
 */
export interface RunStatisticsRequest {
	conditions: QueryCondition[];
	logic: QueryLogic;
	key: string;
	stat_type: StatisticType;
	ok_zero?: boolean;
	trait?: string;
}

/**
 * The result of a statistics aggregate: value buckets and their
 * counts, which characters fall into each bucket, and the overall
 * total and maximum found.
 */
export interface StatisticsResult {
	buckets: Record<string, number>;
	match_sets: Record<string, string[]>;
	total: number;
	maximum: number;
}

/**
 * A query saved by a user for reuse, including its conditions,
 * matching mode, and sort order. is_recent_search marks an
 * automatically retained recent search rather than one the user
 * deliberately named and saved.
 */
export interface SavedQuery {
	id: number;
	game_id: number;
	name: string;
	inventory: string;
	match_all: boolean;
	conditions: QueryCondition[];
	sort_key: string | null;
	sort_direction: 'asc' | 'desc';
	is_recent_search: boolean;
	created_by: number;
	created_at: string;
	updated_at: string;
}

/**
 * Request body for saving a query. name and conditions are
 * required; inventory, logic, and sort fields are optional and
 * take server-side defaults when omitted.
 */
export interface SaveQueryRequest {
	name: string;
	inventory?: string;
	logic?: QueryLogic;
	conditions: QueryCondition[];
	sort_key?: string;
	sort_direction?: 'asc' | 'desc';
}

/**
 * Builds a plain-English description of a single query condition,
 * such as "Clan contains Toreador". Combines the field title with
 * the operator's label and its find or value operand, handling
 * negation and operators that need both a find and a value.
 */
export function describeCondition( condition: QueryCondition, fieldTitle: string ): string {
	const label = OPERATOR_LABELS[ condition.operator as QueryOperator ] ?? condition.operator;
	const find = condition.find ?? '';
	const value = condition.value ?? 0;
	const prefix = condition.not ? 'does not ' : '';

	if ( FIND_OPERATORS.includes( condition.operator as QueryOperator ) && VALUE_OPERATORS.includes( condition.operator as QueryOperator ) ) {
		return `${ fieldTitle } ${ prefix }${ label } ${ find } x${ value }`;
	}
	if ( FIND_OPERATORS.includes( condition.operator as QueryOperator ) ) {
		return `${ fieldTitle } ${ prefix }${ label } ${ find || value }`;
	}
	if ( VALUE_OPERATORS.includes( condition.operator as QueryOperator ) ) {
		return `${ fieldTitle } ${ prefix }${ label } ${ value }`;
	}
	return `${ fieldTitle } ${ prefix }${ label }`;
}
