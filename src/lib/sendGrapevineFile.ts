/**
 * Splits the Send a Grapevine File form's chronicle picker into "Your chronicles" (the
 * sender's own real memberships) and "Other chronicles" (every other chronicle on the site,
 * F-122) - a plain function so the no-repeats rule can be tested without mounting the form.
 */
import type { MyGame, Game } from '../types';

export function otherChroniclesFor(
	myChronicles: MyGame[],
	allChronicles: Game[]
): Game[] {
	const mySlugs = new Set( myChronicles.map( ( g ) => g.slug ) );
	return allChronicles.filter( ( g ) => ! mySlugs.has( g.slug ) );
}
