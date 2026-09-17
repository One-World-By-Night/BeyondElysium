<?php

namespace BeyondElysium\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Database schema definition, creation and migration for Beyond Elysium.
 *
 * Defines every plugin table via create_tables(), tracks the installed
 * schema version against the running plugin version, and runs migrate()
 * steps to bring an existing installation's tables and data up to date.
 * maybe_upgrade() is the single entry point that drives all of this on
 * every request.
 */
class Schema {

	/**
	 * The plugin's current database schema version, matching the plugin
	 * release version. Compared against the stored VERSION_OPTION value by
	 * maybe_upgrade() to decide whether migrations need to run.
	 */
	const DB_VERSION = '1.1.0';

	/**
	 * Option key holding the installed schema version.
	 */
	const VERSION_OPTION = 'be_db_version';

	/**
	 * Option key holding the upgrade lock: the time the running upgrade
	 * started. Only one request upgrades at a time.
	 */
	const UPGRADE_LOCK_OPTION = 'be_upgrade_lock';

	/**
	 * Seconds after which an upgrade lock is stale and may be taken over -
	 * how long a failed upgrade waits before it is tried again.
	 */
	const UPGRADE_LOCK_TTL = 600;

	/**
	 * Option key holding why the last upgrade did not finish, shown to
	 * administrators until an upgrade does.
	 */
	const UPGRADE_ERROR_OPTION = 'be_upgrade_error';

	/**
	 * Short names of every table create_tables() creates, unprefixed past `be_`. Kept as
	 * an explicit list rather than derived from create_tables()'s SQL, so a table rename
	 * or addition has one obvious place to update this too.
	 *
	 * @var string[]
	 */
	const TABLES = [
		'games',
		'schema_blocks',
		'creature_stacks',
		'characters',
		'character_changes',
		'character_snapshots',
		'plots',
		'plot_entries',
		'connections',
		'world_objects',
		'templates',
		'queries',
		'character_sheet_styles',
		'game_members',
		'character_attestations',
		'character_transfers',
		'character_submissions',
		'attachments',
		'game_sessions',
		'attendance',
		'release_batches',
		'notification_queue',
		'npc_castings',
		'secrets',
		'secret_reveals',
		'item_events',
		'item_attestations',
		'after_game_reports',
		'factions',
		'faction_members',
		'positions',
		'position_history',
	];

	/**
	 * Reports which of the plugin's own tables do not currently exist in
	 * the database. Compares TABLES against a live SHOW TABLES query, since
	 * dbDelta() never reports failure to its caller and maybe_upgrade()
	 * would otherwise have no way to tell an empty install apart from a
	 * broken one.
	 *
	 * @return string[] Short table names (matching TABLES), not full prefixed names.
	 */
	public static function missing_tables(): array {
		global $wpdb;

		$prefix   = $wpdb->prefix . 'be_';
		// phpcs:ignore -- prefix is derived from $wpdb, not user input.
		$existing = $wpdb->get_col( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $prefix ) . '%' ) );

		$missing = [];
		foreach ( self::TABLES as $table ) {
			if ( ! in_array( $prefix . $table, $existing, true ) ) {
				$missing[] = $table;
			}
		}

		return $missing;
	}

	/**
	 * Creates every plugin database table via dbDelta.
	 * Issues one CREATE TABLE statement per table, safe to run on both a
	 * fresh install and an existing one - dbDelta() only applies the
	 * differences. Runs migrate() and refreshes the stored schema version
	 * once every table statement has been issued.
	 */
	public static function create_tables(): void {
		global $wpdb;

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'be_';

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		// be_games: one row per chronicle; asc_role_path and notifications_enabled configure per-game access and email.
		dbDelta( "CREATE TABLE {$prefix}games (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			name varchar(255) NOT NULL,
			slug varchar(100) NOT NULL,
			game_type varchar(50) NOT NULL DEFAULT 'met',
			description longtext,
			settings json DEFAULT NULL,
			asc_role_path varchar(191) DEFAULT NULL,
			notifications_enabled tinyint(1) NOT NULL DEFAULT 1,
			owbn_chronicle_post_id bigint(20) unsigned DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			UNIQUE KEY owbn_chronicle_post (owbn_chronicle_post_id),
			KEY game_type (game_type)
		) $charset_collate;" );

		// be_schema_blocks: reusable character-sheet section definitions.
		dbDelta( "CREATE TABLE {$prefix}schema_blocks (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(100) NOT NULL,
			name varchar(255) NOT NULL,
			section_type varchar(20) NOT NULL,
			definition json NOT NULL,
			version int unsigned NOT NULL DEFAULT 1,
			is_system tinyint(1) NOT NULL DEFAULT 0,
			storyteller_only tinyint(1) NOT NULL DEFAULT 0,
			fork_changes longtext DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY section_type (section_type)
		) $charset_collate;" );

		// be_creature_stacks: which schema blocks make up each creature type's sheet.
		dbDelta( "CREATE TABLE {$prefix}creature_stacks (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			slug varchar(100) NOT NULL,
			name varchar(255) NOT NULL,
			game_line varchar(100) NOT NULL DEFAULT 'met',
			stack_definition json NOT NULL,
			creation_rules json DEFAULT NULL,
			is_system tinyint(1) NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
			KEY game_line (game_line)
		) $charset_collate;" );

		// be_characters: one row per character sheet; the uuid unique index is added later by migrate(), after backfilling.
		dbDelta( "CREATE TABLE {$prefix}characters (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			uuid char(36) NOT NULL DEFAULT '',
			name varchar(255) NOT NULL,
			stack_slug varchar(100) NOT NULL,
			owner_type varchar(20) NOT NULL DEFAULT 'chronicle',
			owner_slug varchar(100) NOT NULL,
			wp_user_id bigint(20) unsigned DEFAULT NULL,
			player_name varchar(255) DEFAULT NULL,
			pending_player_email varchar(255) DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			is_npc tinyint(1) NOT NULL DEFAULT 0,
			npc_detail varchar(10) NOT NULL DEFAULT 'full',
			narrator varchar(255) DEFAULT NULL,
			start_date date DEFAULT NULL,
			xp_earned int unsigned NOT NULL DEFAULT 0,
			xp_unspent int NOT NULL DEFAULT 0,
			biography longtext,
			notes longtext,
			rp_notes longtext,
			image_id bigint(20) unsigned DEFAULT NULL,
			sheet_data json DEFAULT NULL,
			assigned_to bigint(20) unsigned DEFAULT NULL,
			public_name varchar(255) DEFAULT NULL,
			public_description longtext,
			public_image_id bigint(20) unsigned DEFAULT NULL,
			profile_audience varchar(20) NOT NULL DEFAULT 'storytellers',
			profile_audience_rules json DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_name (name),
			KEY idx_stack_slug (stack_slug),
			KEY idx_owner (owner_type, owner_slug),
			KEY idx_wp_user (wp_user_id),
			KEY idx_status (status),
			KEY idx_assigned_to (assigned_to),
			FULLTEXT KEY ft_text (biography, notes)
		) $charset_collate;" );

		// be_character_changes: pending and reviewed edits awaiting approval.
		dbDelta( "CREATE TABLE {$prefix}character_changes (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			character_id bigint(20) unsigned NOT NULL,
			change_type varchar(50) NOT NULL,
			category varchar(100) DEFAULT NULL,
			change_data json DEFAULT NULL,
			xp_cost decimal(10,2) NOT NULL DEFAULT 0.00,
			status varchar(20) NOT NULL DEFAULT 'pending',
			submitted_by bigint(20) unsigned NOT NULL,
			reviewed_by bigint(20) unsigned DEFAULT NULL,
			submitted_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			reviewed_at datetime DEFAULT NULL,
			notes text,
			review_notes text,
			reason text,
			PRIMARY KEY  (id),
			KEY idx_character (character_id),
			KEY idx_status (status),
			KEY idx_change_type (change_type),
			KEY idx_submitted_at (submitted_at)
		) $charset_collate;" );

		// be_character_snapshots: point-in-time copies of a character's sheet data.
		dbDelta( "CREATE TABLE {$prefix}character_snapshots (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			character_id bigint(20) unsigned NOT NULL,
			snapshot_data json NOT NULL,
			change_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_character (character_id),
			KEY idx_change (change_id)
		) $charset_collate;" );

		// be_character_attestations: per-issuance verification tokens (GX-7). Keyed by a
		// random per-issuance token/short_code, never the character's own UUID - a UUIDv7
		// is partly a timestamp and is published as the permanent cross-plugin key
		// (INTEROP-UUID.md), so it must never double as a revocable, rotatable public key.
		dbDelta( "CREATE TABLE {$prefix}character_attestations (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			character_uuid char(36) NOT NULL,
			character_id bigint(20) unsigned DEFAULT NULL,
			game_slug varchar(100) NOT NULL,
			token char(43) NOT NULL,
			short_code varchar(12) NOT NULL,
			kind varchar(10) NOT NULL,
			sheet_hash char(64) NOT NULL,
			attested json NOT NULL,
			issued_at datetime NOT NULL,
			issued_by bigint(20) unsigned NOT NULL,
			expires_at datetime DEFAULT NULL,
			revoked_at datetime DEFAULT NULL,
			last_checked_at datetime DEFAULT NULL,
			check_count int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_token (token),
			UNIQUE KEY uq_short (short_code),
			KEY idx_uuid (character_uuid),
			KEY idx_game (game_slug, issued_at)
		) $charset_collate;" );

		// be_character_transfers: chronicle-to-chronicle travel state (GX-8/9). Not
		// be_connections - a transfer is a fact about one character's relationship to two
		// chronicles across two WordPress installs, not a relationship between two BE
		// records on this one (gex-export-transfer-design.md §2h). One row per leg of a
		// journey; the newest non-terminal row for a uuid+direction is the authoritative
		// travel state (§7.3) - enforced in Models/Transfer.php, not by a unique index,
		// since a composite unique index would fight its own in-place state transitions.
		dbDelta( "CREATE TABLE {$prefix}character_transfers (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			character_uuid char(36) NOT NULL,
			character_id bigint(20) unsigned DEFAULT NULL,
			character_name varchar(255) DEFAULT NULL,
			direction varchar(10) NOT NULL,
			state varchar(20) NOT NULL,
			home_slug varchar(100) NOT NULL,
			home_site varchar(191) NOT NULL,
			home_chronicle varchar(255) NOT NULL,
			host_slug varchar(100) DEFAULT NULL,
			host_site varchar(191) DEFAULT NULL,
			host_chronicle varchar(255) DEFAULT NULL,
			attestation_id bigint(20) unsigned DEFAULT NULL,
			snapshot_id bigint(20) unsigned DEFAULT NULL,
			payload_hash char(64) NOT NULL,
			initiated_by bigint(20) unsigned NOT NULL,
			initiated_at datetime NOT NULL,
			acknowledged_at datetime DEFAULT NULL,
			returned_at datetime DEFAULT NULL,
			notes text,
			payload longtext,
			PRIMARY KEY  (id),
			KEY idx_uuid_state (character_uuid, state),
			KEY idx_home (home_slug, state),
			KEY idx_host (host_slug, state)
		) $charset_collate;" );

		// be_character_submissions: a player-sent Grapevine file waiting for a Storyteller's
		// review (F-122). Not be_character_transfers - the sender's file may carry no uuid at
		// all (and any it does carry is dropped, 1.0.0-review F-003/F-059), and there is no
		// home site to call back to until a Storyteller accepts and a real character exists.
		// `parsed`/`verification_source` hold only what the request needs while it waits, and
		// both are cleared the moment the row leaves 'waiting' (Models/Submission.php).
		dbDelta( "CREATE TABLE {$prefix}character_submissions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned NOT NULL,
			submitted_by bigint(20) unsigned NOT NULL,
			arrival varchar(10) NOT NULL,
			home_chronicle varchar(255) DEFAULT NULL,
			character_name varchar(255) NOT NULL,
			stack_slug varchar(100) NOT NULL,
			source_file varchar(255) NOT NULL,
			format varchar(10) NOT NULL,
			file_hash char(64) NOT NULL,
			parsed longtext,
			verification_source longtext,
			state varchar(20) NOT NULL,
			character_id bigint(20) unsigned DEFAULT NULL,
			answered_by bigint(20) unsigned DEFAULT NULL,
			answer_note text,
			created_at datetime NOT NULL,
			answered_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			KEY idx_game_state (game_id, state),
			KEY idx_sender_state (submitted_by, state)
		) $charset_collate;" );

		// be_plots: storyline records, optionally nested under a parent plot.
		dbDelta( "CREATE TABLE {$prefix}plots (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned NOT NULL,
			parent_plot_id bigint(20) unsigned DEFAULT NULL,
			image_id bigint(20) unsigned DEFAULT NULL,
			title varchar(255) NOT NULL,
			description longtext,
			status varchar(20) NOT NULL DEFAULT 'active',
			initiated_by varchar(20) NOT NULL DEFAULT 'st',
			plot_category varchar(32) DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL,
			first_introduced varchar(255) DEFAULT NULL,
			start_date date DEFAULT NULL,
			end_date date DEFAULT NULL,
			game_date date DEFAULT NULL,
			resolution_details longtext,
			resolution_impact longtext,
			faction_goals json DEFAULT NULL,
			cliffhanger longtext,
			target_query json DEFAULT NULL,
			st_notes longtext,
			audience varchar(20) NOT NULL DEFAULT 'everyone',
			audience_rules json DEFAULT NULL,
			held tinyint(1) NOT NULL DEFAULT 0,
			release_batch_id bigint(20) unsigned DEFAULT NULL,
			rumor_level_key varchar(64) DEFAULT NULL,
			rumor_level_match varchar(255) DEFAULT NULL,
			assigned_to bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_game_status (game_id, status),
			KEY idx_created_by (created_by),
			KEY idx_game_date (game_id, game_date),
			KEY idx_parent_plot (parent_plot_id),
			KEY idx_release_batch (release_batch_id),
			KEY idx_assigned_to (assigned_to),
			FULLTEXT KEY ft_plot_text (title, description)
		) $charset_collate;" );

		// be_plot_entries: timeline entries attached to a plot; event_date is the in-fiction date.
		// audience_character_ids only applies when audience = 'characters' (a Storyteller post
		// aimed at specific characters, 1.1.0 §2.4); NULL otherwise. held/release_batch_id are
		// the same release-batch gate as plots (1.1.0 §3.2). level (1-10) only applies to a
		// 'rumor_level' entry (1.1.0 §3.4); it carries no held/release_batch_id of its own -
		// a level text follows its plot's own release state, never its own.
		dbDelta( "CREATE TABLE {$prefix}plot_entries (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			plot_id bigint(20) unsigned NOT NULL,
			author_id bigint(20) unsigned NOT NULL,
			entry_type varchar(20) NOT NULL,
			content longtext NOT NULL,
			event_date date DEFAULT NULL,
			audience varchar(20) NOT NULL DEFAULT 'plot',
			audience_character_ids json DEFAULT NULL,
			held tinyint(1) NOT NULL DEFAULT 0,
			release_batch_id bigint(20) unsigned DEFAULT NULL,
			level tinyint(3) unsigned DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_release_batch (release_batch_id),
			KEY idx_plot_date (plot_id, created_at)
		) $charset_collate;" );

		// be_connections: links between characters, plots and world objects.
		dbDelta( "CREATE TABLE {$prefix}connections (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned NOT NULL,
			source_type varchar(20) NOT NULL,
			source_id bigint(20) unsigned NOT NULL,
			target_type varchar(20) NOT NULL,
			target_id bigint(20) unsigned DEFAULT NULL,
			label varchar(255) DEFAULT NULL,
			notes longtext,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_source (source_type, source_id),
			KEY idx_target (target_type, target_id),
			KEY idx_game (game_id)
		) $charset_collate;" );

		// be_world_objects: items, locations and other non-character game elements.
		dbDelta( "CREATE TABLE {$prefix}world_objects (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned NOT NULL,
			object_type varchar(50) NOT NULL,
			name varchar(255) NOT NULL,
			description longtext,
			rarity varchar(20) DEFAULT NULL,
			cost varchar(100) DEFAULT NULL,
			limitations longtext,
			properties json DEFAULT NULL,
			audience varchar(20) NOT NULL DEFAULT 'everyone',
			audience_rules json DEFAULT NULL,
			parent_id bigint(20) unsigned DEFAULT NULL,
			based_on_id bigint(20) unsigned DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_game_type (game_id, object_type),
			KEY idx_rarity (rarity),
			KEY idx_parent (parent_id),
			KEY idx_based_on (based_on_id),
			FULLTEXT KEY ft_object_text (name, description)
		) $charset_collate;" );

		// be_templates: saved character-sheet layouts.
		dbDelta( "CREATE TABLE {$prefix}templates (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned DEFAULT NULL,
			stack_slug varchar(100) DEFAULT NULL,
			name varchar(255) NOT NULL,
			template_type varchar(50) NOT NULL,
			layout json NOT NULL,
			is_system tinyint(1) NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_resolution (game_id, stack_slug, template_type)
		) $charset_collate;" );

		// be_queries: saved search/filter definitions for characters or other inventories.
		dbDelta( "CREATE TABLE {$prefix}queries (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned NOT NULL,
			name varchar(255) NOT NULL,
			inventory varchar(20) NOT NULL DEFAULT 'char',
			match_all tinyint(1) NOT NULL DEFAULT 1,
			conditions json NOT NULL,
			sort_key varchar(100) DEFAULT NULL,
			sort_direction varchar(4) NOT NULL DEFAULT 'asc',
			is_recent_search tinyint(1) NOT NULL DEFAULT 0,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_game (game_id),
			KEY idx_recent (game_id, created_by, is_recent_search)
		) $charset_collate;" );

		// be_character_sheet_styles: one optional cosmetic override row per character.
		dbDelta( "CREATE TABLE {$prefix}character_sheet_styles (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			character_id bigint(20) unsigned NOT NULL,
			font_family varchar(100) DEFAULT NULL,
			accent_color varchar(20) DEFAULT NULL,
			background_color varchar(20) DEFAULT NULL,
			text_color varchar(20) DEFAULT NULL,
			background_image_id bigint(20) unsigned DEFAULT NULL,
			section_graphics json DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_character (character_id)
		) $charset_collate;" );

		// be_game_members: one row per (game, user) pairing a chronicle role to a WordPress user.
		dbDelta( "CREATE TABLE {$prefix}game_members (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned NOT NULL,
			wp_user_id bigint(20) unsigned NOT NULL,
			role varchar(32) NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY game_user (game_id, wp_user_id),
			KEY idx_wp_user (wp_user_id)
		) $charset_collate;" );

		// be_attachments: private uploads on a plot or world object (1.1.0 §2.6). The file itself
		// lives outside the uploads tree the media library serves from - stored_name is a random
		// 32-hex-char component of that private path, never the original filename, and is never
		// echoed in any REST response. Visibility is the owning entity's own audience, checked by
		// Attachments_Controller on every download - this table records what exists, not who may see it.
		dbDelta( "CREATE TABLE {$prefix}attachments (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned NOT NULL,
			entity_type varchar(20) NOT NULL,
			entity_id bigint(20) unsigned NOT NULL,
			original_name varchar(255) NOT NULL,
			stored_name varchar(64) NOT NULL,
			mime varchar(100) NOT NULL,
			bytes bigint(20) unsigned NOT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_entity (entity_type, entity_id),
			KEY idx_game (game_id)
		) $charset_collate;" );

		// be_game_sessions: one row per game night (1.1.0 §3.1).
		dbDelta( "CREATE TABLE {$prefix}game_sessions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned NOT NULL,
			game_date date NOT NULL,
			start_time varchar(50) DEFAULT NULL,
			place varchar(255) DEFAULT NULL,
			notes longtext,
			downtime_opens_at datetime DEFAULT NULL,
			downtime_deadline_at datetime DEFAULT NULL,
			downtime_extensions json DEFAULT NULL,
			default_batch_id bigint(20) unsigned DEFAULT NULL,
			reports_due_at datetime DEFAULT NULL,
			attendance_xp_awarded_at datetime DEFAULT NULL,
			attendance_xp_awarded_by bigint(20) unsigned DEFAULT NULL,
			report_xp_awarded_at datetime DEFAULT NULL,
			report_xp_awarded_by bigint(20) unsigned DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY game_date (game_id, game_date)
		) $charset_collate;" );

		// be_attendance: who signed in at a session - a character, or a visitor recorded by name.
		dbDelta( "CREATE TABLE {$prefix}attendance (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			session_id bigint(20) unsigned NOT NULL,
			game_id bigint(20) unsigned NOT NULL,
			character_id bigint(20) unsigned DEFAULT NULL,
			visitor_name varchar(255) DEFAULT NULL,
			visitor_chronicle varchar(255) DEFAULT NULL,
			recorded_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY session_character (session_id, character_id),
			KEY idx_character (character_id)
		) $charset_collate;" );

		// be_release_batches: scheduled batches rumors and downtime answers go out in
		// (1.1.0 §3.2, owner ruling - several releases between games, never immediate).
		dbDelta( "CREATE TABLE {$prefix}release_batches (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned NOT NULL,
			name varchar(255) NOT NULL,
			release_at datetime DEFAULT NULL,
			status varchar(20) NOT NULL DEFAULT 'draft',
			released_at datetime DEFAULT NULL,
			notified_at datetime DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_game_status (game_id, status),
			KEY idx_due (status, release_at)
		) $charset_collate;" );

		// be_notification_queue: daily-digest plot-post notifications (1.1.0 §3.5) queued for a
		// user who has opted into 'daily' rather than 'immediate' - Maintenance::run() sends one
		// digest per user and deletes the rows it sent. payload is longtext, not the design
		// doc's literal `json` type, matching how every other JSON-shaped column in this schema
		// (plots.target_query, plots.audience_rules, ...) is already modeled.
		dbDelta( "CREATE TABLE {$prefix}notification_queue (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			wp_user_id bigint(20) unsigned NOT NULL,
			game_id bigint(20) unsigned NOT NULL,
			kind varchar(20) NOT NULL,
			payload longtext,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_user (wp_user_id)
		) $charset_collate;" );

		// be_npc_castings: a chronicle member cast to play one NPC for one session (1.1.0
		// §3.8) - a per-session loan of "how to play this character tonight," separate from
		// characters.assigned_to (S6's permanent staff owner). brief is longtext, not the
		// design doc's literal wording, matching this schema's own JSON/free-text convention.
		dbDelta( "CREATE TABLE {$prefix}npc_castings (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned NOT NULL,
			session_id bigint(20) unsigned NOT NULL,
			character_id bigint(20) unsigned NOT NULL,
			wp_user_id bigint(20) unsigned NOT NULL,
			brief longtext,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY session_character (session_id, character_id),
			KEY idx_wp_user (wp_user_id)
		) $charset_collate;" );

		// be_secrets: a Storyteller-authored secret attached to a plot, item, location, or NPC
		// (1.1.0 §3.11) - a real Audience-shaped audience/audience_rules pair in its own
		// right, not simply "hidden until revealed."
		dbDelta( "CREATE TABLE {$prefix}secrets (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned NOT NULL,
			entity_type varchar(20) NOT NULL,
			entity_id bigint(20) unsigned NOT NULL,
			title varchar(255) NOT NULL,
			content longtext,
			audience varchar(20) NOT NULL DEFAULT 'storytellers',
			audience_rules json DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_game (game_id),
			KEY idx_entity (entity_type, entity_id)
		) $charset_collate;" );

		// be_secret_reveals: one character learning one secret - held/release_batch_id is the
		// same per-item gate plots/plot_entries already use (§3.2), applied per reveal rather
		// than to the whole secret, since different characters can learn the same secret at
		// different times.
		dbDelta( "CREATE TABLE {$prefix}secret_reveals (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			secret_id bigint(20) unsigned NOT NULL,
			character_id bigint(20) unsigned NOT NULL,
			how varchar(20) NOT NULL DEFAULT 'game',
			note text,
			held tinyint(1) NOT NULL DEFAULT 0,
			release_batch_id bigint(20) unsigned DEFAULT NULL,
			revealed_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY secret_character (secret_id, character_id),
			KEY idx_character (character_id),
			KEY idx_release_batch (release_batch_id)
		) $charset_collate;" );

		// be_item_events: an item's own history (1.1.0 §3.12, I1/I2) - I1 writes only 'copied'
		// for now; I2 adds given/taken/traded/stolen/lost/used/proposed/adjusted once it ships.
		// The full column set is built now since I1 already needs the table to exist at all.
		dbDelta( "CREATE TABLE {$prefix}item_events (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned NOT NULL,
			world_object_id bigint(20) unsigned NOT NULL,
			event varchar(20) NOT NULL,
			character_id bigint(20) unsigned DEFAULT NULL,
			from_character_id bigint(20) unsigned DEFAULT NULL,
			note text,
			recorded_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_object_created (world_object_id, created_at)
		) $charset_collate;" );

		// be_item_attestations: an item's own verification codes (1.1.0 §3.13) - the item
		// sibling of be_character_attestations (GX-7), sharing Services\Short_Code so a
		// character code and an item code can never collide against the one shared
		// GET /be/v1/verify/{code} route. No expires_at/sheet_hash/kind of its own: an item has
		// no document-format variant to distinguish and no canonicalized-export hash to compare
		// against - still_matches instead re-derives name/holder/uses_left/expires_on live.
		dbDelta( "CREATE TABLE {$prefix}item_attestations (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_slug varchar(100) NOT NULL,
			world_object_id bigint(20) unsigned NOT NULL,
			character_id bigint(20) unsigned DEFAULT NULL,
			token char(43) NOT NULL,
			short_code varchar(12) NOT NULL,
			attested json NOT NULL,
			issued_at datetime NOT NULL,
			issued_by bigint(20) unsigned NOT NULL,
			revoked_at datetime DEFAULT NULL,
			last_checked_at datetime DEFAULT NULL,
			check_count int(10) unsigned NOT NULL DEFAULT 0,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_token (token),
			UNIQUE KEY uq_short (short_code),
			KEY idx_object (world_object_id)
		) $charset_collate;" );

		// be_after_game_reports: one player-written report per character per session (1.1.0
		// §3.14, A1) - what did your character do, what do you want next, anything for staff.
		// Storytellers read and mark read; they never edit a player's own words.
		dbDelta( "CREATE TABLE {$prefix}after_game_reports (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned NOT NULL,
			session_id bigint(20) unsigned NOT NULL,
			character_id bigint(20) unsigned NOT NULL,
			wp_user_id bigint(20) unsigned NOT NULL,
			did longtext,
			wants longtext,
			to_staff longtext,
			read_at datetime DEFAULT NULL,
			read_by bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY session_character (session_id, character_id),
			KEY idx_character (character_id)
		) $charset_collate;" );

		// be_factions: sects, coteries, packs, chantries, courts and similar groups (1.1.0
		// §3.10, F1). A real Audience-shaped audience/audience_rules pair, same discipline as
		// be_secrets - visibility is a first-class rule, not "hidden until named." parent_id
		// is a plain nullable self-reference like every other relationship in this schema -
		// no FOREIGN KEY anywhere in this codebase, app-level integrity only.
		dbDelta( "CREATE TABLE {$prefix}factions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned NOT NULL,
			parent_id bigint(20) unsigned DEFAULT NULL,
			name varchar(255) NOT NULL,
			faction_type varchar(30) NOT NULL DEFAULT 'other',
			description longtext,
			goals longtext,
			status varchar(20) NOT NULL DEFAULT 'active',
			created_via_proposal tinyint(1) NOT NULL DEFAULT 0,
			audience varchar(20) NOT NULL DEFAULT 'storytellers',
			audience_rules json DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_game_status (game_id, status)
		) $charset_collate;" );

		// be_faction_members: one row per character in a faction, at most one leader flag
		// per member (not enforced at the row level - a faction may have more than one
		// leader, checked in the model).
		dbDelta( "CREATE TABLE {$prefix}faction_members (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			faction_id bigint(20) unsigned NOT NULL,
			character_id bigint(20) unsigned NOT NULL,
			member_rank varchar(100) DEFAULT NULL,
			is_leader tinyint(1) NOT NULL DEFAULT 0,
			added_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY faction_character (faction_id, character_id),
			KEY idx_character (character_id)
		) $charset_collate;" );

		// be_positions: a chronicle office (Prince, Sheriff, Grand Elder, ...), optionally tied
		// to a faction (a court seat) or standing alone (an independent title). holder_public
		// controls whether a non-Storyteller sees who holds it or only that it is held.
		dbDelta( "CREATE TABLE {$prefix}positions (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			game_id bigint(20) unsigned NOT NULL,
			faction_id bigint(20) unsigned DEFAULT NULL,
			title varchar(100) NOT NULL,
			character_id bigint(20) unsigned DEFAULT NULL,
			since date DEFAULT NULL,
			holder_public tinyint(1) NOT NULL DEFAULT 1,
			audience varchar(20) NOT NULL DEFAULT 'everyone',
			audience_rules json DEFAULT NULL,
			notes longtext,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_game (game_id),
			KEY idx_faction (faction_id),
			KEY idx_character (character_id)
		) $charset_collate;" );

		// be_position_history: one row per holder change, written whenever a position's
		// character_id changes - never edited afterward, an append-only log.
		dbDelta( "CREATE TABLE {$prefix}position_history (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			position_id bigint(20) unsigned NOT NULL,
			character_id bigint(20) unsigned DEFAULT NULL,
			started date NOT NULL,
			ended date DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_position (position_id)
		) $charset_collate;" );

		self::migrate();

		// The schema version is recorded by the caller once every step after this one has run too,
		// never here: recorded first, a later failure left the site reading as upgraded (1.0.0-review F-064).

		// Clears the cached health-notice state so any fix is reflected immediately.
		\BeyondElysium\Core\Health_Notice::clear_cache();
	}

	/**
	 * Post-dbDelta migration steps.
	 *
	 * dbDelta handles columns and plain indexes. Anything that needs data to exist in a
	 * particular state first — a UNIQUE index over a column dbDelta just backfilled with
	 * a constant default, for example — has to run here, in order, after dbDelta.
	 *
	 * Every step must be idempotent: this runs on every activation and every upgrade.
	 */
	public static function migrate(): void {
		self::backfill_character_uuids();
		self::add_character_uuid_index();
		self::make_xp_unspent_signed();
		self::rename_fera_gifts_sheet_data_key();
		self::add_name_order_indexes();
		self::backfill_game_members();
		// A pure structure change with no row-content reseed hazard, so it is safe to run here.
		self::add_schema_block_game_scoping();
		self::add_storyteller_only_to_schema_blocks();
		self::add_fork_changes_to_schema_blocks();
		self::add_owbn_chronicle_post_id();
		self::backfill_owbn_chronicle_post_ids();
		self::add_review_notes_to_character_changes();
		self::remove_coordinator_approvals();
		self::make_power_ladders_cumulative();
		self::carry_storyteller_only_to_forks();
		// Before any reseed, so an old copy's own changes are told apart from the update's (F-034).
		self::record_fork_changes();
		self::seed_character_plots();
		self::preserve_existing_signing_choice();
		self::add_audience_to_plots();
		self::add_audience_to_plot_entries();
		self::add_audience_to_world_objects();
		self::migrate_actor_plots_to_restricted_audience();
		self::add_rumor_levels_to_plots();
		self::add_level_to_plot_entries();
		self::migrate_rumors_to_release_batches();
		self::add_assigned_to_to_plots();
		self::add_assigned_to_to_characters();
		self::add_npc_profile_to_characters();
		self::add_parent_id_to_world_objects();
		self::add_based_on_id_to_world_objects();
	}

	/**
	 * Secure printing became an opt-in in 1.0.1 (C2), defaulting off. For a *new* install that
	 * is right. For a site that was already signing, flipping it off at upgrade would silently
	 * stop signing sheets that chronicles rely on being signed - a behaviour change nobody
	 * asked for, announced nowhere, discovered the next time someone printed.
	 *
	 * So: an install that already has a working certificate at upgrade time keeps signing. A
	 * site with no certificate gets the documented default of off, and ticking the box is a
	 * deliberate act either way.
	 *
	 * Found by 1.0.1's own pre-deploy trace, not by reading the design - the local install has
	 * a certificate configured and stopped signing the moment the option landed.
	 *
	 * Idempotent: writes only when the option has never been set.
	 */
	private static function preserve_existing_signing_choice(): void {
		if ( get_option( \BeyondElysium\Services\Pdf_Signer::OPT_IN_OPTION, null ) !== null ) {
			return;
		}

		add_option( \BeyondElysium\Services\Pdf_Signer::OPT_IN_OPTION, \BeyondElysium\Services\Pdf_Signer::availability()['ok'] );
	}

	/**
	 * Adds one column to an existing table if it is not already there. Shared by the four
	 * 1.1.0 audience migrations below rather than four independent copies of the same
	 * information_schema probe - each still logs its own table/column on failure.
	 *
	 * @param string $table      Fully prefixed table name (from self::table()).
	 * @param string $column     Column name to check for.
	 * @param string $definition The full column definition clause, e.g. "varchar(20) NOT NULL DEFAULT 'everyone'".
	 */
	private static function add_column_if_missing( string $table, string $column, string $definition ): void {
		global $wpdb;

		$has_column = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.columns
				 WHERE table_schema = DATABASE() AND table_name = %s AND column_name = %s",
				$table,
				$column
			)
		);
		if ( (int) $has_column === 0 ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN {$column} {$definition}" );
			if ( $wpdb->last_error ) {
				error_log( "Beyond Elysium: failed to add {$column} to {$table}: " . $wpdb->last_error );
			}
		}
	}

	/**
	 * Gives an existing plots table its 1.1.0 audience columns. A fresh install already has
	 * them from create_tables(); this brings an upgrade up to the same shape.
	 */
	public static function add_audience_to_plots(): void {
		$table = self::table( 'plots' );
		self::add_column_if_missing( $table, 'audience', "varchar(20) NOT NULL DEFAULT 'everyone' AFTER st_notes" );
		self::add_column_if_missing( $table, 'audience_rules', 'json DEFAULT NULL AFTER audience' );
	}

	/**
	 * Gives an existing plot_entries table its 1.1.0 audience columns.
	 */
	public static function add_audience_to_plot_entries(): void {
		$table = self::table( 'plot_entries' );
		self::add_column_if_missing( $table, 'audience', "varchar(20) NOT NULL DEFAULT 'plot' AFTER event_date" );
		self::add_column_if_missing( $table, 'audience_character_ids', 'json DEFAULT NULL AFTER audience' );
	}

	/**
	 * Gives an existing world_objects table its 1.1.0 audience columns.
	 */
	public static function add_audience_to_world_objects(): void {
		$table = self::table( 'world_objects' );
		self::add_column_if_missing( $table, 'audience', "varchar(20) NOT NULL DEFAULT 'everyone' AFTER properties" );
		self::add_column_if_missing( $table, 'audience_rules', 'json DEFAULT NULL AFTER audience' );
	}

	/**
	 * Gives an existing world_objects table its 1.1.0 "Inside of" column (§3.9 item 1) - a
	 * location nested inside another location. Never meaningful for an item, rote, or boon;
	 * nothing here enforces that at the schema level, matching how `object_type` itself is a
	 * plain column with app-level validation, not a per-type table.
	 */
	public static function add_parent_id_to_world_objects(): void {
		$table = self::table( 'world_objects' );
		self::add_column_if_missing( $table, 'parent_id', 'bigint(20) unsigned DEFAULT NULL AFTER audience_rules' );
	}

	/**
	 * Gives an existing world_objects table its 1.1.0 "based on" column (§3.12, I1) - the
	 * source item an item copy was made from.
	 */
	public static function add_based_on_id_to_world_objects(): void {
		$table = self::table( 'world_objects' );
		self::add_column_if_missing( $table, 'based_on_id', 'bigint(20) unsigned DEFAULT NULL AFTER parent_id' );
	}

	/**
	 * Preserves every existing plot's real visibility under 1.1.0's new audience column
	 * (owner, 2026-09-16: "Player plots do what global can - but are ST/Narrator and player +
	 * anyone added to it").
	 *
	 * A plot connected to a character via Action_Allocator::ACTOR_LABEL ('apr_actor') is that
	 * character's own personal plot - already hidden from every other player by
	 * Plot::actor_ownership_exclusion(). Setting its audience to 'restricted' with no rules
	 * makes the new audience system agree with what Plot::actor_ownership_exclusion() already
	 * enforces: restricted-with-no-rules-and-no-other-connections means "the owner only",
	 * which is exactly today's behavior. The owner's connection itself is untouched, so
	 * Audience::visible_character_ids() finds the owner through it - see Services\Audience.
	 *
	 * Every other existing plot is left at the column's own default, 'everyone' - unchanged
	 * from how every non-personal plot behaves today. New plots created after this migration
	 * get 1.1.0's own defaults (Storytellers-only for a global plot, owner-only for a player
	 * plot) from Plots_Controller::create_item(), not from this one-time backfill.
	 *
	 * Idempotent via a dedicated option, since re-running the UPDATE is harmless but pointless
	 * once done - a Storyteller may deliberately widen a personal plot's audience afterward,
	 * and a later upgrade must never overwrite that choice back to 'restricted'.
	 */
	public static function migrate_actor_plots_to_restricted_audience(): void {
		if ( get_option( 'be_actor_plots_audience_migrated' ) ) {
			return;
		}

		global $wpdb;
		$plots       = self::table( 'plots' );
		$connections = self::table( 'connections' );

		$wpdb->query(
			$wpdb->prepare(
				"UPDATE {$plots} p
				 INNER JOIN {$connections} c ON c.source_type = 'plot' AND c.source_id = p.id
				   AND c.target_type = 'character' AND c.label = %s
				 SET p.audience = 'restricted'
				 WHERE p.audience = 'everyone'",
				\BeyondElysium\Services\Action_Allocator::ACTOR_LABEL
			)
		);
		if ( $wpdb->last_error ) {
			error_log( 'Beyond Elysium: failed to migrate actor-plot audiences: ' . $wpdb->last_error );
			return;
		}

		update_option( 'be_actor_plots_audience_migrated', 1 );
	}

	/**
	 * Gives an existing plots table its 1.1.0 rumor-level columns (§3.4). A fresh install
	 * already has them from create_tables(); this brings an upgrade up to the same shape.
	 */
	public static function add_rumor_levels_to_plots(): void {
		$table = self::table( 'plots' );
		self::add_column_if_missing( $table, 'rumor_level_key', 'varchar(64) DEFAULT NULL AFTER release_batch_id' );
		self::add_column_if_missing( $table, 'rumor_level_match', 'varchar(255) DEFAULT NULL AFTER rumor_level_key' );
	}

	/**
	 * Gives an existing plot_entries table its 1.1.0 `level` column (§3.4).
	 */
	public static function add_level_to_plot_entries(): void {
		self::add_column_if_missing( self::table( 'plot_entries' ), 'level', 'tinyint(3) unsigned DEFAULT NULL AFTER release_batch_id' );
	}

	/**
	 * Gives an existing plots table its 1.1.0 staff-assignment column (§3.6).
	 */
	public static function add_assigned_to_to_plots(): void {
		self::add_column_if_missing( self::table( 'plots' ), 'assigned_to', 'bigint(20) unsigned DEFAULT NULL AFTER rumor_level_match' );
	}

	/**
	 * Gives an existing characters table its 1.1.0 staff-assignment column (§3.6) - an NPC's
	 * staff owner, unused on a player character.
	 */
	public static function add_assigned_to_to_characters(): void {
		self::add_column_if_missing( self::table( 'characters' ), 'assigned_to', 'bigint(20) unsigned DEFAULT NULL AFTER sheet_data' );
	}

	/**
	 * Gives an existing characters table its 1.1.0 quick-NPC and public-profile columns (§3.7).
	 * `npc_detail`/the five profile fields are meaningful only on an NPC; unused on a PC.
	 */
	public static function add_npc_profile_to_characters(): void {
		$table = self::table( 'characters' );
		self::add_column_if_missing( $table, 'npc_detail', "varchar(10) NOT NULL DEFAULT 'full' AFTER is_npc" );
		self::add_column_if_missing( $table, 'public_name', 'varchar(255) DEFAULT NULL AFTER assigned_to' );
		self::add_column_if_missing( $table, 'public_description', 'longtext DEFAULT NULL AFTER public_name' );
		self::add_column_if_missing( $table, 'public_image_id', 'bigint(20) unsigned DEFAULT NULL AFTER public_description' );
		self::add_column_if_missing( $table, 'profile_audience', "varchar(20) NOT NULL DEFAULT 'storytellers' AFTER public_image_id" );
		self::add_column_if_missing( $table, 'profile_audience_rules', 'json DEFAULT NULL AFTER profile_audience' );
	}

	/**
	 * Preserves every existing rumor's visibility under 1.1.0's held-from-birth rule (§3.4):
	 * every plot ever tagged `apr_rumor` gets `held = 1` and joins one already-`released`
	 * batch per chronicle, named "Released before 1.1.0" - so a non-manager who could read a
	 * rumor before this migration can still read it after, through the release-batch gate
	 * instead of through an unheld plot. Their `audience` is never touched here - U1's own
	 * migration already set it correctly, and a Storyteller may since have widened or
	 * narrowed it deliberately.
	 *
	 * One batch per chronicle rather than one batch for every rumor, or one shared batch
	 * across every chronicle: `Release_Batch` is game-scoped everywhere else in this
	 * codebase (`for_game()`, the Releases tab), and a single cross-chronicle batch would be
	 * the first row in this table not to be.
	 *
	 * Idempotent via a dedicated option: re-running would otherwise create a second
	 * "Released before 1.1.0" batch per chronicle every upgrade, and a Storyteller may
	 * deliberately move a rumor to a later draft/scheduled batch afterward, which a repeat
	 * run must never overwrite back.
	 */
	public static function migrate_rumors_to_release_batches(): void {
		if ( get_option( 'be_rumor_release_migrated' ) ) {
			return;
		}

		global $wpdb;
		$plots       = self::table( 'plots' );
		$connections = self::table( 'connections' );
		$rumor_label = \BeyondElysium\Services\Rumor_Generator::RUMOR_LABEL;

		$game_ids = $wpdb->get_col( $wpdb->prepare(
			"SELECT DISTINCT p.game_id FROM {$plots} p
			 INNER JOIN {$connections} c ON c.source_type = 'plot' AND c.source_id = p.id
			   AND c.target_type = 'tag' AND c.label = %s
			 WHERE p.held = 0",
			$rumor_label
		) );
		if ( $wpdb->last_error ) {
			error_log( 'Beyond Elysium: failed to find chronicles with pre-1.1.0 rumors: ' . $wpdb->last_error );
			return;
		}

		foreach ( $game_ids as $game_id ) {
			if ( ! self::migrate_one_chronicles_rumors_to_a_release_batch( (int) $game_id, $rumor_label ) ) {
				return;
			}
		}

		update_option( 'be_rumor_release_migrated', 1 );
	}

	/**
	 * One chronicle's own share of `migrate_rumors_to_release_batches()`: a fresh "Released
	 * before 1.1.0" batch, marked released immediately, and every one of this game's own
	 * pre-1.1.0 rumors pointed at it. Split into its own method (rather than a loop body)
	 * so each chronicle's own `$wpdb->last_error` check runs in a function scope with no
	 * earlier check to be mistakenly narrowed against.
	 *
	 * @param int    $game_id
	 * @param string $rumor_label
	 * @return bool False on any failure - the caller stops there and leaves the option unset,
	 *              so a later upgrade retries a chronicle that never got its batch.
	 */
	private static function migrate_one_chronicles_rumors_to_a_release_batch( int $game_id, string $rumor_label ): bool {
		global $wpdb;

		$batch_id = \BeyondElysium\Models\Release_Batch::create( [
			'game_id'    => $game_id,
			'name'       => 'Released before 1.1.0',
			'created_by' => 0,
		] );
		if ( $batch_id === false ) {
			error_log( "Beyond Elysium: failed to create the pre-1.1.0 release batch for game {$game_id}." );
			return false;
		}
		$now = current_time( 'mysql' );
		\BeyondElysium\Models\Release_Batch::mark_released( (int) $batch_id, $now, $now );

		$plots       = self::table( 'plots' );
		$connections = self::table( 'connections' );
		$wpdb->query( $wpdb->prepare(
			"UPDATE {$plots} p
			 INNER JOIN {$connections} c ON c.source_type = 'plot' AND c.source_id = p.id
			   AND c.target_type = 'tag' AND c.label = %s
			 SET p.held = 1, p.release_batch_id = %d
			 WHERE p.game_id = %d AND p.held = 0",
			$rumor_label,
			$batch_id,
			$game_id
		) );
		if ( $wpdb->last_error ) {
			error_log( 'Beyond Elysium: failed to migrate rumors to a release batch for game ' . $game_id . ': ' . $wpdb->last_error );
			return false;
		}

		return true;
	}

	/**
	 * Gives every character made before 1.0.0 its own plot, and moves its action
	 * rounds that sit under no plot beneath it (owner, 2026-09-15). A round a
	 * Storyteller nested under a plot stays where it is. Runs once; a character
	 * whose plot couldn't be written is logged, and the next upgrade tries again.
	 */
	public static function seed_character_plots(): void {
		if ( get_option( 'be_character_plots_seeded' ) ) {
			return;
		}

		global $wpdb;
		$characters  = self::table( 'characters' );
		$games       = self::table( 'games' );
		$plots       = self::table( 'plots' );
		$connections = self::table( 'connections' );

		// A character whose chronicle no longer exists has nowhere to keep a plot.
		$ids = $wpdb->get_col(
			"SELECT ch.id FROM {$characters} ch
			 INNER JOIN {$games} g ON g.slug = ch.owner_slug
			 WHERE ch.owner_type = 'chronicle'
			 ORDER BY ch.id"
		) ?: [];

		$failed = 0;
		foreach ( array_map( 'intval', $ids ) as $id ) {
			$plot_id = \BeyondElysium\Models\Character::ensure_plot( $id );
			if ( $plot_id === null ) {
				++$failed;
				error_log( "Beyond Elysium: failed to give character {$id} its own plot." );
				continue;
			}
			// 'apr_actor' is Services\Action_Allocator::ACTOR_LABEL.
			$moved = $wpdb->query( $wpdb->prepare(
				"UPDATE {$plots} p
				 INNER JOIN {$connections} c ON c.source_type = 'plot' AND c.source_id = p.id
				 SET p.parent_plot_id = %d
				 WHERE c.target_type = 'character' AND c.target_id = %d AND c.label = 'apr_actor'
				   AND p.game_date IS NOT NULL AND p.parent_plot_id IS NULL AND p.id <> %d",
				$plot_id,
				$id,
				$plot_id
			) );
			if ( $moved === false ) {
				++$failed;
				error_log( "Beyond Elysium: failed to move character {$id}'s action rounds under its plot: " . $wpdb->last_error );
			}
		}

		if ( $failed === 0 ) {
			update_option( 'be_character_plots_seeded', 1 );
		}
	}

	/**
	 * Records, for every chronicle copy of a catalog block made before copies
	 * recorded their own changes, how it differs from the catalog block it came
	 * from - so the next catalog update keeps those differences and brings the
	 * copy everything else (1.0.0-review F-034). Every difference is taken to be
	 * the chronicle's, so nothing it set is lost. Runs before the reseed, while
	 * the catalog still matches what the copy was made from as closely as it
	 * ever will; a copy already recorded is left alone.
	 */
	public static function record_fork_changes(): void {
		global $wpdb;
		$table = self::table( 'schema_blocks' );
		$rows  = $wpdb->get_results(
			"SELECT copy.id, copy.definition AS copy_definition, shared.definition AS shared_definition
			 FROM {$table} copy
			 INNER JOIN {$table} shared ON shared.slug = copy.slug AND shared.game_slug = ''
			 WHERE copy.game_slug <> '' AND copy.fork_changes IS NULL"
		) ?: [];

		foreach ( $rows as $row ) {
			$copy   = json_decode( (string) $row->copy_definition, true );
			$shared = json_decode( (string) $row->shared_definition, true );
			if ( ! is_array( $copy ) || ! is_array( $shared ) ) {
				continue;
			}
			$changes = Fork_Merge::changes_against( $shared, $copy );
			if ( $wpdb->update( $table, [ 'fork_changes' => wp_json_encode( $changes ) ], [ 'id' => (int) $row->id ] ) === false ) {
				error_log( 'Beyond Elysium: failed to record chronicle changes for schema block copy ' . (int) $row->id . ': ' . $wpdb->last_error );
			}
		}
	}

	/**
	 * Adds `fork_changes` to `schema_blocks`: what a chronicle's copy of a
	 * catalog block has changed, so catalog updates can reach the rest of it
	 * (1.0.0-review F-034). Null on a global block, and on a copy made before
	 * the column existed until `record_fork_changes()` fills it.
	 */
	public static function add_fork_changes_to_schema_blocks(): void {
		global $wpdb;
		$table      = self::table( 'schema_blocks' );
		$has_column = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.columns
				 WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'fork_changes'",
				$table
			)
		);
		if ( (int) $has_column === 0 ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN fork_changes longtext DEFAULT NULL AFTER storyteller_only" );
			if ( $wpdb->last_error ) {
				error_log( 'Beyond Elysium: failed to add fork_changes to schema_blocks: ' . $wpdb->last_error );
			}
		}
	}

	/**
	 * Marks every chronicle's copy of a Storyteller-only block Storyteller-only
	 * too, exactly once (1.0.0-review F-062). Copies were always made unflagged,
	 * which changed nothing while the shared block's flag hid the block in
	 * every chronicle; once a copy decides for its own chronicle, an old copy
	 * would show a hidden block to that chronicle's players. Runs once, so a
	 * chronicle that opens its copy afterwards keeps that choice.
	 */
	public static function carry_storyteller_only_to_forks(): void {
		if ( get_option( 'be_fork_storyteller_only_carried' ) ) {
			return;
		}

		global $wpdb;
		$table  = self::table( 'schema_blocks' );
		$result = $wpdb->query(
			"UPDATE {$table} copy
			 INNER JOIN {$table} shared ON shared.slug = copy.slug AND shared.game_slug = ''
			 SET copy.storyteller_only = 1
			 WHERE copy.game_slug <> '' AND shared.storyteller_only = 1 AND copy.storyteller_only = 0"
		);

		if ( $result === false ) {
			error_log( 'Beyond Elysium: failed to carry storyteller_only to chronicle copies: ' . $wpdb->last_error );
			return;
		}
		update_option( 'be_fork_storyteller_only_carried', 1 );
	}

	/**
	 * Makes every chronicle's copy of a tiered-power block price its levels
	 * cumulatively, exactly once (owner ruling "levels add up", 1.0.0-review
	 * F-040). The shared blocks get it from the reseed; a copy is never
	 * reseeded, and every copy made before this release carries the old seed's
	 * `sequential: false` without any chronicle having chosen it. After this
	 * runs, a chronicle that switches its copy back to flat pricing keeps it.
	 */
	public static function make_power_ladders_cumulative(): void {
		if ( get_option( 'be_power_ladders_cumulative' ) ) {
			return;
		}

		global $wpdb;
		$table = self::table( 'schema_blocks' );
		$rows  = $wpdb->get_results( "SELECT id, definition FROM {$table} WHERE section_type = 'tiered_power' AND game_slug <> ''" );

		// Every write is checked, not only the last: `last_error` knows about the last query alone, so
		// a failure ahead of a success was marked done with the rest (1.0.0-review F-065).
		$failed = false;
		foreach ( $rows ?: [] as $row ) {
			$definition = json_decode( (string) $row->definition, true );
			if ( ! is_array( $definition ) || ! empty( $definition['sequential'] ) ) {
				continue;
			}
			$definition['sequential'] = true;
			if ( $wpdb->update( $table, [ 'definition' => wp_json_encode( $definition ) ], [ 'id' => (int) $row->id ] ) === false ) {
				$failed = true;
				error_log( 'Beyond Elysium: failed to make power ladder cumulative for schema block ' . (int) $row->id . ': ' . $wpdb->last_error );
			}
		}

		if ( $failed || $rows === null ) {
			return;
		}
		update_option( 'be_power_ladders_cumulative', 1 );
	}

	/**
	 * Turns every approval rule stored as the retired `coordinator` level into
	 * a Storyteller (`st`) rule, in every schema block - shared and forked -
	 * exactly once (owner ruling, 1.0.0-review F-043). Nothing ever enforced
	 * the coordinator tier, so this changes what the editors show, not what
	 * happens to a change. Reads each definition as it is stored and rewrites
	 * only the rows that held one.
	 */
	public static function remove_coordinator_approvals(): void {
		if ( get_option( 'be_coordinator_tier_removed' ) ) {
			return;
		}

		global $wpdb;
		$table = self::table( 'schema_blocks' );
		$rows  = $wpdb->get_results( "SELECT id, definition FROM {$table} WHERE definition LIKE '%coordinator%'" );

		// Every write is checked, not only the last (F-065, as above).
		$failed = false;
		foreach ( $rows ?: [] as $row ) {
			$definition = json_decode( (string) $row->definition, true );
			if ( ! is_array( $definition ) ) {
				continue;
			}
			$changed    = false;
			$definition = self::coordinator_to_st( $definition, $changed );
			if ( $changed && $wpdb->update( $table, [ 'definition' => wp_json_encode( $definition ) ], [ 'id' => (int) $row->id ] ) === false ) {
				$failed = true;
				error_log( 'Beyond Elysium: failed to retire coordinator approvals in schema block ' . (int) $row->id . ': ' . $wpdb->last_error );
			}
		}

		if ( $failed || $rows === null ) {
			return;
		}
		update_option( 'be_coordinator_tier_removed', 1 );
	}

	/**
	 * Replaces `coordinator` with `st` wherever a definition names an approval
	 * level - an entry's `approval`/`approval_override`, a schedule entry's
	 * `approval`, or an `approval_rules` value - and nowhere else, so reason
	 * text that mentions a coordinator is left alone.
	 *
	 * @param array<mixed> $node
	 * @param bool         $changed Set true when anything was replaced.
	 * @return array<mixed>
	 */
	private static function coordinator_to_st( array $node, bool &$changed ): array {
		foreach ( $node as $key => $value ) {
			if ( is_array( $value ) ) {
				$node[ $key ] = $key === 'approval_rules'
					? array_map( static function ( $level ) use ( &$changed ) {
						if ( $level === 'coordinator' ) {
							$changed = true;
							return 'st';
						}
						return $level;
					}, $value )
					: self::coordinator_to_st( $value, $changed );
			} elseif ( in_array( $key, [ 'approval', 'approval_override' ], true ) && $value === 'coordinator' ) {
				$node[ $key ] = 'st';
				$changed      = true;
			}
		}
		return $node;
	}

	/**
	 * Gives a change's reviewer their own column. `notes` used to hold both the
	 * submitter's note and, once reviewed, the reviewer's - which replaced it
	 * (1.0.0-review F-032). Adds `review_notes`, then moves the reviewer text
	 * that reviewed rows already carry in `notes` into it, exactly once: after
	 * the split, a reviewed row's `notes` is the submitter's and must never be
	 * moved again.
	 */
	public static function add_review_notes_to_character_changes(): void {
		global $wpdb;

		$table = self::table( 'character_changes' );

		$has_column = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.columns
				 WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'review_notes'",
				$table
			)
		);
		if ( (int) $has_column === 0 ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN review_notes text AFTER notes" );
			if ( $wpdb->last_error ) {
				error_log( 'Beyond Elysium: failed to add review_notes to character_changes: ' . $wpdb->last_error );
				return;
			}
		}

		if ( get_option( 'be_review_notes_split' ) ) {
			return;
		}

		$wpdb->query(
			"UPDATE {$table} SET review_notes = notes, notes = NULL
			 WHERE reviewed_by IS NOT NULL AND status IN ('approved', 'rejected') AND review_notes IS NULL"
		);
		if ( $wpdb->last_error ) {
			error_log( 'Beyond Elysium: failed to move review notes: ' . $wpdb->last_error );
			return;
		}

		update_option( 'be_review_notes_split', 1 );
	}

	/**
	 * Adds the owbn_chronicle_post_id column and its unique index to an
	 * existing games table. Fresh installs get both from create_tables();
	 * this brings an upgrade up to the same shape. Column and index are
	 * each probed independently so a partially-applied prior run (e.g. the
	 * column exists but the index add failed) still completes correctly.
	 */
	public static function add_owbn_chronicle_post_id(): void {
		global $wpdb;

		$table = self::table( 'games' );

		$has_column = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.columns
				 WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'owbn_chronicle_post_id'",
				$table
			)
		);
		if ( (int) $has_column === 0 ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN owbn_chronicle_post_id bigint(20) unsigned DEFAULT NULL AFTER notifications_enabled" );
			if ( $wpdb->last_error ) {
				error_log( 'Beyond Elysium: failed to add owbn_chronicle_post_id to games: ' . $wpdb->last_error );
				return;
			}
		}

		$has_index = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.statistics
				 WHERE table_schema = DATABASE() AND table_name = %s AND index_name = 'owbn_chronicle_post'",
				$table
			)
		);
		if ( (int) $has_index === 0 ) {
			$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY owbn_chronicle_post (owbn_chronicle_post_id)" );
			if ( $wpdb->last_error ) {
				error_log( 'Beyond Elysium: failed to add owbn_chronicle_post index to games: ' . $wpdb->last_error );
			}
		}
	}

	/**
	 * Correlates each games row with the owbn_chronicle post it corresponds
	 * to, by exact slug match, wherever that correlation is not already
	 * set. Every failure mode leaves the row NULL rather than guessing:
	 * zero or more than one matching post, or a post already claimed by a
	 * different row, are each skipped and logged rather than resolved by a
	 * best guess. Runs on every upgrade (not one-time-guarded), so a
	 * chronicle post that appears later gets correlated on the next
	 * upgrade with no manual step - see
	 * BE_PROCESS/design/chronicle-rename-design.md §8.2 for the full reasoning.
	 */
	public static function backfill_owbn_chronicle_post_ids(): void {
		global $wpdb;

		$games_table = self::table( 'games' );
		$rows        = $wpdb->get_results( "SELECT id, slug FROM {$games_table} WHERE owbn_chronicle_post_id IS NULL" );

		foreach ( $rows as $row ) {
			$post_ids = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT p.ID FROM {$wpdb->posts} p
					 INNER JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = 'chronicle_slug'
					 WHERE p.post_type = 'owbn_chronicle'
					   AND p.post_status IN ('publish','private')
					   AND pm.meta_value = %s",
					$row->slug
				)
			);

			if ( count( $post_ids ) !== 1 ) {
				if ( count( $post_ids ) > 1 ) {
					error_log( "Beyond Elysium: games.slug '{$row->slug}' matches " . count( $post_ids ) . ' owbn_chronicle posts - ambiguous, left uncorrelated.' );
				}
				continue;
			}
			$post_id = (int) $post_ids[0];

			$already_claimed = (int) $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$games_table} WHERE owbn_chronicle_post_id = %d", $post_id )
			);
			if ( $already_claimed > 0 ) {
				error_log( "Beyond Elysium: owbn_chronicle post {$post_id} is already claimed by another games row - '{$row->slug}' left uncorrelated." );
				continue;
			}

			// Re-checked in the WHERE, not just the initial read, so this can never re-point an already-correlated row.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$games_table} SET owbn_chronicle_post_id = %d WHERE id = %d AND owbn_chronicle_post_id IS NULL",
					$post_id,
					$row->id
				)
			);
		}
	}

	/**
	 * Adds any Combo Disciplines missing from the seeded catalog.
	 *
	 * Appends "Eye for the Weakness of Steel" to vampire-combo-disciplines
	 * when it is not already present. Idempotent: does nothing once the
	 * entry exists.
	 */
	public static function add_missing_combo_disciplines(): void {
		$block = \BeyondElysium\Models\Schema_Block::find_by_slug( 'vampire-combo-disciplines' );
		if ( ! $block || $block->section_type !== 'trait_list' ) {
			return;
		}

		$definition = $block->definition;
		$items      = is_array( $definition->items ?? null ) ? $definition->items : [];

		foreach ( $items as $item ) {
			if ( strcasecmp( (string) ( $item->name ?? '' ), 'Eye for the Weakness of Steel' ) === 0 ) {
				return; // Already present; nothing to do.
			}
		}

		$items[]            = (object) [
			'name'   => 'Eye for the Weakness of Steel',
			'cost'   => '3',
			'source' => 'Kings of New York (kony) chronicle wiki',
		];
		$definition->items  = $items;
		\BeyondElysium\Models\Schema_Block::update( 'vampire-combo-disciplines', [ 'definition' => $definition ] );
	}

	/**
	 * Adds the `vampire-blood-magic` section to vampire's own `sheet_full` template for
	 * every install that seeded it before Blood Magic existed (BE_PROCESS/releases/0.99.2-workflow.md
	 * BM-9) - the layout-repair counterpart to add_missing_combo_disciplines() above, which
	 * does the same for a catalog rather than a layout. Deliberately its own narrow,
	 * one-off function rather than a generalization of repair_stale_default_layouts()'s
	 * width-based staleness check: VampireTemplateRepairTest::
	 * test_a_template_already_on_the_new_shape_is_left_untouched establishes that a template
	 * genuinely missing sections the current code defines is not, on that basis alone, stale
	 * - an admin's own deliberate trim via the structured editor looks identical. Only a
	 * chronicle's own customized (`is_system = 0`) template is left untouched here too,
	 * matching that same established rule.
	 *
	 * Every section shares `column: 1` (Seeder::build_layout_sections()'s "single flowing
	 * sequence" convention - visual placement comes from `order` + `width` alone), so
	 * inserting means shifting every later section's `order` up by one.
	 *
	 * Must run after Seeder::seed_schema_blocks() has seeded vampire-blood-magic, and before
	 * repair_stale_npc_layouts(), which propagates this same addition into npc_full.
	 * Idempotent: a template that already has the section is left untouched.
	 */
	public static function add_missing_blood_magic_template_section(): void {
		foreach ( \BeyondElysium\Models\Template::globals( [ 'stack_slug' => 'vampire', 'template_type' => 'sheet_full' ] ) as $template ) {
			/** @var object{id:int,is_system:int,layout:array} $template */
			if ( empty( $template->is_system ) ) {
				continue;
			}

			$sections = $template->layout['sections'] ?? [];
			if ( in_array( 'vampire-blood-magic', array_column( $sections, 'block_slug' ), true ) ) {
				continue; // Already has it.
			}

			$anchor_index = null;
			foreach ( $sections as $i => $section ) {
				if ( $section['block_slug'] === 'vampire-disciplines' ) {
					$anchor_index = $i;
					break;
				}
			}
			$insert_at = $anchor_index !== null ? $anchor_index + 1 : count( $sections );

			array_splice( $sections, $insert_at, 0, [
				[
					'block_slug' => 'vampire-blood-magic',
					'column'     => 1,
					'order'      => 0, // Renumbered below.
					'title'      => 'Blood Magic',
					'display'    => null,
					'collapsed'  => false,
					'width'      => 'full',
				],
			] );
			foreach ( $sections as $i => &$section ) {
				$section['order'] = $i + 1;
			}
			unset( $section );

			$layout             = $template->layout;
			$layout['sections'] = $sections;

			if ( ! \BeyondElysium\Models\Template::update( (int) $template->id, [ 'layout' => $layout ] ) ) {
				error_log( 'Beyond Elysium: failed to add vampire-blood-magic to sheet_full template id ' . (int) $template->id );
			}
		}
	}

	/**
	 * Removes the duplicate "Awakening of the Steel" power family from
	 * vampire-disciplines, keeping the tradition-prefixed "Dur An Ki:
	 * Awakening the Steel" entry, and rewrites any character's held pick
	 * stored under the bare name to the surviving name. Idempotent: does
	 * nothing once the bare entry is gone.
	 */
	public static function dedupe_awakening_of_the_steel(): void {
		$bare_name      = 'Awakening of the Steel';
		$surviving_name = 'Dur An Ki: Awakening the Steel';

		$block = \BeyondElysium\Models\Schema_Block::find_by_slug( 'vampire-disciplines' );
		if ( ! $block || $block->section_type !== 'tiered_power' ) {
			return;
		}

		$definition = $block->definition;
		$powers     = is_array( $definition->powers ?? null ) ? $definition->powers : [];

		$has_bare      = false;
		$has_surviving = false;
		$kept          = [];
		foreach ( $powers as $power ) {
			$name = (string) ( $power->name ?? '' );
			if ( strcasecmp( $name, $bare_name ) === 0 ) {
				$has_bare = true;
				continue; // Dropped; the surviving entry carries the same data.
			}
			if ( strcasecmp( $name, $surviving_name ) === 0 ) {
				$has_surviving = true;
			}
			$kept[] = $power;
		}

		if ( ! $has_bare ) {
			return; // Already deduped; nothing to do.
		}
		if ( ! $has_surviving ) {
			// No surviving entry to fall back to; refuse rather than lose data.
			return;
		}

		$definition->powers = $kept;
		\BeyondElysium\Models\Schema_Block::update( 'vampire-disciplines', [ 'definition' => $definition ] );

		self::rename_held_discipline_pick( $bare_name, $surviving_name );
	}

	/**
	 * Rewrites every character's held vampire-disciplines pick stored under
	 * the name `$from` to `$to`. Only the family name is changed; level,
	 * power_name and tier all carry over unchanged. Processes characters in
	 * batches so a large table does not exhaust memory in one request.
	 *
	 * @param string $from
	 * @param string $to
	 * @return void
	 */
	private static function rename_held_discipline_pick( string $from, string $to ): void {
		global $wpdb;
		$table = self::table( 'characters' );

		$last_id = 0;
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, sheet_data FROM {$table}
					 WHERE id > %d
					   AND JSON_CONTAINS_PATH( sheet_data, 'one', '$.\"vampire-disciplines\"' )
					 ORDER BY id ASC
					 LIMIT 200",
					$last_id
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$last_id = (int) $row['id'];
				$sheet   = json_decode( $row['sheet_data'], true );
				$changed = false;

				if ( ! empty( $sheet['vampire-disciplines'] ) && is_array( $sheet['vampire-disciplines'] ) ) {
					foreach ( $sheet['vampire-disciplines'] as $i => $held ) {
						if ( is_array( $held ) && isset( $held['name'] ) && strcasecmp( (string) $held['name'], $from ) === 0 ) {
							$sheet['vampire-disciplines'][ $i ]['name'] = $to;
							$changed                                    = true;
						}
					}
				}

				if ( $changed ) {
					$wpdb->update(
						$table,
						[ 'sheet_data' => wp_json_encode( $sheet ) ],
						[ 'id' => (int) $row['id'] ],
						[ '%s' ],
						[ '%d' ]
					);
				}
			}
		} while ( count( $rows ) === 200 );
	}

	/**
	 * Adds an index on the `name` column to every table whose default
	 * listing order sorts by name, so that sort can be satisfied from the
	 * index instead of a filesort. Idempotent: skips a table that already
	 * has the index.
	 */
	public static function add_name_order_indexes(): void {
		foreach ( [ 'schema_blocks', 'creature_stacks', 'games', 'world_objects' ] as $short_name ) {
			self::add_index_if_missing( self::table( $short_name ), 'idx_name_order', 'name' );
		}
	}

	/**
	 * Adds a plain KEY index to a table if it does not already exist.
	 * Checks information_schema.statistics for the index name first, then
	 * issues an ALTER TABLE ADD KEY and logs any resulting database error.
	 */
	private static function add_index_if_missing( string $table, string $index_name, string $column ): void {
		global $wpdb;

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.statistics
				 WHERE table_schema = DATABASE() AND table_name = %s AND index_name = %s",
				$table,
				$index_name
			)
		);

		if ( (int) $exists > 0 ) {
			return;
		}

		$wpdb->query( "ALTER TABLE {$table} ADD KEY {$index_name} ({$column})" );

		if ( $wpdb->last_error ) {
			error_log( "Beyond Elysium: failed to add {$index_name} to {$table}: " . $wpdb->last_error );
		}
	}

	/**
	 * Renames the `werewolf-gifts` sheet_data key to `fera-gifts` for every
	 * fera/bete character that still has held picks stored under the old
	 * key. Only touches a row that has the old key and not yet the new
	 * one, so a repeat run or a fresh install is a harmless no-op.
	 */
	public static function rename_fera_gifts_sheet_data_key(): void {
		global $wpdb;

		$table = self::table( 'characters' );

		$wpdb->query(
			"UPDATE {$table}
			 SET sheet_data = JSON_REMOVE(
			     JSON_SET( sheet_data, '$.\"fera-gifts\"', JSON_EXTRACT( sheet_data, '$.\"werewolf-gifts\"' ) ),
			     '$.\"werewolf-gifts\"'
			 )
			 WHERE stack_slug IN ( 'fera', 'bete' )
			   AND JSON_CONTAINS_PATH( sheet_data, 'one', '$.\"werewolf-gifts\"' )
			   AND NOT JSON_CONTAINS_PATH( sheet_data, 'one', '$.\"fera-gifts\"' )"
		);

		if ( $wpdb->last_error ) {
			error_log( 'Beyond Elysium: failed to rename fera-gifts sheet_data key: ' . $wpdb->last_error );
		}
	}

	/**
	 * Rewrites a held Gift's stored `name` from its old compound label
	 * (e.g. "Silver Fangs: Falcon's Grasp (basic)") to the plain catalog
	 * name (e.g. "Falcon's Grasp") for werewolf-gifts and fera-gifts.
	 * Only rewrites a name that both changes under extraction and matches
	 * a real entry in the freshly reseeded catalog; anything else is left
	 * untouched. Processes characters in batches.
	 */
	public static function migrate_held_gift_names_to_grouped_fields(): void {
		global $wpdb;

		$table = self::table( 'characters' );

		$catalogs = [];
		foreach ( [ 'werewolf-gifts', 'fera-gifts' ] as $slug ) {
			$block = \BeyondElysium\Models\Schema_Block::find_by_slug( $slug );
			if ( ! $block ) {
				continue;
			}
			$names = [];
			foreach ( $block->definition->items as $item ) {
				$names[ $item->name ] = true;
			}
			$catalogs[ $slug ] = $names;
		}

		if ( empty( $catalogs ) ) {
			return;
		}

		$last_id = 0;
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, sheet_data FROM {$table}
					 WHERE id > %d
					   AND ( JSON_CONTAINS_PATH( sheet_data, 'one', '$.\"werewolf-gifts\"' )
					      OR JSON_CONTAINS_PATH( sheet_data, 'one', '$.\"fera-gifts\"' ) )
					 ORDER BY id ASC
					 LIMIT 200",
					$last_id
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$last_id = (int) $row['id'];
				$sheet   = json_decode( $row['sheet_data'], true );
				$changed = false;

				foreach ( $catalogs as $slug => $names ) {
					if ( empty( $sheet[ $slug ] ) || ! is_array( $sheet[ $slug ] ) ) {
						continue;
					}
					foreach ( $sheet[ $slug ] as $i => $held ) {
						if ( ! is_array( $held ) || ! isset( $held['name'] ) ) {
							continue;
						}
						$plain = self::extract_plain_gift_name( $held['name'] );
						if ( $plain === $held['name'] || ! isset( $names[ $plain ] ) ) {
							continue;
						}
						$sheet[ $slug ][ $i ]['name'] = $plain;
						$changed                      = true;
					}
				}

				if ( $changed ) {
					$wpdb->update(
						$table,
						[ 'sheet_data' => wp_json_encode( $sheet ) ],
						[ 'id' => (int) $row['id'] ],
						[ '%s' ],
						[ '%d' ]
					);
				}
			}
		} while ( count( $rows ) === 200 );
	}

	/**
	 * Strips a Gift's compound-label prefix and tier suffix, leaving just
	 * the plain catalog name - e.g. "Gurahl (Ursine): Heightened Senses
	 * (basic)" becomes "Heightened Senses". A name with no ": " prefix
	 * passes through with only the tier suffix stripped.
	 */
	private static function extract_plain_gift_name( string $stored_name ): string {
		$name       = $stored_name;
		$last_colon = strrpos( $name, ': ' );
		if ( $last_colon !== false ) {
			$name = substr( $name, $last_colon + 2 );
		}
		return trim( (string) preg_replace( '/\s*\([^()]*\)\s*$/', '', $name ) );
	}

	/**
	 * The four Assamite caste names Blood Magic deliberately excludes from tradition
	 * splitting everywhere - Seeder.php's met-csv-map.php config, this migration, and
	 * migrate_blood_magic_held_picks() all agree on this exact literal list. A caste name
	 * happens to contain ": "-adjacent punctuation of its own kind but is never a
	 * tradition prefix - the same trap D39 avoided populating vampire-clan-disciplines.php.
	 *
	 * @return string[]
	 */
	private static function blood_magic_excluded_power_names(): array {
		return [
			'Quietus, Cruscitus / Warrior', 'Quietus, Hematus / Vizier',
			'Quietus, Minhit Dume / Vizier', 'Quietus, Sorcerer',
		];
	}

	/**
	 * Splits a stale, pre-Blood-Magic chronicle fork of vampire-disciplines (a
	 * game-scoped copy made before the Blood Magic redesign, BE_PROCESS/releases/0.99.2-workflow.md)
	 * the same way Seeder::build_met_blood_magic_powers() already split the global row: any
	 * power whose name is "{Tradition}: {Path}" - excluding the four Assamite caste names,
	 * see blood_magic_excluded_power_names() - moves to that same game's own
	 * vampire-blood-magic fork, created via Schema_Block::find_or_create_fork_for_game() if
	 * the chronicle has no fork of it yet.
	 *
	 * Deliberately does NOT re-run the CSV-driven canonical-path/ladder-merge logic against
	 * a fork's own content - a fork may not carry every sibling tradition's data to merge
	 * against, and this must never lose or alter a chronicle's real customization. Each
	 * power is transplanted one-to-one, reshaped to the new field convention (name split on
	 * the first ": ", tradition recorded in a one-entry `traditions` map) - UNLESS a power
	 * of that same bare name already exists in the target vampire-blood-magic fork (the
	 * common case: find_or_create_fork_for_game() seeds a brand-new fork from the already-
	 * correct global 111-path catalog), in which case only the tradition entry is merged
	 * into the existing power rather than adding a duplicate.
	 *
	 * Idempotent: a fork already free of colon-prefixed powers is left untouched.
	 */
	public static function migrate_blood_magic_schema_forks(): void {
		global $wpdb;
		$table    = self::table( 'schema_blocks' );
		$excluded = self::blood_magic_excluded_power_names();

		$forks = $wpdb->get_results(
			"SELECT game_slug FROM {$table} WHERE slug = 'vampire-disciplines' AND game_slug IS NOT NULL AND game_slug != ''",
			ARRAY_A
		);

		foreach ( $forks as $row ) {
			$game_slug = (string) $row['game_slug'];
			$fork      = \BeyondElysium\Models\Schema_Block::find_for_game( 'vampire-disciplines', $game_slug );
			if ( ! $fork || ( $fork->game_slug ?? '' ) !== $game_slug ) {
				continue; // Only ever operate on a real fork row, never the global one.
			}

			$powers      = is_array( $fork->definition->powers ?? null ) ? $fork->definition->powers : [];
			$ordinary    = [];
			$blood_magic = [];

			foreach ( $powers as $power ) {
				$power = (array) $power;
				$name  = (string) ( $power['name'] ?? '' );
				$colon = strpos( $name, ': ' );

				if ( $colon === false || in_array( $name, $excluded, true ) ) {
					$ordinary[] = $power;
					continue;
				}

				$tradition           = trim( substr( $name, 0, $colon ) );
				$power['name']       = trim( substr( $name, $colon + 2 ) );
				$power['traditions'] = [ $tradition => null ];
				$blood_magic[]       = $power;
			}

			if ( $blood_magic === [] ) {
				continue; // Already clean - nothing colon-prefixed to migrate.
			}

			$fork_definition           = (array) $fork->definition;
			$fork_definition['powers'] = $ordinary;
			\BeyondElysium\Models\Schema_Block::update( 'vampire-disciplines', [ 'definition' => (object) $fork_definition ], $game_slug );

			$bm_fork = \BeyondElysium\Models\Schema_Block::find_or_create_fork_for_game( 'vampire-blood-magic', $game_slug );
			if ( ! $bm_fork ) {
				continue; // vampire-blood-magic does not exist globally yet - nothing to fork into.
			}

			$bm_definition         = (array) $bm_fork->definition;
			$bm_powers             = is_array( $bm_definition['powers'] ?? null ) ? $bm_definition['powers'] : [];
			$bm_traditions         = is_array( $bm_definition['traditions'] ?? null ) ? $bm_definition['traditions'] : [];

			foreach ( $blood_magic as $transplant ) {
				$matched_index = null;
				foreach ( $bm_powers as $i => $existing ) {
					$existing_name = (string) ( is_array( $existing ) ? ( $existing['name'] ?? '' ) : ( $existing->name ?? '' ) );
					if ( strcasecmp( $existing_name, $transplant['name'] ) === 0 ) {
						$matched_index = $i;
						break;
					}
				}

				$tradition_key = array_key_first( $transplant['traditions'] );
				if ( $matched_index !== null ) {
					$existing                          = (array) $bm_powers[ $matched_index ];
					$existing['traditions']             = array_merge( (array) ( $existing['traditions'] ?? [] ), $transplant['traditions'] );
					$bm_powers[ $matched_index ]        = $existing;
				} else {
					$bm_powers[] = $transplant;
				}
				$bm_traditions[] = $tradition_key;
			}

			$bm_definition['powers']     = $bm_powers;
			$bm_definition['traditions'] = array_values( array_unique( $bm_traditions ) );
			sort( $bm_definition['traditions'] );

			\BeyondElysium\Models\Schema_Block::update( 'vampire-blood-magic', [ 'definition' => (object) $bm_definition ], $game_slug );
		}
	}

	/**
	 * Moves a character's own held vampire-disciplines pick to vampire-blood-magic when its
	 * stored name identifies it as a pre-Blood-Magic tradition-prefixed pick, splitting the
	 * name into the bare canonical path plus a `tradition` field. Covers both real shapes a
	 * character can hold:
	 *
	 *   - A catalog-matched pick stored as "{Tradition}: {Path}" - the ordinary case, its
	 *     tradition text already canonically spelled since it came from an exact catalog
	 *     match rather than raw free text.
	 *   - A keep_custom pick from before Blood Magic existed (D41/Decision 074), stored
	 *     with the tradition in `name` and the actual path in `power_name` - Chase
	 *     Ashford's own real committed import (data-samples/1506_chase_ashford_.gex) is
	 *     exactly this shape. Real .gex exports spell a tradition inconsistently (verified
	 *     2026-09-11 - "Dur-An-Ki", "Sadhanna"), so `name` is matched against the real
	 *     tradition list the same normalized-then-single-unambiguous-fuzzy way
	 *     Trait_Mapper::normalize_blood_magic_tradition() matches on import - and, same as
	 *     that method, a `name` matching neither is left alone rather than guessed at,
	 *     since it may simply be some other, unrelated keep_custom pick.
	 *
	 * Must run after Seeder::seed_schema_blocks() has seeded vampire-blood-magic and after
	 * migrate_blood_magic_schema_forks(), for the same "reshape, never re-derive" reasoning
	 * that migration follows - see its own docblock.
	 *
	 * Idempotent: a character with nothing to migrate is never written to. Processes
	 * characters in batches, matching migrate_held_gift_names_to_grouped_fields()'s pattern.
	 */
	public static function migrate_blood_magic_held_picks(): void {
		global $wpdb;
		$table    = self::table( 'characters' );
		$excluded = self::blood_magic_excluded_power_names();

		// The real, curated 14-tradition list (Seeder::build_met_blood_magic_powers()) -
		// duplicated here rather than read from the seeded catalog, since a character's
		// own stored data must migrate consistently regardless of what any one chronicle's
		// catalog fork currently contains.
		$known_traditions = [
			'Akhu', 'Bacaban', 'Dark Thaumaturgy', 'Dur An Ki', 'Judicium', 'Koldunism', 'Mortis',
			'Nahuallotl', 'Necromancy', 'Sadhana', 'Sielanic', 'Thaumaturgy (Anarch)', 'Thaumaturgy (Camarilla)', 'Wanga',
		];

		$last_id = 0;
		do {
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, sheet_data FROM {$table}
					 WHERE id > %d
					   AND JSON_CONTAINS_PATH( sheet_data, 'one', '$.\"vampire-disciplines\"' )
					 ORDER BY id ASC
					 LIMIT 200",
					$last_id
				),
				ARRAY_A
			);

			foreach ( $rows as $row ) {
				$last_id = (int) $row['id'];
				$sheet   = json_decode( $row['sheet_data'], true );
				$changed = false;

				$held = is_array( $sheet['vampire-disciplines'] ?? null ) ? $sheet['vampire-disciplines'] : [];
				$stay = [];
				$move = [];

				foreach ( $held as $entry ) {
					if ( ! is_array( $entry ) || ! isset( $entry['name'] ) ) {
						$stay[] = $entry;
						continue;
					}
					$name  = (string) $entry['name'];
					$colon = strpos( $name, ': ' );

					if ( $colon !== false && ! in_array( $name, $excluded, true ) ) {
						$entry['tradition'] = trim( substr( $name, 0, $colon ) );
						$entry['name']      = trim( substr( $name, $colon + 2 ) );
						$move[]             = $entry;
						$changed            = true;
						continue;
					}

					$tradition_match = ( ! empty( $entry['custom'] ) && isset( $entry['power_name'] ) )
						? self::match_known_blood_magic_tradition( $name, $known_traditions )
						: null;
					if ( $tradition_match !== null ) {
						$entry['tradition'] = $tradition_match;
						$entry['name']      = $entry['power_name'];
						unset( $entry['power_name'] );
						$move[]  = $entry;
						$changed = true;
						continue;
					}

					$stay[] = $entry;
				}

				if ( ! $changed ) {
					continue;
				}

				$sheet['vampire-disciplines'] = $stay;
				$sheet['vampire-blood-magic'] = array_merge(
					is_array( $sheet['vampire-blood-magic'] ?? null ) ? $sheet['vampire-blood-magic'] : [],
					$move
				);

				$wpdb->update(
					$table,
					[ 'sheet_data' => wp_json_encode( $sheet ) ],
					[ 'id' => (int) $row['id'] ],
					[ '%s' ],
					[ '%d' ]
				);
			}
		} while ( count( $rows ) === 200 );
	}

	/**
	 * Matches a raw string (e.g. a keep_custom pick's `name` field, which pre-Blood-Magic
	 * held a tradition rather than a power name) against the real tradition list: an exact
	 * match once case/whitespace/punctuation is normalized, then a fuzzy match only when it
	 * is the single unambiguous candidate - the same two-tier rule
	 * Trait_Mapper::normalize_blood_magic_tradition() applies on import, duplicated here in
	 * miniature since this is one-off migration code with no reason to depend on the
	 * Services layer. Returns null (never the raw text) when nothing matches confidently -
	 * for this migration, null means "this probably isn't a tradition at all", not "keep it
	 * as typed", since an unmatched name here decides whether the whole entry moves.
	 *
	 * @param string[] $known_traditions
	 */
	private static function match_known_blood_magic_tradition( string $raw, array $known_traditions ): ?string {
		foreach ( $known_traditions as $tradition ) {
			if ( \BeyondElysium\Services\Fuzzy_Matcher::normalize( $raw ) === \BeyondElysium\Services\Fuzzy_Matcher::normalize( $tradition ) ) {
				return $tradition;
			}
		}
		$suggestions = \BeyondElysium\Services\Fuzzy_Matcher::suggest( $raw, $known_traditions );
		return count( $suggestions ) === 1 ? $suggestions[0] : null;
	}

	/**
	 * Assigns a UUIDv7 to every character row that does not already have one.
	 *
	 * Processes rows in batches so a large chronicle does not exhaust memory
	 * or run long enough to hit a request timeout during activation.
	 */
	public static function backfill_character_uuids(): void {
		global $wpdb;

		$table = self::table( 'characters' );

		do {
			$ids = $wpdb->get_col(
				"SELECT id FROM {$table} WHERE uuid = '' OR uuid IS NULL LIMIT 500"
			);

			foreach ( $ids as $id ) {
				$wpdb->update(
					$table,
					[ 'uuid' => \BeyondElysium\Utils\Uuid::v7() ],
					[ 'id' => (int) $id ],
					[ '%s' ],
					[ '%d' ]
				);
			}
		} while ( count( $ids ) === 500 );
	}

	/**
	 * Adds the UNIQUE index on be_characters.uuid, once every row holds a
	 * value. Skips adding the index if it already exists, and refuses if
	 * any row still has a blank uuid.
	 */
	public static function add_character_uuid_index(): void {
		global $wpdb;

		$table = self::table( 'characters' );

		$exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.statistics
				 WHERE table_schema = DATABASE() AND table_name = %s AND index_name = 'uq_uuid'",
				$table
			)
		);

		if ( (int) $exists > 0 ) {
			return;
		}

		$blank = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$table} WHERE uuid = '' OR uuid IS NULL"
		);

		if ( $blank > 0 ) {
			error_log(
				'Beyond Elysium: cannot add uq_uuid index, ' . $blank
				. ' character row(s) still have no uuid.'
			);
			return;
		}

		$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY uq_uuid (uuid)" );

		if ( $wpdb->last_error ) {
			error_log( 'Beyond Elysium: failed to add uq_uuid index: ' . $wpdb->last_error );
		}
	}

	/**
	 * Adds the `storyteller_only` column to `schema_blocks`.
	 *
	 * Marks a block whose contents must never reach a viewer without
	 * `be_manage_characters`, both in a resolved template layout and in a
	 * character's own `sheet_data`. Skips the change when the column is
	 * already present, so this is safe to run on every activation and upgrade.
	 */
	public static function add_storyteller_only_to_schema_blocks(): void {
		global $wpdb;

		$table = self::table( 'schema_blocks' );

		$has_column = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.columns
				 WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'storyteller_only'",
				$table
			)
		);
		if ( (int) $has_column === 0 ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN storyteller_only tinyint(1) NOT NULL DEFAULT 0 AFTER is_system" );
			if ( $wpdb->last_error ) {
				error_log( 'Beyond Elysium: failed to add storyteller_only to schema_blocks: ' . $wpdb->last_error );
			}
		}
	}

	/**
	 * Adds the `game_slug` column to `schema_blocks` and replaces its
	 * unique index on `slug` alone with a unique index on `(slug,
	 * game_slug)`. An empty string means the global/system scope. Skips
	 * each step that has already been applied, so this is safe to run on
	 * every activation and upgrade.
	 */
	public static function add_schema_block_game_scoping(): void {
		global $wpdb;

		$table = self::table( 'schema_blocks' );

		$has_column = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.columns
				 WHERE table_schema = DATABASE() AND table_name = %s AND column_name = 'game_slug'",
				$table
			)
		);
		if ( (int) $has_column === 0 ) {
			$wpdb->query( "ALTER TABLE {$table} ADD COLUMN game_slug varchar(100) NOT NULL DEFAULT '' AFTER slug" );
			if ( $wpdb->last_error ) {
				error_log( 'Beyond Elysium: failed to add game_slug to schema_blocks: ' . $wpdb->last_error );
				return;
			}
		}

		$has_old_index = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.statistics
				 WHERE table_schema = DATABASE() AND table_name = %s AND index_name = 'slug'",
				$table
			)
		);
		if ( (int) $has_old_index > 0 ) {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX slug" );
			if ( $wpdb->last_error ) {
				error_log( 'Beyond Elysium: failed to drop old slug index on schema_blocks: ' . $wpdb->last_error );
				return;
			}
		}

		$has_new_index = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.statistics
				 WHERE table_schema = DATABASE() AND table_name = %s AND index_name = 'slug_game'",
				$table
			)
		);
		if ( (int) $has_new_index === 0 ) {
			$wpdb->query( "ALTER TABLE {$table} ADD UNIQUE KEY slug_game (slug, game_slug)" );
			if ( $wpdb->last_error ) {
				error_log( 'Beyond Elysium: failed to add slug_game index to schema_blocks: ' . $wpdb->last_error );
			}
		}
	}

	/**
	 * Converts an existing `characters.xp_unspent` column from unsigned to
	 * signed, so a character's XP balance can go negative. CREATE TABLE
	 * already declares the column signed for a fresh install; this brings
	 * an existing table in line. Safe to run repeatedly.
	 */
	public static function make_xp_unspent_signed(): void {
		global $wpdb;

		$table = self::table( 'characters' );

		$is_unsigned = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM information_schema.columns
				 WHERE table_schema = DATABASE() AND table_name = %s
				   AND column_name = 'xp_unspent' AND column_type LIKE '%%unsigned%%'",
				$table
			)
		);

		if ( (int) $is_unsigned === 0 ) {
			return;
		}

		$wpdb->query( "ALTER TABLE {$table} MODIFY COLUMN xp_unspent int NOT NULL DEFAULT 0" );

		if ( $wpdb->last_error ) {
			error_log( 'Beyond Elysium: failed to make xp_unspent signed: ' . $wpdb->last_error );
		}
	}

	/**
	 * Rebuilds a creature stack's default `sheet_full` template layout when
	 * it is out of date, so a correction to default_template_sections()
	 * reaches a chronicle that already seeded its template before the fix
	 * shipped. Only touches system (non-customized) global templates, and
	 * only when at least one section is missing its `width` value, a
	 * `title_refs` reference the current default expects, or its
	 * `block_slug` no longer exists in the current layout.
	 */
	public static function repair_stale_default_layouts(): void {
		foreach ( \BeyondElysium\Models\Creature_Stack::all() as $stack ) {
			foreach ( \BeyondElysium\Models\Template::globals( [
				'stack_slug'    => $stack->slug,
				'template_type' => 'sheet_full',
			] ) as $template ) {
				/** @var object{id:int,is_system:int,layout:array} $template */
				if ( empty( $template->is_system ) ) {
					continue;
				}

				$sections = $template->layout['sections'] ?? [];
				$layout   = Seeder::rebuild_default_layout_for_stack( $stack->slug );
				if ( ! $layout ) {
					continue;
				}

				$fresh_by_slug = [];
				foreach ( $layout['sections'] ?? [] as $fresh_section ) {
					$fresh_by_slug[ $fresh_section['block_slug'] ] = $fresh_section;
				}

				// Detects a renamed or removed block_slug that a plain field-presence check
				// would miss. Deliberately one-directional only (stored minus fresh, never
				// the reverse) - VampireTemplateRepairTest::test_a_template_already_on_the_new_shape_is_left_untouched
				// establishes that an is_system template genuinely missing sections the
				// current code defines (an admin's own deliberate trim via the structured
				// editor is exactly this) must NOT be treated as stale on that basis alone.
				// Adding a brand-new default section (e.g. vampire-blood-magic,
				// BE_PROCESS/releases/0.99.2-workflow.md BM-9) to an already-current, already-seeded
				// template needs its own dedicated, narrowly-scoped repair instead - see
				// Seeder::add_missing_template_section().
				$has_renamed_slug = (bool) array_diff( array_column( $sections, 'block_slug' ), array_keys( $fresh_by_slug ) );

				$up_to_date = ! empty( $sections ) && ! $has_renamed_slug && ! array_filter(
					$sections,
					static function ( $section ) use ( $fresh_by_slug ) {
						if ( empty( $section['width'] ) ) {
							return true;
						}
						$fresh_section = $fresh_by_slug[ $section['block_slug'] ] ?? null;
						return $fresh_section && ! empty( $fresh_section['title_refs'] ) && empty( $section['title_refs'] );
					}
				);
				if ( $up_to_date ) {
					continue;
				}

				if ( ! \BeyondElysium\Models\Template::update( (int) $template->id, [ 'layout' => $layout ] ) ) {
					error_log( 'Beyond Elysium: failed to repair ' . $stack->slug . ' template id ' . (int) $template->id );
				}
			}
		}
	}

	/**
	 * Repairs an existing, already-seeded `npc_full` template whose section list has
	 * fallen behind its stack's current `sheet_full` - the same class of drift
	 * repair_stale_default_layouts() fixes for `sheet_full` itself, needed separately
	 * because Seeder::seed_npc_templates() only ever builds `npc_full` once (guarded by
	 * "already exists? skip") and never revisits it afterward. Blood Magic's own new
	 * `vampire-blood-magic` section (BE_PROCESS/releases/0.99.2-workflow.md BM-9) would otherwise
	 * reach a fresh `sheet_full` but never an already-seeded `npc_full`.
	 *
	 * Merges rather than rebuilds from scratch: every section `sheet_full` currently has
	 * that `npc_full` is missing is appended (in `sheet_full`'s own order/column
	 * position), and the `npc-roleplaying-notes` section - always last, full-width - is
	 * moved to the end again rather than left stranded in the middle. A section
	 * `npc_full` already has is left exactly as it is, so a chronicle would never lose
	 * npc_full-specific fields this way (none exist today, but this must not assume that
	 * stays true forever).
	 *
	 * Must run after repair_stale_default_layouts(), which is what makes the `sheet_full`
	 * this reads from correct in the first place.
	 */
	public static function repair_stale_npc_layouts(): void {
		foreach ( \BeyondElysium\Models\Creature_Stack::all() as $stack ) {
			foreach ( \BeyondElysium\Models\Template::globals( [
				'stack_slug'    => $stack->slug,
				'template_type' => 'npc_full',
			] ) as $template ) {
				/** @var object{id:int,is_system:int,layout:array} $template */
				if ( empty( $template->is_system ) ) {
					continue;
				}

				$sheet_full = \BeyondElysium\Models\Template::resolve( $stack->slug, 'sheet_full', null );
				$fresh_sections = $sheet_full->layout['sections'] ?? [];
				if ( ! $fresh_sections ) {
					continue;
				}

				$sections    = $template->layout['sections'] ?? [];
				$have_slugs  = array_column( $sections, 'block_slug' );
				$missing     = array_values( array_filter(
					$fresh_sections,
					static fn( $section ) => ! in_array( $section['block_slug'], $have_slugs, true )
				) );
				if ( ! $missing ) {
					continue; // Already has every section sheet_full does.
				}

				// The notes section must stay last, full-width, regardless of where the
				// newly-appended sheet_full sections land.
				$notes = array_values( array_filter( $sections, static fn( $s ) => $s['block_slug'] === 'npc-roleplaying-notes' ) );
				$rest  = array_values( array_filter( $sections, static fn( $s ) => $s['block_slug'] !== 'npc-roleplaying-notes' ) );

				// $missing is already confirmed non-empty above, so this list always has at least one order value.
				$next_order = 1 + max( array_column( array_merge( $rest, $missing ), 'order' ) );
				foreach ( $notes as &$note_section ) {
					$note_section['order'] = $next_order++;
				}
				unset( $note_section );

				$layout             = $template->layout;
				$layout['sections'] = array_merge( $rest, $missing, $notes );

				if ( ! \BeyondElysium\Models\Template::update( (int) $template->id, [ 'layout' => $layout ] ) ) {
					error_log( 'Beyond Elysium: failed to repair npc_full for ' . $stack->slug . ' template id ' . (int) $template->id );
				}
			}
		}
	}

	/**
	 * One-time backfill of `be_game_members` from each user's existing
	 * site-wide access: every user holding `be_manage_characters` becomes
	 * `hst` in every game, and every character's own owning WordPress user
	 * becomes `player` in that character's game. Runs once, guarded by the
	 * `be_game_members_backfilled` option, so membership removed afterward
	 * by an operator is never silently restored.
	 */
	public static function backfill_game_members(): void {
		if ( get_option( 'be_game_members_backfilled' ) ) {
			return;
		}

		global $wpdb;
		$members_table    = self::table( 'game_members' );
		$games_table      = self::table( 'games' );
		$characters_table = self::table( 'characters' );

		// Pass 1: managers -> hst, every game.
		$managers = get_users(
			[
				'capability' => 'be_manage_characters',
				'fields'     => 'ID',
			]
		);

		foreach ( \BeyondElysium\Models\Game::all() as $game ) {
			foreach ( $managers as $user_id ) {
				$wpdb->query(
					$wpdb->prepare(
						"INSERT IGNORE INTO {$members_table} (game_id, wp_user_id, role, created_at) VALUES (%d, %d, 'hst', %s)",
						(int) $game->id,
						(int) $user_id,
						current_time( 'mysql' )
					)
				);
			}
		}

		// Pass 2: character owners -> player, their own game only; one set-based INSERT...SELECT.
		$wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$members_table} (game_id, wp_user_id, role, created_at)
				 SELECT DISTINCT g.id, c.wp_user_id, 'player', %s
				 FROM {$characters_table} c
				 INNER JOIN {$games_table} g ON g.slug = c.owner_slug
				 WHERE c.wp_user_id IS NOT NULL AND c.owner_type = 'chronicle'",
				current_time( 'mysql' )
			)
		);

		update_option( 'be_game_members_backfilled', 1 );
	}

	/**
	 * Run migrations when the installed schema version is behind the code.
	 *
	 * register_activation_hook only fires on activation, so a plugin file update alone
	 * would otherwise never migrate. Called on every request via Plugin::init().
	 */
	public static function maybe_upgrade(): void {
		$installed     = get_option( self::VERSION_OPTION, '0.0.0' );
		$fresh_install = get_option( self::VERSION_OPTION ) === false;

		if ( version_compare( $installed, self::DB_VERSION, '>=' ) ) {
			return;
		}

		// One request runs the upgrade at a time; the rest carry on until it is recorded.
		if ( ! Option_Lock::claim( self::UPGRADE_LOCK_OPTION, self::UPGRADE_LOCK_TTL ) ) {
			return;
		}

		try {
			self::run_upgrade( $fresh_install );
		} catch ( \Throwable $e ) {
			// Recorded, logged, and left locked until the lock goes stale: a step that fails every
			// time retries every few minutes instead of failing every request (1.0.0-review F-064).
			update_option( self::UPGRADE_ERROR_OPTION, [
				'version' => self::DB_VERSION,
				'message' => $e->getMessage(),
				'at'      => time(),
			], false );
			error_log( 'Beyond Elysium: upgrade to ' . self::DB_VERSION . ' did not finish: ' . $e->getMessage() );
			return;
		}

		update_option( self::VERSION_OPTION, self::DB_VERSION );
		delete_option( self::UPGRADE_ERROR_OPTION );
		Option_Lock::release( self::UPGRADE_LOCK_OPTION );
	}

	/**
	 * Every step of an upgrade, in order. Throws if a step does; the caller
	 * records the new schema version only once this returns.
	 *
	 * @param bool $fresh_install Whether no schema version was recorded before this upgrade.
	 */
	private static function run_upgrade( bool $fresh_install ): void {
		self::create_tables();

		// Refreshes system schema blocks/stacks so a block-map correction reaches existing installs.
		Seeder::seed_schema_blocks();

		// Must run after seed_schema_blocks(), which would otherwise overwrite this addition.
		self::add_missing_combo_disciplines();

		// Must run after seed_schema_blocks(), for the same reason as the call above.
		self::dedupe_awakening_of_the_steel();

		// Must run after seed_schema_blocks() has refreshed the werewolf-gifts/fera-gifts catalogs.
		self::migrate_held_gift_names_to_grouped_fields();

		// Blood magic (BE_PROCESS/releases/0.99.2-workflow.md, BM-8): must run after
		// seed_schema_blocks() has seeded the global vampire-blood-magic catalog, and the
		// schema-fork split must run before the held-picks migration reads it.
		self::migrate_blood_magic_schema_forks();
		self::migrate_blood_magic_held_picks();

		Seeder::seed_creature_stacks();
		Seeder::reconcile_stack_blocks();
		Seeder::seed_default_templates();

		// Must run after seed_default_templates(), whose sheet_full layout it builds on.
		Seeder::seed_npc_templates();

		// Must run after seed_schema_blocks(), since a rebuilt layout can reference a new block slug.
		self::repair_stale_default_layouts();

		// Must run after seed_schema_blocks() has seeded vampire-blood-magic, and before
		// repair_stale_npc_layouts(), which propagates this same addition into npc_full.
		self::add_missing_blood_magic_template_section();

		// Must run after repair_stale_default_layouts() and the call above, which are what
		// make the sheet_full layout this reads from correct in the first place.
		self::repair_stale_npc_layouts();

		// Demo data, seeded on a fresh install only.
		Seeder::seed_demo_characters( $fresh_install );

		// Re-registers capabilities so a new one reaches existing installs, not just fresh activations.
		\BeyondElysium\Core\Capabilities::register();

		// Must run after seed_demo_characters(), which Page_Provisioner's hook listener depends on existing.
		do_action( 'be_after_upgrade' );
	}

	/**
	 * Builds the fully-qualified table name for a short table name.
	 * Combines the WordPress table prefix with the plugin's own 'be_'
	 * prefix.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'be_' . $name;
	}
}
