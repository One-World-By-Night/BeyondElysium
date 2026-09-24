/**
 * The wp-admin Plots page gains a Releases tab alongside its existing Plots & Rumors content.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { TabStrip } from '../../shared/TabStrip';
import { readTabFromUrl, writeTabToUrl } from '../../../lib/pluginPages';
import AdminPlots from '../AdminPlots';
import AdminReleaseBatches from '../AdminReleaseBatches';
import type { Tab } from '../../shared/TabStrip';

const TABS = { plots: 'plots', releases: 'releases' };

export function PlotsHub() {
	const [ tab, setTab ] = useState( () => readTabFromUrl( TABS.plots ) );

	useEffect( () => {
		writeTabToUrl( tab );
	}, [ tab ] );

	const capabilities = window.beyondElysium?.capabilities;
	const tabs: Tab[] = [
		capabilities?.be_manage_plots && {
			key: TABS.plots,
			label: __( 'Plots & Rumors', 'beyond-elysium' ),
		},
		capabilities?.be_manage_plots && {
			key: TABS.releases,
			label: __( 'Releases', 'beyond-elysium' ),
		},
	].filter( Boolean ) as Tab[];

	useEffect( () => {
		if ( tabs.length > 0 && ! tabs.some( ( t ) => t.key === tab ) ) {
			setTab( tabs[ 0 ].key );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ tabs.map( ( t ) => t.key ).join( ',' ) ] );

	if ( tabs.length === 0 ) {
		return (
			<p>
				{ __(
					'You do not have permission to view this page.',
					'beyond-elysium'
				) }
			</p>
		);
	}

	return (
		<div className="be-admin-hub">
			<TabStrip tabs={ tabs } active={ tab } onChange={ setTab } />
			{ tab === TABS.plots && <AdminPlots /> }
			{ tab === TABS.releases && <AdminReleaseBatches /> }
		</div>
	);
}

export default PlotsHub;
