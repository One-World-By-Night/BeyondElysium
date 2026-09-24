/**
 * One tabbed hub for Chronicle Setup, Chronicle Access, and Action & Rumor Settings: all three configure one
 * chronicle.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { TabStrip } from '../../shared/TabStrip';
import { readTabFromUrl, writeTabToUrl } from '../../../lib/pluginPages';
import { chronicleSetupTabKeys } from '../../../lib/hubTabs';
import AdminChronicleSetup from '../AdminChronicleSetup';
import AdminChronicleAccess from '../AdminChronicleAccess';
import AdminAprSettings from '../AdminAprSettings';
import AdminAiAssistChronicle from '../AdminAiAssistChronicle';
import type { Tab } from '../../shared/TabStrip';

const TABS = {
	setup: 'setup',
	access: 'access',
	apr: 'apr',
	aiAssist: 'ai-assist',
};

/**
 * What each tab is called.
 */
function tabLabels(): Record< string, string > {
	return {
		[ TABS.setup ]: __( 'Chronicle Setup', 'beyond-elysium' ),
		[ TABS.access ]: __( 'Chronicle Access', 'beyond-elysium' ),
		[ TABS.apr ]: __( 'Action & Rumor Settings', 'beyond-elysium' ),
		[ TABS.aiAssist ]: __( 'AI Assist', 'beyond-elysium' ),
	};
}

export function ChronicleSetupHub() {
	const [ tab, setTab ] = useState( () => readTabFromUrl( TABS.setup ) );

	useEffect( () => {
		writeTabToUrl( tab );
	}, [ tab ] );

	const labels = tabLabels();
	const tabs: Tab[] = chronicleSetupTabKeys(
		window.beyondElysium?.capabilities
	).map( ( key ) => ( { key, label: labels[ key ] } ) );

	useEffect( () => {
		if ( tabs.length > 0 && ! tabs.some( ( t ) => t.key === tab ) ) {
			setTab( tabs[ 0 ].key );
		}
		// eslint-disable-next-line react-hooks/exhaustive-deps
	}, [ tabs.map( ( t ) => t.key ).join( ',' ) ] );

	// WordPress already keeps a viewer with none of these off the page; this is the second lock.
	if ( tabs.length === 0 ) {
		return null;
	}

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
