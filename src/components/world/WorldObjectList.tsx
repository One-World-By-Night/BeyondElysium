/**
 * World object catalog browser: a type tab strip (items, locations, rotes), a name search field, and a paginated
 * table with columns specific to the active type.
 */
import { useEffect, useState } from '@wordpress/element';
import { __, sprintf } from '@wordpress/i18n';
import api from '../../api/client';
import type { CopiesFilter, ObjectType, WorldObject } from '../../types/world';
import TabStrip from '../shared/TabStrip';
import './WorldObjectList.css';

export interface WorldObjectListProps {
	gameSlug: string;
	onSelect?: ( objectId: number ) => void;
	defaultType?: ObjectType;
	onTypeChange?: ( type: ObjectType ) => void;
}

/**
 * Boons have their own ledger UI (BoonLedger.tsx).
 */
const CATALOG_TYPES: ObjectType[] = [ 'item', 'location', 'rote' ];

const TYPE_LABELS: Record< ObjectType, string > = {
	item: __( 'Items', 'beyond-elysium' ),
	location: __( 'Locations', 'beyond-elysium' ),
	rote: __( 'Rotes', 'beyond-elysium' ),
	boon: __( 'Boons', 'beyond-elysium' ),
};

/**
 * Which item copies the list shows, for items only.
 */
const COPIES_OPTIONS: { value: CopiesFilter; label: string }[] = [
	{ value: 'exclude', label: __( 'Catalog', 'beyond-elysium' ) },
	{ value: 'only', label: __( 'Personal copies', 'beyond-elysium' ) },
	{ value: 'include', label: __( 'All', 'beyond-elysium' ) },
];

/**
 * Renders the world object catalog for one type at a time: a tab strip to switch type, a search field, and a
 * paginated table whose columns change with the active type.
 */
export function WorldObjectList( {
	gameSlug,
	onSelect,
	defaultType,
	onTypeChange,
}: WorldObjectListProps ) {
	const [ objectType, setObjectType ] = useState< ObjectType >(
		defaultType ?? 'item'
	);
	const [ items, setItems ] = useState< WorldObject[] >( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ search, setSearch ] = useState( '' );
	const [ copies, setCopies ] = useState< CopiesFilter >( 'exclude' );
	const [ loading, setLoading ] = useState( true );
	const [ error, setError ] = useState< string | null >( null );

	function load() {
		setLoading( true );
		setError( null );
		api.worldObjects( gameSlug )
			.listPaginated( {
				object_type: objectType,
				search: search || undefined,
				copies: objectType === 'item' ? copies : undefined,
				page,
				per_page: 20,
			} )
			.then( ( result ) => {
				setItems( result.items );
				setTotal( result.total );
				setLoading( false );
			} )
			.catch( () => {
				setError(
					__( 'Failed to load world objects.', 'beyond-elysium' )
				);
				setLoading( false );
			} );
	}

	useEffect( load, [ gameSlug, objectType, search, copies, page ] ); // eslint-disable-line react-hooks/exhaustive-deps

	function selectType( type: ObjectType ) {
		setObjectType( type );
		setPage( 1 );
		onTypeChange?.( type );
	}

	return (
		<div className="be-world-list">
			<TabStrip
				tabs={ CATALOG_TYPES.map( ( type ) => ( {
					key: type,
					label: TYPE_LABELS[ type ],
				} ) ) }
				active={ objectType }
				onChange={ ( key ) => selectType( key as ObjectType ) }
			/>

			<div className="be-world-list__filters">
				<input
					type="search"
					value={ search }
					onChange={ ( e ) => setSearch( e.target.value ) }
					placeholder={ __( 'Search name…', 'beyond-elysium' ) }
				/>
				{ objectType === 'item' && (
					<select
						value={ copies }
						onChange={ ( e ) => {
							setCopies( e.target.value as CopiesFilter );
							setPage( 1 );
						} }
					>
						{ COPIES_OPTIONS.map( ( o ) => (
							<option key={ o.value } value={ o.value }>
								{ o.label }
							</option>
						) ) }
					</select>
				) }
			</div>

			{ error && (
				<div className="be-world-list__error" role="alert">
					{ error }
				</div>
			) }

			{ loading ? (
				<p>{ __( 'Loading…', 'beyond-elysium' ) }</p>
			) : items.length === 0 ? (
				<p>
					{ sprintf(
						/* translators: %1$s: the object type, e.g. "items" or "locations" */
						__( 'No %1$s match these filters.', 'beyond-elysium' ),
						TYPE_LABELS[ objectType ].toLowerCase()
					) }
				</p>
			) : (
				<div className="be-table-box">
					<table className="be-world-list__table be-responsive-table">
						<thead>
							<tr>
								<th>{ __( 'Name', 'beyond-elysium' ) }</th>
								{ objectType === 'item' && (
									<>
										<th>
											{ __( 'Type', 'beyond-elysium' ) }
										</th>
										<th>
											{ __( 'Damage', 'beyond-elysium' ) }
										</th>
										<th>
											{ __( 'Level', 'beyond-elysium' ) }
										</th>
									</>
								) }
								{ objectType === 'location' && (
									<>
										<th>
											{ __( 'Type', 'beyond-elysium' ) }
										</th>
										<th>
											{ __( 'Owner', 'beyond-elysium' ) }
										</th>
										<th>
											{ __(
												'Security',
												'beyond-elysium'
											) }
										</th>
									</>
								) }
								{ objectType === 'rote' && (
									<>
										<th>
											{ __( 'Level', 'beyond-elysium' ) }
										</th>
										<th>
											{ __(
												'Spheres',
												'beyond-elysium'
											) }
										</th>
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
												onKeyDown: (
													e: React.KeyboardEvent
												) => {
													if (
														e.key === 'Enter' ||
														e.key === ' '
													) {
														e.preventDefault();
														onSelect( item.id );
													}
												},
										  }
										: {} ) }
								>
									<td
										className="be-world-list__name"
										data-label={ __(
											'Name',
											'beyond-elysium'
										) }
									>
										{ item.name }
									</td>
									{ objectType === 'item' && (
										<>
											<td
												data-label={ __(
													'Type',
													'beyond-elysium'
												) }
											>
												{ String(
													item.properties.item_type ??
														'—'
												) }
											</td>
											<td
												data-label={ __(
													'Damage',
													'beyond-elysium'
												) }
											>
												{ item.properties.damage_type
													? `${
															item.properties
																.damage_type
													  } ${
															item.properties
																.damage_amount ??
															''
													  }`.trim()
													: '—' }
											</td>
											<td
												data-label={ __(
													'Level',
													'beyond-elysium'
												) }
											>
												{ String(
													item.properties.level ?? '—'
												) }
											</td>
										</>
									) }
									{ objectType === 'location' && (
										<>
											<td
												data-label={ __(
													'Type',
													'beyond-elysium'
												) }
											>
												{ String(
													item.properties
														.location_type ?? '—'
												) }
											</td>
											<td
												data-label={ __(
													'Owner',
													'beyond-elysium'
												) }
											>
												{ String(
													item.properties.owner ?? '—'
												) }
											</td>
											<td
												data-label={ __(
													'Security',
													'beyond-elysium'
												) }
											>
												{ String(
													item.properties.security ??
														'—'
												) }
											</td>
										</>
									) }
									{ objectType === 'rote' && (
										<>
											<td
												data-label={ __(
													'Level',
													'beyond-elysium'
												) }
											>
												{ String(
													item.properties.level ?? '—'
												) }
											</td>
											<td
												data-label={ __(
													'Spheres',
													'beyond-elysium'
												) }
											>
												{ summarizeSpheres(
													item.properties.spheres
												) }
											</td>
										</>
									) }
								</tr>
							) ) }
						</tbody>
					</table>
				</div>
			) }

			<div className="be-world-list__pagination">
				<button
					type="button"
					disabled={ page <= 1 }
					onClick={ () => setPage( ( p ) => p - 1 ) }
				>
					{ __( 'Previous', 'beyond-elysium' ) }
				</button>
				<span>
					{ sprintf(
						/* translators: 1: current page number, 2: total number of matching objects */
						__( 'Page %1$d (%2$d total)', 'beyond-elysium' ),
						page,
						total
					) }
				</span>
				<button
					type="button"
					disabled={ page * 20 >= total }
					onClick={ () => setPage( ( p ) => p + 1 ) }
				>
					{ __( 'Next', 'beyond-elysium' ) }
				</button>
			</div>
		</div>
	);
}

/**
 * Formats a rote's sphere entries as a comma-separated "name count" string.
 */
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
