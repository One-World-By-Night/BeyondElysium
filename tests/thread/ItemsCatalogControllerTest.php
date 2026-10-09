<?php

namespace BeyondElysium\Tests\Thread;

use WP_REST_Request;
use WP_UnitTestCase;

/**
 * `GET /items/catalog`: any signed-in account reads the real shipped book data, with search/book/type filters, and
 * nothing chronicle-private ever enters the response - there is no chronicle in this route at all.
 */
class ItemsCatalogControllerTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		do_action( 'rest_api_init' );
	}

	private function request( array $params = [] ) {
		$request = new WP_REST_Request( 'GET', '/be/v1/items/catalog' );
		foreach ( $params as $key => $value ) {
			$request->set_param( $key, $value );
		}
		return rest_get_server()->dispatch( $request );
	}

	public function test_a_plain_subscriber_lists_the_real_catalog(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$response = $this->request();
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertGreaterThanOrEqual( 43, count( $data['items'] ) );
		$this->assertContains( 'Dark Epics', array_column( $data['books'], 'name' ) );
	}

	public function test_a_logged_out_visitor_is_refused(): void {
		wp_set_current_user( 0 );
		$this->assertSame( 403, $this->request()->get_status() );
	}

	public function test_search_filters_by_name(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$data = $this->request( [ 'search' => 'Broken Bottle' ] )->get_data();
		$this->assertCount( 3, $data['items'], 'Dark Epics, Laws of the East and Laws of the Reckoning each print a Broken Bottle with real, different stats' );
		foreach ( $data['items'] as $item ) {
			$this->assertSame( 'Broken Bottle', $item['name'] );
		}
		$this->assertSame(
			[ 'dark-epics', 'laws-of-the-east', 'laws-of-the-reckoning' ],
			array_column( $data['items'], 'book_slug' )
		);
	}

	public function test_search_filters_by_name_to_a_single_match(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$data = $this->request( [ 'search' => 'Dart of Nullity' ] )->get_data();
		$this->assertCount( 1, $data['items'] );
		$this->assertSame( 'Dart of Nullity', $data['items'][0]['name'] );
	}

	public function test_book_filter_narrows_to_that_book(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$data = $this->request( [ 'book' => 'dark-epics' ] )->get_data();
		$this->assertSame( 43, count( $data['items'] ) );
		foreach ( $data['items'] as $item ) {
			$this->assertSame( 'dark-epics', $item['book_slug'] );
		}

		$empty = $this->request( [ 'book' => 'no-such-book' ] )->get_data();
		$this->assertSame( [], $empty['items'] );
	}

	public function test_item_type_filter_narrows_to_that_type(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$data = $this->request( [ 'item_type' => 'Shield' ] )->get_data();
		$this->assertSame( 4, count( $data['items'] ) );
		foreach ( $data['items'] as $item ) {
			$this->assertSame( 'Shield', $item['properties']['item_type'] );
		}
	}

	public function test_nothing_in_the_response_carries_a_chronicle(): void {
		$subscriber = self::factory()->user->create( [ 'role' => 'subscriber' ] );
		wp_set_current_user( $subscriber );

		$data = $this->request()->get_data();
		foreach ( $data['items'] as $item ) {
			$this->assertArrayNotHasKey( 'game_id', $item );
			$this->assertArrayNotHasKey( 'game_slug', $item );
			$this->assertArrayNotHasKey( 'owner_slug', $item );
		}
	}
}
