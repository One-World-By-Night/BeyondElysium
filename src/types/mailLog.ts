/**
 * Type definitions for a chronicle's mail log: the emails the plugin sent, failed to send, held for a digest or chose not
 * to send.
 */

export type MailLogResult = 'sent' | 'failed' | 'skipped' | 'queued';

/**
 * One row of the log. The labels are the server's own translated words for the kind, result, reason and entity.
 */
export interface MailLogEntry {
	id: number;
	created_at: string;
	wp_user_id: number;
	recipient_name: string;
	recipient_email: string;
	kind: string;
	kind_label: string;
	subject: string;
	result: MailLogResult;
	result_label: string;
	reason: string;
	reason_label: string;
	error: string;
	entity_type: string;
	entity_id: number;
	entity_label: string;
}

export interface MailLogOption {
	key: string;
	label: string;
}

/**
 * What the screen's filters offer, and how long rows are kept.
 */
export interface MailLogOptions {
	kinds: MailLogOption[];
	results: MailLogOption[];
	periods: MailLogOption[];
	retention_days: number;
}

export interface MailLogParams {
	page?: number;
	per_page?: number;
	search?: string;
	kind?: string;
	result?: string;
	since?: string;
	entity_type?: string;
	entity_id?: number;
	wp_user_id?: number;
}
