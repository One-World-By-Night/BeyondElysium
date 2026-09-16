/**
 * Type definitions for a player-sent Grapevine file waiting for a
 * Storyteller's review (F-122): the request itself, previewing an
 * upload before sending, reviewing a waiting one, checking its
 * verification code, and the result of accepting it.
 */
import type { ImportedEntity, ImportPreview } from './import';

/** One character a preview found in an uploaded file, and whether it may be sent here. */
export interface SubmissionPreviewCharacter {
	index: number;
	name: string;
	stack_slug: string;
	/** Null when this install has no creature stack for the raw slug at all. */
	stack_name: string | null;
	allowed: boolean;
	/** Why this character can't be sent here - null when allowed is true. */
	reason: string | null;
	/** True only for a single-character XML file whose id carries a real Beyond Elysium verification code. */
	verifiable: boolean;
}

/** Response from reading an uploaded file before sending it - nothing is stored yet. */
export interface SubmissionPreviewResponse {
	format: 'GVBE' | 'XML';
	characters: SubmissionPreviewCharacter[];
}

/** A waiting (or already-answered) submission row. Mirrors `be_character_submissions`, without its stored file columns. */
export interface Submission {
	id: number;
	game_id: number;
	submitted_by: number;
	arrival: 'joining' | 'visiting';
	home_chronicle: string | null;
	character_name: string;
	stack_slug: string;
	source_file: string;
	format: 'GVBE' | 'XML';
	file_hash: string;
	state: 'waiting' | 'accepted' | 'refused' | 'withdrawn' | 'expired';
	character_id: number | null;
	answered_by: number | null;
	answer_note: string | null;
	created_at: string;
	answered_at: string | null;
	/** Only on a response naming a specific chronicle (create, and /my/submissions). */
	game_name?: string;
	/** Only on /my/submissions. */
	game_slug?: string;
	/** Only on a Storyteller's own review(). */
	sender_name?: string | null;
	sender_email?: string | null;
}

/** A waiting submission, shown the way the Import page shows a parsed file. */
export interface SubmissionReview {
	submission: Submission;
	preview: ImportPreview;
}

/** Response from accepting a submission: the row, now closed, and the character it created or updated. */
export interface SubmissionAcceptResult {
	submission: Submission;
	character: ImportedEntity;
}

/** A waiting submission's verification check - see Services/Sheet_Verification.php for the full contract. */
export interface SubmissionVerification {
	status:
		| 'none'
		| 'unchanged'
		| 'changed'
		| 'revoked'
		| 'unknown'
		| 'unreachable'
		| 'issuer_mismatch';
	base?: string;
	code?: string;
	issuer?: { chronicle: string; slug: string; site: string };
	issued_at?: string | null;
	compared_with?: 'document' | 'sheet';
	attested?: Record< string, unknown >;
	file?: {
		name: string;
		stack: string;
		xp_earned: number;
		xp_unspent: number;
	};
	facts_match?: {
		name: boolean;
		stack: boolean;
		xp_earned: boolean;
		xp_unspent: boolean;
	};
	home_changed?: boolean | null;
}
