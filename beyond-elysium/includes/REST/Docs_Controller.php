<?php

namespace BeyondElysium\REST;

defined( 'ABSPATH' ) || exit;

/**
 * REST controller that serves the plugin's shipped documentation files.
 *
 * Reads a Markdown file from the plugin's own `docs/` directory and returns
 * its raw content. The requested slug is constrained by the route's regex to
 * the fixed set of known documents, so there is no path-traversal surface to
 * defend against. A help page (`docs/help/{key}.md`, one per screen, opened by
 * the screen's `?` - 1.0.0-help.md H-1) is served only for a key that names
 * one of those files.
 */
class Docs_Controller extends Base_Controller {

	protected $rest_base = 'docs';

	/** The allowed document slugs, used directly in the route's own regex. */
	const SLUGS = [ 'st-guide', 'admin-guide', 'player-guide', 'rest-api' ];

	/**
	 * Registers the docs route.
	 *
	 * Adds a single GET route whose slug segment is restricted by regex to
	 * the values in `SLUGS`.
	 */
	public function register_routes(): void {
		register_rest_route( $this->namespace, '/' . $this->rest_base . '/(?P<slug>' . implode( '|', self::SLUGS ) . ')', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_item' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );

		register_rest_route( $this->namespace, '/' . $this->rest_base . '/help/(?P<key>[a-z0-9-]+)', [
			[
				'methods'             => 'GET',
				'callback'            => [ $this, 'get_help' ],
				'permission_callback' => $this->permission( 'be_view_characters' ),
			],
		] );
	}

	/**
	 * Returns one screen's help page.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_help( $request ) {
		$key = (string) $request['key'];
		if ( ! in_array( $key, self::help_keys(), true ) ) {
			return $this->error( 'not_found', __( 'That document was not found.', 'beyond-elysium' ), 404 );
		}

		$content = file_get_contents( BE_PLUGIN_DIR . 'docs/help/' . $key . '.md' );
		if ( $content === false ) {
			return $this->error( 'read_failed', __( 'That document could not be read.', 'beyond-elysium' ), 500 );
		}

		return $this->success( [ 'key' => $key, 'content' => $content ] );
	}

	/**
	 * The key of every help page the plugin ships: each `docs/help/*.md` file's name.
	 *
	 * @return string[]
	 */
	private static function help_keys(): array {
		return array_map(
			static fn( string $path ): string => basename( $path, '.md' ),
			glob( BE_PLUGIN_DIR . 'docs/help/*.md' ) ?: []
		);
	}

	/**
	 * Returns the content of one shipped documentation file.
	 *
	 * Reads the Markdown file matching the requested slug from the plugin's
	 * `docs/` directory and returns its slug and raw content.
	 *
	 * @param \WP_REST_Request $request
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function get_item( $request ) {
		$slug = (string) $request['slug'];
		$path = BE_PLUGIN_DIR . 'docs/' . $slug . '.md';

		if ( ! file_exists( $path ) ) {
			return $this->error( 'not_found', __( 'That document was not found.', 'beyond-elysium' ), 404 );
		}

		$content = file_get_contents( $path );
		if ( $content === false ) {
			return $this->error( 'read_failed', __( 'That document could not be read.', 'beyond-elysium' ), 500 );
		}

		return $this->success( [ 'slug' => $slug, 'content' => $content ] );
	}
}
