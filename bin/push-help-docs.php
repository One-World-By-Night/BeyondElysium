<?php
/**
 * Pushes the help pages to beyondelysium.com: one BetterDocs "docs" post per page, in a "Help" category, each with its
 * screenshots, and the Portuguese text, screenshots and alt text paired with the English ones in TranslatePress.
 *
 * Reads what bin/sync-help-docs.js and bin/capture-help-screenshots.js write. Run it with `wp eval-file`.
 *
 *   BE_HELP_JSON     the pages file, default /tmp/help-docs.json
 *   BE_HELP_IMAGES   the screenshots folder, default /tmp/help-screenshots, holding en/ and pt_BR/
 *   BE_HELP_DRY_RUN  set to 1 to report what would change and write nothing
 *   BE_HELP_ONLY     slugs, comma separated: push only these pages
 *   BE_HELP_PREFIX   put the pages under this slug prefix and out of the Help category, to rehearse a push
 *   BE_HELP_REMOVE   set to 1, with a prefix, to put the rehearsal's pages in the trash and delete its screenshots
 *
 * Every step is idempotent: a second run changes nothing.
 */

if ( ! class_exists( 'Be_Help_TranslatePress' ) ) {

	/**
	 * Writes translation pairs into TranslatePress's own dictionary, through its own query component.
	 */
	final class Be_Help_TranslatePress {

		/**
		 * The elements TranslatePress treats as one whole block when they hold no other such element.
		 */
		private const BLOCK_TAGS = [ 'p', 'div', 'li', 'ol', 'ul', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'h7', 'body', 'footer', 'article', 'main', 'iframe', 'section', 'figure', 'figcaption', 'blockquote', 'cite', 'tr', 'td', 'th', 'table', 'tbody', 'thead', 'tfoot', 'form', 'label' ];

		public function __construct( private string $language ) {}

		/**
		 * True when TranslatePress is active with this language published, or the reason it is not.
		 *
		 * @return true|string
		 */
		public function ready() {
			if ( ! class_exists( 'TRP_Translate_Press' ) ) {
				return 'TranslatePress is not active';
			}
			$settings = (array) get_option( 'trp_settings', [] );
			if ( ! in_array( $this->language, (array) ( $settings['translation-languages'] ?? [] ), true ) ) {
				return "TranslatePress does not translate into {$this->language}";
			}
			return true;
		}

		/**
		 * The dictionary rows a page's pairs become, and the pairs the rendered English page has no place for.
		 *
		 * @param array<int,array<string,string>> $pairs    The pairs from bin/sync-help-docs.js, plus image pairs.
		 * @param string                          $rendered The English page as the website shows its content.
		 * @return array{rows: array<int,array<string,mixed>>, unmatched: array<int,string>}
		 */
		public function plan( array $pairs, string $rendered ): array {
			$found     = $this->found_in( $rendered );
			$rows      = [];
			$unmatched = [];

			foreach ( $pairs as $pair ) {
				$kind = $pair['kind'];
				if ( 'block' === $kind ) {
					$original   = wptexturize( $pair['original'] );
					$translated = wptexturize( $pair['translated'] );
					$present    = isset( $found['blocks'][ $this->words( $original ) ] );
					$type       = 1;
				} elseif ( 'text' === $kind ) {
					$original   = $this->plain( $pair['original'] );
					$translated = $this->plain( $pair['translated'] );
					$present    = isset( $found['texts'][ $original ] );
					$type       = 0;
				} else {
					$original   = $pair['original'];
					$translated = $pair['translated'];
					$present    = isset( $found['attributes'][ $original ] );
					$type       = 0;
				}
				if ( ! $present ) {
					$unmatched[] = "{$kind}: " . mb_substr( $pair['original'], 0, 80 );
					continue;
				}
				$rows[ $original ] = [
					'original'   => $original,
					'translated' => $translated,
					'block_type' => $type,
				];
			}
			return [
				'rows'      => array_values( $rows ),
				'unmatched' => $unmatched,
			];
		}

		/**
		 * Writes the rows as human-reviewed translations, all or none, and returns what changed.
		 *
		 * @param array<int,array<string,mixed>> $rows
		 * @return array{inserted: int, updated: int, unchanged: int}
		 */
		public function write( array $rows ): array {
			global $wpdb;
			$query = TRP_Translate_Press::get_trp_instance()->get_component( 'query' );
			$table = $query->get_table_name( $this->language );
			$human = $query->get_constant_human_reviewed();

			$existing = [];
			foreach ( array_chunk( array_column( $rows, 'original' ), 100 ) as $chunk ) {
				$marks = implode( ',', array_fill( 0, count( $chunk ), 'BINARY %s' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				$found = $wpdb->get_results( $wpdb->prepare( "SELECT id, original, translated, status, block_type FROM `{$table}` WHERE BINARY original IN ({$marks})", $chunk ), ARRAY_A );
				foreach ( (array) $found as $row ) {
					$existing[ $row['original'] ] = $row;
				}
			}

			$insert    = [ 0 => [], 1 => [] ];
			$unchanged = 0;
			$changes   = [];
			foreach ( $rows as $row ) {
				$have = $existing[ $row['original'] ] ?? null;
				if ( null === $have ) {
					$insert[ $row['block_type'] ][] = $row['original'];
				} elseif ( $have['translated'] === $row['translated'] && (int) $have['status'] === $human && (int) $have['block_type'] === $row['block_type'] ) {
					++$unchanged;
				}
				if ( null === $have || $have['translated'] !== $row['translated'] || (int) $have['status'] !== $human || (int) $have['block_type'] !== $row['block_type'] ) {
					$changes[] = $row;
				}
			}

			$inserted = count( $insert[0] ) + count( $insert[1] );
			if ( ! $changes ) {
				return [
					'inserted'  => 0,
					'updated'   => 0,
					'unchanged' => $unchanged,
				];
			}

			$wpdb->query( 'START TRANSACTION' );
			foreach ( $insert as $type => $originals ) {
				if ( $originals ) {
					$query->insert_strings( $originals, $this->language, $type );
				}
			}
			$ids = [];
			foreach ( array_chunk( array_column( $changes, 'original' ), 100 ) as $chunk ) {
				$marks = implode( ',', array_fill( 0, count( $chunk ), 'BINARY %s' ) );
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
				foreach ( (array) $wpdb->get_results( $wpdb->prepare( "SELECT id, original FROM `{$table}` WHERE BINARY original IN ({$marks})", $chunk ), ARRAY_A ) as $row ) {
					$ids[ $row['original'] ] = (int) $row['id'];
				}
			}
			$update = [];
			foreach ( $changes as $row ) {
				if ( ! isset( $ids[ $row['original'] ] ) ) {
					$wpdb->query( 'ROLLBACK' );
					throw new RuntimeException( 'TranslatePress did not keep: ' . mb_substr( $row['original'], 0, 80 ) );
				}
				$update[] = [
					'id'         => $ids[ $row['original'] ],
					'original'   => $row['original'],
					'translated' => $row['translated'],
					'status'     => $human,
					'block_type' => $row['block_type'],
				];
			}
			if ( ! $query->update_strings( $update, $this->language, [ 'id', 'translated', 'status', 'block_type' ] ) ) {
				$wpdb->query( 'ROLLBACK' );
				throw new RuntimeException( 'TranslatePress refused the translations' );
			}
			$wpdb->query( 'COMMIT' );

			return [
				'inserted'  => $inserted,
				'updated'   => count( $changes ) - $inserted,
				'unchanged' => $unchanged,
			];
		}

		/**
		 * The text TranslatePress matches a whole block by: its markup stripped and its white space collapsed.
		 */
		private function words( string $html ): string {
			return TRP_Translate_Press::get_trp_instance()->get_component( 'translation_render' )->trim_translation_block( $html );
		}

		/**
		 * A piece of text the way the website's page holds it: typography applied and entities written out, line breaks
		 * kept. TranslatePress keeps text with its entities, as the page's HTML has them.
		 */
		private function plain( string $text ): string {
			$escaped = str_replace( [ '&', '<', '>', '"', "'" ], [ '&amp;', '&lt;', '&gt;', '&quot;', '&#39;' ], $text );
			return trim( wptexturize( $escaped ) );
		}

		/**
		 * What the rendered English page offers TranslatePress to match: whole blocks, text nodes and attribute values.
		 *
		 * @return array{blocks: array<string,true>, texts: array<string,true>, attributes: array<string,true>}
		 */
		private function found_in( string $rendered ): array {
			$found = [
				'blocks'     => [],
				'texts'      => [],
				'attributes' => [],
			];
			$dom   = new DOMDocument();
			libxml_use_internal_errors( true );
			$dom->loadHTML( '<?xml encoding="UTF-8"><body>' . $rendered . '</body>' );
			libxml_clear_errors();
			$xpath = new DOMXPath( $dom );

			foreach ( $xpath->query( '//*' ) as $element ) {
				if ( in_array( $element->nodeName, self::BLOCK_TAGS, true ) && ! $this->holds_block( $element ) ) {
					$inner = '';
					foreach ( $element->childNodes as $child ) {
						$inner .= $dom->saveHTML( $child );
					}
					$found['blocks'][ $this->words( $inner ) ] = true;
				}
				foreach ( [ 'alt', 'title', 'src' ] as $name ) {
					if ( $element->hasAttribute( $name ) ) {
						$found['attributes'][ $element->getAttribute( $name ) ] = true;
					}
				}
			}
			// Text is read from the page's own markup, since TranslatePress matches it with its entities as written.
			foreach ( preg_split( '/(<[^>]*>)/', $rendered, -1, PREG_SPLIT_NO_EMPTY ) as $part ) {
				if ( '<' !== $part[0] ) {
					$text = trim( $part );
					if ( '' !== $text ) {
						$found['texts'][ $text ] = true;
					}
				}
			}
			return $found;
		}

		private function holds_block( DOMElement $element ): bool {
			foreach ( $element->getElementsByTagName( '*' ) as $child ) {
				if ( in_array( $child->nodeName, self::BLOCK_TAGS, true ) ) {
					return true;
				}
			}
			return false;
		}
	}
}

if ( ! class_exists( 'Be_Help_Push' ) ) {

	/**
	 * The push: English pages, their screenshots, then the Portuguese pairs.
	 */
	final class Be_Help_Push {

		private const IMAGE_KEY  = '_be_help_image';
		private const IMAGE_HASH = '_be_help_image_hash';
		private const LANGUAGE   = 'pt_BR';
		private const AUTHOR     = 2; // be-admin, the author the four top-level guide docs use.

		/**
		 * @var array<string,int>
		 */
		private array $count = [
			'created'   => 0,
			'updated'   => 0,
			'unchanged' => 0,
			'failed'    => 0,
		];

		/**
		 * @var string[]
		 */
		private array $notes = [];

		/**
		 * @param string[] $only
		 */
		public function __construct(
			private string $json,
			private string $images,
			private bool $dry_run,
			private array $only = [],
			private string $prefix = '',
			private bool $remove = false
		) {}

		public function run(): void {
			// The pages are this script's own markup: the filter for accounts without unfiltered_html would rewrite it.
			kses_remove_filters();
			try {
				$this->push();
			} finally {
				kses_init();
			}
		}

		private function push(): void {
			$pages = $this->load();
			if ( null === $pages ) {
				return;
			}
			if ( $this->only ) {
				$pages = array_values( array_filter( $pages, fn( $page ) => in_array( $page['slug'], $this->only, true ) ) );
			}
			if ( $this->remove ) {
				$this->remove( $pages );
				return;
			}

			$category = $this->category();
			if ( null === $category && ! $this->dry_run ) {
				return;
			}

			$posts = [];
			foreach ( $pages as $page ) {
				$post = $this->english( $page, (int) $category );
				if ( $post ) {
					$posts[ $page['slug'] ] = $post;
				}
			}
			echo "Created: {$this->count['created']}, Updated: {$this->count['updated']}, Unchanged: {$this->count['unchanged']}, Failed: {$this->count['failed']}\n";

			$this->portuguese( $pages, $posts );

			foreach ( $this->notes as $note ) {
				echo "  {$note}\n";
			}
			if ( $this->dry_run ) {
				echo "Dry run: nothing was written.\n";
			}
		}

		/**
		 * The pages file, decoded, or null with the reason printed.
		 *
		 * @return array<int,array<string,mixed>>|null
		 */
		private function load(): ?array {
			if ( ! file_exists( $this->json ) ) {
				fwrite( STDERR, "Missing {$this->json} - copy it over first (bin/sync-help-docs.js's own output).\n" );
				return null;
			}
			$data = json_decode( (string) file_get_contents( $this->json ), true );
			if ( ! is_array( $data ) || ! isset( $data['pages'] ) || ! is_array( $data['pages'] ) ) {
				fwrite( STDERR, "Could not read {$this->json} as the pages file.\n" );
				return null;
			}
			return $data['pages'];
		}

		/**
		 * The Help category's id, created on first run.
		 */
		private function category(): ?int {
			if ( '' !== $this->prefix ) {
				return 0;
			}
			$category = get_term_by( 'slug', 'help', 'doc_category' );
			if ( $category ) {
				return (int) $category->term_id;
			}
			if ( $this->dry_run ) {
				echo "Would create the 'Help' doc_category\n";
				return null;
			}
			$result = wp_insert_term( 'Help', 'doc_category', [ 'slug' => 'help' ] );
			if ( is_wp_error( $result ) ) {
				fwrite( STDERR, 'Failed to create Help category: ' . $result->get_error_message() . "\n" );
				return null;
			}
			echo "Created 'Help' doc_category (term_id {$result['term_id']})\n";
			return (int) $result['term_id'];
		}

		/**
		 * Creates or updates one English page with its screenshots, and returns its post.
		 *
		 * @param array<string,mixed> $page
		 */
		private function english( array $page, int $category ): ?WP_Post {
			$slug     = $page['slug'];
			$name     = $this->prefix . $slug;
			$title    = $page['title'] ?? $slug;
			$existing = get_page_by_path( $name, OBJECT, 'docs' );

			$postarr = [
				'post_type'   => 'docs',
				'post_title'  => $title,
				'post_name'   => $name,
				'post_status' => 'publish',
				'post_author' => self::AUTHOR,
			];

			if ( $this->dry_run ) {
				$this->count[ $existing ? 'unchanged' : 'created' ]++;
				return $existing instanceof WP_Post ? $existing : null;
			}

			// The page exists before its images do, so they can be attached to it.
			$created = false;
			if ( ! $existing ) {
				$id = wp_insert_post( wp_slash( $postarr + [ 'post_content' => $this->content( $page, [] ) ] ), true );
				if ( is_wp_error( $id ) ) {
					$this->fail( $slug, $id->get_error_message() );
					return null;
				}
				$created = true;
				++$this->count['created'];
				$existing = get_post( $id );
			}

			$images  = $this->screenshots( $page, (int) $existing->ID );
			$content = $this->content( $page, $images );
			if ( $existing->post_content !== $content || $existing->post_title !== $title || 'publish' !== $existing->post_status ) {
				$id = wp_update_post( wp_slash( $postarr + [ 'ID' => $existing->ID, 'post_content' => $content ] ), true );
				if ( is_wp_error( $id ) ) {
					$this->fail( $slug, $id->get_error_message() );
					return null;
				}
				if ( ! $created ) {
					++$this->count['updated'];
				}
			} elseif ( ! $created ) {
				++$this->count['unchanged'];
			}
			if ( $category && ! in_array( $category, wp_get_object_terms( $existing->ID, 'doc_category', [ 'fields' => 'ids' ] ), true ) ) {
				wp_set_object_terms( $existing->ID, [ $category ], 'doc_category' );
			}
			return get_post( $existing->ID );
		}

		/**
		 * The page's blocks as one string, each screenshot placed under the heading it follows.
		 *
		 * @param array<string,mixed>            $page
		 * @param array<int,array<string,array>> $images The uploaded screenshots by number, then language.
		 */
		private function content( array $page, array $images ): string {
			$after = [];
			foreach ( $page['shots'] ?? [] as $shot ) {
				if ( isset( $images[ $shot['number'] ]['en'] ) ) {
					$after[ $shot['afterBlock'] ][] = $this->image_block( $images[ $shot['number'] ]['en'] );
				}
			}
			$out = [];
			foreach ( $page['blocks'] as $index => $block ) {
				$out[] = $block;
				foreach ( $after[ $index ] ?? [] as $image ) {
					$out[] = $image;
				}
			}
			return implode( "\n\n", $out );
		}

		/**
		 * @param array{id: int, url: string, alt: string} $image
		 */
		private function image_block( array $image ): string {
			$id  = (int) $image['id'];
			$alt = esc_attr( $image['alt'] );
			$url = esc_url( $image['url'] );
			return "<!-- wp:image {\"id\":{$id},\"sizeSlug\":\"full\",\"linkDestination\":\"none\"} -->\n"
				. "<figure class=\"wp-block-image size-full\"><img src=\"{$url}\" alt=\"{$alt}\" class=\"wp-image-{$id}\"/></figure>\n"
				. '<!-- /wp:image -->';
		}

		/**
		 * Uploads a page's screenshots in both languages, or none of them when a file is missing.
		 *
		 * @param array<string,mixed> $page
		 * @return array<int,array<string,array>>
		 */
		private function screenshots( array $page, int $post_id ): array {
			$shots = $page['shots'] ?? [];
			if ( ! $shots ) {
				return [];
			}
			foreach ( $shots as $shot ) {
				foreach ( [ 'en', self::LANGUAGE ] as $language ) {
					$file = "{$this->images}/{$language}/{$page['slug']}-{$shot['number']}.webp";
					if ( ! is_readable( $file ) ) {
						$this->notes[] = "{$page['slug']}: no screenshots, {$file} is missing";
						return [];
					}
				}
			}

			$images = [];
			foreach ( $shots as $shot ) {
				foreach ( [
					'en'            => 'en',
					self::LANGUAGE  => 'pt_BR',
				] as $language => $alt_key ) {
					$file  = "{$this->images}/{$language}/{$page['slug']}-{$shot['number']}.webp";
					$image = $this->image( $file, "{$this->prefix}{$page['slug']}-{$shot['number']}-{$language}", $shot['alt'][ $alt_key ], $post_id );
					if ( null === $image ) {
						return [];
					}
					$images[ $shot['number'] ][ $language ] = $image;
				}
			}
			return $images;
		}

		/**
		 * One screenshot in the media library: reused when its file is unchanged, replaced when it is not.
		 *
		 * @return array{id: int, url: string, alt: string}|null
		 */
		private function image( string $file, string $key, string $alt, int $post_id ): ?array {
			$hash  = (string) md5_file( $file );
			$found = get_posts(
				[
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'meta_key'    => self::IMAGE_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'  => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'numberposts' => 1,
					'fields'      => 'ids',
				]
			);
			$old   = $found ? (int) $found[0] : 0;

			if ( $old && get_post_meta( $old, self::IMAGE_HASH, true ) === $hash ) {
				if ( get_post_meta( $old, '_wp_attachment_image_alt', true ) !== $alt ) {
					update_post_meta( $old, '_wp_attachment_image_alt', wp_slash( $alt ) );
				}
				return [
					'id'  => $old,
					'url' => (string) wp_get_attachment_url( $old ),
					'alt' => $alt,
				];
			}

			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';

			$temporary = wp_tempnam( basename( $file ) );
			copy( $file, $temporary );
			$id = media_handle_sideload(
				[
					'name'     => "help-{$key}.webp",
					'tmp_name' => $temporary,
				],
				$post_id,
				$alt
			);
			if ( is_wp_error( $id ) ) {
				@unlink( $temporary ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				$this->fail( $key, $id->get_error_message() );
				return null;
			}
			update_post_meta( $id, self::IMAGE_KEY, $key );
			update_post_meta( $id, self::IMAGE_HASH, $hash );
			update_post_meta( $id, '_wp_attachment_image_alt', wp_slash( $alt ) );
			if ( $old ) {
				wp_delete_attachment( $old, true );
			}
			return [
				'id'  => (int) $id,
				'url' => (string) wp_get_attachment_url( $id ),
				'alt' => $alt,
			];
		}

		/**
		 * The Portuguese pass: pairs for every page that pairs, nothing for one that does not.
		 *
		 * @param array<int,array<string,mixed>> $pages
		 * @param array<string,WP_Post>          $posts
		 */
		private function portuguese( array $pages, array $posts ): void {
			$translator = apply_filters( 'be_help_translator', new Be_Help_TranslatePress( self::LANGUAGE ) );
			$ready      = $translator->ready();
			if ( true !== $ready ) {
				echo "Portuguese pass skipped: {$ready}\n";
				return;
			}

			$totals = [
				'inserted'  => 0,
				'updated'   => 0,
				'unchanged' => 0,
			];
			$done   = 0;
			$left   = [];
			foreach ( $pages as $page ) {
				$slug = $page['slug'];
				if ( empty( $page['portuguese'] ) ) {
					$left[] = "{$slug}: " . implode( '; ', $page['unpaired'] ?? [ 'not translated' ] );
					continue;
				}
				if ( ! isset( $posts[ $slug ] ) ) {
					$left[] = "{$slug}: not on the website yet";
					continue;
				}
				$post  = $posts[ $slug ];
				$pairs = $page['portuguese']['pairs'];
				$pairs[] = [
					'kind'       => 'text',
					'original'   => (string) $page['title'],
					'translated' => (string) $page['portuguese']['title'],
				];
				foreach ( $this->image_pairs( $page, $post ) as $pair ) {
					$pairs[] = $pair;
				}

				$plan = $translator->plan( $pairs, (string) apply_filters( 'the_content', $post->post_content ) );
				if ( $plan['unmatched'] ) {
					$left[] = "{$slug}: " . count( $plan['unmatched'] ) . ' strings the English page has no place for, first: ' . $plan['unmatched'][0];
					continue;
				}
				if ( $this->dry_run ) {
					++$done;
					continue;
				}
				try {
					$result = $translator->write( $plan['rows'] );
				} catch ( RuntimeException $error ) {
					$left[] = "{$slug}: {$error->getMessage()}";
					continue;
				}
				foreach ( $result as $name => $number ) {
					$totals[ $name ] += $number;
				}
				++$done;
			}

			echo "Portuguese: {$done} pages paired, rows inserted: {$totals['inserted']}, updated: {$totals['updated']}, unchanged: {$totals['unchanged']}\n";
			foreach ( $left as $line ) {
				echo "  Stays English: {$line}\n";
			}
		}

		/**
		 * The pairs that turn a page's English screenshots into its Portuguese ones.
		 *
		 * @param array<string,mixed> $page
		 * @return array<int,array<string,string>>
		 */
		private function image_pairs( array $page, WP_Post $post ): array {
			$pairs = [];
			foreach ( $page['shots'] ?? [] as $shot ) {
				$english    = $this->attachment( "{$this->prefix}{$page['slug']}-{$shot['number']}-en" );
				$portuguese = $this->attachment( "{$this->prefix}{$page['slug']}-{$shot['number']}-" . self::LANGUAGE );
				if ( ! $english || ! $portuguese ) {
					continue;
				}
				$pairs[] = [
					'kind'       => 'src',
					'original'   => (string) wp_get_attachment_url( $english ),
					'translated' => (string) wp_get_attachment_url( $portuguese ),
				];
				$pairs[] = [
					'kind'       => 'alt',
					'original'   => $shot['alt']['en'],
					'translated' => $shot['alt']['pt_BR'],
				];
			}
			return $pairs;
		}

		private function attachment( string $key ): int {
			$found = get_posts(
				[
					'post_type'   => 'attachment',
					'post_status' => 'inherit',
					'meta_key'    => self::IMAGE_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
					'meta_value'  => $key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
					'numberposts' => 1,
					'fields'      => 'ids',
				]
			);
			return $found ? (int) $found[0] : 0;
		}

		/**
		 * Puts a rehearsal's pages in the trash and deletes its screenshots.
		 *
		 * @param array<int,array<string,mixed>> $pages
		 */
		private function remove( array $pages ): void {
			if ( '' === $this->prefix ) {
				echo "BE_HELP_REMOVE needs BE_HELP_PREFIX: it never touches the real pages.\n";
				return;
			}
			$trashed = 0;
			$deleted = 0;
			foreach ( $pages as $page ) {
				$post = get_page_by_path( $this->prefix . $page['slug'], OBJECT, 'docs' );
				if ( $post && ! $this->dry_run ) {
					wp_trash_post( $post->ID );
					++$trashed;
				}
				foreach ( $page['shots'] ?? [] as $shot ) {
					foreach ( [ 'en', self::LANGUAGE ] as $language ) {
						$id = $this->attachment( "{$this->prefix}{$page['slug']}-{$shot['number']}-{$language}" );
						if ( $id && ! $this->dry_run ) {
							wp_delete_attachment( $id, true );
							++$deleted;
						}
					}
				}
			}
			echo "Trashed: {$trashed} pages, deleted: {$deleted} screenshots\n";
		}

		private function fail( string $what, string $why ): void {
			++$this->count['failed'];
			echo "  FAILED: {$what}: {$why}\n";
		}
	}
}

( new Be_Help_Push(
	getenv( 'BE_HELP_JSON' ) ?: '/tmp/help-docs.json',
	getenv( 'BE_HELP_IMAGES' ) ?: '/tmp/help-screenshots',
	'1' === getenv( 'BE_HELP_DRY_RUN' ),
	array_values( array_filter( array_map( 'trim', explode( ',', (string) getenv( 'BE_HELP_ONLY' ) ) ) ) ),
	(string) preg_replace( '/[^a-z0-9-]/', '', strtolower( (string) getenv( 'BE_HELP_PREFIX' ) ) ),
	'1' === getenv( 'BE_HELP_REMOVE' )
) )->run();
