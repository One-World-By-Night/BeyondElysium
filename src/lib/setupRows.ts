/**
 * Whether a Chronicle Setup row shows its controls.
 */
export function isRowOpen(
	toggled: Record< string, boolean >,
	row: { id: string; status: 'attention' | 'ok' | 'info' }
): boolean {
	return toggled[ row.id ] ?? row.status === 'attention';
}

/**
 * What a Chronicle Setup row shows beside its text: its own controls, a toggle for its panel, the note that says why
 * the viewer has no toggle, a link to where it is fixed, or nothing.
 */
export type RowControl = 'extra' | 'toggle' | 'note' | 'link' | 'none';

export function rowControl(
	item: {
		actionable: boolean;
		fix: { kind: string; href?: string | null };
	},
	flags: { hasPanel: boolean; hasExtra: boolean }
): RowControl {
	if ( flags.hasExtra ) {
		return 'extra';
	}
	if ( flags.hasPanel ) {
		return item.actionable ? 'toggle' : 'note';
	}
	if ( item.fix.kind === 'link' && item.fix.href ) {
		return 'link';
	}
	return 'none';
}
