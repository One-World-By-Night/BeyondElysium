/**
 * Names one Query Tool search - its inventory, conditions, and match
 * logic - so a bulk selection can belong to the search it was made on.
 * Paging through the results or sorting them is the same search; editing
 * a condition, or searching another inventory, is a new one
 * (1.0.0-review F-075).
 */
import type { QueryLogic } from '../types/query';

/**
 * A stable name for a search. Two searches with the same inventory,
 * conditions, and logic share it; any difference gives another.
 */
export function searchKey(
	inventory: string,
	conditions: readonly unknown[],
	logic: QueryLogic | string
): string {
	return JSON.stringify( [ inventory, logic, conditions ] );
}
