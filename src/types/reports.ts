/**
 * The plain JSON form of a report that is a table.
 */
export interface TableDocument {
	title: string;
	shape: 'table';
	columns: string[];
	rows: string[][];
	/**
	 * What to say when there are no rows.
	 */
	note?: string;
	game: string;
}
