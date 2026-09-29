/**
 * The two ends of a stored connection, read from one entity's side.
 */
import type { Connection, EntityType } from '../types/plot';
import { idOf } from './ids';

/**
 * The end of a connection that is not the given entity.
 */
export function otherEnd(
	connection: Connection,
	entityType: EntityType,
	entityId: number
): { type: EntityType; id: number | null; label: string | null } {
	const isSource =
		connection.source_type === entityType &&
		idOf( connection.source_id ) === entityId;
	return isSource
		? {
				type: connection.target_type,
				id: idOf( connection.target_id ),
				label: connection.label,
			}
		: {
				type: connection.source_type,
				id: idOf( connection.source_id ),
				label: connection.label,
			};
}
