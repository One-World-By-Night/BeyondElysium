<?php

namespace BeyondElysium\Tests\Thread;

use WP_UnitTestCase;

/**
 * `bin/push-help-docs.php` puts each help page on the website with its screenshots, and pairs the Portuguese
 * strings, screenshots and alt text with the English ones.
 */
class PushHelpDocsThreadTest extends WP_UnitTestCase {

	/**
	 * A one-pixel lossless WebP image.
	 */
	private const WEBP = 'UklGRhoAAABXRUJQVlA4TA0AAAAvAAAAEAcQERGIiP4HAA==';

	private string $json;
	private string $images;

	public function setUp(): void {
		parent::setUp();
		register_post_type( 'docs', [ 'public' => true ] );
		register_taxonomy( 'doc_category', 'docs' );
		$this->json   = tempnam( sys_get_temp_dir(), 'help-docs-' ) . '.json';
		$this->images = sys_get_temp_dir() . '/help-shots-' . wp_generate_password( 8, false );
		putenv( 'BE_HELP_JSON=' . $this->json );
		putenv( 'BE_HELP_IMAGES=' . $this->images );
		putenv( 'BE_HELP_DRY_RUN' );
		putenv( 'BE_HELP_ONLY' );
		putenv( 'BE_HELP_PREFIX' );
		putenv( 'BE_HELP_REMOVE' );
	}

	public function tearDown(): void {
		putenv( 'BE_HELP_JSON' );
		putenv( 'BE_HELP_IMAGES' );
		putenv( 'BE_HELP_DRY_RUN' );
		putenv( 'BE_HELP_ONLY' );
		putenv( 'BE_HELP_PREFIX' );
		putenv( 'BE_HELP_REMOVE' );
		@unlink( $this->json ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		foreach ( glob( $this->images . '/*/*' ) ?: [] as $file ) {
			@unlink( $file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		foreach ( glob( $this->images . '/*' ) ?: [] as $folder ) {
			@rmdir( $folder ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		@rmdir( $this->images ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		remove_all_filters( 'be_help_translator' );
		unregister_taxonomy( 'doc_category' );
		unregister_post_type( 'docs' );
		kses_init();
		parent::tearDown();
	}

	/**
	 * Runs the script against the given pages and returns what it printed.
	 *
	 * @param array<int,array<string,mixed>> $pages
	 */
	private function push( array $pages ): string {
		file_put_contents( $this->json, (string) wp_json_encode( [ 'pages' => $pages ] ) );
		ob_start();
		include BE_PLUGIN_ROOT . '/bin/push-help-docs.php';
		return (string) ob_get_clean();
	}

	/**
	 * A page as bin/sync-help-docs.js writes it.
	 *
	 * @param array<string,mixed> $extra
	 * @return array<string,mixed>
	 */
	private function page( string $slug, array $extra = [] ): array {
		return $extra + [
			'slug'       => $slug,
			'title'      => "Title {$slug}",
			'blocks'     => [
				"<!-- wp:heading {\"level\":1} -->\n<h1 class=\"wp-block-heading\">Title {$slug}</h1>\n<!-- /wp:heading -->",
				"<!-- wp:paragraph -->\n<p>Body of {$slug}.</p>\n<!-- /wp:paragraph -->",
			],
			'shots'      => [],
			'portuguese' => null,
			'unpaired'   => [ 'no Portuguese translation' ],
		];
	}

	/**
	 * Puts a screenshot file where the script looks for it.
	 */
	private function screenshot( string $language, string $name, ?string $bytes = null ): void {
		if ( ! is_dir( "{$this->images}/{$language}" ) ) {
			mkdir( "{$this->images}/{$language}", 0777, true );
		}
		file_put_contents( "{$this->images}/{$language}/{$name}.webp", $bytes ?? (string) base64_decode( self::WEBP ) );
	}

	/**
	 * A page with one screenshot after its title.
	 *
	 * @return array<string,mixed>
	 */
	private function page_with_shot( string $slug ): array {
		return $this->page(
			$slug,
			[
				'shots' => [
					[
						'number'     => 1,
						'afterBlock' => 0,
						'alt'        => [
							'en'    => "English view of {$slug}",
							'pt_BR' => "Vista em português de {$slug}",
						],
					],
				],
			]
		);
	}

	/**
	 * A translator that records what it is given instead of writing to TranslatePress.
	 *
	 * @param array<int,string> $refuse Page text the English page has no place for.
	 */
	private function fake_translator( array $refuse = [] ): object {
		$fake = new class( $refuse ) {
			/** @var array<int,array<int,array<string,string>>> */
			public array $planned = [];
			/** @var array<int,array<int,array<string,mixed>>> */
			public array $written = [];
			/** @param array<int,string> $refuse */
			public function __construct( private array $refuse ) {}
			/** @return true */
			public function ready() {
				return true;
			}
			/**
			 * @param array<int,array<string,string>> $pairs
			 * @return array{rows: array<int,array<string,string>>, unmatched: array<int,string>}
			 */
			public function plan( array $pairs, string $rendered ): array {
				$this->planned[] = $pairs;
				$unmatched       = [];
				foreach ( $this->refuse as $text ) {
					if ( str_contains( $rendered, $text ) ) {
						$unmatched[] = $text;
					}
				}
				return [
					'rows'      => $pairs,
					'unmatched' => $unmatched,
				];
			}
			/**
			 * @param array<int,array<string,mixed>> $rows
			 * @return array{inserted: int, updated: int, unchanged: int}
			 */
			public function write( array $rows ): array {
				$this->written[] = $rows;
				return [
					'inserted'  => count( $rows ),
					'updated'   => 0,
					'unchanged' => 0,
				];
			}
		};
		add_filter(
			'be_help_translator',
			static fn () => $fake
		);
		return $fake;
	}

	public function test_a_new_page_keeps_its_backslashes(): void {
		$block = "<!-- wp:paragraph -->\n<p>Save it under C:\\Users\\you, and type \\\"quoted\\\" text.</p>\n<!-- /wp:paragraph -->";

		$this->push( [ $this->page( 'thread-help-backslash', [ 'title' => 'Back\\slash', 'blocks' => [ $block ] ] ) ] );

		$post = get_page_by_path( 'thread-help-backslash', OBJECT, 'docs' );
		$this->assertSame( $block, $post->post_content );
		$this->assertSame( 'Back\\slash', $post->post_title );
	}

	public function test_a_page_is_its_blocks_with_a_blank_line_between(): void {
		$page = $this->page( 'thread-help-blocks' );

		$this->push( [ $page ] );

		$this->assertSame( implode( "\n\n", $page['blocks'] ), get_page_by_path( 'thread-help-blocks', OBJECT, 'docs' )->post_content );
	}

	public function test_an_updated_page_keeps_its_backslashes_and_a_second_run_changes_nothing(): void {
		$this->push( [ $this->page( 'thread-help-update' ) ] );
		$block = "<!-- wp:paragraph -->\n<p>A regex like \\d+ stays as typed.</p>\n<!-- /wp:paragraph -->";
		$page  = $this->page( 'thread-help-update', [ 'blocks' => [ $block ] ] );

		$printed = $this->push( [ $page ] );
		$this->assertStringContainsString( 'Updated: 1', $printed );
		$this->assertSame( $block, get_page_by_path( 'thread-help-update', OBJECT, 'docs' )->post_content );

		$again = $this->push( [ $page ] );
		$this->assertStringContainsString( 'Created: 0, Updated: 0, Unchanged: 1, Failed: 0', $again );
	}

	public function test_every_page_lands_in_the_help_category(): void {
		$this->push( [ $this->page( 'thread-help-category' ) ] );

		$post  = get_page_by_path( 'thread-help-category', OBJECT, 'docs' );
		$terms = wp_get_object_terms( $post->ID, 'doc_category', [ 'fields' => 'slugs' ] );
		$this->assertSame( [ 'help' ], $terms );
	}

	public function test_screenshots_are_uploaded_with_alt_text_and_placed_under_their_heading(): void {
		$this->screenshot( 'en', 'thread-help-shot-1' );
		$this->screenshot( 'pt_BR', 'thread-help-shot-1' );

		$this->push( [ $this->page_with_shot( 'thread-help-shot' ) ] );

		$post = get_page_by_path( 'thread-help-shot', OBJECT, 'docs' );
		$blocks = explode( "\n\n", $post->post_content );
		$this->assertCount( 3, $blocks );
		$this->assertStringContainsString( 'wp:heading', $blocks[0] );
		$this->assertStringContainsString( '<!-- wp:image', $blocks[1] );
		$this->assertStringContainsString( 'alt="English view of thread-help-shot"', $blocks[1] );
		$this->assertStringContainsString( 'wp:paragraph', $blocks[2] );

		$english    = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_be_help_image', 'meta_value' => 'thread-help-shot-1-en', 'numberposts' => 1 ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$portuguese = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_be_help_image', 'meta_value' => 'thread-help-shot-1-pt_BR', 'numberposts' => 1 ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$this->assertCount( 1, $english );
		$this->assertCount( 1, $portuguese );
		$this->assertSame( 'English view of thread-help-shot', get_post_meta( $english[0]->ID, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Vista em português de thread-help-shot', get_post_meta( $portuguese[0]->ID, '_wp_attachment_image_alt', true ) );
		$this->assertSame( $post->ID, (int) $english[0]->post_parent );
	}

	public function test_a_second_run_keeps_the_same_screenshots_and_changes_nothing(): void {
		$this->screenshot( 'en', 'thread-help-again-1' );
		$this->screenshot( 'pt_BR', 'thread-help-again-1' );
		$page = $this->page_with_shot( 'thread-help-again' );
		$this->push( [ $page ] );
		$first = get_page_by_path( 'thread-help-again', OBJECT, 'docs' )->post_content;

		$printed = $this->push( [ $page ] );

		$this->assertSame( $first, get_page_by_path( 'thread-help-again', OBJECT, 'docs' )->post_content );
		$this->assertStringContainsString( 'Created: 0, Updated: 0, Unchanged: 1, Failed: 0', $printed );
		$all = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_be_help_image', 'numberposts' => -1 ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$this->assertCount( 2, $all );
	}

	public function test_a_changed_screenshot_replaces_the_old_one_and_a_changed_alt_text_does_not(): void {
		$this->screenshot( 'en', 'thread-help-swap-1' );
		$this->screenshot( 'pt_BR', 'thread-help-swap-1' );
		$page = $this->page_with_shot( 'thread-help-swap' );
		$this->push( [ $page ] );
		$old = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_be_help_image', 'meta_value' => 'thread-help-swap-1-en', 'numberposts' => 1, 'fields' => 'ids' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery

		$page['shots'][0]['alt']['en'] = 'A new description';
		$this->push( [ $page ] );
		$same = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_be_help_image', 'meta_value' => 'thread-help-swap-1-en', 'numberposts' => 1, 'fields' => 'ids' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$this->assertSame( $old, $same );
		$this->assertSame( 'A new description', get_post_meta( $same[0], '_wp_attachment_image_alt', true ) );

		$this->screenshot( 'en', 'thread-help-swap-1', (string) base64_decode( 'UklGRiIAAABXRUJQVlA4IBYAAAAwAQCdASoBAAEADsD+JaQAA3AAAAAA' ) );
		$this->push( [ $page ] );
		$new = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_be_help_image', 'meta_value' => 'thread-help-swap-1-en', 'numberposts' => 1, 'fields' => 'ids' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$this->assertNotSame( $old, $new );
		$this->assertNull( get_post( $old[0] ) );
		$this->assertStringContainsString( "wp-image-{$new[0]}", get_page_by_path( 'thread-help-swap', OBJECT, 'docs' )->post_content );
	}

	public function test_a_page_with_a_screenshot_file_missing_is_pushed_as_text_and_listed(): void {
		$this->screenshot( 'en', 'thread-help-missing-1' );

		$printed = $this->push( [ $this->page_with_shot( 'thread-help-missing' ) ] );

		$post = get_page_by_path( 'thread-help-missing', OBJECT, 'docs' );
		$this->assertStringNotContainsString( 'wp:image', $post->post_content );
		$this->assertStringContainsString( 'thread-help-missing: no screenshots', $printed );
		$this->assertCount( 0, get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_be_help_image', 'numberposts' => -1 ] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	public function test_the_portuguese_pass_is_skipped_when_translatepress_is_not_there(): void {
		$printed = $this->push( [ $this->page( 'thread-help-no-tp', [ 'portuguese' => [ 'title' => 'T', 'pairs' => [] ] ] ) ] );

		$this->assertStringContainsString( 'Portuguese pass skipped: TranslatePress is not active', $printed );
	}

	public function test_the_portuguese_pass_hands_over_a_pages_pairs_its_title_and_its_screenshots(): void {
		$this->screenshot( 'en', 'thread-help-pt-1' );
		$this->screenshot( 'pt_BR', 'thread-help-pt-1' );
		$fake = $this->fake_translator();
		$page = $this->page_with_shot( 'thread-help-pt' );
		$page['portuguese'] = [
			'title' => 'Título thread-help-pt',
			'pairs' => [ [ 'kind' => 'text', 'original' => 'Body of thread-help-pt.', 'translated' => 'Corpo de thread-help-pt.' ] ],
		];

		$printed = $this->push( [ $page ] );

		$this->assertStringContainsString( 'Portuguese: 1 pages paired', $printed );
		$this->assertCount( 1, $fake->planned );
		$kinds = array_column( $fake->planned[0], 'kind' );
		$this->assertSame( [ 'text', 'text', 'src', 'alt' ], $kinds );
		$this->assertSame( 'Title thread-help-pt', $fake->planned[0][1]['original'] );
		$this->assertSame( 'Título thread-help-pt', $fake->planned[0][1]['translated'] );
		$this->assertMatchesRegularExpression( '/help-thread-help-pt-1-en(-\d+)?\.webp$/', $fake->planned[0][2]['original'] );
		$this->assertMatchesRegularExpression( '/help-thread-help-pt-1-pt_BR(-\d+)?\.webp$/', $fake->planned[0][2]['translated'] );
		$this->assertSame( 'English view of thread-help-pt', $fake->planned[0][3]['original'] );
		$this->assertSame( 'Vista em português de thread-help-pt', $fake->planned[0][3]['translated'] );
		$this->assertCount( 1, $fake->written );
	}

	public function test_a_page_that_does_not_pair_stays_english_and_is_listed_with_nothing_written_for_it(): void {
		$fake = $this->fake_translator( [ 'cannot be placed' ] );
		$good = $this->page( 'thread-help-good', [ 'portuguese' => [ 'title' => 'Bom', 'pairs' => [] ] ] );
		$bad  = $this->page( 'thread-help-bad', [ 'blocks' => [ "<!-- wp:paragraph -->\n<p>cannot be placed</p>\n<!-- /wp:paragraph -->" ], 'portuguese' => [ 'title' => 'Ruim', 'pairs' => [] ] ] );
		$none = $this->page( 'thread-help-none', [ 'unpaired' => [ 'block 4: the markup differs' ] ] );

		$printed = $this->push( [ $good, $bad, $none ] );

		$this->assertStringContainsString( 'Portuguese: 1 pages paired', $printed );
		$this->assertStringContainsString( 'Stays English: thread-help-bad', $printed );
		$this->assertStringContainsString( 'Stays English: thread-help-none: block 4: the markup differs', $printed );
		$this->assertCount( 1, $fake->written );
	}

	public function test_a_dry_run_writes_nothing(): void {
		$this->screenshot( 'en', 'thread-help-dry-1' );
		$this->screenshot( 'pt_BR', 'thread-help-dry-1' );
		$fake = $this->fake_translator();
		putenv( 'BE_HELP_DRY_RUN=1' );

		$printed = $this->push( [ $this->page_with_shot( 'thread-help-dry' ) ] );

		$this->assertNull( get_page_by_path( 'thread-help-dry', OBJECT, 'docs' ) );
		$this->assertFalse( get_term_by( 'slug', 'help', 'doc_category' ) );
		$this->assertCount( 0, get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_be_help_image', 'numberposts' => -1 ] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$this->assertCount( 0, $fake->written );
		$this->assertStringContainsString( 'Dry run: nothing was written.', $printed );
	}

	public function test_only_the_named_pages_are_pushed(): void {
		putenv( 'BE_HELP_ONLY=thread-help-one, thread-help-three' );

		$printed = $this->push( [ $this->page( 'thread-help-one' ), $this->page( 'thread-help-two' ), $this->page( 'thread-help-three' ) ] );

		$this->assertStringContainsString( 'Created: 2,', $printed );
		$this->assertNotNull( get_page_by_path( 'thread-help-one', OBJECT, 'docs' ) );
		$this->assertNull( get_page_by_path( 'thread-help-two', OBJECT, 'docs' ) );
	}

	public function test_a_rehearsal_goes_under_its_prefix_and_out_of_the_help_category_and_can_be_cleared(): void {
		$this->screenshot( 'en', 'thread-help-trial-1' );
		$this->screenshot( 'pt_BR', 'thread-help-trial-1' );
		putenv( 'BE_HELP_PREFIX=trial-' );

		$this->push( [ $this->page_with_shot( 'thread-help-trial' ) ] );

		$post = get_page_by_path( 'trial-thread-help-trial', OBJECT, 'docs' );
		$this->assertNotNull( $post );
		$this->assertNull( get_page_by_path( 'thread-help-trial', OBJECT, 'docs' ) );
		$this->assertSame( [], wp_get_object_terms( $post->ID, 'doc_category', [ 'fields' => 'ids' ] ) );
		$kept = get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_be_help_image', 'numberposts' => -1, 'fields' => 'ids' ] ); // phpcs:ignore WordPress.DB.SlowDBQuery
		$this->assertCount( 2, $kept );

		putenv( 'BE_HELP_REMOVE=1' );
		$printed = $this->push( [ $this->page_with_shot( 'thread-help-trial' ) ] );

		$this->assertStringContainsString( 'Trashed: 1 pages, deleted: 2 screenshots', $printed );
		$this->assertSame( 'trash', get_post_status( $post->ID ) );
		$this->assertCount( 0, get_posts( [ 'post_type' => 'attachment', 'post_status' => 'inherit', 'meta_key' => '_be_help_image', 'numberposts' => -1 ] ) ); // phpcs:ignore WordPress.DB.SlowDBQuery
	}

	public function test_clearing_a_rehearsal_needs_a_prefix_so_it_never_touches_the_real_pages(): void {
		$this->push( [ $this->page( 'thread-help-real' ) ] );
		putenv( 'BE_HELP_REMOVE=1' );

		$printed = $this->push( [ $this->page( 'thread-help-real' ) ] );

		$this->assertStringContainsString( 'BE_HELP_REMOVE needs BE_HELP_PREFIX', $printed );
		$this->assertSame( 'publish', get_post_status( get_page_by_path( 'thread-help-real', OBJECT, 'docs' )->ID ) );
	}

	public function test_text_is_set_the_way_the_website_holds_it(): void {
		$this->push( [ $this->page( 'thread-help-plain' ) ] );
		$translator = new \Be_Help_TranslatePress( 'pt_BR' );
		$plain      = new \ReflectionMethod( $translator, 'plain' );

		$this->assertSame( 'It&#39;s &#8211; here &amp; &quot;there&quot;', $plain->invoke( $translator, "It's - here & \"there\"" ) );
		$this->assertSame( "Two\nlines", $plain->invoke( $translator, "  Two\nlines  " ) );
	}
}
