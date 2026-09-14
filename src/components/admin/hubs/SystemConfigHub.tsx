/**
 * admin-menu-consolidation-design.md: replaces the separate "Games," "Schema
 * Blocks," "Creature Stacks," "Templates," and "Approval Rules" wp-admin
 * pages with one tabbed hub - all five are global, cross-chronicle admin
 * rather than "configure this one chronicle" (that's ChronicleSetupHub).
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import { TabStrip } from '../../shared/TabStrip';
import { readTabFromUrl, writeTabToUrl } from '../../../lib/pluginPages';
import AdminGames from '../AdminGames';
import AdminSchemaBlocks from '../AdminSchemaBlocks';
import AdminCreatureStacks from '../AdminCreatureStacks';
import AdminTemplates from '../AdminTemplates';
import AdminApprovalRules from '../AdminApprovalRules';
import AdminAiAssistSite from '../AdminAiAssistSite';
import type { Tab } from '../../shared/TabStrip';

const TABS = { games: 'games', schemaBlocks: 'schema-blocks', creatureStacks: 'creature-stacks', templates: 'templates', approvalRules: 'approval-rules', aiAssist: 'ai-assist' };

export function SystemConfigHub() {
	const [ tab, setTab ] = useState( () => readTabFromUrl( TABS.games ) );

	useEffect( () => {
		writeTabToUrl( tab );
	}, [ tab ] );

	const capabilities = window.beyondElysium?.capabilities;
	const tabs: Tab[] = [
		capabilities?.be_manage_games && { key: TABS.games, label: __( 'Games', 'beyond-elysium' ) },
		capabilities?.be_manage_schemas && { key: TABS.schemaBlocks, label: __( 'Schema Blocks', 'beyond-elysium' ) },
		capabilities?.be_manage_schemas && { key: TABS.creatureStacks, label: __( 'Creature Stacks', 'beyond-elysium' ) },
		capabilities?.be_manage_templates && { key: TABS.templates, label: __( 'Templates', 'beyond-elysium' ) },
		capabilities?.be_manage_approval_rules && { key: TABS.approvalRules, label: __( 'Approval Rules', 'beyond-elysium' ) },
		capabilities?.be_manage_games && { key: TABS.aiAssist, label: __( 'AI Assist', 'beyond-elysium' ) },
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
			{ tab === TABS.games && <AdminGames /> }
			{ tab === TABS.schemaBlocks && <AdminSchemaBlocks /> }
			{ tab === TABS.creatureStacks && <AdminCreatureStacks /> }
			{ tab === TABS.templates && <AdminTemplates /> }
			{ tab === TABS.approvalRules && <AdminApprovalRules /> }
			{ tab === TABS.aiAssist && <AdminAiAssistSite /> }
		</div>
	);
}

export default SystemConfigHub;
