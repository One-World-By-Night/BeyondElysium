/**
 * Renders a trait_list schema block (Merits, Abilities, Backgrounds,...).
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
	/**
	 * Template section override.
	 */
	display: DisplayType | null;
	/**
	 * Whether a count_is_cost block's flat XP price is shown at all.
	 */
	showCost?: boolean;
}

interface TraitGroup {
	label: string | null;
	traits: Trait[];
}

/**
 * Resolves the display mode: the template section's override.
 */
export function resolveDisplay(
	sectionDisplay: DisplayType | null,
	blockDisplay: DisplayType | undefined
): DisplayType {
	return sectionDisplay ?? blockDisplay ?? 'simple';
}

/**
 * Resolves the display mode a whole trait_list section renders at: `resolveDisplay()` for every ordinary block.
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
 */
export function groupsAndSorts( definition: TraitListDefinition ): boolean {
	return ! definition.player_order;
}

/**
 * Group held traits by `definition.categories`, in that array's order, by looking each trait's catalog entry up in
 * `definition.items` for its `category`.
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
 * Swaps a held trait's display name for its catalog translation, when the site is Portuguese and one exists.
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
 * Renders a trait_list section.
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
