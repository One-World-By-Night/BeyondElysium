/**
 * Landing page for the top-level "Beyond Elysium" wp-admin click
 * (admin-menu-consolidation-design.md). Previously this click landed
 * directly on the Games page with no real dashboard content at all - this
 * is the first real content there: an about/what's-where reference, the
 * widgets & shortcodes inventory, and a dynamic call-to-action that's
 * prominent only while the only chronicle present is the seeded demo one.
 */
import {
	createInterpolateElement,
	useEffect,
	useState,
} from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import { storytellerTabUrl, STORYTELLER_TABS } from '../../lib/pluginPages';
import type { Game } from '../../types';
import HelpButton from '../shared/HelpButton';
import './Admin.css';
import './AdminDashboard.css';

const ELEMENTOR_WIDGETS = [
	'Character Sheet',
	'Character List',
	'Character Editor',
	'Approval Queue',
	'Plot Manager',
	'My Plots',
	'Query Tool',
	'World Objects',
	'Boon Ledger',
	'Import Tool',
	'Game Dashboard',
	'House Rules',
];

export function AdminDashboard() {
	const [ games, setGames ] = useState< Game[] | null >( null );

	useEffect( () => {
		api.games
			.list()
			.then( setGames )
			.catch( () => setGames( [] ) );
	}, [] );

	const onlyDemoExists =
		games !== null && games.every( ( g ) => g.slug === 'be-demo' );

	return (
		<div className="be-admin be-admin-dashboard">
			<div className="be-help-heading">
				<h1>{ __( 'Beyond Elysium', 'beyond-elysium' ) }</h1>
				<HelpButton helpKey="admin-dashboard" />
			</div>
			<p>
				{ __(
					"Character management for Mind's Eye Theatre LARP chronicles - Grapevine 3.01, ported for One World by Night.",
					'beyond-elysium'
				) }
			</p>

			{ games !== null && onlyDemoExists && (
				<div className="be-admin-dashboard__cta">
					<h2>
						{ __(
							'Create your first chronicle',
							'beyond-elysium'
						) }
					</h2>
					<p>
						{ __(
							'Only the demo chronicle exists so far. Everything else in this menu is scoped to a real chronicle - start here.',
							'beyond-elysium'
						) }
					</p>
					<a
						className="button button-primary"
						href="admin.php?page=beyond-elysium-system-config"
					>
						{ __(
							'Go to System Config → Games',
							'beyond-elysium'
						) }
					</a>
				</div>
			) }

			<h2>{ __( "What's where", 'beyond-elysium' ) }</h2>
			<table className="widefat">
				<tbody>
					<tr>
						<td>
							<strong>
								{ __( 'Characters', 'beyond-elysium' ) }
							</strong>
						</td>
						<td>
							{ __(
								'The full roster - player characters and NPCs, one page, one toggle.',
								'beyond-elysium'
							) }
						</td>
					</tr>
					<tr>
						<td>
							<strong>{ __( 'Plots', 'beyond-elysium' ) }</strong>
						</td>
						<td>
							{ __(
								'The Storyteller Toolkit: plots, actions, and rumors.',
								'beyond-elysium'
							) }
						</td>
					</tr>
					<tr>
						<td>
							<strong>
								{ __( 'Items & Locations', 'beyond-elysium' ) }
							</strong>
						</td>
						<td>
							{ __(
								'The world-object catalog - items, locations, rotes, and boons.',
								'beyond-elysium'
							) }
						</td>
					</tr>
					<tr>
						<td>
							<strong>
								{ __( 'Query Tool', 'beyond-elysium' ) }
							</strong>
						</td>
						<td>
							{ __(
								'Ad-hoc roster queries, and the full Grapevine report set as signed PDFs.',
								'beyond-elysium'
							) }
						</td>
					</tr>
					<tr>
						<td>
							<strong>
								{ __( 'Import', 'beyond-elysium' ) }
							</strong>
						</td>
						<td>
							{ __(
								'Bring characters or a whole chronicle in from Grapevine.',
								'beyond-elysium'
							) }
						</td>
					</tr>
					<tr>
						<td>
							<strong>
								{ __( 'Chronicle Setup', 'beyond-elysium' ) }
							</strong>
						</td>
						<td>
							{ __(
								'Configure one chronicle: the setup checklist, membership/access, and downtime & rumor settings.',
								'beyond-elysium'
							) }
						</td>
					</tr>
					<tr>
						<td>
							<strong>
								{ __( 'System Config', 'beyond-elysium' ) }
							</strong>
						</td>
						<td>
							{ __(
								'Global, cross-chronicle admin: chronicles themselves, the shared catalog, sheet templates, and approval rules.',
								'beyond-elysium'
							) }
						</td>
					</tr>
					<tr>
						<td>
							<strong>{ __( 'Docs', 'beyond-elysium' ) }</strong>
						</td>
						<td>
							{ __(
								'The Storyteller, Admin, and Player guides, and the REST API reference.',
								'beyond-elysium'
							) }
						</td>
					</tr>
				</tbody>
			</table>

			<h2>
				{ __(
					'Player- and Storyteller-facing pages',
					'beyond-elysium'
				) }
			</h2>
			<p>
				{ __(
					'Not everything lives in wp-admin - these are chronicle-scoped front-end pages, provisioned automatically for every chronicle:',
					'beyond-elysium'
				) }
			</p>
			<table className="widefat">
				<tbody>
					<tr>
						<td>
							<strong>
								{ __( 'Game Dashboard', 'beyond-elysium' ) }
							</strong>
						</td>
						<td>
							{ createInterpolateElement(
								__(
									'Roster stats, roster health, and upcoming plots. The Dashboard tab on both front-end pages: <toolkit>Storyteller Toolkit</toolkit> (staff) and My Chronicle (players, their own stats only).',
									'beyond-elysium'
								),
								{
									toolkit: (
										// eslint-disable-next-line jsx-a11y/anchor-has-content -- the translated words fill it
										<a
											href={ storytellerTabUrl(
												STORYTELLER_TABS.dashboard
											) }
										/>
									),
								}
							) }
						</td>
					</tr>
					<tr>
						<td>
							<strong>
								{ __( 'Notifications', 'beyond-elysium' ) }
							</strong>
						</td>
						<td>
							{ __(
								'Per-chronicle on/off switch: Chronicle Setup - Chronicle Access. Each player can opt out individually on their own WordPress Profile page.',
								'beyond-elysium'
							) }
						</td>
					</tr>
				</tbody>
			</table>

			<div className="be-help-heading">
				<h2>{ __( 'Widgets & shortcodes', 'beyond-elysium' ) }</h2>
				<HelpButton helpKey="elementor-widgets" />
			</div>
			<p>
				{ __(
					'Every one of these can be dropped onto a front-end page in Elementor:',
					'beyond-elysium'
				) }
			</p>
			<ul className="be-admin-dashboard__widget-list">
				{ ELEMENTOR_WIDGETS.map( ( w ) => (
					<li key={ w }>{ w }</li>
				) ) }
			</ul>
			<p>
				{ __(
					'One shortcode exists today, for a live, always-current house rules page:',
					'beyond-elysium'
				) }{ ' ' }
				<code>{ '[be_house_rules game="chronicle-slug"]' }</code>
			</p>
		</div>
	);
}

export default AdminDashboard;
