/**
 * Renders a resource_pool schema block (Blood, Willpower, Renown, ...)
 * as one row per pool, showing its resolved display label and its
 * permanent/temporary value rendered as glyphs via `displayTemper()`.
 * Pools missing from the character's data fall back to their default
 * starting value.
 */
import { displayTemper, type ResourcePoolValue } from '../../lib/displayTemper';
import { resolvePoolName } from '../../lib/resolveCrossBlockRef';
import type { ResourcePoolDefinition } from '../../types';
import './ResourcePoolRenderer.css';

export interface ResourcePoolRendererProps {
	blockSlug: string;
	data: Record<string, ResourcePoolValue>;
	definition: ResourcePoolDefinition;
	/** The character's full sheet_data, used to resolve a pool's display name when it depends on another block's value. */
	sheetData?: Record<string, unknown>;
}

/**
 * Renders a resource_pool section (Blood, Willpower, Renown, ...) via
 * `displayTemper()`. A pool absent from the character's data starts at
 * the block's own default rather than rendering blank. The storage key
 * for each pool is always `pool.name`; only its displayed label can vary
 * per character via `name_lookup`.
 */
export function ResourcePoolRenderer( { blockSlug, data, definition, sheetData }: ResourcePoolRendererProps ) {
	return (
		<div className="be-resource-pool" data-block-slug={ blockSlug }>
			{ definition.pools.map( ( pool ) => {
				const value: ResourcePoolValue = data[ pool.name ] ?? {
					permanent: pool.default_start,
					temporary: pool.default_start,
				};

				return (
					<div className="be-resource-pool__row" key={ pool.name }>
						<span className="be-resource-pool__label">{ resolvePoolName( pool, sheetData ?? {} ) }</span>
						<span className="be-resource-pool__glyphs">{ displayTemper( value ) }</span>
					</div>
				);
			} ) }
		</div>
	);
}

export default ResourcePoolRenderer;
