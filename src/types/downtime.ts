/**
 * Type definitions for downtime windows: the per-date open/deadline gate on a character's action entries and
 * background uses, and the Storyteller queue built from it.
 */

/**
 * A character's downtime state for one game date.
 */
export type DowntimeWindowState = 'none' | 'not_open' | 'open' | 'closed';

/**
 * How a held downtime answer currently stands, for the queue's own "answer_release_state" column.
 */
export type DowntimeAnswerReleaseState =
	| 'not_answered'
	| 'immediate'
	| 'draft'
	| 'scheduled'
	| 'released';

/**
 * One row in the Storyteller downtime queue.
 */
export interface DowntimeQueueRow {
	plot_id: number;
	character_id: number;
	character_name: string;
	player_id: number | null;
	player_name: string | null;
	action_count: number;
	last_action_at: string | null;
	answered: boolean;
	answer_release_state: DowntimeAnswerReleaseState;
	window_state: DowntimeWindowState;
	/**
	 * The plot's own staff owner, or null when unassigned.
	 */
	assigned_to: number | null;
}
