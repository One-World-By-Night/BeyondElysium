<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Where an attachment's bytes actually live, and the one place that decides.
 */
class Attachment_Storage {

	/**
	 * Images and PDFs only - matched against the file's real, sniffed type.
	 */
	const ALLOWED_MIME_TYPES = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf' ];

	/**
	 * 10 MB, matched against the size PHP itself reports for the uploaded file.
	 */
	const MAX_BYTES = 10 * 1024 * 1024;

	const PRIVATE_SUBDIR = 'beyond-elysium-private';

	/**
	 * This site's own private storage root, creating it (and its deny-all guard files) the first time anything is stored.
	 *
	 * @return string Absolute path, no trailing slash.
	 */
	public static function base_dir(): string {
		$dir = rtrim( wp_upload_dir()['basedir'], '/' ) . '/' . self::PRIVATE_SUBDIR;

		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '/.htaccess' ) ) {
			file_put_contents( $dir . '/.htaccess', "Require all denied\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		if ( ! file_exists( $dir . '/index.php' ) ) {
			file_put_contents( $dir . '/index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}

		return $dir;
	}

	/**
	 * Validates and stores one uploaded file (the shape of a single `$_FILES` entry), returning what
	 * `Attachment::create()` needs.
	 *
	 * @param array<string,mixed> $uploaded_file One `$_FILES` entry - untrusted request input,
	 *                             not a shape PHP or a client is ever guaranteed to send intact,
	 *                             so every key access below is defensive.
	 * @return array{stored_name:string,original_name:string,mime:string,bytes:int}|\WP_Error
	 */
	public static function store( array $uploaded_file ) {
		if ( ( $uploaded_file['error'] ?? UPLOAD_ERR_NO_FILE ) !== UPLOAD_ERR_OK ) {
			return new \WP_Error( 'upload_error', __( 'The file could not be uploaded.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}

		$bytes = (int) ( $uploaded_file['size'] ?? 0 );
		if ( $bytes <= 0 || $bytes > self::MAX_BYTES ) {
			return new \WP_Error( 'file_too_large', __( 'Files must be 10 MB or smaller.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}

		$tmp_name = (string) ( $uploaded_file['tmp_name'] ?? '' );
		if ( $tmp_name === '' || ! is_file( $tmp_name ) ) {
			return new \WP_Error( 'upload_error', __( 'The file could not be uploaded.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}

		$checked = wp_check_filetype_and_ext( $tmp_name, (string) ( $uploaded_file['name'] ?? '' ) );
		$mime = (string) $checked['type'];
		if ( ! in_array( $mime, self::ALLOWED_MIME_TYPES, true ) ) {
			return new \WP_Error(
				'invalid_file_type',
				__( 'Only images (JPEG, PNG, GIF, WebP) and PDFs may be uploaded.', 'beyond-elysium' ),
				[ 'status' => 400 ]
			);
		}

		$original_name = sanitize_file_name( wp_basename( (string) ( $uploaded_file['name'] ?? 'file' ) ) );
		if ( $original_name === '' ) {
			$original_name = 'file';
		}

		$stored_name = self::generate_unique_stored_name();
		$target_dir  = self::base_dir() . '/' . $stored_name;
		if ( ! wp_mkdir_p( $target_dir ) ) {
			return new \WP_Error( 'storage_failed', __( 'The file could not be saved.', 'beyond-elysium' ), [ 'status' => 500 ] );
		}

		$target_path = $target_dir . '/' . $original_name;
		if ( ! copy( $tmp_name, $target_path ) ) {
			return new \WP_Error( 'storage_failed', __( 'The file could not be saved.', 'beyond-elysium' ), [ 'status' => 500 ] );
		}

		return [
			'stored_name'   => $stored_name,
			'original_name' => $original_name,
			'mime'          => $mime,
			'bytes'         => $bytes,
		];
	}

	/**
	 * Copies an already-stored file to a new random directory for an item copy; the source file is never modified or
	 * moved.
	 *
	 * @param string $stored_name   The source attachment's stored_name.
	 * @param string $original_name The source attachment's original_name.
	 * @return array{stored_name:string,original_name:string,mime:string,bytes:int}|\WP_Error
	 */
	public static function duplicate( string $stored_name, string $original_name ) {
		$source_path = self::path_for( $stored_name, $original_name );
		if ( ! is_file( $source_path ) ) {
			return new \WP_Error( 'storage_failed', __( 'The source file could not be found.', 'beyond-elysium' ), [ 'status' => 500 ] );
		}

		$new_stored_name = self::generate_unique_stored_name();
		$target_dir      = self::base_dir() . '/' . $new_stored_name;
		if ( ! wp_mkdir_p( $target_dir ) ) {
			return new \WP_Error( 'storage_failed', __( 'The file could not be saved.', 'beyond-elysium' ), [ 'status' => 500 ] );
		}

		$target_path = $target_dir . '/' . $original_name;
		if ( ! copy( $source_path, $target_path ) ) {
			return new \WP_Error( 'storage_failed', __( 'The file could not be saved.', 'beyond-elysium' ), [ 'status' => 500 ] );
		}

		$checked = wp_check_filetype_and_ext( $target_path, $original_name );

		return [
			'stored_name'   => $new_stored_name,
			'original_name' => $original_name,
			'mime'          => (string) $checked['type'],
			'bytes'         => (int) filesize( $target_path ),
		];
	}

	/**
	 * The real path to an already-stored file on disk, reconstructed from the two values a decoded `Attachment` row
	 * carries.
	 *
	 * @param string $stored_name
	 * @param string $original_name
	 * @return string
	 */
	public static function path_for( string $stored_name, string $original_name ): string {
		return self::base_dir() . '/' . $stored_name . '/' . $original_name;
	}

	/**
	 * Removes one attachment's file and its containing directory.
	 *
	 * @param string $stored_name
	 * @param string $original_name
	 * @return void
	 */
	public static function delete( string $stored_name, string $original_name ): void {
		$path = self::path_for( $stored_name, $original_name );
		if ( file_exists( $path ) ) {
			unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		}
		$dir = dirname( $path );
		if ( is_dir( $dir ) ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		}
	}

	/**
	 * Removes the entire private storage tree for this site.
	 *
	 * @return void
	 */
	public static function remove_all(): void {
		$dir = rtrim( wp_upload_dir()['basedir'], '/' ) . '/' . self::PRIVATE_SUBDIR;
		if ( is_dir( $dir ) ) {
			self::remove_directory_recursive( $dir );
		}
	}

	/**
	 * @param string $dir
	 * @return void
	 */
	private static function remove_directory_recursive( string $dir ): void {
		$entries = scandir( $dir );
		if ( $entries === false ) {
			return;
		}
		foreach ( $entries as $entry ) {
			if ( $entry === '.' || $entry === '..' ) {
				continue;
			}
			$path = $dir . '/' . $entry;
			if ( is_dir( $path ) ) {
				self::remove_directory_recursive( $path );
			} else {
				unlink( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions
			}
		}
		rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions
	}

	/**
	 * @return string 32 lowercase hex characters (`random_bytes(16)`), regenerated on the
	 *                vanishingly unlikely event of a real directory collision - matching
	 *                `Attestation::generate_unique_short_code()`'s own "don't just trust
	 *                randomness" precedent.
	 */
	private static function generate_unique_stored_name(): string {
		do {
			$candidate = bin2hex( random_bytes( 16 ) );
		} while ( is_dir( self::base_dir() . '/' . $candidate ) );
		return $candidate;
	}
}
