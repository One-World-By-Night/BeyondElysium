/**
 * Which decisions an import offers for a character that is already on this site. A character
 * that lives in another chronicle can be skipped or copied as a new character, never overwritten
 * from here; accepting a transfer never offers Skip, since skipping its one character leaves
 * nothing to accept (1.0.0-review F-003). Mirrors `Import_Controller::blocking_reason()`.
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
