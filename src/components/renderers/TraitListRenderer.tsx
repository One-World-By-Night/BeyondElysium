/**
 * Renders a trait_list schema block (Merits, Abilities, Backgrounds,
 * ...): each held trait formatted through `displayTrait()`, optionally
 * grouped by category or by a nested field, and optionally alphabetized.
 * Renders a "None" placeholder when the list is empty.
 */
import { __ } from '@wordpress/i18n';
import {
	displayTrait,
	type Trait,
	type DisplayType,
} from '../../lib/displayTrait';
import { groupTraitsByField } from '../../lib/groupTraitsByField';
import { localizedItemName } from '../../lib/localizeName';
import WithDots from '../shared/Dots';
import type { TraitListDefinition, TraitListItem } from '../../types';
import './TraitListRenderer.css';

export interface TraitListRendererProps {
	blockSlug: string;
	data: Trait[];
	definition: TraitListDefinition;
	/** Template section override. Null falls through to the block's own default. */
	display: DisplayType | null;
	/** Whether a count_is_cost block's flat XP price is shown at all (1.2.11 D94). Unset shows it. */
	showCost?: boolean;
}

interface TraitGroup {
	label: string | null;
	traits: Trait[];
}

/** Resolves the display mode: the template section's override, then the block's own default, then 'simple'. */
export function resolveDisplay(
	sectionDisplay: DisplayType | null,
	blockDisplay: DisplayType | undefined
): DisplayType {
	return sectionDisplay ?? blockDisplay ?? 'simple';
}

/**
 * Resolves the display mode a whole trait_list section renders at: `resolveDisplay()` for
 * every ordinary block, and never `resolveDisplay()` for a `count_is_cost` one.
 *
 * 1.2.11 D94: a `count_is_cost` block (Combo Disciplines) stores a flat XP price in the
 * field every other block stores a rating in, so handing it to a rating display prints a
 * price as dots or as a bare number with no unit. The price is therefore always labelled
 * - `Draw Fire (12 XP)` - whatever `display` the block or template section carries. The
 * viewer preference chooses only whether the price is shown; hidden, the number is
 * dropped entirely rather than falling back to a rating.
 *
 * The PHP twin is `Trait_Grouping::resolve_mode()`, proven against the same fixture.
 */
export function resolveTraitListMode(
	definition: TraitListDefinition,
	sectionDisplay: DisplayType | null,
	showCost?: boolean
): DisplayType {
	if ( ! definition.count_is_cost ) {
		return resolveDisplay( sectionDisplay, definition.display );
	}
	return showCost === false ? 'note_only' : 'cost_xp';
}

/**
 * Whether a trait_list section groups and alphabetizes its held entries at all.
 * False only for a player_order block (1.1.0 D4): its held entries render in
 * their stored array order - no alphabetizing, no field/category grouping. The
 * player's own order is their grouping.
 */
export function groupsAndSorts( definition: TraitListDefinition ): boolean {
	return ! definition.player_order;
}

/**
 * Group held traits by `definition.categories`, in that array's order, by looking each
 * trait's catalog entry up in `definition.items` for its `category`. Uncategorized or
 * unrecognized-category traits land in a trailing "Other" bucket rather than vanishing.
 * A block with no `categories` renders flat.
 */
export function groupByCategory(
	data: Trait[],
	definition: TraitListDefinition
): TraitGroup[] {
	if ( ! definition.categories || definition.categories.length === 0 ) {
		return [ { label: null, traits: data } ];
	}

	const catalogByName = new Map(
		definition.items.map( ( item ) => [ item.name, item ] )
	);
	const buckets = new Map< string, Trait[] >();
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
		.map( ( category ) => ( {
			label: category,
			traits: buckets.get( category ) as Trait[],
		} ) );

	if ( other.length > 0 ) {
		groups.push( {
			label: __( 'Other', 'beyond-elysium' ),
			traits: other,
		} );
	}

	return groups;
}

export function sortIfAlphabetized(
	traits: Trait[],
	alphabetize?: boolean
): Trait[] {
	if ( ! alphabetize ) {
		return traits;
	}
	return [ ...traits ].sort( ( a, b ) => a.name.localeCompare( b.name ) );
}

/**
 * Swaps a held trait's display name for its catalog translation, when the site is
 * Portuguese and one exists (i18n-pt-br-design.md) - a display-only copy, never mutating
 * the trait's own `name` (still the canonical value every sort/key/lookup above uses).
 */
function localizeTraitForDisplay(
	trait: Trait,
	catalogByName: Map< string, TraitListItem >
): Trait {
	const catalogItem = catalogByName.get( trait.name );
	if ( ! catalogItem?.name_pt ) {
		return trait;
	}
	return { ...trait, name: localizedItemName( catalogItem ) };
}

/**
 * Renders a trait_list section. Every trait goes through `displayTrait()` at the
 * resolved display mode. An empty list still renders its "None" placeholder rather than
 * disappearing - a blank Merits section is information, not nothing.
 *
 * Creature-agnostic: nothing here branches on stack_slug or block_slug identity.
 */
export function TraitListRenderer( {
	blockSlug,
	data,
	definition,
	display,
	showCost,
}: TraitListRendererProps ) {
	const mode = resolveTraitListMode( definition, display, showCost );
	const nested = groupsAndSorts( definition )
		? groupTraitsByField( data, definition )
		: null;
	const catalogByName = new Map(
		definition.items.map( ( item ) => [ item.name, item ] )
	);

	if ( data.length === 0 ) {
		return (
			<div className="be-trait-list" data-block-slug={ blockSlug }>
				<p className="be-trait-list__empty">
					{ __( 'None', 'beyond-elysium' ) }
				</p>
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
							<div
								className="be-trait-list__subgroup"
								key={ subgroup ?? '' }
							>
								{ subgroup && (
									<h5 className="be-trait-list__subcategory">
										{ subgroup }
									</h5>
								) }
								<ul className="be-trait-list__items">
									{ sortIfAlphabetized(
										items,
										definition.alphabetize
									).map( ( trait, i ) => (
										<li key={ `${ trait.name }-${ i }` }>
											<WithDots
												text={ displayTrait(
													localizeTraitForDisplay(
														trait,
														catalogByName
													),
													mode
												) }
											/>
										</li>
									) ) }
								</ul>
							</div>
						) ) }
					</div>
				) ) }
			</div>
		);
	}

	const groups = groupsAndSorts( definition )
		? groupByCategory( data, definition )
		: [ { label: null, traits: data } ];

	return (
		<div className="be-trait-list" data-block-slug={ blockSlug }>
			{ groups.map( ( group, index ) => (
				<div
					className="be-trait-list__group"
					key={ group.label ?? index }
				>
					{ group.label && (
						<h4 className="be-trait-list__category">
							{ group.label }
						</h4>
					) }
					<ul className="be-trait-list__items">
						{ ( groupsAndSorts( definition )
							? sortIfAlphabetized(
									group.traits,
									definition.alphabetize
							  )
							: group.traits
						).map( ( trait, i ) => (
							<li key={ `${ trait.name }-${ i }` }>
								<WithDots
									text={ displayTrait(
										localizeTraitForDisplay(
											trait,
											catalogByName
										),
										mode
									) }
								/>
							</li>
						) ) }
					</ul>
				</div>
			) ) }
		</div>
	);
}

export default TraitListRenderer;
