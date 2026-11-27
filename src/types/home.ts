/**
 * What a player's home in one chronicle carries.
 */
import type { Notice } from '../lib/notices';

export interface HomeCharacter {
	id: number;
	name: string;
	stack_slug: string;
	status: string;
	image_url: string | null;
	xp_unspent: number;
	xp_held: number;
	waiting: number;
}

export interface HomeDowntime {
	character_id: number;
	character_name: string;
	game_date: string;
	deadline: string | null;
	plot_id: number | null;
}

export interface HomeReport {
	character_id: number;
	character_name: string;
	session_id: number;
	game_date: string;
	due_at: string;
}

export interface HomeSession {
	id: number;
	game_date: string;
	start_time: string | null;
	place: string | null;
	downtime_opens_at: string | null;
	downtime_deadline_at: string | null;
	reports_due_at: string | null;
	castings: string[];
}

export interface HomePlot {
	id: number;
	title: string;
	updated_at: string;
}

export interface PlayerHomeData {
	/**
	 * The site's own date, `YYYY-MM-DD`.
	 */
	today: string;
	characters: HomeCharacter[];
	due: {
		downtime: HomeDowntime[];
		reports: HomeReport[];
	};
	notices: {
		unread: number;
		items: Notice[];
	};
	next_session: HomeSession | null;
	plots: HomePlot[];
}

export interface HomeCasting {
	character_name: string;
	game_date: string;
}

export interface StorytellerNight {
	id: number;
	game_date: string;
	start_time: string | null;
	place: string | null;
	downtime_deadline_at: string | null;
	/**
	 * How many of the chronicle's active player characters have sent actions for the night; null when it has no
	 * downtime window.
	 */
	downtime: { sent: number; total: number } | null;
	/**
	 * Reports for the latest game night whose reports are still due; null when none are.
	 */
	reports: {
		game_date: string;
		written: number;
		attended: number;
		due_at: string;
	} | null;
}

/**
 * A Storyteller's home. A part is null for someone who does not hold the capability behind it.
 */
export interface StorytellerHomeData {
	today: string;
	waiting: {
		approvals: {
			changes: number;
			xp_requests: number;
			files: number;
			new_characters: number;
		} | null;
		downtime: { mine: number; unassigned: number } | null;
		plots: { mine: number; unassigned: number } | null;
		players: { join_requests: number; links: number } | null;
		castings: HomeCasting[];
	};
	next_session: StorytellerNight | null;
	spotlight: { flagged: number; days: number; names: string[] } | null;
}
