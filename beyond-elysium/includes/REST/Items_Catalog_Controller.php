<?php

namespace BeyondElysium\REST;

use BeyondElysium\Services\Item_Catalog;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller for the declared item catalog - book data, read-only, not chronicle-scoped.
 */
class Items_Catalog_Controller extends Base_Controller {

	protected $rest_base = 'items/catalog';

	/**
	 * Registers the REST route for the item catalog.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->rest_base, [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_items' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );
	}

	/**
	 * Lists the declared item catalog, filtered by a name search, a book slug, and an item type.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response
	 */
	public function get_items( $request ) {
		$items = Item_Catalog::search(
			(string) ( $request->get_param( 'search' ) ?? '' ),
			(string) ( $request->get_param( 'book' ) ?? '' ),
			(string) ( $request->get_param( 'item_type' ) ?? '' )
		);

		$books = [];
		foreach ( Item_Catalog::load()['books'] as $book ) {
			$books[ $book['slug'] ] = $book;
		}

		return new \WP_REST_Response( [
			'items' => array_values( $items ),
			'books' => array_values( $books ),
		], 200 );
	}
}
