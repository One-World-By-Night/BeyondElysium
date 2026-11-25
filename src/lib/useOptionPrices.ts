/**
 * Prices a picker's options as they come into view.
 */
import { useEffect, useMemo, useRef, useState } from '@wordpress/element';
import api from '../api/client';
import type { BlockDefinition, SectionType } from '../types';
import { addedRowChange } from './optionPrice';
import { PriceBook } from './priceBook';

export interface OptionPricesInput {
	/**
	 * Present when this picker shows prices; `saved` is the block's saved data, which the prices are worked out from.
	 */
	pricing?: { saved: unknown };
	gameSlug?: string;
	characterId?: number;
	blockSlug: string;
	sectionType: SectionType;
	definition: BlockDefinition;
	/**
	 * The block's data after picking `value`, or null when that pick cannot be priced.
	 */
	nextFor: ( value: string ) => unknown;
	/**
	 * True for an option whose cost the player chooses.
	 */
	isVariable?: ( value: string ) => boolean;
}

/**
 * The hints for one picker's options, fetched for the options on screen; null when the picker shows no prices.
 */
export function useOptionPrices( input: OptionPricesInput ): PriceBook | null {
	const latest = useRef( input );
	latest.current = input;
	const [ , refresh ] = useState( 0 );

	const book = useMemo(
		() =>
			new PriceBook( {
				preview: ( changes ) =>
					api
						.characters( latest.current.gameSlug as string )
						.previewChanges(
							latest.current.characterId as number,
							changes
						),
				changeFor: ( value ) => {
					const now = latest.current;
					const next = now.nextFor( value );
					return next === null
						? null
						: addedRowChange(
								now.sectionType,
								now.blockSlug,
								now.definition,
								now.pricing?.saved,
								next
							);
				},
				isVariable: ( value ) =>
					latest.current.isVariable?.( value ) ?? false,
			} ),
		[]
	);

	useEffect(
		() => book.subscribe( () => refresh( ( count ) => count + 1 ) ),
		[ book ]
	);

	const enabled =
		!! input.pricing && !! input.gameSlug && !! input.characterId;
	const saved = input.pricing?.saved;
	useEffect( () => {
		book.reset();
	}, [
		book,
		saved,
		input.gameSlug,
		input.characterId,
		input.blockSlug,
		enabled,
	] );

	return enabled ? book : null;
}
