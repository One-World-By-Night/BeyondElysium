/**
 * Names one Query Tool search.
 */
import type { QueryLogic } from '../types/query';

/**
 * A stable name for a search.
 */
export function searchKey(
	inventory: string,
	conditions: readonly unknown[],
	logic: QueryLogic | string
): string {
	return JSON.stringify( [ inventory, logic, conditions ] );
}
