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
	const DB_VERSION = '0.99.4';

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
		self::add_owbn_chronicle_post_id();
		self::backfill_owbn_chronicle_post_ids();
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
	 * BE_PROCESS/chronicle-rename-design.md §8.2 for the full reasoning.
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
	 * every install that seeded it before Blood Magic existed (BE_PROCESS/0.99.2-workflow.md
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
	 * game-scoped copy made before the Blood Magic redesign, BE_PROCESS/0.99.2-workflow.md)
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
				// BE_PROCESS/0.99.2-workflow.md BM-9) to an already-current, already-seeded
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
	 * `vampire-blood-magic` section (BE_PROCESS/0.99.2-workflow.md BM-9) would otherwise
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

		// Blood magic (BE_PROCESS/0.99.2-workflow.md, BM-8): must run after
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
