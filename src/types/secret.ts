/**
 * Type definitions for Storyteller-authored secrets and their reveals.
 */
import type { AudienceRules, AudienceValue } from './plot';

/**
 * What a secret may attach to.
 */
export type SecretEntityType = 'plot' | 'item' | 'location' | 'npc';

/**
 * How a character came to learn a secret.
 */
export type RevealHow = 'game' | 'downtime' | 'rumor' | 'other';

/**
 * A single secret - a title/content pair with its own real, Audience-shaped visibility.
 */
export interface Secret {
	id: number;
	game_id: number;
	entity_type: SecretEntityType;
	entity_id: number;
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
	entity_type: SecretEntityType;
	entity_id: number;
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
 * One row of "What I Know" (`GET /my/secrets`).
 */
export interface MySecretRow {
	id: number;
	title: string;
	content: string | null;
	entity_type: SecretEntityType;
	entity_name: string | null;
	how: RevealHow;
	learned_at: string;
}
