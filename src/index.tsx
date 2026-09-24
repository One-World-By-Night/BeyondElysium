/**
 * Beyond Elysium — React widget hydration router and plugin entry point.
 */

import { createRoot } from '@wordpress/element';
import ErrorBoundary from './components/ErrorBoundary';
import PoweredByFooter from './components/shared/PoweredByFooter';
import './styles/theme.css';
import './styles/breakpoints.css';

/**
 * Maps each data-be-widget attribute value to a lazy loader for its React component.
 */
const widgetRegistry: Record<
	string,
	() => Promise< { default: React.ComponentType< any > } >
> = {
	'character-sheet': () => import( './components/character/CharacterSheet' ),
	'character-list': () => import( './components/character/CharacterList' ),
	'character-editor': () =>
		import( './components/character/CharacterEditor' ),
	'approval-queue': () => import( './components/changes/ApprovalQueue' ),
	'plot-manager': () => import( './components/apr/PlotManager' ),
	'my-plots': () => import( './components/apr/MyPlotsFeed' ),
	'query-tool': () => import( './components/query/QueryTool' ),
	'world-objects': () => import( './components/world/WorldObjectManager' ),
	'boon-ledger': () => import( './components/world/BoonLedger' ),
	'import-tool': () => import( './components/import/ImportTool' ),
	'game-dashboard': () => import( './components/game/GameDashboard' ),
	'verify-character': () =>
		import( './components/character/VerifyCharacter' ),
	'house-rules': () => import( './components/game/HouseRules' ),
	'my-chronicle': () => import( './components/pages/MyChroniclePage' ),
	'storyteller-toolkit-page': () =>
		import( './components/pages/StorytellerToolkitPage' ),

	'admin-dashboard': () => import( './components/admin/AdminDashboard' ),
	'admin-games': () => import( './components/admin/AdminGames' ),
	'admin-characters': () => import( './components/admin/AdminCharacters' ),
	'admin-schema-blocks': () =>
		import( './components/admin/AdminSchemaBlocks' ),
	'admin-creature-stacks': () =>
		import( './components/admin/AdminCreatureStacks' ),
	'admin-templates': () => import( './components/admin/AdminTemplates' ),
	'admin-plots': () => import( './components/admin/hubs/PlotsHub' ),
	'admin-world-objects': () =>
		import( './components/admin/AdminWorldObjects' ),
	'admin-game-nights': () => import( './components/admin/AdminGameNights' ),
	'admin-query': () => import( './components/admin/AdminQuery' ),
	'admin-import': () => import( './components/admin/AdminImport' ),
	'admin-chronicle-access': () =>
		import( './components/admin/AdminChronicleAccess' ),
	'admin-docs': () => import( './components/admin/AdminDocs' ),
	'admin-approval-rules': () =>
		import( './components/admin/AdminApprovalRules' ),
	'admin-apr-settings': () => import( './components/admin/AdminAprSettings' ),
	'admin-reports': () => import( './components/admin/AdminReports' ),
	'admin-chronicle-setup': () =>
		import( './components/admin/AdminChronicleSetup' ),
	'admin-query-hub': () => import( './components/admin/hubs/QueryHub' ),
	'admin-chronicle-setup-hub': () =>
		import( './components/admin/hubs/ChronicleSetupHub' ),
	'admin-system-config-hub': () =>
		import( './components/admin/hubs/SystemConfigHub' ),
};

function parseConfig( el: HTMLElement ): Record< string, unknown > {
	const raw = el.getAttribute( 'data-be-config' );
	let config: Record< string, unknown > = {};

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
 * Hydrates every Beyond Elysium widget mount point currently on the page: finds each [data-be-widget] element, loads
 * its component, parses its config, and mounts it inside an error boundary.
 */
async function hydrateWidgets(): Promise< void > {
	// Zero-risk detection, never injection.
	if ( ! document.querySelector( 'meta[name="viewport"]' ) ) {
		console.warn(
			'[BE] No <meta name="viewport"> found on this page - phone-width layout will not apply correctly.'
		);
	}

	const mountPoints =
		document.querySelectorAll< HTMLElement >( '[data-be-widget]' );

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
			el.classList.add( 'wp-dark-mode-ignore' );
			const root = createRoot( el );
			// Admin pages already carry their own PHP-rendered memorial footer (Admin_Menu::render_mount()).
			const isAdminWidget = widgetName.startsWith( 'admin-' );
			root.render(
				<ErrorBoundary label={ widgetName }>
					<Component { ...config } />
					{ ! isAdminWidget && <PoweredByFooter /> }
				</ErrorBoundary>
			);
		} catch ( err ) {
			console.error(
				`[BE] Failed to hydrate widget "${ widgetName }":`,
				err
			);
		}
	}
}

// Run on DOMContentLoaded or immediately if already loaded.
if ( document.readyState === 'loading' ) {
	document.addEventListener( 'DOMContentLoaded', hydrateWidgets );
} else {
	hydrateWidgets();
}
