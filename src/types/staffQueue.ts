/**
 * Type definitions for My Queue: what a Storyteller or Narrator is personally on the hook for in one chronicle.
 */

/**
 * An unanswered action-allocation plot assigned to the viewer, any game date.
 */
export interface StaffQueueDowntimeRow {
	plot_id: number;
	character_id: number;
	character_name: string;
	game_date: string | null;
	action_count: number;
	last_action_at: string | null;
}

/**
 * An ordinary plot assigned to the viewer whose newest player post outpaces the newest staff post.
 */
export interface StaffQueuePlotRow {
	plot_id: number;
	title: string;
	newest_player_post_at: string;
	newest_staff_post_at: string | null;
}

/**
 * My castings for sessions today or later.
 */
export interface StaffQueueCastingRow {
	casting_id: number;
	character_id: number;
	character_name: string;
	session_id: number;
	game_date: string;
	start_time: string | null;
	place: string | null;
}

/**
 * Counts of unanswered downtime and unanswered plot posts nobody owns yet.
 */
export interface StaffQueueUnassignedCounts {
	downtime: number;
	plots: number;
}

/**
 * The full `GET /{game}/my/queue` response.
 */
export interface StaffQueue {
	downtime: StaffQueueDowntimeRow[];
	plots: StaffQueuePlotRow[];
	castings: StaffQueueCastingRow[];
	unassigned: StaffQueueUnassignedCounts;
}

/**
 * One eligible assignee - a chronicle member holding a staff role.
 */
export interface StaffMember {
	id: number;
	name: string;
	role: string;
}
