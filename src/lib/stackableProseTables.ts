/**
 * Makes every table in a block of stored rich text collapse into stacked cards on a narrow screen: each
 * table joins the shared responsive-table mechanism inside its own table box, and each body cell carries its
 * column heading as a `data-label`, which the card layout prints beside the value.
 */

const ST_MARKER = /\[\/?ST\]/g;

function headingTexts( table: HTMLTableElement ): string[] {
	const headRow =
		table.tHead?.rows[ 0 ] ??
		Array.from( table.rows ).find( ( row ) =>
			Array.from( row.cells ).every( ( cell ) => cell.tagName === 'TH' )
		);

	return headRow
		? Array.from( headRow.cells ).map( ( cell ) =>
				( cell.textContent ?? '' ).replace( ST_MARKER, '' ).trim()
			)
		: [];
}

function isEmptyRow( row: HTMLTableRowElement ): boolean {
	return Array.from( row.cells ).every(
		( cell ) =>
			( cell.textContent ?? '' ).trim() === '' &&
			! cell.querySelector( 'img' )
	);
}

/**
 * Returns `html` with each `<table>` made stackable, or `html` itself when it holds no table. A body row whose cells
 * are all empty is dropped (a viewer without Storyteller access receives Storyteller-only cells emptied), and a
 * table left with no body row is dropped with it.
 */
export function stackableProseTables( html: string ): string {
	if ( ! html || ! /<table[\s>]/i.test( html ) ) {
		return html;
	}

	const doc = new DOMParser().parseFromString( html, 'text/html' );

	doc.querySelectorAll( 'table' ).forEach( ( table ) => {
		Array.from( table.rows ).forEach( ( row ) => {
			const isBodyRow = Array.from( row.cells ).some(
				( cell ) => cell.tagName === 'TD'
			);
			if ( isBodyRow && isEmptyRow( row ) ) {
				row.remove();
			}
		} );

		if (
			! Array.from( table.rows ).some( ( row ) =>
				Array.from( row.cells ).some(
					( cell ) => cell.tagName === 'TD'
				)
			)
		) {
			table.remove();
			return;
		}

		const headings = headingTexts( table );

		table.classList.add( 'be-responsive-table' );
		Array.from( table.rows ).forEach( ( row ) => {
			Array.from( row.cells ).forEach( ( cell, index ) => {
				if ( cell.tagName === 'TD' && headings[ index ] ) {
					cell.setAttribute( 'data-label', headings[ index ] );
				}
			} );
		} );

		const box = doc.createElement( 'div' );
		box.className = 'be-table-box';
		table.replaceWith( box );
		box.appendChild( table );
	} );

	return doc.body.innerHTML;
}
