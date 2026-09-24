/**
 * Which tabs of the Chronicle Setup hub a viewer gets, decided from the capability map the bundle
 * is handed (`window.beyondElysium.capabilities`). Kept as one pure function so the rule can be
 * tested without mounting the hub: the first tab used to be shown to everyone, and that was the
 * whole of the bug (1.3.2.2).
 */

export type ChronicleSetupTabKey = 'setup' | 'access' | 'apr' | 'ai-assist';

/**
 * Every tab needs the capability its own save route needs, and only an explicit `true` counts.
 * A viewer with none of them gets no tab, and the hub renders nothing.
 */
export function chronicleSetupTabKeys(
	capabilities: Record< string, boolean > | undefined
): ChronicleSetupTabKey[] {
	const can = ( capability: string ) => capabilities?.[ capability ] === true;
	const keys: ChronicleSetupTabKey[] = [];
	if ( can( 'be_manage_chronicle_setup' ) ) {
		keys.push( 'setup' );
	}
	if ( can( 'be_manage_games' ) ) {
		keys.push( 'access' );
	}
	if ( can( 'be_manage_apr' ) ) {
		keys.push( 'apr', 'ai-assist' );
	}
	return keys;
}
