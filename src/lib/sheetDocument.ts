/**
 * Reading `Sheet_Document`'s rows on screen.
 */
import type { SheetDocumentRow } from '../types/npcCasting';

/**
 * A sheet document row's text and indent: a plain row is its own text; a printed line's text comes without the rating
 * a printed sheet draws as empty circles.
 */
export function sheetRow( row: SheetDocumentRow ): {
	text: string;
	indent: number;
} {
	return typeof row === 'string'
		? { text: row, indent: 0 }
		: { text: row.text, indent: row.indent ?? 0 };
}
