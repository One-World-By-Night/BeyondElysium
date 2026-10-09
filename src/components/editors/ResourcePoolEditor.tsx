/**
 * ResourcePoolEditor renders the editable dot trackers for a resource_pool block.
 */
import { __ } from '@wordpress/i18n';
import DotTracker, { type DotTrackerValue } from '../shared/DotTracker';
import { resolvePoolName } from '../../lib/resolveCrossBlockRef';
import { trackerMax } from '../../lib/poolMax';
import { raiseButtonLabel, spentBadgeLabel } from '../../lib/raisedByPool';
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
	/**
	 * The character's full sheet_data, used to resolve a pool's display name from another block's value.
	 */
	sheetData?: Record< string, unknown >;
	/**
	 * A Storyteller of this chronicle sets any pool directly, bypassing its own max and any raised_by cost.
	 */
	isManager?: boolean;
}

/**
 * Renders a DotTracker control per pool defined in a resource_pool block, such as Blood Pool or Willpower.
 */
export function ResourcePoolEditor( {
	blockSlug,
	data,
	definition,
	onChange,
	readOnly,
	sheetData,
	isManager,
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
				const raisedBy = pool.raised_by;

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
							max={ trackerMax(
								isManager ? undefined : pool.max,
								value
							) }
							onChange={ ( next ) => setPool( pool.name, next ) }
							readOnly={
								readOnly || ( !! raisedBy && ! isManager )
							}
						/>
						{ spentBadgeLabel( value.spent ) && (
							<span
								className="be-resource-pool-editor__spent"
								title={ __(
									'Marked spent by a purchase elsewhere on the sheet - this never lowers the rating above.',
									'beyond-elysium'
								) }
							>
								{ spentBadgeLabel( value.spent ) }
							</span>
						) }
						{ raisedBy && (
							<button
								type="button"
								className="be-resource-pool-editor__raise"
								disabled={ readOnly }
								onClick={ () =>
									setPool( pool.name, {
										permanent: value.permanent + 1,
										temporary: value.temporary,
									} )
								}
							>
								{ raiseButtonLabel( raisedBy ) }
							</button>
						) }
					</div>
				);
			} ) }
		</div>
	);
}

export default ResourcePoolEditor;
