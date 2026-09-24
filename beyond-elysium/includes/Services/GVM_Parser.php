<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Grapevine menu set parser, with two front ends over one normalized output.
 */
class GVM_Parser {

	/**
	 * Binary menu-set header (PublicConstants.bas BinHeaderMenu).
	 */
	const BINARY_HEADER = 'GVBM';

	/**
	 * Parses a menu file, selecting the binary or XML front end based on the file's opening bytes.
	 *
	 * @param string $path Absolute path.
	 * @return array{version:float,description:string,menus:array}
	 * @throws \RuntimeException On an unreadable or unrecognized file.
	 */
	public static function parse_file( string $path ): array {
		$reader = GV_Binary_Reader::from_file( $path );

		// A binary menu set opens with a length-prefixed "GVBM"; XML opens with "<?xm".
		if ( strpos( $reader->peek( 8 ), self::BINARY_HEADER ) !== false ) {
			return self::parse_binary( $reader );
		}

		return self::parse_xml( $path );
	}

	/**
	 * Parses a GVBM binary menu set into the normalized menu structure.
	 *
	 * @param GV_Binary_Reader $reader Positioned at the start of the file.
	 * @return array{version:float,description:string,menus:array}
	 * @throws \RuntimeException When the header is wrong or the stream desynchronizes.
	 */
	public static function parse_binary( GV_Binary_Reader $reader ): array {
		$header = $reader->string();

		if ( $header !== self::BINARY_HEADER ) {
			throw new \RuntimeException(
				sprintf( 'Not a Grapevine binary menu file: header was "%s"', $header )
			);
		}

		$version     = $reader->double();
		$description = $reader->string();
		$count       = $reader->int16();
		$menus       = [];

		for ( $i = 0; $i < $count; $i++ ) {
			$name = $reader->string();

			$category = $version >= 2.397 ? $reader->int32() : 1;

			$alphabetized = $reader->bool();
			$negative     = $reader->bool();
			$autonote     = $reader->bool();
			$required     = $reader->bool();
			$display      = $reader->int32();

			$item_count = $reader->int16();
			$items      = [];
			$submenus   = [];
			$includes   = [];

			for ( $j = 0; $j < $item_count; $j++ ) {
				$item_name = $reader->string();
				$cost      = $reader->string();
				$note      = $reader->string();

				if ( $cost === '+' ) {
					$includes[] = $note !== '' ? $note : $item_name;
					continue;
				}

				if ( $cost === ':' ) {
					$submenus[] = [ 'name' => $item_name, 'link' => $note ];
					continue;
				}

				$items[] = [ 'name' => $item_name, 'cost' => $cost, 'note' => $note ];
			}

			$menus[ $name ] = [
				'category'     => $category,
				'items'        => $items,
				'submenus'     => $submenus,
				'includes'     => $includes,
				'alphabetized' => $alphabetized,
				'negative'     => $negative,
				'autonote'     => $autonote,
				'required'     => $required,
				'display'      => $display,
			];
		}

		// Leftover bytes indicate a field was misread earlier in the stream.
		if ( ! $reader->eof() ) {
			throw new \RuntimeException(
				sprintf(
					'Binary menu parse ended at byte %d of %d - the stream desynchronized.',
					$reader->tell(),
					$reader->size()
				)
			);
		}

		return [
			'version'     => $version,
			'description' => $description,
			'menus'       => $menus,
		];
	}

	/**
	 * Parses an XML menu set into the same normalized structure the binary front end produces.
	 *
	 * @param string $path Absolute path.
	 * @return array{version:float,description:string,menus:array}
	 * @throws \RuntimeException When the file will not parse.
	 */
	public static function parse_xml( string $path ): array {
		$xml = @simplexml_load_file( $path );

		if ( ! $xml ) {
			throw new \RuntimeException( 'Cannot parse XML menu file: ' . $path );
		}

		$menus = [];

		foreach ( $xml->menu as $menu ) {
			$items    = [];
			$submenus = [];
			$includes = [];

			foreach ( $menu->children() as $child ) {
				switch ( $child->getName() ) {
					case 'item':
						$items[] = [
							'name' => (string) $child['name'],
							'cost' => (string) $child['cost'],
							'note' => (string) $child['note'],
						];
						break;
					case 'submenu':
						$submenus[] = [
							'name' => (string) $child['name'],
							'link' => (string) $child['link'],
						];
						break;
					case 'include':
						$includes[] = (string) $child['name'];
						break;
				}
			}

			// A missing category attribute defaults to gvRaceAll (all creature types).
			$menus[ (string) $menu['name'] ] = [
				'category'     => isset( $menu['category'] ) ? (int) $menu['category'] : 1,
				'items'        => $items,
				'submenus'     => $submenus,
				'includes'     => $includes,
				'alphabetized' => (string) $menu['abc'] === 'yes',
				'negative'     => (string) $menu['negative'] === 'yes',
				'autonote'     => (string) $menu['autonote'] === 'yes',
				'required'     => (string) $menu['required'] === 'yes',
				'display'      => (int) $menu['display'],
			];
		}

		return [
			'version'     => (float) $xml['version'],
			'description' => (string) $xml->description,
			'menus'       => $menus,
		];
	}
}
