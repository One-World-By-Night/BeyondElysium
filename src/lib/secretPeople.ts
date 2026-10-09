/**
 * Matching what a player typed in "From whom" against the people they may name.
 */
import type { SecretPerson } from '../types/secret';

function tidy( text: string ): string {
	return text.replace( /\s+/g, ' ' ).trim();
}

/**
 * The people a character may name as the one who told it something: everyone but itself.
 */
export function peopleFor(
	people: SecretPerson[],
	learnerId: number | string | null
): SecretPerson[] {
	const id = Number( learnerId );
	return learnerId === null || learnerId === '' || Number.isNaN( id )
		? people
		: people.filter( ( person ) => person.id !== id );
}

/**
 * The one person whose name equals the typed text, ignoring case and extra spaces. Nobody when the text is empty,
 * matches no one or matches more than one.
 */
export function matchPerson(
	typed: string,
	people: SecretPerson[]
): SecretPerson | null {
	const wanted = tidy( typed ).toLowerCase();
	if ( wanted === '' ) {
		return null;
	}
	const matches = people.filter(
		( person ) => tidy( person.name ).toLowerCase() === wanted
	);
	return matches.length === 1 ? matches[ 0 ] : null;
}

/**
 * The teller fields of a "log what I learned" request: the chosen person, else a typed name that is a listed person,
 * else the typed name as plain text, else nothing.
 */
export function tellerFields(
	chosenId: number | '',
	typed: string,
	people: SecretPerson[]
): { teller_character_id?: number; teller_name?: string } {
	if ( chosenId !== '' && people.some( ( p ) => p.id === chosenId ) ) {
		return { teller_character_id: chosenId };
	}
	const name = tidy( typed );
	if ( name === '' ) {
		return {};
	}
	const person = matchPerson( name, people );
	return person ? { teller_character_id: person.id } : { teller_name: name };
}
