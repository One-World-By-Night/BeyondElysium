/**
 * ResourcePoolEditor renders the editable dot trackers for a resource_pool block -
 * paired permanent/temporary point pools such as Blood Pool, Willpower, or Rage.
 * Each pool in the block definition gets its own DotTracker control. Changing a
 * pool's value reports the whole block's data back through one onChange call.
 */
import DotTracker, { type DotTrackerValue } from '../shared/DotTracker';
import { resolvePoolName } from '../../lib/resolveCrossBlockRef';
import { trackerMax } from '../../lib/poolMax';
import type { ResourcePoolValue } from '../../lib/displayTemper';
import type { ResourcePoolDefinition } from '../../types';
import './ResourcePoolEditor.css';

export interface ResourcePoolEditorProps {
	blockSlug: string;
	data: Record< string, ResourcePoolValue >;
	definition: ResourcePoolDefinition;
	onChange: (
		blockSlug: string,
		nextData: Record< string, ResourcePoolValue >
	) => void;
	readOnly?: boolean;
	/** The character's full sheet_data, used to resolve a pool's display name from another block's value. */
	sheetData?: Record< string, unknown >;
}

/**
 * Renders a DotTracker control per pool defined in a resource_pool block, such as
 * Blood Pool or Willpower. Each tracker edits its pool's permanent and temporary
 * values independently; every change is reported back through onChange as the
 * full updated pool map.
 */
export function ResourcePoolEditor( {
	blockSlug,
	data,
	definition,
	onChange,
	readOnly,
	sheetData,
}: ResourcePoolEditorProps ) {
	const setPool = ( poolName: string, next: DotTrackerValue ) => {
		onChange( blockSlug, { ...data, [ poolName ]: next } );
	};

	return (
		<div className="be-resource-pool-editor" data-block-slug={ blockSlug }>
			{ definition.pools.map( ( pool ) => {
				const value: ResourcePoolValue = data[ pool.name ] ?? {
					permanent: pool.default_start,
					temporary: pool.default_start,
				};

				return (
					<div
						className="be-resource-pool-editor__row"
						key={ pool.name }
					>
						<span className="be-resource-pool-editor__label">
							{ resolvePoolName( pool, sheetData ?? {} ) }
						</span>
						<DotTracker
							permanent={ value.permanent }
							temporary={ value.temporary }
							max={ trackerMax( pool.max, value ) }
							onChange={ ( next ) => setPool( pool.name, next ) }
							readOnly={ readOnly }
						/>
					</div>
				);
			} ) }
		</div>
	);
}

export default ResourcePoolEditor;
