/**
 * Which decisions an import offers for a character that is already on this site.
 */
import type { DuplicateAction, DuplicateCharacter } from '../types/import';

export function duplicateActionsFor(
	matchedBy: DuplicateCharacter[ 'matched_by' ],
	allowSkip = true,
	allowOverwrite = true
): DuplicateAction[] {
	const actions: DuplicateAction[] =
		matchedBy === 'uuid_elsewhere'
			? [ 'skip', 'import_as_new' ]
			: [ 'skip', 'overwrite', 'import_as_new' ];
	return actions.filter(
		( action ) =>
			( allowSkip || action !== 'skip' ) &&
			( allowOverwrite || action !== 'overwrite' )
	);
}
