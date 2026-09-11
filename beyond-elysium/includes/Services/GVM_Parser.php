<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Grapevine menu set parser, with two front ends over one normalized output.
 *
 * Grapevine ships menu sets in two serializations of the same data:
 *   XML   <grapevinemenus> ... Grapevine Menus XML.gvm
 *   GVBM  binary ............. Grapevine Menus.gvm, Dark Ages Menus.gvm, and what
 *                              chronicles actually save
 *
 * Both front ends produce the same normalized structure: a map of menu name
 * to its category, items, submenus, includes, and display flags.
 *
 * @see BE_PROCESS/GV-SOURCEMAP.md "GVBM binary shape"
 * @see BE_PROCESS/workflow-0.8.md Step 3
 */
class GVM_Parser {

	/** Binary menu-set header (PublicConstants.bas BinHeaderMenu). */
	const BINARY_HEADER = 'GVBM';

	/**
	 * Parses a menu file, selecting the binary or XML front end based on
	 * the file's opening bytes. Delegates to `parse_binary()` when the
	 * file starts with the GVBM binary header, otherwise treats it as XML.
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
	 * Reads the header, format version, description, and menu count, then
	 * reads each menu's flags and item list in turn.
	 *
	 * Layout, from MenuSetClass::InputFromBinary and LinkedMenuList::InputFromBinary:
	 *
	 *   string  header "GVBM"
	 *   double  version
	 *   string  description
	 *   int16   menu count
	 *   per menu:
	 *     string Name
	 *     int32  Category       (enum: 4 bytes, only when version >= 2.397)
	 *     int16  Alphabetized   (Boolean)
	 *     int16  Negative       (Boolean)
	 *     int16  Autonote       (Boolean)
	 *     int16  Required       (Boolean)
	 *     int32  Display        (enum: 4 bytes)
	 *     int16  item count
	 *     per item: string Name, string Cost, string Note
	 *
	 * Includes and submenus are stored as items, discriminated by the Cost field:
	 * '+' marks an include, ':' marks a submenu, anything else is a real item.
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

			// Category is present only when the format version is 2.397 or later.
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
	 * Parses an XML menu set into the same normalized structure the binary
	 * front end produces. Reads each `<menu>` element's attributes and
	 * walks its children to collect items, submenus, and includes by
	 * element name.
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
