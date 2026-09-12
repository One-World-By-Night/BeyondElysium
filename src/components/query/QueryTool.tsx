/**
 * QueryTool is the Storyteller character-search widget: it hosts the Search,
 * Statistics, and Saved Queries tabs, owns the query-building and results state,
 * and calls the query REST endpoints. QueryBuilder, QueryResults, and
 * StatisticsView render its three tabs.
 */
import { __ } from '@wordpress/i18n';
import { useEffect, useState } from '@wordpress/element';
import api from '../../api/client';
import type { QueryCondition, QueryField, QueryLogic, QueryResultCharacter, SavedQuery, StatisticsResult, StatisticType } from '../../types/query';
import { QueryBuilder } from './QueryBuilder';
import { QueryResults } from './QueryResults';
import { StatisticsView } from './StatisticsView';
import './QueryTool.css';

export interface QueryToolProps {
	gameSlug: string;
}

type Tab = 'query' | 'statistics' | 'saved';

/** The four inventories the query builder offers - Field_Registry::QUERYABLE_INVENTORIES. */
const INVENTORIES: { value: string; label: string }[] = [
	{ value: 'char', label: __( 'Characters', 'beyond-elysium' ) },
	{ value: 'item', label: __( 'Items', 'beyond-elysium' ) },
	{ value: 'loc', label: __( 'Locations', 'beyond-elysium' ) },
	{ value: 'rote', label: __( 'Rotes', 'beyond-elysium' ) },
];

/** Result-table columns per inventory - matches query-inventories.php's own result_columns. */
const RESULT_COLUMNS: Record<string, { key: string; label: string }[]> = {
	char: [
		{ key: 'name', label: __( 'Name', 'beyond-elysium' ) },
		{ key: 'stack_slug', label: __( 'Type', 'beyond-elysium' ) },
		{ key: 'status', label: __( 'Status', 'beyond-elysium' ) },
	],
	item: [
		{ key: 'name', label: __( 'Name', 'beyond-elysium' ) },
		{ key: 'item_type', label: __( 'Item Type', 'beyond-elysium' ) },
		{ key: 'level', label: __( 'Level', 'beyond-elysium' ) },
	],
	loc: [
		{ key: 'name', label: __( 'Name', 'beyond-elysium' ) },
		{ key: 'location_type', label: __( 'Location Type', 'beyond-elysium' ) },
		{ key: 'level', label: __( 'Level', 'beyond-elysium' ) },
	],
	rote: [
		{ key: 'name', label: __( 'Name', 'beyond-elysium' ) },
		{ key: 'level', label: __( 'Level', 'beyond-elysium' ) },
		{ key: 'duration', label: __( 'Duration', 'beyond-elysium' ) },
	],
};

const PER_PAGE = 20;

/**
 * The Storyteller search tool: build and run a query against characters, view
 * results or statistics, and save, load, rename, or delete saved queries. Tabs
 * switch between the Search, Statistics, and Saved Queries views.
 */
export function QueryTool( { gameSlug }: QueryToolProps ) {
	const [ tab, setTab ] = useState<Tab>( 'query' );
	const [ inventory, setInventory ] = useState( 'char' );
	const [ fields, setFields ] = useState<QueryField[]>( [] );
	const [ conditions, setConditions ] = useState<QueryCondition[]>( [] );
	const [ logic, setLogic ] = useState<QueryLogic>( 'AND' );

	const [ results, setResults ] = useState<QueryResultCharacter[]>( [] );
	const [ total, setTotal ] = useState( 0 );
	const [ page, setPage ] = useState( 1 );
	const [ sortField, setSortField ] = useState<string | undefined>();
	const [ sortDirection, setSortDirection ] = useState<'asc' | 'desc'>( 'asc' );
	const [ loading, setLoading ] = useState( false );
	const [ error, setError ] = useState<string | null>( null );

	const [ statsResult, setStatsResult ] = useState<StatisticsResult | null>( null );
	const [ statsLoading, setStatsLoading ] = useState( false );
	const [ statsError, setStatsError ] = useState<string | null>( null );

	const [ savedQueries, setSavedQueries ] = useState<SavedQuery[]>( [] );
	const [ saveName, setSaveName ] = useState( '' );

	// The field list is fetched once here (not independently in QueryBuilder/StatisticsView)
	// and re-fetched whenever the inventory changes - a Clan clause built against the char
	// field list has no meaning once the field list itself has moved to Items.
	useEffect( () => {
		api.queryFields
			.list( inventory )
			.then( ( all ) => setFields( all.filter( ( f ) => f.mapped ) ) )
			.catch( () => {
				setFields( [] );
				setError( __( 'Failed to load the field list. Try refreshing the page.', 'beyond-elysium' ) );
			} );
	}, [ gameSlug, inventory ] );

	useEffect( () => {
		loadSavedQueries();
	}, [ gameSlug ] ); // eslint-disable-line react-hooks/exhaustive-deps

	/**
	 * Switches the active inventory and clears every piece of state that
	 * belonged to the previous one - stale conditions built against a field
	 * list that no longer applies, a stale result set, and a stale
	 * statistics result. This is the fix for Grapevine's own frmQuery.frm:
	 * 1203 bug, applied to the one place a stale inventory could otherwise
	 * survive a switch.
	 */
	function switchInventory( next: string ) {
		setInventory( next );
		setConditions( [] );
		setResults( [] );
		setTotal( 0 );
		setSortField( undefined );
		setStatsResult( null );
	}

	function loadSavedQueries() {
		api.query( gameSlug )
			.savedQueries.list()
			.then( setSavedQueries )
			.catch( () => {
				setSavedQueries( [] );
				setError( __( 'Failed to load saved queries.', 'beyond-elysium' ) );
			} );
	}

	async function runQuery( targetPage = page ) {
		if ( conditions.length === 0 ) {
			return;
		}
		setLoading( true );
		setError( null );
		try {
			const result = await api.query( gameSlug ).runPaginated( {
				inventory,
				conditions,
				logic,
				sort: sortField ? { field: sortField, direction: sortDirection } : undefined,
				page: targetPage,
				per_page: PER_PAGE,
			} );
			setResults( result.items );
			setTotal( result.total );
			setPage( targetPage );
			loadSavedQueries();
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		} finally {
			setLoading( false );
		}
	}

	function onSort( field: string, direction: 'asc' | 'desc' ) {
		setSortField( field );
		setSortDirection( direction );
		runQuery( 1 );
	}

	async function runStatistics( key: string, statType: StatisticType, okZero: boolean, trait?: string ) {
		setStatsLoading( true );
		setStatsError( null );
		try {
			const result = await api.query( gameSlug ).statistics( { inventory, conditions, logic, key, stat_type: statType, ok_zero: okZero, trait } );
			setStatsResult( result );
		} catch ( err: unknown ) {
			// Shows an explicit error instead of a null result indistinguishable from "nothing run yet."
			setStatsResult( null );
			setStatsError( errorMessage( err ) );
		} finally {
			setStatsLoading( false );
		}
	}

	async function saveCurrentQuery() {
		if ( ! saveName.trim() || conditions.length === 0 ) {
			return;
		}
		try {
			await api.query( gameSlug ).savedQueries.create( { name: saveName.trim(), inventory, logic, conditions } );
			setSaveName( '' );
			loadSavedQueries();
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		}
	}

	/**
	 * Restores a saved query's inventory FIRST, before its conditions - the
	 * direct fix for Grapevine's own frmQuery.frm:1203, which loaded a saved
	 * query's Inventory and then immediately overwrote it with qiCharacters
	 * on the next validate, silently re-running a saved item/location/rote
	 * query against characters instead. Setting conditions before the
	 * inventory would show clauses whose fields are not in the (still-char)
	 * field list for one render.
	 */
	function loadSavedQuery( saved: SavedQuery ) {
		setInventory( saved.inventory );
		setConditions( saved.conditions );
		setLogic( saved.match_all ? 'AND' : 'OR' );
		setResults( [] );
		setTotal( 0 );
		setTab( 'query' );
	}

	async function deleteSavedQuery( id: number ) {
		try {
			await api.query( gameSlug ).savedQueries.delete( id );
			loadSavedQueries();
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		}
	}

	async function renameSavedQuery( saved: SavedQuery ) {
		// eslint-disable-next-line no-alert
		const name = window.prompt( __( 'Rename query', 'beyond-elysium' ), saved.name );
		if ( ! name || ! name.trim() || name.trim() === saved.name ) {
			return;
		}
		try {
			await api.query( gameSlug ).savedQueries.update( saved.id, { name: name.trim() } );
			loadSavedQueries();
		} catch ( err: unknown ) {
			setError( errorMessage( err ) );
		}
	}

	return (
		<div className="be-query-tool">
			<nav className="be-query-tool__tabs">
				<button type="button" className={ tab === 'query' ? 'is-active' : '' } onClick={ () => setTab( 'query' ) }>
					{ __( 'Search', 'beyond-elysium' ) }
				</button>
				<button type="button" className={ tab === 'statistics' ? 'is-active' : '' } onClick={ () => setTab( 'statistics' ) }>
					{ __( 'Statistics', 'beyond-elysium' ) }
				</button>
				<button type="button" className={ tab === 'saved' ? 'is-active' : '' } onClick={ () => setTab( 'saved' ) }>
					{ __( 'Saved Queries', 'beyond-elysium' ) }
				</button>
			</nav>

			{ error && (
				<div className="be-query-tool__error" role="alert">
					{ error }
				</div>
			) }

			<nav className="be-query-tool__inventories" role="tablist" aria-label={ __( 'Inventory to query', 'beyond-elysium' ) }>
				{ INVENTORIES.map( ( inv ) => (
					<button
						key={ inv.value }
						type="button"
						role="tab"
						aria-selected={ inventory === inv.value }
						className={ inventory === inv.value ? 'is-active' : '' }
						onClick={ () => switchInventory( inv.value ) }
					>
						{ inv.label }
					</button>
				) ) }
			</nav>

			{ tab === 'query' && (
				<>
					<QueryBuilder fields={ fields } conditions={ conditions } logic={ logic } onChange={ ( c, l ) => { setConditions( c ); setLogic( l ); } } />

					<div className="be-query-tool__run-row">
						<button type="button" onClick={ () => runQuery( 1 ) } disabled={ loading || conditions.length === 0 }>
							{ loading ? __( 'Running…', 'beyond-elysium' ) : __( 'Run Query', 'beyond-elysium' ) }
						</button>
						<input type="text" placeholder={ __( 'Save as…', 'beyond-elysium' ) } value={ saveName } onChange={ ( e ) => setSaveName( e.target.value ) } />
						<button type="button" onClick={ saveCurrentQuery } disabled={ ! saveName.trim() || conditions.length === 0 }>
							{ __( 'Save', 'beyond-elysium' ) }
						</button>
					</div>

					<QueryResults
						gameSlug={ gameSlug }
						inventory={ inventory }
						columns={ RESULT_COLUMNS[ inventory ] ?? RESULT_COLUMNS.char }
						items={ results }
						total={ total }
						page={ page }
						perPage={ PER_PAGE }
						onPageChange={ runQuery }
						onSort={ onSort }
						sortField={ sortField }
						sortDirection={ sortDirection }
					/>
				</>
			) }

			{ tab === 'statistics' && (
				<>
					{ statsError && (
						<div className="be-query-tool__error" role="alert">
							{ statsError }
						</div>
					) }
					<StatisticsView fields={ fields } onRun={ runStatistics } result={ statsResult } loading={ statsLoading } />
				</>
			) }

			{ tab === 'saved' && (
				<ul className="be-query-tool__saved-list">
					{ savedQueries.length === 0 && <p>{ __( 'No saved queries yet.', 'beyond-elysium' ) }</p> }
					{ savedQueries.map( ( saved ) => (
						<li key={ saved.id }>
							<span>
								{ saved.name }
								{ ' ' }
								<em className="be-query-tool__saved-inventory">
									({ INVENTORIES.find( ( inv ) => inv.value === saved.inventory )?.label ?? saved.inventory })
								</em>
								{ saved.is_recent_search && <em> { __( '(auto)', 'beyond-elysium' ) }</em> }
							</span>
							<button type="button" onClick={ () => loadSavedQuery( saved ) }>
								{ __( 'Load', 'beyond-elysium' ) }
							</button>
							{ ! saved.is_recent_search && (
								<button type="button" onClick={ () => renameSavedQuery( saved ) }>
									{ __( 'Rename', 'beyond-elysium' ) }
								</button>
							) }
							<button type="button" onClick={ () => deleteSavedQuery( saved.id ) }>
								{ __( 'Delete', 'beyond-elysium' ) }
							</button>
						</li>
					) ) }
				</ul>
			) }
		</div>
	);
}

interface RestError {
	message?: string;
}

function errorMessage( error: unknown ): string {
	if ( typeof error === 'object' && error !== null && ( error as RestError ).message ) {
		return ( error as RestError ).message as string;
	}
	return __( 'Failed to run this query.', 'beyond-elysium' );
}

export default QueryTool;
