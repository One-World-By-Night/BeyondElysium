/**
 * Type definitions for a chronicle's game sessions (1.1.0 §3.1): the calendar of game
 * nights, sign-in attendance, and awarding attendance XP.
 */

/**
 * One game night. The four attendance/report XP-awarded fields are only present for a
 * viewer with be_manage_sessions - absent, not null, for anyone else.
 */
export interface GameSession {
	id: number;
	game_id: number;
	game_date: string;
	start_time: string | null;
	place: string | null;
	notes: string | null;
	downtime_opens_at: string | null;
	downtime_deadline_at: string | null;
	downtime_extensions: Record< string, unknown > | null;
	default_batch_id: number | null;
	reports_due_at: string | null;
	attendance_xp_awarded_at?: string | null;
	attendance_xp_awarded_by?: number | null;
	report_xp_awarded_at?: string | null;
	report_xp_awarded_by?: number | null;
	created_by: number;
	created_at: string;
	updated_at: string;
}

/**
 * One sign-in at a session: a real character, or a visitor recorded by name - never both.
 */
export interface SessionAttendance {
	id: number;
	session_id: number;
	game_id: number;
	character_id: number | null;
	visitor_name: string | null;
	visitor_chronicle: string | null;
	recorded_by: number;
	created_at: string;
}

/**
 * Request body for creating a new session. game_date is required; every other field,
 * including the four downtime fields (which also require be_manage_apr), is optional.
 */
export interface CreateSessionRequest {
	game_date: string;
	start_time?: string;
	place?: string;
	notes?: string;
	reports_due_at?: string;
	downtime_opens_at?: string;
	downtime_deadline_at?: string;
	downtime_extensions?: Record< string, unknown >;
	default_batch_id?: number;
}

/**
 * Request body for updating an existing session. Every field is optional; only the fields
 * included in the request are changed.
 */
export type UpdateSessionRequest = Partial< CreateSessionRequest >;

/**
 * Request body for recording a sign-in: a real character, or a visitor by name - never both.
 */
export interface RecordAttendanceRequest {
	character_id?: number;
	visitor_name?: string;
	visitor_chronicle?: string;
}

/** Response from awarding attendance XP for a session. */
export interface AwardAttendanceXpResponse {
	awarded_count: number;
	amount: number;
}

/** This chronicle's session-related settings, merged into settings.sessions. */
export interface SessionSettings {
	attendance_xp?: number;
	report_xp?: number;
	spotlight_days?: number;
	release_schedule?: ReleaseSchedule;
}

/**
 * One recurring release-schedule rule (1.1.1 §3): a weekly rule names a weekday, a monthly
 * rule a day of month (1-28, no 29/30/31 ambiguity across short months). `last_run_date`
 * is the scheduler's own idempotency cursor - server-set, never sent by the client.
 */
export interface ReleaseScheduleRule {
	type: 'weekly' | 'monthly';
	weekday?: string;
	day_of_month?: number;
	time: string;
	last_run_date?: string;
}

/** A chronicle's own recurring release-schedule rules, settings.release_schedule. */
export interface ReleaseSchedule {
	rules: ReleaseScheduleRule[];
}

/** Response from updating a chronicle's session-related and release-schedule settings. */
export interface SessionSettingsResponse {
	sessions: SessionSettings;
	release_schedule: ReleaseSchedule;
}

/**
 * A player's own after-game report for one character at one session (1.1.0 §3.14, A1) - what
 * did your character do, what do you want next, anything for staff. A Storyteller reads and
 * marks one read; they never edit a player's own words.
 */
export interface AfterGameReport {
	id: number;
	game_id: number;
	session_id: number;
	character_id: number;
	wp_user_id: number;
	did: string | null;
	wants: string | null;
	to_staff: string | null;
	read_at: string | null;
	read_by: number | null;
	created_at: string;
	updated_at: string;
}

/** Request body for filing or editing an after-game report for the caller's own character. */
export interface AfterGameReportRequest {
	character_id: number;
	did?: string;
	wants?: string;
	to_staff?: string;
}

/** Response from awarding report XP for a session. */
export interface AwardReportXpResponse {
	awarded_count: number;
	amount: number;
}

/** One character's own attention profile on the spotlight check (1.1.0 §3.14, A2). */
export interface SpotlightRow {
	character_id: number;
	name: string;
	last_attended: string | null;
	active_plots: number;
	last_staff_post_at: string | null;
	last_report_at: string | null;
	flagged: boolean;
}
