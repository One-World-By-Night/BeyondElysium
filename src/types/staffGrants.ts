/**
 * Type definitions for staff permissions: the areas an HST gives an AST or a Narrator beyond the role's own bundle.
 */

/**
 * The five capabilities a grant can carry.
 */
export type StaffGrantArea =
	| 'be_manage_schemas'
	| 'be_manage_approval_rules'
	| 'be_manage_templates'
	| 'be_manage_chronicle_setup'
	| 'be_delete_characters';

/**
 * One area a person holds, with who gave it and when.
 */
export interface StaffGrantEntry {
	area: StaffGrantArea;
	by: number;
	by_name: string | null;
	at: string;
}

/**
 * A person holding at least one area, with the role they hold in the chronicle now.
 */
export interface StaffGrantPerson {
	wp_user_id: number;
	display_name: string;
	role: string | null;
	/**
	 * Whether they are an AST or a Narrator here now, which is when a grant does anything.
	 */
	eligible: boolean;
	areas: StaffGrantEntry[];
}

/**
 * One grant or removal in the chronicle's log.
 */
export interface StaffGrantLogEntry {
	action: 'granted' | 'revoked';
	area: StaffGrantArea;
	wp_user_id: number;
	user_name: string | null;
	by: number;
	by_name: string | null;
	at: string;
}

/**
 * `GET /{game_slug}/staff-grants`.
 */
export interface StaffGrantsResponse {
	areas: StaffGrantArea[];
	people: StaffGrantPerson[];
	log: StaffGrantLogEntry[];
}

/**
 * `PUT /{game_slug}/staff-grants/{wp_user_id}`.
 */
export interface StaffGrantsSaved {
	person: StaffGrantPerson;
	log: StaffGrantLogEntry[];
}
