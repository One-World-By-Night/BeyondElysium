/**
 * One tabbed hub for Games, Schema Blocks, Creature Stacks, Templates and Approval Rules: all global, cross-chronicle
 * admin.
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
import AdminSecurePrinting from '../AdminSecurePrinting';
import AdminTranslations from '../AdminTranslations';
import AdminBranding from '../AdminBranding';
import type { Tab } from '../../shared/TabStrip';

const TABS = {
	games: 'games',
	schemaBlocks: 'schema-blocks',
	creatureStacks: 'creature-stacks',
	templates: 'templates',
	approvalRules: 'approval-rules',
	aiAssist: 'ai-assist',
	securePrinting: 'secure-printing',
	translations: 'translations',
	branding: 'branding',
};

export function SystemConfigHub() {
	const [ tab, setTab ] = useState( () => readTabFromUrl( TABS.games ) );

	useEffect( () => {
		writeTabToUrl( tab );
	}, [ tab ] );

	const capabilities = window.beyondElysium?.capabilities;
	const tabs: Tab[] = [
		capabilities?.be_manage_games && {
			key: TABS.games,
			label: __( 'Games', 'beyond-elysium' ),
		},
		capabilities?.be_manage_schemas && {
			key: TABS.schemaBlocks,
			label: __( 'Schema Blocks', 'beyond-elysium' ),
		},
		// Creature stacks are shared by every chronicle and have no per-chronicle copy.
		capabilities?.be_manage_games && {
			key: TABS.creatureStacks,
			label: __( 'Creature Stacks', 'beyond-elysium' ),
		},
		capabilities?.be_manage_templates && {
			key: TABS.templates,
			label: __( 'Templates', 'beyond-elysium' ),
		},
		capabilities?.be_manage_approval_rules && {
			key: TABS.approvalRules,
			label: __( 'Approval Rules', 'beyond-elysium' ),
		},
		capabilities?.be_manage_games && {
			key: TABS.aiAssist,
			label: __( 'AI Assist', 'beyond-elysium' ),
		},
		capabilities?.be_manage_games && {
			key: TABS.securePrinting,
			label: __( 'Secure Printing', 'beyond-elysium' ),
		},
		capabilities?.be_manage_translations && {
			key: TABS.translations,
			label: __( 'Translations', 'beyond-elysium' ),
		},
		capabilities?.be_manage_games && {
			key: TABS.branding,
			label: __( 'Branding', 'beyond-elysium' ),
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
			{ tab === TABS.games && <AdminGames /> }
			{ tab === TABS.schemaBlocks && <AdminSchemaBlocks /> }
			{ tab === TABS.creatureStacks && <AdminCreatureStacks /> }
			{ tab === TABS.templates && <AdminTemplates /> }
			{ tab === TABS.approvalRules && <AdminApprovalRules /> }
			{ tab === TABS.aiAssist && <AdminAiAssistSite /> }
			{ tab === TABS.securePrinting && <AdminSecurePrinting /> }
			{ tab === TABS.translations && <AdminTranslations /> }
			{ tab === TABS.branding && <AdminBranding /> }
		</div>
	);
}

export default SystemConfigHub;
