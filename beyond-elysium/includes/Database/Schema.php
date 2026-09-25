<?php

namespace BeyondElysium\Database;

defined( 'ABSPATH' ) || exit;

/**
 * Database schema definition, creation and migration for Beyond Elysium.
 */
class Schema {

	/**
	 * The plugin's current database schema version, matching the plugin release version.
	 */
	const DB_VERSION = '1.3.8.1';

	/**
	 * Option key holding the installed schema version.
	 */
	const VERSION_OPTION = 'be_db_version';

	/**
	 * Option key holding the upgrade lock: the time the running upgrade started.
	 */
	const UPGRADE_LOCK_OPTION = 'be_upgrade_lock';

	/**
	 * Seconds after which an upgrade lock is stale and may be taken over.
	 */
	const UPGRADE_LOCK_TTL = 600;

	/**
	 * Option key holding why the last upgrade did not finish, shown to administrators until an upgrade does.
	 */
	const UPGRADE_ERROR_OPTION = 'be_upgrade_error';

	/**
	 * Short names of every table create_tables() creates, unprefixed past `be_`.
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
		'translation_strings',
		'translations',
	];

	/**
	 * Reports which of the plugin's own tables do not currently exist in the database.
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

		// be_characters: one row per character sheet.
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

		// be_character_attestations: per-issuance verification tokens.
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

		// be_character_transfers: chronicle-to-chronicle travel state.
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

		// be_character_submissions: a player-sent Grapevine file waiting for a Storyteller's review.
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

		// be_plot_entries: timeline entries attached to a plot.
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

		// be_attachments: private uploads on a plot or world object.
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

		// be_game_sessions: one row per game night.
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

		// be_release_batches: scheduled batches rumors and downtime answers go out in.
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

		// be_notification_queue: daily-digest plot-post notifications queued for a user who has opted into 'daily'.
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

		// be_npc_castings: a chronicle member cast to play one NPC for one session.
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

		// be_secrets: a Storyteller-authored secret attached to a plot, item, location, or NPC.
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

		// be_secret_reveals: one character learning one secret.
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

		// be_item_events: an item's own history.
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

		// be_item_attestations: an item's own verification codes.
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

		// be_after_game_reports: one player-written report per character per session.
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

		// be_factions: sects, coteries, packs, chantries, courts and similar groups.
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

		// be_faction_members: one row per character in a faction.
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

		// be_positions: a chronicle office, optionally tied to a faction.
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

		// be_position_history: one row per holder change, written whenever a position's character_id changes.
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

		// be_translation_strings: the locale-independent index of every distinct English catalog term.
		dbDelta( "CREATE TABLE {$prefix}translation_strings (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			source_key varchar(191) NOT NULL,
			source_text varchar(255) NOT NULL,
			used_in json DEFAULT NULL,
			first_seen datetime(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
			last_seen datetime(6) DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY source_key (source_key),
			KEY idx_last_seen (last_seen)
		) $charset_collate;" );

		// be_translations: per-locale translated text for a be_translation_strings row.
		dbDelta( "CREATE TABLE {$prefix}translations (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			string_id bigint(20) unsigned NOT NULL,
			locale varchar(10) NOT NULL,
			context varchar(100) DEFAULT NULL,
			translation longtext,
			status varchar(20) NOT NULL DEFAULT 'draft',
			note longtext,
			updated_by bigint(20) unsigned DEFAULT NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY string_locale_context (string_id, locale, context),
			KEY idx_locale_status (locale, status)
		) $charset_collate;" );

		self::migrate();

		// Clears the cached health-notice state.
		\BeyondElysium\Core\Health_Notice::clear_cache();
	}

	/**
	 * Post-dbDelta migration steps.
	 */
	public static function migrate(): void {
		self::backfill_character_uuids();
		self::add_character_uuid_index();
		self::make_xp_unspent_signed();
		self::rename_fera_gifts_sheet_data_key();
		self::add_name_order_indexes();
		self::backfill_game_members();
		self::add_schema_block_game_scoping();
		self::add_storyteller_only_to_schema_blocks();
		self::add_fork_changes_to_schema_blocks();
		self::split_forked_tiered_powers();
		self::add_owbn_chronicle_post_id();
		self::backfill_owbn_chronicle_post_ids();
		self::add_review_notes_to_character_changes();
		self::remove_coordinator_approvals();
		self::make_power_ladders_cumulative();
		self::carry_storyteller_only_to_forks();
		// Records what each chronicle copy of a block has changed.
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
	 * Sets the secure-printing opt-in for an install that has never set it: on when a working certificate already exists,
	 * otherwise off.
	 */
	private static function preserve_existing_signing_choice(): void {
		if ( get_option( \BeyondElysium\Services\Pdf_Signer::OPT_IN_OPTION, null ) !== null ) {
			return;
		}

		add_option( \BeyondElysium\Services\Pdf_Signer::OPT_IN_OPTION, \BeyondElysium\Services\Pdf_Signer::availability()['ok'] );
	}

	/**
	 * Adds one column to an existing table if it is not already there.
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
	 * Adds the audience columns to an existing plots table.
	 */
	public static function add_audience_to_plots(): void {
		$table = self::table( 'plots' );
		self::add_column_if_missing( $table, 'audience', "varchar(20) NOT NULL DEFAULT 'everyone' AFTER st_notes" );
		self::add_column_if_missing( $table, 'audience_rules', 'json DEFAULT NULL AFTER audience' );
	}

	/**
	 * Adds the audience columns to an existing plot_entries table.
	 */
	public static function add_audience_to_plot_entries(): void {
		$table = self::table( 'plot_entries' );
		self::add_column_if_missing( $table, 'audience', "varchar(20) NOT NULL DEFAULT 'plot' AFTER event_date" );
		self::add_column_if_missing( $table, 'audience_character_ids', 'json DEFAULT NULL AFTER audience' );
	}

	/**
	 * Adds the audience columns to an existing world_objects table.
	 */
	public static function add_audience_to_world_objects(): void {
		$table = self::table( 'world_objects' );
		self::add_column_if_missing( $table, 'audience', "varchar(20) NOT NULL DEFAULT 'everyone' AFTER properties" );
		self::add_column_if_missing( $table, 'audience_rules', 'json DEFAULT NULL AFTER audience' );
	}

	/**
	 * Adds the "Inside of" column to an existing world_objects table: the location a location sits inside.
	 */
	public static function add_parent_id_to_world_objects(): void {
		$table = self::table( 'world_objects' );
		self::add_column_if_missing( $table, 'parent_id', 'bigint(20) unsigned DEFAULT NULL AFTER audience_rules' );
	}

	/**
	 * Adds the "based on" column to an existing world_objects table: the source item an item copy was made from.
	 */
	public static function add_based_on_id_to_world_objects(): void {
		$table = self::table( 'world_objects' );
		self::add_column_if_missing( $table, 'based_on_id', 'bigint(20) unsigned DEFAULT NULL AFTER parent_id' );
	}

	/**
	 * Sets every personal actor plot's audience to 'restricted', matching who can already see it.
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
	 * Recovers existing translation work into the translations table, once: the Portuguese names already stored in the
	 * catalog's block data first, then the shipped drafts for names still without a translation.
	 */
	public static function migrate_catalog_translations_to_table(): void {
		if ( get_option( 'be_catalog_translations_migrated' ) ) {
			return;
		}

		\BeyondElysium\Services\Catalog_Translator::rescan();

		$counts = [
			'db_added'      => 0,
			'db_conflicts'  => 0,
			'csv_added'     => 0,
			'csv_conflicts' => 0,
			'csv_orphaned'  => 0,
		];

		// Pass 1: translations already stored in the seeded catalog.
		$seen = [];
		foreach ( \BeyondElysium\Services\Catalog_Translator::harvest_existing_pt_pairs() as [ $text, $pt ] ) {
			$key    = \BeyondElysium\Services\Name_Key::for( $text );
			$string = \BeyondElysium\Models\Translation_String::find_by_source_key( $key );
			if ( ! $string ) {
				continue; // Rescan just indexed every real catalog string; defensive only.
			}

			if ( isset( $seen[ $key ] ) ) {
				if ( $seen[ $key ]['value'] !== $pt ) {
					self::record_migration_conflict( (int) $seen[ $key ]['id'], $pt );
					++$counts['db_conflicts'];
				}
				continue;
			}

			$id = \BeyondElysium\Models\Translation::create( [
				'string_id'   => (int) $string->id,
				'locale'      => 'pt_BR',
				'translation' => $pt,
				'status'      => 'draft',
			] );
			if ( $id ) {
				$seen[ $key ] = [ 'id' => $id, 'value' => $pt, 'pass' => 1 ];
				++$counts['db_added'];
			}
		}

		// Pass 2: the shipped drafts, filling only the names pass 1 left without a translation.
		foreach ( \BeyondElysium\Services\Catalog_Translator::shipped_pt_pairs() as [ $name, $name_pt ] ) {
			$key = \BeyondElysium\Services\Name_Key::for( $name );

			if ( isset( $seen[ $key ] ) ) {
				if ( 1 === $seen[ $key ]['pass'] ) {
					continue;
				}
				if ( $seen[ $key ]['value'] !== $name_pt ) {
					self::record_migration_conflict( (int) $seen[ $key ]['id'], $name_pt );
					++$counts['csv_conflicts'];
				}
				continue;
			}

			$string = \BeyondElysium\Models\Translation_String::find_by_source_key( $key );
			if ( ! $string ) {
				$new_id = \BeyondElysium\Models\Translation_String::create( [
					'source_text' => $name,
					'last_seen'   => null,
				] );
				if ( ! $new_id ) {
					continue;
				}
				$string = \BeyondElysium\Models\Translation_String::find( (int) $new_id );
				++$counts['csv_orphaned'];
			}
			if ( ! $string ) {
				continue;
			}

			$id = \BeyondElysium\Models\Translation::create( [
				'string_id'   => (int) $string->id,
				'locale'      => 'pt_BR',
				'translation' => $name_pt,
				'status'      => 'draft',
			] );
			if ( $id ) {
				$seen[ $key ] = [ 'id' => $id, 'value' => $name_pt, 'pass' => 2 ];
				++$counts['csv_added'];
			}
		}

		\BeyondElysium\Services\Catalog_Translator::bust_cache();

		// Records the migration's counts.
		update_option( 'be_catalog_translations_migration_counts', $counts, false );

		update_option( 'be_catalog_translations_migrated', 1 );
	}

	/**
	 * Marks an already-created translation row as a conflict and records the losing value in its own note.
	 *
	 * @param int    $translation_id
	 * @param string $losing_value
	 */
	private static function record_migration_conflict( int $translation_id, string $losing_value ): void {
		$existing = \BeyondElysium\Models\Translation::find( $translation_id );
		if ( ! $existing ) {
			return;
		}

		$note = empty( $existing->note )
			? 'Also found: ' . $losing_value
			: $existing->note . '; ' . $losing_value;

		\BeyondElysium\Models\Translation::update( $translation_id, [
			'status' => 'conflict',
			'note'   => $note,
		] );
	}

	/**
	 * Adds the rumor-level columns to an existing plots table.
	 */
	public static function add_rumor_levels_to_plots(): void {
		$table = self::table( 'plots' );
		self::add_column_if_missing( $table, 'rumor_level_key', 'varchar(64) DEFAULT NULL AFTER release_batch_id' );
		self::add_column_if_missing( $table, 'rumor_level_match', 'varchar(255) DEFAULT NULL AFTER rumor_level_key' );
	}

	/**
	 * Adds the `level` column to an existing plot_entries table.
	 */
	public static function add_level_to_plot_entries(): void {
		self::add_column_if_missing( self::table( 'plot_entries' ), 'level', 'tinyint(3) unsigned DEFAULT NULL AFTER release_batch_id' );
	}

	/**
	 * Adds the staff-assignment column to an existing plots table.
	 */
	public static function add_assigned_to_to_plots(): void {
		self::add_column_if_missing( self::table( 'plots' ), 'assigned_to', 'bigint(20) unsigned DEFAULT NULL AFTER rumor_level_match' );
	}

	/**
	 * Adds the staff-assignment column to an existing characters table.
	 */
	public static function add_assigned_to_to_characters(): void {
		self::add_column_if_missing( self::table( 'characters' ), 'assigned_to', 'bigint(20) unsigned DEFAULT NULL AFTER sheet_data' );
	}

	/**
	 * Adds the quick-NPC and public-profile columns to an existing characters table.
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
	 * Puts every existing rumor into a released batch, one per chronicle, so it stays visible to whoever could read it.
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
	 * Puts one chronicle's existing rumors into a fresh released batch.
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
	 * Gives every character its own plot and moves its action rounds that sit under no plot beneath it.
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
	 * Records, for every chronicle copy of a catalog block made before copies recorded their own changes, how it differs
	 * from the catalog block it came from.
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
	 * Adds `fork_changes` to `schema_blocks`: what a chronicle's copy of a catalog block has changed.
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
	 * Marks every chronicle's copy of a Storyteller-only block Storyteller-only too, exactly once.
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
	 * Makes every chronicle's copy of a tiered-power block price its levels cumulatively, exactly once.
	 */
	public static function make_power_ladders_cumulative(): void {
		if ( get_option( 'be_power_ladders_cumulative' ) ) {
			return;
		}

		global $wpdb;
		$table = self::table( 'schema_blocks' );
		$rows  = $wpdb->get_results( "SELECT id, definition FROM {$table} WHERE section_type = 'tiered_power' AND game_slug <> ''" );

		// Every write is checked.
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
	 * Turns every approval rule stored at the retired `coordinator` level into a Storyteller (`st`) rule, in every schema
	 * block, exactly once.
	 */
	public static function remove_coordinator_approvals(): void {
		if ( get_option( 'be_coordinator_tier_removed' ) ) {
			return;
		}

		global $wpdb;
		$table = self::table( 'schema_blocks' );
		$rows  = $wpdb->get_results( "SELECT id, definition FROM {$table} WHERE definition LIKE '%coordinator%'" );

		// Every write is checked.
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
	 * Replaces `coordinator` with `st` wherever a definition names an approval level.
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
	 * Gives a change's reviewer their own column.
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
	 * Adds the owbn_chronicle_post_id column and its unique index to an existing games table.
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
	 * Correlates each games row with the owbn_chronicle post it corresponds to, by exact slug match, wherever that
	 * correlation is not already set.
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
	 * Adds the `vampire-blood-magic` section to vampire's own `sheet_full` template for every install that seeded it
	 * before Blood Magic existed.
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
	 * Removes the duplicate "Awakening of the Steel" power family from vampire-disciplines, keeping the
	 * tradition-prefixed "Dur An Ki: Awakening the Steel" entry, and rewrites any character's held pick stored under the
	 * bare name to the surviving name.
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
			return;
		}

		$definition->powers = $kept;
		\BeyondElysium\Models\Schema_Block::update( 'vampire-disciplines', [ 'definition' => $definition ] );

		self::rename_held_discipline_pick( $bare_name, $surviving_name );
	}

	/**
	 * Rewrites every character's held vampire-disciplines pick stored under the name `$from` to `$to`.
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
	 * Adds an index on the `name` column to every table whose default listing order sorts by name.
	 */
	public static function add_name_order_indexes(): void {
		foreach ( [ 'schema_blocks', 'creature_stacks', 'games', 'world_objects' ] as $short_name ) {
			self::add_index_if_missing( self::table( $short_name ), 'idx_name_order', 'name' );
		}
	}

	/**
	 * Adds a plain KEY index to a table if it does not already exist.
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
	 * Renames the `werewolf-gifts` sheet_data key to `fera-gifts` for every fera/bete character that still has held picks
	 * stored under the old key.
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
	 * Rewrites a held Gift's stored `name` from its old compound label (e.g. "Silver Fangs: Falcon's Grasp (basic)") to
	 * the plain catalog name (e.g. "Falcon's Grasp") for werewolf-gifts and fera-gifts.
	 */
	public static function migrate_held_gift_names_to_grouped_fields(): void {
		global $wpdb;

		$table = self::table( 'characters' );

		$catalogs = [];
		foreach ( [ 'werewolf-gifts', 'fera-gifts' ] as $slug ) {
			$block = \BeyondElysium\Models\Schema_Block::find_by_slug( $slug );
			if ( ! $block || ! is_array( $block->definition->items ?? null ) ) {
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
	 * Strips a Gift's compound-label prefix and tier suffix, leaving just the plain catalog name.
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
	 * The four Assamite caste names Blood Magic excludes from tradition splitting.
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
	 * Splits a stale chronicle fork of vampire-disciplines: any power named "{Tradition}: {Path}", excluding the four
	 * Assamite caste names, moves to that chronicle's own vampire-blood-magic fork, created if it does not exist.
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
	 * Moves a character's own held vampire-disciplines pick to vampire-blood-magic when its stored name identifies it as
	 * a pre-Blood-Magic tradition-prefixed pick, splitting the name into the bare canonical path plus a `tradition`
	 * field.
	 */
	public static function migrate_blood_magic_held_picks(): void {
		global $wpdb;
		$table    = self::table( 'characters' );
		$excluded = self::blood_magic_excluded_power_names();

		// The 14 Blood Magic traditions.
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
	 * Adds the UNIQUE index on be_characters.uuid, once every row holds a value.
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
	 * Adds the `game_slug` column to `schema_blocks` and gives it a unique index on `(slug, game_slug)` in place of the
	 * one on `slug` alone.
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
	 * Converts an existing `characters.xp_unspent` column from unsigned to signed.
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
	 * Rebuilds a creature stack's default `sheet_full` template layout when it is out of date.
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

				$known_slugs = array_keys( $fresh_by_slug );
				foreach ( $stack->stack_definition->sections ?? [] as $stack_section ) {
					$known_slugs[] = (string) ( $stack_section->block_slug ?? '' );
				}
				$has_renamed_slug = (bool) array_diff( array_column( $sections, 'block_slug' ), $known_slugs );

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
	 * Repairs an existing, already-seeded `npc_full` template whose section list has fallen behind its stack's current
	 * `sheet_full`.
	 */
	/**
	 * Completes every system `sheet_full` and `npc_full` from its own stack: any block the stack declares that the
	 * template does not already show is inserted beside its own kind (the seeded half of).
	 */
	public static function complete_full_sheet_templates(): void {
		foreach ( \BeyondElysium\Models\Creature_Stack::all() as $stack ) {
			$declared = [];
			foreach ( $stack->stack_definition->sections ?? [] as $section ) {
				if ( ! empty( $section->block_slug ) ) {
					$declared[ (string) $section->block_slug ] = (string) ( $section->label ?? '' );
				}
			}
			if ( ! $declared ) {
				continue;
			}

			foreach ( [ 'sheet_full', 'npc_full' ] as $template_type ) {
				foreach ( \BeyondElysium\Models\Template::globals( [
					'stack_slug'    => $stack->slug,
					'template_type' => $template_type,
				] ) as $template ) {
					/** @var object{id:int,is_system:int,layout:array} $template */
					if ( empty( $template->is_system ) ) {
						continue;
					}

					$sections = $template->layout['sections'] ?? [];
					if ( ! $sections ) {
						continue;
					}

					$missing = array_diff( array_keys( $declared ), array_column( $sections, 'block_slug' ) );
					if ( ! $missing ) {
						continue; // Already complete.
					}

					foreach ( $missing as $slug ) {
						$sections = self::insert_declared_section( $sections, array_keys( $declared ), $slug, $declared[ $slug ] );
					}

					// Roleplaying notes stay last on an npc_full, as repair_stale_npc_layouts() has it.
					$notes = null;
					foreach ( $sections as $i => $section ) {
						if ( $section['block_slug'] === 'npc-roleplaying-notes' ) {
							$notes = $section;
							unset( $sections[ $i ] );
							break;
						}
					}
					$sections = array_values( $sections );
					if ( $notes !== null ) {
						$sections[] = $notes;
					}

					foreach ( $sections as $i => &$section ) {
						$section['order'] = $i + 1;
					}
					unset( $section );

					$layout             = $template->layout;
					$layout['sections'] = $sections;

					if ( ! \BeyondElysium\Models\Template::update( (int) $template->id, [ 'layout' => $layout ] ) ) {
						error_log( 'Beyond Elysium: failed to complete ' . $stack->slug . ' ' . $template_type . ' template id ' . (int) $template->id );
					}
				}
			}
		}
	}

	/**
	 * Inserts one declared block into a layout directly after the nearest earlier-declared block the layout already
	 * shows, taking that block's width.
	 *
	 * @param array    $sections      The layout's sections.
	 * @param string[] $declared_order Every block the stack declares, in its declared order.
	 * @param string   $slug          The block to insert.
	 * @param string   $label         The stack's own label for it.
	 * @return array The sections with the block inserted; `order` is renumbered by the caller.
	 */
	private static function insert_declared_section( array $sections, array $declared_order, string $slug, string $label ): array {
		$shown    = array_column( $sections, 'block_slug' );
		$position = array_search( $slug, $declared_order, true );

		$insert_at = 0;
		$width     = 'full';
		if ( $position !== false ) {
			for ( $i = (int) $position - 1; $i >= 0; $i-- ) {
				$anchor = array_search( $declared_order[ $i ], $shown, true );
				if ( $anchor !== false ) {
					$insert_at = (int) $anchor + 1;
					$width     = (string) ( $sections[ $anchor ]['width'] ?? 'full' );
					break;
				}
			}
		}

		array_splice( $sections, $insert_at, 0, [
			[
				'block_slug' => $slug,
				'column'     => 1,
				'order'      => 0, // Renumbered by the caller once every insertion is done.
				'title'      => $label !== '' ? $label : ucwords( str_replace( '-', ' ', $slug ) ),
				'display'    => self::default_display_for_block( $slug ),
				'collapsed'  => false,
				'width'      => $width,
			],
		] );

		return $sections;
	}

	/**
	 * How a newly inserted section renders its held rows: a counted `trait_list` as dots, everything else with the
	 * default.
	 */
	private static function default_display_for_block( string $slug ): ?string {
		$block = \BeyondElysium\Models\Schema_Block::find_by_slug( $slug );
		if ( ! $block || $block->section_type !== 'trait_list' ) {
			return null;
		}
		return empty( $block->definition->atomic ) ? 'multiplier_dot' : null;
	}

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

				$notes = array_values( array_filter( $sections, static fn( $s ) => $s['block_slug'] === 'npc-roleplaying-notes' ) );
				$rest  = array_values( array_filter( $sections, static fn( $s ) => $s['block_slug'] !== 'npc-roleplaying-notes' ) );

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
	 * One-time backfill of `be_game_members` from each user's existing site-wide access: every user holding
	 * `be_manage_characters` becomes `hst` in every game, and every character's own owning WordPress user becomes
	 * `player` in that character's game.
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
	 * Every step of an upgrade, in order: tables, translation recovery, catalog reseed and the migrations after it, the
	 * move onto per-creature lists, combos moved into their combo lists, lists filled from import records, stacks and
	 * templates, retired-block removal, translation rescan, demo data and capabilities.
	 *
	 * @param bool $fresh_install Whether no schema version was recorded before this upgrade.
	 */
	private static function run_upgrade( bool $fresh_install ): void {
		self::create_tables();

		// Recovers existing translation data into the translations table.
		self::migrate_catalog_translations_to_table();

		// Refreshes the system schema blocks.
		Seeder::seed_schema_blocks();

		self::add_missing_combo_disciplines();

		self::dedupe_awakening_of_the_steel();

		self::migrate_held_gift_names_to_grouped_fields();

		self::migrate_blood_magic_schema_forks();
		self::migrate_blood_magic_held_picks();

		// Moves an install still on the shared lists onto the per-creature catalog.
		$move = \BeyondElysium\Services\Catalog_Cutover::ensure_declared();
		if ( ! in_array( $move['status'], [ 'already_declared', 'marked', 'applied' ], true ) ) {
			throw new \RuntimeException( \BeyondElysium\Services\Catalog_Cutover::refusal_message( $move ) );
		}
		delete_option( 'be_catalog_cutover_record' );

		// Moves combos held as picks in a power list into the combo list beside it.
		\BeyondElysium\Services\Combo_Refile::run();

		// Fills blocks from the lists earlier imports kept only in the import record.
		\BeyondElysium\Services\Kept_List_Backfill::run();

		Seeder::seed_creature_stacks();
		Seeder::reconcile_stack_blocks();

		// Drops the empty sections of the two blocks no stack lists any more from the system templates.
		\BeyondElysium\Services\Retired_Blocks::drop_from_system_templates();

		Seeder::seed_default_templates();

		Seeder::seed_npc_templates();

		self::repair_stale_default_layouts();

		self::add_missing_blood_magic_template_section();

		self::repair_stale_npc_layouts();

		self::complete_full_sheet_templates();

		// Gives system templates the section titles their declared files name.
		\BeyondElysium\Services\Template_Titles::run();

		// Deletes each retired block that nothing names any more.
		\BeyondElysium\Services\Retired_Blocks::remove_unused();

		// Re-indexes the catalog's terms for translation.
		\BeyondElysium\Services\Catalog_Translator::rescan();

		// Demo data, seeded on a fresh install only.
		Seeder::seed_demo_characters( $fresh_install );

		// Registers capabilities.
		\BeyondElysium\Core\Capabilities::register();

		do_action( 'be_after_upgrade' );
	}

	/**
	 * Builds the fully-qualified table name for a short table name.
	 */
	public static function table( string $name ): string {
		global $wpdb;
		return $wpdb->prefix . 'be_' . $name;
	}

	/**
	 * Re-splits every chronicle-forked `tiered_power` block into its three containers.
	 */
	private static function split_forked_tiered_powers(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'be_schema_blocks';

		$forks = $wpdb->get_results(
			"SELECT id, slug, game_slug, definition FROM {$table}
			  WHERE section_type = 'tiered_power' AND is_system = 0"
		);
		if ( ! $forks ) {
			return;
		}

		$migrated = 0;
		foreach ( $forks as $fork ) {
			try {
				$definition = json_decode( (string) $fork->definition, true );
				if ( ! is_array( $definition ) ) {
					continue;
				}
				$split = \BeyondElysium\Database\Seeder::split_stored_definition( (string) $fork->slug, $definition );
				if ( $split === null ) {
					continue;
				}
				$wpdb->update(
					$table,
					[ 'definition' => (string) wp_json_encode( $split ) ],
					[ 'id' => (int) $fork->id ]
				);
				$migrated++;
			} catch ( \Throwable $e ) {
				error_log( sprintf(
					'Beyond Elysium: could not split forked block %s/%s: %s',
					(string) $fork->slug,
					(string) $fork->game_slug,
					$e->getMessage()
				) );
			}
		}

		if ( $migrated > 0 ) {
			error_log( "Beyond Elysium: split {$migrated} forked tiered_power block(s) into the 1.2.10 containers." );
		}
	}

}
