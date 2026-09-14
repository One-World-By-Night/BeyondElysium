/**
 * admin-menu-consolidation-design.md: replaces the separate "Query Tool" and
 * "Reports" wp-admin pages with one tabbed hub - both are "ask the roster a
 * question," one ad-hoc, one canned-to-signed-PDF. Each wrapped component
 * keeps its own <h1>, which doubles as the hub's live page title.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { TabStrip } from '../../shared/TabStrip';
import { readTabFromUrl, writeTabToUrl } from '../../../lib/pluginPages';
import AdminQuery from '../AdminQuery';
import AdminReports from '../AdminReports';
import type { Tab } from '../../shared/TabStrip';

const TABS = { query: 'query', reports: 'reports' };

export function QueryHub() {
	const [ tab, setTab ] = useState( () => readTabFromUrl( TABS.query ) );

	useEffect( () => {
		writeTabToUrl( tab );
	}, [ tab ] );

	const capabilities = window.beyondElysium?.capabilities;
	const tabs: Tab[] = [
		capabilities?.be_run_queries && { key: TABS.query, label: __( 'Query Tool', 'beyond-elysium' ) },
		capabilities?.be_view_reports && { key: TABS.reports, label: __( 'Reports', 'beyond-elysium' ) },
	].filter( Boolean ) as Tab[];

	useEffect( () => {
		if ( tabs.length > 0 && ! tabs.some( ( t ) => t.key === tab ) ) {
			setTab( tabs[ 0 ].key );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ tabs.map( ( t ) => t.key ).join( ',' ) ] );

	if ( tabs.length === 0 ) {
		return <p>{ __( 'You do not have permission to view this page.', 'beyond-elysium' ) }</p>;
	}

	return (
		<div className="be-admin-hub">
			<TabStrip tabs={ tabs } active={ tab } onChange={ setTab } />
			{ tab === TABS.query && <AdminQuery /> }
			{ tab === TABS.reports && <AdminReports /> }
		</div>
	);
}

export default QueryHub;
