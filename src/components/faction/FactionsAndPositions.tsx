/**
 * The Storyteller Toolkit's combined Factions tab (1.1.0 §3.10, F1/F2): factions and their
 * positions share one tab with an inner sub-strip, the same way court offices are scoped to
 * a faction in the data model itself - `PositionManager` needs the current faction list for
 * its own faction-scope picker, so it is loaded once here rather than twice.
 */
import { useEffect, useState } from '@wordpress/element';
import { __ } from '@wordpress/i18n';
import api from '../../api/client';
import { TabStrip } from '../shared/TabStrip';
import { FactionManager } from './FactionManager';
import { PositionManager } from './PositionManager';
import type { Faction } from '../../types/faction';
import type { Tab } from '../shared/TabStrip';

export interface FactionsAndPositionsProps {
	gameSlug: string;
}

const SUB_TABS = {
	factions: 'factions',
	positions: 'positions',
} as const;

export function FactionsAndPositions( {
	gameSlug,
}: FactionsAndPositionsProps ) {
	const [ subTab, setSubTab ] = useState< string >( SUB_TABS.factions );
	const [ factions, setFactions ] = useState< Faction[] >( [] );

	useEffect( () => {
		api.factions( gameSlug )
			.list()
			.then( setFactions )
			.catch( () => setFactions( [] ) );
	}, [ gameSlug, subTab ] );

	const tabs: Tab[] = [
		{ key: SUB_TABS.factions, label: __( 'Factions', 'beyond-elysium' ) },
		{ key: SUB_TABS.positions, label: __( 'Positions', 'beyond-elysium' ) },
	];

	return (
		<div className="be-factions-and-positions">
			<TabStrip tabs={ tabs } active={ subTab } onChange={ setSubTab } />
			{ subTab === SUB_TABS.factions && (
				<FactionManager gameSlug={ gameSlug } />
			) }
			{ subTab === SUB_TABS.positions && (
				<PositionManager gameSlug={ gameSlug } factions={ factions } />
			) }
		</div>
	);
}

export default FactionsAndPositions;
