/**
 * admin-menu-consolidation-design.md: replaces the separate "Chronicle
 * Setup," "Chronicle Access," and "Action & Rumor Settings" wp-admin pages
 * with one tabbed hub - all three are "configure this one chronicle."
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { TabStrip } from '../../shared/TabStrip';
import { readTabFromUrl, writeTabToUrl } from '../../../lib/pluginPages';
import AdminChronicleSetup from '../AdminChronicleSetup';
import AdminChronicleAccess from '../AdminChronicleAccess';
import AdminAprSettings from '../AdminAprSettings';
import AdminAiAssistChronicle from '../AdminAiAssistChronicle';
import type { Tab } from '../../shared/TabStrip';

const TABS = { setup: 'setup', access: 'access', apr: 'apr', aiAssist: 'ai-assist' };

export function ChronicleSetupHub() {
	const [ tab, setTab ] = useState( () => readTabFromUrl( TABS.setup ) );

	useEffect( () => {
		writeTabToUrl( tab );
	}, [ tab ] );

	const capabilities = window.beyondElysium?.capabilities;
	const tabs: Tab[] = [
		{ key: TABS.setup, label: __( 'Chronicle Setup', 'beyond-elysium' ) },
		capabilities?.be_manage_games && { key: TABS.access, label: __( 'Chronicle Access', 'beyond-elysium' ) },
		capabilities?.be_manage_apr && { key: TABS.apr, label: __( 'Action & Rumor Settings', 'beyond-elysium' ) },
		capabilities?.be_manage_apr && { key: TABS.aiAssist, label: __( 'AI Assist', 'beyond-elysium' ) },
	].filter( Boolean ) as Tab[];

	useEffect( () => {
		if ( tabs.length > 0 && ! tabs.some( ( t ) => t.key === tab ) ) {
			setTab( tabs[ 0 ].key );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ tabs.map( ( t ) => t.key ).join( ',' ) ] );

	return (
		<div className="be-admin-hub">
			<TabStrip tabs={ tabs } active={ tab } onChange={ setTab } />
			{ tab === TABS.setup && <AdminChronicleSetup /> }
			{ tab === TABS.access && <AdminChronicleAccess /> }
			{ tab === TABS.apr && <AdminAprSettings /> }
			{ tab === TABS.aiAssist && <AdminAiAssistChronicle /> }
		</div>
	);
}

export default ChronicleSetupHub;
