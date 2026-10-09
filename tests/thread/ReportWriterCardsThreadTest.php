<?php

namespace BeyondElysium\Tests\Thread;

require_once __DIR__ . '/../support/PdfSigningTestFixture.php';

use BeyondElysium\Models\Attachment;
use BeyondElysium\Models\Game;
use BeyondElysium\Models\World_Object;
use BeyondElysium\Services\Attachment_Storage;
use BeyondElysium\Services\Report_Document;
use BeyondElysium\Services\Report_Writer;
use BeyondElysium\Tests\Support\PdfSigningTestFixture;
use WP_REST_Request;
use WP_UnitTestCase;

/**
 * Item Cards: `build_card()`'s own picture and uses, the landscape 2x2 grid's page break, and a card too long for
 * one face printing as a front and back pair that takes a full row.
 */
class ReportWriterCardsThreadTest extends WP_UnitTestCase {

	private string $game_slug = 'thread-report-writer-cards';
	private int $game_id;
	private int $manager_id;
	private array $written_paths = [];

	public static function setUpBeforeClass(): void {
		parent::setUpBeforeClass();
		PdfSigningTestFixture::ensure();
	}

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
		$this->game_id    = (int) Game::create( [ 'slug' => $this->game_slug, 'name' => 'Report Writer Cards' ] );
		$this->manager_id = self::factory()->user->create( [ 'role' => 'administrator' ] );
	}

	protected function tearDown(): void {
		foreach ( $this->written_paths as $path ) {
			if ( is_dir( $path ) ) {
				array_map( 'unlink', glob( $path . '/*' ) ?: [] );
				rmdir( $path );
			} elseif ( is_file( $path ) ) {
				unlink( $path );
			}
		}
		if ( is_file( $this->pdf_path() ) ) {
			unlink( $this->pdf_path() );
		}
		parent::tearDown();
	}

	private function make_item( string $name, array $properties = [] ): int {
		return (int) World_Object::create( [
			'game_id'     => $this->game_id,
			'object_type' => 'item',
			'name'        => $name,
			'properties'  => $properties,
			'created_by'  => $this->manager_id,
		] );
	}

	private function attach_picture_to( string $entity_type, int $entity_id ): int {
		wp_set_current_user( $this->manager_id );

		$path  = tempnam( sys_get_temp_dir(), 'be-test-png-' );
		$image = imagecreate( 2, 2 );
		imagecolorallocate( $image, 255, 0, 0 );
		imagepng( $image, $path );
		$this->written_paths[] = $path;

		$request = new WP_REST_Request( 'POST', "/be/v1/{$this->game_slug}/attachments" );
		$request->set_param( 'entity_type', $entity_type );
		$request->set_param( 'entity_id', $entity_id );
		$request->set_file_params( [ 'file' => [
			'tmp_name' => $path,
			'name'     => 'photo.png',
			'error'    => 0,
			'size'     => filesize( $path ),
			'type'     => 'image/png',
		] ] );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 201, $response->get_status() );

		$id  = (int) $response->get_data()['id'];
		$row = Attachment::find( $id );
		$this->written_paths[] = dirname( Attachment_Storage::path_for( $row->stored_name, $row->original_name ) );

		return $id;
	}

	private function pdf_path(): string {
		return sys_get_temp_dir() . '/be-report-writer-cards-' . $this->getName() . '.pdf';
	}

	private static function extract_text( string $path, ?int $page = null ): string {
		if ( ! shell_exec( 'command -v pdftotext' ) ) {
			self::markTestSkipped( 'pdftotext (poppler) is not installed.' );
		}
		$range = $page !== null ? sprintf( '-f %1$d -l %1$d ', $page ) : '';
		return (string) shell_exec( 'pdftotext ' . $range . escapeshellarg( $path ) . ' - 2>/dev/null' );
	}

	private static function pdfsig( string $path ): string {
		if ( ! shell_exec( 'command -v pdfsig' ) ) {
			self::markTestSkipped( 'pdfsig (poppler) is not installed.' );
		}
		return (string) shell_exec( 'pdfsig ' . escapeshellarg( $path ) . ' 2>/dev/null' );
	}

	// -------------------------------------------------------------------------
	// build_card() returns the picture and the uses.
	// -------------------------------------------------------------------------

	public function test_build_card_returns_the_picture_and_the_uses(): void {
		$item_id      = $this->make_item( 'Charm of Ravens', [ 'uses_max' => 3, 'uses_left' => 1 ] );
		$attachment_id = $this->attach_picture_to( 'item', $item_id );

		$document = Report_Document::build( 'item-cards', $this->game_slug, [], [ 'can_manage' => true ] );
		$card     = $document['cards'][0];

		$this->assertSame( 'Charm of Ravens', $card['name'] );
		$this->assertSame( $attachment_id, $card['picture'] );
		$this->assertSame( 3, $card['uses_max'] );
		$this->assertSame( 2, $card['uses_used'] );
	}

	// -------------------------------------------------------------------------
	// Five items render two pages, four on the first.
	// -------------------------------------------------------------------------

	public function test_five_items_render_two_pages_four_on_the_first(): void {
		// Named in alphabetical order, since the report sorts by name.
		$names = [ 'Card Item A', 'Card Item B', 'Card Item C', 'Card Item D', 'Card Item E' ];
		foreach ( $names as $name ) {
			$this->make_item( $name );
		}

		$document = Report_Document::build( 'item-cards', $this->game_slug, [], [ 'can_manage' => true ] );
		$bytes    = Report_Writer::write( $document, (object) [ 'name' => $this->game_slug ] );
		file_put_contents( $this->pdf_path(), $bytes );

		$this->assertSame( 2, preg_match_all( '/\/Type\s*\/Page[^s]/', $bytes ) );

		$page_one = self::extract_text( $this->pdf_path(), 1 );
		$page_two = self::extract_text( $this->pdf_path(), 2 );

		foreach ( array_slice( $names, 0, 4 ) as $name ) {
			$this->assertStringContainsString( $name, $page_one );
		}
		$this->assertStringNotContainsString( $names[4], $page_one );
		$this->assertStringContainsString( $names[4], $page_two );
	}

	// -------------------------------------------------------------------------
	// A card too long for one face prints as a front and back pair, and the signature still verifies.
	// -------------------------------------------------------------------------

	public function test_a_card_too_long_for_one_face_prints_front_and_back_and_still_verifies(): void {
		$long_powers = implode( ' ', array_fill( 0, 10, 'Shimmering runes of ancient power cover every inch, humming faintly when danger nears.' ) );
		$this->make_item( 'Overflowing Relic', [
			'item_type'      => 'Artifact',
			'bonus'          => 2,
			'damage_type'    => 'Lethal',
			'damage_amount'  => 3,
			'concealability' => 'Trench',
			'negatives'      => [ [ 'name' => 'Heavy' ] ],
			'powers'         => '<p>' . $long_powers . '</p>',
		] );

		$document = Report_Document::build( 'item-cards', $this->game_slug, [], [ 'can_manage' => true ] );
		$bytes    = Report_Writer::write( $document, (object) [ 'name' => $this->game_slug ] );
		file_put_contents( $this->pdf_path(), $bytes );

		$text = self::extract_text( $this->pdf_path() );
		$this->assertSame( 2, substr_count( $text, 'Overflowing Relic' ), 'the title prints once per face' );
		$this->assertSame( 1, substr_count( $text, 'Overflowing Relic (cont.)' ), 'only the back face is labelled as a continuation' );
		$this->assertStringContainsString( 'Signature Validation: Signature is Valid.', self::pdfsig( $this->pdf_path() ) );
	}
}
