/**
 * World object catalog browser: a type tab strip (items, locations,
 * rotes), a name search field, and a paginated table with columns
 * specific to the active type. Reports the selected row and the active
 * type to its parent for use in a detail pane.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type { ObjectType, WorldObject } from '../../types/world';
import './WorldObjectList.css';

export interface WorldObjectListProps {
	gameSlug: string;
	onSelect?: ( objectId: number ) => void;
	defaultType?: ObjectType;
	onTypeChange?: ( type: ObjectType ) => void;
}

/** Boons have their own ledger UI (BoonLedger.tsx) - this catalog is items/locations/rotes. */
const CATALOG_TYPES: ObjectType[] = [ 'item', 'location', 'rote' ];

const TYPE_LABELS: Record<ObjectType, string> = {
	item: __( 'Items', 'beyond-elysium' ),
	location: __( 'Locations', 'beyond-elysium' ),
	rote: __( 'Rotes', 'beyond-elysium' ),
	boon: __( 'Boons', 'beyond-elysium' ),
};

/**
 * Renders the world object catalog for one type at a time: a tab strip
 * to switch type, a search field, and a paginated table whose columns
 * change with the active type - items show type/damage/level, locations
 * show type/owner/security, rotes show level/spheres.
 */
export function WorldObjectList( { gameSlug, onSelect, defaultType, onTypeChange }: WorldObjectListProps ) {
	const [ objectType, setObjectType ] = useState<ObjectType>( defaultType ?? 'item' );
	const [ items, setItems ] = useState<WorldObject[]>( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState<string | null>( null );

	function load() {
		setLoading( true );
		setError( null );
		api
			.worldObjects( gameSlug )
			.listPaginated( {
				object_type: objectType,
				search: search || undefined,
				page,
				per_page: 20,
			} )
			.then( ( result ) => {
				setItems( result.items );
				setTotal( result.total );
				setLoading( false );
			} )
			.catch( () => {
				setError( __( 'Failed to load world objects.', 'beyond-elysium' ) );
				setLoading( false );
			} );
	}

	useEffect( load, [ gameSlug, objectType, search, page ] ); // eslint-disable-line react-hooks/exhaustive-deps

	function selectType( type: ObjectType ) {
		setObjectType( type );
		setPage( 1 );
		onTypeChange?.( type );
	}

	return (
		<div className="be-world-list">
			<nav className="be-world-list__tabs">
				{ CATALOG_TYPES.map( ( type ) => (
					<button
						key={ type }
						type="button"
						className={ objectType === type ? 'is-active' : '' }
						onClick={ () => selectType( type ) }
					>
						{ TYPE_LABELS[ type ] }
					</button>
				) ) }
			</nav>

			<div className="be-world-list__filters">
				<input
					type="search"
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
					placeholder={ __( 'Search name…', 'beyond-elysium' ) }
				/>
			</div>

			{ error && (
				<div className="be-world-list__error" role="alert">
					{ error }
				</div>
			) }

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : items.length === 0 ? (
				<p>{ sprintf( __( 'No %1$s match these filters.', 'beyond-elysium' ), TYPE_LABELS[ objectType ].toLowerCase() ) }</p>
			) : (
				<table className="be-world-list__table">
					<thead>
						<tr>
							<th>{ __( 'Name', 'beyond-elysium' ) }</th>
							{ objectType === 'item' && (
								<>
									<th>{ __( 'Type', 'beyond-elysium' ) }</th>
									<th>{ __( 'Damage', 'beyond-elysium' ) }</th>
									<th>{ __( 'Level', 'beyond-elysium' ) }</th>
								</>
							) }
							{ objectType === 'location' && (
								<>
									<th>{ __( 'Type', 'beyond-elysium' ) }</th>
									<th>{ __( 'Owner', 'beyond-elysium' ) }</th>
									<th>{ __( 'Security', 'beyond-elysium' ) }</th>
								</>
							) }
							{ objectType === 'rote' && (
								<>
									<th>{ __( 'Level', 'beyond-elysium' ) }</th>
									<th>{ __( 'Spheres', 'beyond-elysium' ) }</th>
								</>
							) }
						</tr>
					</thead>
					<tbody>
						{ items.map( ( item ) => (
							<tr
								key={ item.id }
								className="be-world-list__row"
								onClick={ () => onSelect?.( item.id ) }
								{ ...( onSelect
									? {
											role: 'button',
											tabIndex: 0,
											onKeyDown: ( e: React.KeyboardEvent ) => {
												if ( e.key === 'Enter' || e.key === ' ' ) {
													e.preventDefault();
													onSelect( item.id );
												}
											},
									  }
									: {} ) }
							>
								<td className="be-world-list__name">{ item.name }</td>
								{ objectType === 'item' && (
									<>
										<td>{ String( item.properties.item_type ?? '—' ) }</td>
										<td>
											{ item.properties.damage_type
												? `${ item.properties.damage_type } ${ item.properties.damage_amount ?? '' }`.trim()
												: '—' }
										</td>
										<td>{ String( item.properties.level ?? '—' ) }</td>
									</>
								) }
								{ objectType === 'location' && (
									<>
										<td>{ String( item.properties.location_type ?? '—' ) }</td>
										<td>{ String( item.properties.owner ?? '—' ) }</td>
										<td>{ String( item.properties.security ?? '—' ) }</td>
									</>
								) }
								{ objectType === 'rote' && (
									<>
										<td>{ String( item.properties.level ?? '—' ) }</td>
										<td>{ summarizeSpheres( item.properties.spheres ) }</td>
									</>
								) }
							</tr>
						) ) }
					</tbody>
				</table>
			) }

			<div className="be-world-list__pagination">
				<button type="button" disabled={ page <= 1 } onClick={ () => setPage( ( p ) => p - 1 ) }>
					{ __( 'Previous', 'beyond-elysium' ) }
				</button>
				<span>
					{ sprintf( __( 'Page %1$d (%2$d total)', 'beyond-elysium' ), page, total ) }
				</span>
				<button type="button" disabled={ page * 20 >= total } onClick={ () => setPage( ( p ) => p + 1 ) }>
					{ __( 'Next', 'beyond-elysium' ) }
				</button>
			</div>
		</div>
	);
}

/** Formats a rote's sphere entries as a comma-separated "name count" string. */
function summarizeSpheres( spheres: unknown ): string {
	if ( ! Array.isArray( spheres ) || spheres.length === 0 ) {
		return '—';
	}
	return spheres
		.map( ( entry ) => {
			const name = ( entry as { name?: string } )?.name ?? '?';
			const count = ( entry as { count?: number } )?.count;
			return count ? `${ name } ${ count }` : name;
		} )
		.join( ', ' );
}

export default WorldObjectList;
