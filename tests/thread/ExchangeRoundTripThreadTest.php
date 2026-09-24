<?php

namespace BeyondElysium\Tests\Thread;

use BeyondElysium\Database\Seeder;
use BeyondElysium\Models\Character;
use BeyondElysium\Models\Game;
use BeyondElysium\REST\Import_Controller;
use BeyondElysium\Services\Catalog_Cutover;
use BeyondElysium\Services\Character_Diff;
use BeyondElysium\Services\Character_Exporter;
use BeyondElysium\Services\GEX_Xml_Parser;
use WP_UnitTestCase;

/**
 * A character exported and imported back arrives as it left, over the 22 demo characters.
 */
class ExchangeRoundTripThreadTest extends WP_UnitTestCase {

	private string $slug = 'thread-round-trip';
	private int $game_id;

	public function setUp(): void {
		parent::setUp();

		// Declares the install before the demo characters are seeded.
		update_option( Catalog_Cutover::OPTION, 'declared' );
		Seeder::seed_creature_stacks();

		global $wpdb;
		$wpdb->query( "DELETE FROM {$wpdb->prefix}be_characters WHERE owner_slug = 'be-demo'" );
		$wpdb->query( "DELETE FROM {$wpdb->prefix}be_games WHERE slug = 'be-demo'" );
		delete_option( Seeder::DEMO_SEEDED_OPTION );
		Seeder::seed_demo_characters( true );

		$this->game_id = (int) Game::create( [ 'slug' => $this->slug, 'name' => 'Round Trip' ] );
		wp_set_current_user( self::factory()->user->create( [ 'role' => 'administrator' ] ) );
	}

	public function tearDown(): void {
		delete_option( Catalog_Cutover::OPTION );
		parent::tearDown();
	}

	private static function exported( int $id ): array {
		return GEX_Xml_Parser::parse_string( Character_Exporter::export( $id )['xml'] );
	}

	private function import( array $parsed, array $resolutions = [] ): object {
		$result = Import_Controller::apply_import( $this->game_id, $this->slug, $parsed, 'round-trip.gex', $resolutions );
		return Character::find( (int) $result['characters'][0]['id'] );
	}

	/**
	 * Sheet data as a Storyteller reads it: a trait with no count holds one, and an identity field or pool left empty or
	 * at zero is the same as one never filled.
	 *
	 * @param mixed $sheet
	 * @return array<string,string> block => canonical JSON.
	 */
	private static function readable( $sheet ): array {
		$out = [];
		foreach ( json_decode( wp_json_encode( $sheet ), true ) ?: [] as $block => $held ) {
			if ( is_array( $held ) && array_is_list( $held ) ) {
				$held = array_map( static function ( $entry ) {
					if ( is_array( $entry ) && ! isset( $entry['level'] ) && ! isset( $entry['power_name'] ) ) {
						$entry['count'] = $entry['count'] ?? 1;
					}
					if ( is_array( $entry ) ) {
						ksort( $entry );
					}
					return $entry;
				}, $held );
			} elseif ( is_array( $held ) ) {
				$held = array_filter( $held, static fn( $value ) => ! in_array( $value, [ '', null, 0, [ 'permanent' => 0, 'temporary' => 0 ] ], true ) );
				ksort( $held );
			}
			if ( $held !== [] ) {
				$out[ $block ] = wp_json_encode( $held );
			}
		}
		ksort( $out );
		return $out;
	}

	/** @return array<int,string> Every difference between two characters, one line each. */
	private static function losses( object $original, object $copy ): array {
		$losses = [];
		if ( $copy->stack_slug !== $original->stack_slug ) {
			$losses[] = "{$original->name}: a {$original->stack_slug} came back as a {$copy->stack_slug}";
		}
		$was = self::readable( $original->sheet_data );
		$now = self::readable( $copy->sheet_data );
		foreach ( array_keys( $was + $now ) as $block ) {
			if ( ( $was[ $block ] ?? null ) !== ( $now[ $block ] ?? null ) ) {
				$losses[] = "{$original->name} {$block}: " . ( $was[ $block ] ?? 'nothing' ) . ' became ' . ( $now[ $block ] ?? 'nothing' );
			}
		}
		foreach ( [ 'player_name', 'status', 'is_npc', 'xp_earned', 'xp_unspent', 'biography', 'notes' ] as $column ) {
			if ( trim( (string) $original->$column ) !== trim( (string) $copy->$column ) ) {
				$losses[] = "{$original->name} {$column}: " . var_export( $original->$column, true ) . ' became ' . var_export( $copy->$column, true );
			}
		}
		return $losses;
	}

	public function test_every_demo_character_comes_back_as_it_left(): void {
		global $wpdb;
		$ids = array_map( 'intval', $wpdb->get_col( "SELECT id FROM {$wpdb->prefix}be_characters WHERE owner_slug = 'be-demo' ORDER BY id" ) );
		$this->assertCount( 22, $ids );

		$losses = [];
		foreach ( $ids as $id ) {
			$original = Character::find( $id );
			try {
				$document = self::exported( $id );
			} catch ( \Throwable $e ) {
				$losses[] = "{$original->name}: export failed - " . $e->getMessage();
				continue;
			}
			$copy   = $this->import( $document );
			$losses = array_merge( $losses, self::losses( $original, $copy ) );
			foreach ( Character_Diff::compare( $document['characters'][0], self::exported( (int) $copy->id )['characters'][0] ) as $row ) {
				$losses[] = "{$original->name} exports differently after the trip: " . wp_json_encode( $row );
			}
			Character::delete( (int) $copy->id );
		}

		// Each known loss must still occur; one that stops occurring is removed from KNOWN_LOSSES.
		foreach ( self::KNOWN_LOSSES as $pattern => $why ) {
			$this->assertNotEmpty(
				array_filter( $losses, static fn( $loss ) => (bool) preg_match( $pattern, $loss ) ),
				"Fixed? {$why} no longer loses anything - remove it from KNOWN_LOSSES."
			);
		}
		$losses = array_values( array_filter( $losses, static function ( $loss ) {
			foreach ( array_keys( self::KNOWN_LOSSES ) as $pattern ) {
				if ( preg_match( $pattern, $loss ) ) {
					return false;
				}
			}
			return true;
		} ) );

		$this->assertSame( [], $losses );
	}

	/**
	 * Losses on the demo characters that the exchange document does not carry.
	 *
	 * @var array<string,string> pattern => what the exchange document cannot carry.
	 */
	private const KNOWN_LOSSES = [
		'/^(Meridian|Ashkelon) demon-abilities: /' => 'A label on a held trait (a Demon\'s Lore of a kind) has nowhere to travel: neither the exporter nor the importer reads `specialization`.',
		'/^Samuel Ostrowski mortal-fomori: /'      => 'A Mortal\'s Numina have no exchange list: the map still names the block they were split out of.',
	];

	public function test_a_bete_character_reaches_grapevine_as_a_fera_and_beyond_elysium_as_a_bete(): void {
		global $wpdb;
		$bete = (int) $wpdb->get_var( "SELECT id FROM {$wpdb->prefix}be_characters WHERE owner_slug = 'be-demo' AND stack_slug = 'bete' ORDER BY id LIMIT 1" );

		$xml = Character_Exporter::export( $bete )['xml'];

		$this->assertMatchesRegularExpression( '/^  <fera /m', $xml, 'Grapevine 3.01 reads no <bete> element, so a Bete travels as the Fera it shares every block with' );
		$this->assertSame( 'bete', $this->import( GEX_Xml_Parser::parse_string( $xml ) )->stack_slug );
	}

	public function test_a_spectre_stays_a_spectre(): void {
		$id = Character::create( [
			'name' => 'Hollow Voice', 'stack_slug' => 'wraith', 'owner_type' => 'chronicle', 'owner_slug' => 'be-demo', 'status' => 'active',
			'sheet_data' => [ 'wraith-identity' => [ 'Ethnos' => 'Spectre', 'Guild' => 'Haunter' ] ],
		] );

		$copy = $this->import( self::exported( $id ) );

		$this->assertSame( 'Spectre', $copy->sheet_data['wraith-identity']['Ethnos'] ?? null );
	}

	public function test_biography_notes_and_trait_notes_survive_the_trip(): void {
		$id = Character::create( [
			'name' => 'Letter Writer', 'stack_slug' => 'vampire', 'owner_type' => 'chronicle', 'owner_slug' => 'be-demo', 'status' => 'active',
			'biography'  => "<p>Embraced in Vienna.</p>\n<p>Fled in 1938.</p>",
			'notes'      => '<p>Owes the Prince a favor.</p>',
			'sheet_data' => [ 'vampire-backgrounds' => [ [ 'name' => 'Retainers', 'count' => 2, 'note' => 'Ghoul chauffeur' ] ] ],
		] );
		$original = Character::find( $id );

		$copy = $this->import( self::exported( $id ) );

		$this->assertSame( [], self::losses( $original, $copy ) );
	}

	public function test_a_character_coming_home_keeps_what_the_document_cannot_carry(): void {
		$id = Character::create( [
			'name' => 'Homecoming Pooka', 'stack_slug' => 'changeling', 'owner_type' => 'chronicle', 'owner_slug' => $this->slug, 'status' => 'active',
			'sheet_data' => [
				'met-archetypes'       => [ 'Nature' => 'Trickster', 'Demeanor' => 'Jester' ],
				'changeling-identity'  => [ 'Kith' => 'Pooka', 'Court' => 'Seelie' ],
				'changeling-merits'    => [ [ 'name' => 'Common Sense', 'count' => 1 ] ],
				'thread-house-section' => [ 'Oath' => 'Sworn to the Duke' ],
			],
		] );
		$document = self::exported( $id );

		// Played on at home after the document left: a merit gained that the document never saw.
		$sheet                 = json_decode( wp_json_encode( Character::find( $id )->sheet_data ), true );
		$sheet['changeling-merits'][] = [ 'name' => 'Iron Will', 'count' => 1 ];
		Character::update_sheet_data( $id, $sheet );

		$back = $this->import( $document, [ 'duplicates' => [ 'Homecoming Pooka' => 'overwrite' ] ] );

		$this->assertSame( $id, (int) $back->id );
		$this->assertSame( [ 'Nature' => 'Trickster', 'Demeanor' => 'Jester' ], $back->sheet_data['met-archetypes'] ?? null, 'a changeling document has no Nature or Demeanor to replace them with' );
		$this->assertSame( [ 'Oath' => 'Sworn to the Duke' ], $back->sheet_data['thread-house-section'] ?? null, 'no exchange list carries a chronicle\'s own section' );
		$this->assertSame( [ 'Common Sense' ], array_column( $back->sheet_data['changeling-merits'], 'name' ), 'what the document does carry, it replaces' );
	}
}
