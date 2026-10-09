/**
 * Type definitions for Storyteller-authored secrets and their reveals.
 */
import type { AudienceRules, AudienceValue } from './plot';

/**
 * What a secret may attach to.
 */
export type SecretEntityType =
	'plot' | 'item' | 'location' | 'character' | 'npc';

/**
 * How a character came to learn a secret.
 */
export type RevealHow = 'game' | 'downtime' | 'rumor' | 'told' | 'other';

/**
 * A chronicle's own choice for whether players may log and pass secrets.
 */
export type SecretPassingMode = 'off' | 'approval' | 'immediate';

/**
 * A single secret - a title/content pair with its own real, Audience-shaped visibility.
 */
export interface Secret {
	id: number;
	game_id: number;
	entity_type: SecretEntityType | null;
	entity_id: number | null;
	title: string;
	content: string | null;
	audience: AudienceValue;
	audience_rules: AudienceRules | null;
	created_by: number;
	created_at: string;
	updated_at: string;
}

/**
 * Request body for creating a secret.
 */
export interface CreateSecretRequest {
	entity_type?: SecretEntityType;
	entity_id?: number;
	title: string;
	content?: string;
	audience?: AudienceValue;
	audience_rules?: AudienceRules | null;
}

/**
 * Request body for updating a secret.
 */
export interface UpdateSecretRequest {
	title?: string;
	content?: string;
	audience?: AudienceValue;
	audience_rules?: AudienceRules | null;
}

/**
 * One character learning one secret.
 */
export interface SecretReveal {
	id: number;
	secret_id: number;
	character_id: number;
	how: RevealHow;
	note: string | null;
	held: boolean;
	release_batch_id: number | null;
	revealed_by: number;
	from_character_id?: number | null;
	approved?: boolean;
	created_at: string;
}

/**
 * Request body for revealing a secret to a character.
 */
export interface CreateSecretRevealRequest {
	character_id: number;
	how?: RevealHow;
	note?: string;
	held?: boolean;
	release_batch_id?: number;
}

/**
 * One row of "What I Know" (`GET /my/secrets`) - a secret one of the caller's own characters has actually learned.
 */
export interface MySecretRow {
	id: number;
	reveal_id: number;
	character_id: number;
	character_name: string;
	title: string;
	content: string | null;
	entity_type: SecretEntityType | null;
	entity_name: string | null;
	how: RevealHow;
	told_by: string | null;
	approved: boolean;
	can_pass: boolean;
	learned_at: string;
}

/**
 * One of the caller's own waiting (or refused) logged-knowledge or secret-pass claims.
 */
export interface MyWaitingKnowledgeRow {
	id: number;
	change_type: 'log_knowledge' | 'pass_secret';
	status: 'pending' | 'rejected';
	character_id: number;
	character_name: string;
	title: string;
	review_notes: string | null;
	submitted_at: string;
}

/**
 * The full `GET /my/secrets` response.
 */
export interface MySecretsResponse {
	known: MySecretRow[];
	waiting: MyWaitingKnowledgeRow[];
}

/**
 * Request body for `POST /my/secrets/log`.
 */
export interface LogKnowledgeRequest {
	character_id: number;
	title: string;
	details: string;
	how: RevealHow;
	teller_character_id?: number;
	teller_name?: string;
}

/**
 * Request body for `POST /secrets/{id}/pass`.
 */
export interface PassSecretRequest {
	from_character_id: number;
	to_character_id: number;
	note?: string;
}

/**
 * One character a player may name as the person who told them something - `GET /my/secrets/people`.
 */
export interface SecretPerson {
	id: number;
	name: string;
	kind: 'pc' | 'npc';
}
