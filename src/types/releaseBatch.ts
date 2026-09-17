/**
 * Type definitions for a chronicle's release batches (1.1.0 §3.2): scheduling rumors and
 * downtime answers to go out together, several between games.
 */

export type ReleaseBatchStatus = 'draft' | 'scheduled' | 'released';

/** One release batch, with its held item counts for the list/detail views. */
export interface ReleaseBatch {
	id: number;
	game_id: number;
	name: string;
	release_at: string | null;
	status: ReleaseBatchStatus;
	released_at: string | null;
	notified_at: string | null;
	created_by: number;
	created_at: string;
	updated_at: string;
	rumor_count: number;
	entry_count: number;
}

/** Request body for creating a batch. Scheduled when release_at is given, draft otherwise. */
export interface CreateReleaseBatchRequest {
	name: string;
	release_at?: string;
}

/**
 * Request body for updating a draft or scheduled batch. status here is only ever 'draft' or
 * 'scheduled' - reaching 'released' is a separate action (releaseNow).
 */
export interface UpdateReleaseBatchRequest {
	name?: string;
	release_at?: string | null;
	status?: 'draft' | 'scheduled';
}

/** One held item in a batch's items list - a rumor (held plot) or a downtime answer (held entry). */
export interface ReleaseBatchItem {
	type: 'plot' | 'entry';
	id: number;
	title?: string;
	plot_id?: number;
	plot_title?: string;
	content?: string;
}

/** A batch's held items, grouped by kind. Reveals (§3.11) always list empty until K1 ships. */
export interface ReleaseBatchItems {
	rumors: ReleaseBatchItem[];
	entries: ReleaseBatchItem[];
	reveals: ReleaseBatchItem[];
}

/** One item to add when releasing a single rumor or answer with no pre-existing batch. */
export interface ReleaseNowItem {
	type: 'plot' | 'entry';
	id: number;
}

/** Request body for release-now with no pre-existing batch: creates, fills, and releases in one call. */
export interface ReleaseNowSingleRequest {
	name?: string;
	items: ReleaseNowItem[];
}
