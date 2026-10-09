/**
 * Groups schema blocks by the creature type that owns them, so a picker can tell two blocks with the same name apart.
 */
import type { CreatureStack, SchemaBlock } from '../types';
import { pickList, type OptionGroup } from './searchableSelect';

export interface BlockGroup {
	label: string;
	blocks: SchemaBlock[];
}

export interface BlockGroupLabels {
	shared: string;
	other: string;
	notEnabled: ( name: string ) => string;
}

/**
 * Every creature type's block slugs, in the order its template lists them, negative halves right after their
 * positive block.
 */
function slugsInOrder( stack: CreatureStack ): string[] {
	const sections = [ ...( stack.stack_definition?.sections ?? [] ) ].sort(
		( a, b ) => a.display_order - b.display_order
	);
	const slugs: string[] = [];
	for ( const section of sections ) {
		slugs.push( section.block_slug );
		if ( section.negative_block_slug ) {
			slugs.push( section.negative_block_slug );
		}
	}
	return slugs;
}

/**
 * Every creature type whose template includes each block slug, in creature type order.
 */
function templateOwners(
	stacks: CreatureStack[]
): Map< string, CreatureStack[] > {
	const listed = new Map< string, CreatureStack[] >();
	for ( const stack of stacks ) {
		for ( const slug of slugsInOrder( stack ) ) {
			const owners = listed.get( slug ) ?? [];
			if ( ! owners.includes( stack ) ) {
				owners.push( stack );
			}
			listed.set( slug, owners );
		}
	}
	return listed;
}

/**
 * The creature type a block slug names: `<type>-<family>` ("vampire-disciplines") or `<edition>-<type>_<family>`
 * ("darkages-vampire_disciplines"). When one creature type slug starts another, the longer one wins.
 */
function creatureTypeNamedBy(
	slug: string,
	stacks: CreatureStack[]
): CreatureStack | null {
	const dash = slug.indexOf( '-' );
	const afterEdition = dash === -1 ? '' : slug.slice( dash + 1 );
	let named: CreatureStack | null = null;
	for ( const stack of stacks ) {
		const fits =
			slug.startsWith( `${ stack.slug }-` ) ||
			afterEdition.startsWith( `${ stack.slug }_` );
		if ( fits && ( ! named || stack.slug.length > named.slug.length ) ) {
			named = stack;
		}
	}
	return named;
}

/**
 * The one creature type that owns a block: the type its slug names, else the only type whose template lists it.
 */
function owningCreatureType(
	slug: string,
	stacks: CreatureStack[],
	listed: Map< string, CreatureStack[] >
): CreatureStack | null {
	const named = creatureTypeNamedBy( slug, stacks );
	if ( named ) {
		return named;
	}
	const lists = listed.get( slug ) ?? [];
	return lists.length === 1 ? lists[ 0 ] : null;
}

/**
 * The names of the creature types behind each block slug: the one type its slug names, else every type whose
 * template includes it. Covers every template slug and every block passed in.
 */
export function creatureTypesOfBlock(
	stacks: CreatureStack[],
	blocks: SchemaBlock[] = []
): Map< string, string[] > {
	const listed = templateOwners( stacks );
	const slugs = new Set( [
		...listed.keys(),
		...blocks.map( ( block ) => block.slug ),
	] );
	const owners = new Map< string, string[] >();
	for ( const slug of slugs ) {
		const named = creatureTypeNamedBy( slug, stacks );
		if ( named ) {
			owners.set( slug, [ named.name ] );
		} else if ( listed.has( slug ) ) {
			owners.set(
				slug,
				( listed.get( slug ) ?? [] ).map( ( stack ) => stack.name )
			);
		}
	}
	return owners;
}

/**
 * One group per creature type holding the blocks it owns (template blocks first, then its other blocks by name),
 * then every block more than one type uses, then every block no type uses. Creature types the chronicle has
 * switched on come first.
 */
export function groupBlocksByCreatureType(
	blocks: SchemaBlock[],
	stacks: CreatureStack[],
	enabledSlugs: Set< string >,
	labels: BlockGroupLabels
): BlockGroup[] {
	const listed = templateOwners( stacks );
	const bySlug = new Map( blocks.map( ( block ) => [ block.slug, block ] ) );
	const unique = [ ...bySlug.values() ];
	const ownerOf = new Map< string, string >();
	for ( const block of unique ) {
		const owner = owningCreatureType( block.slug, stacks, listed );
		if ( owner ) {
			ownerOf.set( block.slug, owner.slug );
		}
	}

	const byName = ( a: SchemaBlock, b: SchemaBlock ) =>
		a.name.localeCompare( b.name );
	const ordered = [ ...stacks ].sort( ( a, b ) => {
		const aOn = enabledSlugs.has( a.slug );
		const bOn = enabledSlugs.has( b.slug );
		if ( aOn !== bOn ) {
			return aOn ? -1 : 1;
		}
		return a.name.localeCompare( b.name );
	} );

	const groups: BlockGroup[] = [];
	for ( const stack of ordered ) {
		const own = unique.filter(
			( block ) => ownerOf.get( block.slug ) === stack.slug
		);
		if ( own.length === 0 ) {
			continue;
		}
		const position = new Map(
			slugsInOrder( stack ).map( ( slug, index ) => [ slug, index ] )
		);
		own.sort( ( a, b ) => {
			const aAt = position.get( a.slug );
			const bAt = position.get( b.slug );
			if ( aAt !== undefined && bAt !== undefined ) {
				return aAt - bAt;
			}
			if ( aAt !== undefined || bAt !== undefined ) {
				return aAt !== undefined ? -1 : 1;
			}
			return byName( a, b );
		} );
		groups.push( {
			label: enabledSlugs.has( stack.slug )
				? stack.name
				: labels.notEnabled( stack.name ),
			blocks: own,
		} );
	}

	const unowned = unique.filter( ( block ) => ! ownerOf.has( block.slug ) );
	const shared = unowned
		.filter( ( block ) => ( listed.get( block.slug ) ?? [] ).length > 1 )
		.sort( byName );
	const other = unowned
		.filter( ( block ) => ! listed.has( block.slug ) )
		.sort( byName );

	if ( shared.length > 0 ) {
		groups.push( { label: labels.shared, blocks: shared } );
	}
	if ( other.length > 0 ) {
		groups.push( { label: labels.other, blocks: other } );
	}
	return groups;
}

/**
 * A block's name followed by its creature type when exactly one type owns it ("Abilities (Vampire)"), or by its slug
 * ("Gifts [fera-gifts]") when no single type owns it and another listed block carries the same name.
 */
export function blockLabel(
	name: string,
	slug: string,
	owners: Map< string, string[] >,
	listed: SchemaBlock[] = []
): string {
	const types = owners.get( slug ) ?? [];
	if ( types.length === 1 ) {
		return `${ name } (${ types[ 0 ] })`;
	}
	return nameIsRepeated( name, listed ) ? `${ name } [${ slug }]` : name;
}

/**
 * A block's name inside a group that already names its creature type: the name, or the name and slug when another
 * block in the same group carries the same name.
 */
export function optionLabel(
	block: SchemaBlock,
	group: SchemaBlock[]
): string {
	return nameIsRepeated( block.name, group )
		? `${ block.name } [${ block.slug }]`
		: block.name;
}

function nameIsRepeated( name: string, blocks: SchemaBlock[] ): boolean {
	return blocks.filter( ( block ) => block.name === name ).length > 1;
}

export interface BlockChoices {
	groups: OptionGroup[];
	/**
	 * The slug behind a label the picker returned, or undefined for a label it never offered.
	 */
	slugOf: ( label: string ) => string | undefined;
	/**
	 * The label a block is offered under, or '' for a slug no group holds.
	 */
	labelOf: ( slug: string ) => string;
}

/**
 * A block's label in a pick list: its name and creature type, without the type again when the name already
 * carries it ("Vampire Identity").
 */
function choiceLabel(
	block: SchemaBlock,
	owners: Map< string, string[] >,
	listed: SchemaBlock[]
): string {
	const types = owners.get( block.slug ) ?? [];
	if (
		types.length === 1 &&
		block.name.toLowerCase().includes( types[ 0 ].toLowerCase() )
	) {
		return block.name;
	}
	return blockLabel( block.name, block.slug, owners, listed );
}

/**
 * The groups as a searchable select's pick list: one label per block, unique across every group (a block's name
 * and creature type, or its slug when two blocks would still read the same), with a way back to the slug.
 */
export function blockChoices(
	groups: BlockGroup[],
	owners: Map< string, string[] >,
	listed: SchemaBlock[]
): BlockChoices {
	const list = pickList(
		groups.map( ( group ) => ( {
			label: group.label,
			items: group.blocks.map( ( block ) => ( {
				key: block.slug,
				label: choiceLabel( block, owners, listed ),
			} ) ),
		} ) )
	);
	return { groups: list.groups, slugOf: list.keyOf, labelOf: list.labelOf };
}
