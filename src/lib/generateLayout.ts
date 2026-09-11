/**
 * Generates a fallback three-column card layout for a stack's blocks when no template
 * exists. Identity-field blocks are placed in column 1, resource pools in column 1 below
 * identity, and every other block (trait lists, tiered powers) is distributed across
 * columns 2 and 3 in stack section order, balanced by running item count. Exports
 * `generateLayout()` and the block/stack/layout shapes it operates on.
 */

export interface StackSection {
	block_slug: string;
	display_order: number;
	label?: string;
}

export interface StackLike {
	slug: string;
	stack_definition: {
		sections: StackSection[];
	};
}

export interface SchemaBlockLike {
	slug: string;
	name: string;
	section_type: 'trait_list' | 'tiered_power' | 'resource_pool' | 'identity_field';
	definition: {
		items?: unknown[];
		powers?: unknown[];
		fields?: unknown[];
		pools?: unknown[];
	};
}

export interface LayoutSection {
	block_slug: string;
	column: number;
	order: number;
	title: string;
	display: null;
	collapsed: false;
}

export interface Layout {
	version: 1;
	columns: 3;
	sections: LayoutSection[];
}

/** Number of catalog items or powers a block's definition declares, or 0 for neither. */
function itemCount( block: SchemaBlockLike ): number {
	if ( block.definition.items ) {
		return block.definition.items.length;
	}
	if ( block.definition.powers ) {
		return block.definition.powers.length;
	}
	return 0;
}

interface Entry {
	section: StackSection;
	block: SchemaBlockLike;
}

/** Builds one output layout section for a stack entry at the given column and order. */
function buildSection( entry: Entry, column: number, order: number ): LayoutSection {
	return {
		block_slug: entry.block.slug,
		column,
		order,
		title: entry.section.label ?? entry.block.name,
		display: null,
		collapsed: false,
	};
}

/**
 * Builds a full three-column layout for a stack: sorts its sections by display order,
 * places identity and resource-pool blocks in column 1, then distributes the remaining
 * blocks across columns 2 and 3 by running item count so neither column grows
 * disproportionately heavier than the other.
 */
export function generateLayout( stack: StackLike, blocks: Record<string, SchemaBlockLike> ): Layout {
	const sections = [ ...stack.stack_definition.sections ].sort(
		( a, b ) => ( a.display_order ?? 0 ) - ( b.display_order ?? 0 )
	);

	const identity: Entry[] = [];
	const pools: Entry[] = [];
	const distributable: Entry[] = [];

	for ( const section of sections ) {
		const block = blocks[ section.block_slug ];
		if ( ! block ) {
			continue;
		}

		const entry: Entry = { section, block };
		if ( block.section_type === 'identity_field' ) {
			identity.push( entry );
		} else if ( block.section_type === 'resource_pool' ) {
			pools.push( entry );
		} else {
			distributable.push( entry );
		}
	}

	const outSections: LayoutSection[] = [];
	let order = 1;

	for ( const entry of [ ...identity, ...pools ] ) {
		outSections.push( buildSection( entry, 1, order++ ) );
	}

	// Balance columns 2 and 3 by running item count, assigning in stack section order.
	const colOrder: Record<2 | 3, number> = { 2: 1, 3: 1 };
	const colLoad: Record<2 | 3, number> = { 2: 0, 3: 0 };

	for ( const entry of distributable ) {
		const column: 2 | 3 = colLoad[ 2 ] <= colLoad[ 3 ] ? 2 : 3;
		outSections.push( buildSection( entry, column, colOrder[ column ]++ ) );
		colLoad[ column ] += itemCount( entry.block );
	}

	return {
		version: 1,
		columns: 3,
		sections: outSections,
	};
}
