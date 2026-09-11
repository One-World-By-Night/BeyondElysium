/**
 * Renders a trait_list schema block (Merits, Abilities, Backgrounds,
 * ...): each held trait formatted through `displayTrait()`, optionally
 * grouped by category or by a nested field, and optionally alphabetized.
 * Renders a "None" placeholder when the list is empty.
 */
import { __ } from '@wordpress/i18n';
import { displayTrait, type Trait, type DisplayType } from '../../lib/displayTrait';
import { groupTraitsByField } from '../../lib/groupTraitsByField';
import type { TraitListDefinition } from '../../types';

export interface TraitListRendererProps {
	blockSlug: string;
	data: Trait[];
	definition: TraitListDefinition;
	/** Template section override. Null falls through to the block's own default. */
	display: DisplayType | null;
}

interface TraitGroup {
	label: string | null;
	traits: Trait[];
}

/** Resolves the display mode: the template section's override, then the block's own default, then 'simple'. */
function resolveDisplay( sectionDisplay: DisplayType | null, blockDisplay: DisplayType | undefined ): DisplayType {
	return sectionDisplay ?? blockDisplay ?? 'simple';
}

/**
 * Group held traits by `definition.categories`, in that array's order, by looking each
 * trait's catalog entry up in `definition.items` for its `category`. Uncategorized or
 * unrecognized-category traits land in a trailing "Other" bucket rather than vanishing.
 * A block with no `categories` renders flat.
 */
function groupByCategory( data: Trait[], definition: TraitListDefinition ): TraitGroup[] {
	if ( ! definition.categories || definition.categories.length === 0 ) {
		return [ { label: null, traits: data } ];
	}

	const catalogByName = new Map( definition.items.map( ( item ) => [ item.name, item ] ) );
	const buckets = new Map<string, Trait[]>();
	const other: Trait[] = [];

	for ( const trait of data ) {
		const category = catalogByName.get( trait.name )?.category;
		if ( category && definition.categories.includes( category ) ) {
			const bucket = buckets.get( category ) ?? [];
			bucket.push( trait );
			buckets.set( category, bucket );
		} else {
			other.push( trait );
		}
	}

	const groups: TraitGroup[] = definition.categories
		.filter( ( category ) => buckets.has( category ) )
		.map( ( category ) => ( { label: category, traits: buckets.get( category ) as Trait[] } ) );

	if ( other.length > 0 ) {
		groups.push( { label: __( 'Other', 'beyond-elysium' ), traits: other } );
	}

	return groups;
}

function sortIfAlphabetized( traits: Trait[], alphabetize?: boolean ): Trait[] {
	if ( ! alphabetize ) {
		return traits;
	}
	return [ ...traits ].sort( ( a, b ) => a.name.localeCompare( b.name ) );
}

/**
 * Renders a trait_list section. Every trait goes through `displayTrait()` at the
 * resolved display mode. An empty list still renders its "None" placeholder rather than
 * disappearing - a blank Merits section is information, not nothing.
 *
 * Creature-agnostic: nothing here branches on stack_slug or block_slug identity.
 */
export function TraitListRenderer( { blockSlug, data, definition, display }: TraitListRendererProps ) {
	const mode = resolveDisplay( display, definition.display );
	const nested = groupTraitsByField( data, definition );

	if ( data.length === 0 ) {
		return (
			<div className="be-trait-list" data-block-slug={ blockSlug }>
				<p className="be-trait-list__empty">{ __( 'None', 'beyond-elysium' ) }</p>
			</div>
		);
	}

	if ( nested ) {
		return (
			<div className="be-trait-list" data-block-slug={ blockSlug }>
				{ nested.map( ( { group, subgroups } ) => (
					<div className="be-trait-list__group" key={ group }>
						<h4 className="be-trait-list__category">{ group }</h4>
						{ subgroups.map( ( { subgroup, items } ) => (
							<div className="be-trait-list__subgroup" key={ subgroup ?? '' }>
								{ subgroup && <h5 className="be-trait-list__subcategory">{ subgroup }</h5> }
								<ul className="be-trait-list__items">
									{ sortIfAlphabetized( items, definition.alphabetize ).map( ( trait, i ) => (
										<li key={ `${ trait.name }-${ i }` }>{ displayTrait( trait, mode ) }</li>
									) ) }
								</ul>
							</div>
						) ) }
					</div>
				) ) }
			</div>
		);
	}

	const groups = groupByCategory( data, definition );

	return (
		<div className="be-trait-list" data-block-slug={ blockSlug }>
			{ groups.map( ( group, index ) => (
				<div className="be-trait-list__group" key={ group.label ?? index }>
					{ group.label && <h4 className="be-trait-list__category">{ group.label }</h4> }
					<ul className="be-trait-list__items">
						{ sortIfAlphabetized( group.traits, definition.alphabetize ).map( ( trait, i ) => (
							<li key={ `${ trait.name }-${ i }` }>{ displayTrait( trait, mode ) }</li>
						) ) }
					</ul>
				</div>
			) ) }
		</div>
	);
}

export default TraitListRenderer;
