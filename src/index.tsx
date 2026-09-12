/**
 * Beyond Elysium — React widget hydration router and plugin entry
 * point. Scans the DOM for elements with [data-be-widget] and
 * hydrates the corresponding lazy-loaded React component into
 * each mount point.
 *
 * Elementor widgets render:
 *   <div data-be-widget="character-sheet" data-be-config='{"characterId":42}'></div>
 */

import { createRoot } from '@wordpress/element';
import ErrorBoundary from './components/ErrorBoundary';
import PoweredByFooter from './components/shared/PoweredByFooter';

/**
 * Maps each data-be-widget attribute value to a lazy loader for
 * its React component. Front-end entries back Elementor widgets;
 * the admin- prefixed entries back the equivalent wp-admin pages
 * using the same hydration mechanism.
 */
const widgetRegistry: Record<string, () => Promise<{ default: React.ComponentType<any> }>> = {
	'character-sheet': () => import( './components/character/CharacterSheet' ),
	'character-list': () => import( './components/character/CharacterList' ),
	'character-editor': () => import( './components/character/CharacterEditor' ),
	'approval-queue': () => import( './components/changes/ApprovalQueue' ),
	'plot-manager': () => import( './components/apr/PlotManager' ),
	'my-plots': () => import( './components/apr/MyPlotsFeed' ),
	'query-tool': () => import( './components/query/QueryTool' ),
	'world-objects': () => import( './components/world/WorldObjectManager' ),
	'boon-ledger': () => import( './components/world/BoonLedger' ),
	'import-tool': () => import( './components/import/ImportTool' ),
	'game-dashboard': () => import( './components/game/GameDashboard' ),

	// wp-admin pages, mounted the same way as a front-end Elementor widget.
	'admin-games': () => import( './components/admin/AdminGames' ),
	'admin-characters': () => import( './components/admin/AdminCharacters' ),
	'admin-npc-roster': () => import( './components/admin/AdminNpcRoster' ),
	'admin-schema-blocks': () => import( './components/admin/AdminSchemaBlocks' ),
	'admin-creature-stacks': () => import( './components/admin/AdminCreatureStacks' ),
	'admin-templates': () => import( './components/admin/AdminTemplates' ),
	'admin-plots': () => import( './components/admin/AdminPlots' ),
	'admin-world-objects': () => import( './components/admin/AdminWorldObjects' ),
	'admin-query': () => import( './components/admin/AdminQuery' ),
	'admin-import': () => import( './components/admin/AdminImport' ),
	'admin-chronicle-access': () => import( './components/admin/AdminChronicleAccess' ),
	'admin-docs': () => import( './components/admin/AdminDocs' ),
	'admin-approval-rules': () => import( './components/admin/AdminApprovalRules' ),
	'admin-apr-settings': () => import( './components/admin/AdminAprSettings' ),
};

/**
 * Parses the JSON config from a mount point's data-be-config
 * attribute, filling in characterId from the ?character_id= URL
 * query var when the config does not already carry a non-zero
 * value, and letting a game_slug URL param override the config's
 * own gameSlug when present.
 */
function parseConfig( el: HTMLElement ): Record<string, unknown> {
	const raw = el.getAttribute( 'data-be-config' );
	let config: Record<string, unknown> = {};

	if ( raw ) {
		try {
			config = JSON.parse( raw );
		} catch {
			console.error( '[BE] Invalid JSON in data-be-config:', raw );
			config = {};
		}
	}

	const params = new URLSearchParams( window.location.search );

	if ( ! config.characterId ) {
		const fromUrl = params.get( 'character_id' );
		if ( fromUrl ) {
			const parsed = parseInt( fromUrl, 10 );
			if ( Number.isFinite( parsed ) && parsed > 0 ) {
				config.characterId = parsed;
			}
		}
	}

	// The URL's game_slug always wins over the stored config's own gameSlug when present.
	const gameSlugFromUrl = params.get( 'game_slug' );
	if ( gameSlugFromUrl ) {
		config.gameSlug = gameSlugFromUrl;
	}

	return config;
}

/**
 * Hydrates every Beyond Elysium widget mount point currently on
 * the page: finds each [data-be-widget] element, loads its
 * component, parses its config, and mounts it inside an error
 * boundary.
 */
async function hydrateWidgets(): Promise<void> {
	const mountPoints = document.querySelectorAll<HTMLElement>( '[data-be-widget]' );

	for ( const el of mountPoints ) {
		const widgetName = el.getAttribute( 'data-be-widget' );
		if ( ! widgetName ) {
			continue;
		}

		const loader = widgetRegistry[ widgetName ];
		if ( ! loader ) {
			console.warn( `[BE] Unknown widget: "${ widgetName }"` );
			continue;
		}

		try {
			const { default: Component } = await loader();
			const config = parseConfig( el );
			const root = createRoot( el );
			// Admin pages already carry their own PHP-rendered memorial footer
			// (Admin_Menu::render_mount()) - this one is for front-end widgets only.
			const isAdminWidget = widgetName.startsWith( 'admin-' );
			root.render(
				<ErrorBoundary label={ widgetName }>
					<Component { ...config } />
					{ ! isAdminWidget && <PoweredByFooter /> }
				</ErrorBoundary>
			);
		} catch ( err ) {
			console.error( `[BE] Failed to hydrate widget "${ widgetName }":`, err );
		}
	}
}

// Run on DOMContentLoaded or immediately if already loaded.
if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', hydrateWidgets );
} else {
	hydrateWidgets();
}
