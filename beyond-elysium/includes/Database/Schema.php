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
	const DB_VERSION = '0.99.0';

	/**
	 * Option key holding the installed schema version.
	 */
	const VERSION_OPTION = 'be_db_version';

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
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY slug (slug),
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
			narrator varchar(255) DEFAULT NULL,
			start_date date DEFAULT NULL,
			xp_earned int unsigned NOT NULL DEFAULT 0,
			xp_unspent int NOT NULL DEFAULT 0,
			biography longtext,
			notes longtext,
			rp_notes longtext,
			image_id bigint(20) unsigned DEFAULT NULL,
			sheet_data json DEFAULT NULL,
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_name (name),
			KEY idx_stack_slug (stack_slug),
			KEY idx_owner (owner_type, owner_slug),
			KEY idx_wp_user (wp_user_id),
			KEY idx_status (status),
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
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_game_status (game_id, status),
			KEY idx_created_by (created_by),
			KEY idx_game_date (game_id, game_date),
			KEY idx_parent_plot (parent_plot_id),
			FULLTEXT KEY ft_plot_text (title, description)
		) $charset_collate;" );

		// be_plot_entries: timeline entries attached to a plot; event_date is the in-fiction date.
		dbDelta( "CREATE TABLE {$prefix}plot_entries (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			plot_id bigint(20) unsigned NOT NULL,
			author_id bigint(20) unsigned NOT NULL,
			entry_type varchar(20) NOT NULL,
			content longtext NOT NULL,
			event_date date DEFAULT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
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
			created_by bigint(20) unsigned NOT NULL,
			created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			KEY idx_game_type (game_id, object_type),
			KEY idx_rarity (rarity),
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

		self::migrate();

		update_option( self::VERSION_OPTION, self::DB_VERSION );

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

				// Detects a renamed or removed block_slug that a plain field-presence check would miss.
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
		$installed = get_option( self::VERSION_OPTION, '0.0.0' );

		if ( version_compare( $installed, self::DB_VERSION, '>=' ) ) {
			return;
		}

		self::create_tables();

		// Refreshes system schema blocks/stacks so a block-map correction reaches existing installs.
		Seeder::seed_schema_blocks();

		// Must run after seed_schema_blocks(), which would otherwise overwrite this addition.
		self::add_missing_combo_disciplines();

		// Must run after seed_schema_blocks(), for the same reason as the call above.
		self::dedupe_awakening_of_the_steel();

		// Must run after seed_schema_blocks() has refreshed the werewolf-gifts/fera-gifts catalogs.
		self::migrate_held_gift_names_to_grouped_fields();

		Seeder::seed_creature_stacks();
		Seeder::reconcile_stack_blocks();
		Seeder::seed_default_templates();

		// Must run after seed_default_templates(), whose sheet_full layout it builds on.
		Seeder::seed_npc_templates();

		// Must run after seed_schema_blocks(), since a rebuilt layout can reference a new block slug.
		self::repair_stale_default_layouts();

		// Idempotent demo data, safe to re-run on every upgrade.
		Seeder::seed_demo_characters();

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
