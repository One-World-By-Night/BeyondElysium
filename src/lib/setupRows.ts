/**
 * Whether a Chronicle Setup row shows its controls. A row that needs attention starts open and every
 * other row starts folded; once the viewer has opened or closed a row that choice wins, so saving a
 * row that needed attention folds it (it is no longer amber) but never closes one the viewer opened.
 */
export function isRowOpen(
	toggled: Record< string, boolean >,
	row: { id: string; status: 'attention' | 'ok' | 'info' }
): boolean {
	return toggled[ row.id ] ?? row.status === 'attention';
}
