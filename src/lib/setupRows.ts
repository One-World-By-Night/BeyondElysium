/**
 * Whether a Chronicle Setup row shows its controls.
 */
export function isRowOpen(
	toggled: Record< string, boolean >,
	row: { id: string; status: 'attention' | 'ok' | 'info' }
): boolean {
	return toggled[ row.id ] ?? row.status === 'attention';
}
