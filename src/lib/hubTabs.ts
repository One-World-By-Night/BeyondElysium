/**
 * Which tabs of the Chronicle Setup hub a viewer gets, decided from the capability map the bundle is handed
 * (`window.beyondElysium.capabilities`).
 */

export type ChronicleSetupTabKey = 'setup' | 'access' | 'apr' | 'ai-assist';

/**
 * Every tab needs the capability its own save route needs, and only an explicit `true` counts.
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
