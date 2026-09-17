<?php

namespace BeyondElysium\Services;

defined( 'ABSPATH' ) || exit;

/**
 * Where an attachment's bytes actually live, and the one place that decides (1.1.0 §2.6).
 *
 * Deliberately not the WordPress media library: a media library file is a public URL anyone
 * can open, and an attachment must follow its entity's own audience instead. Every file lives
 * under this site's own `uploads/beyond-elysium-private/`, one random 32-hex-character
 * directory per file, holding one file each named after its own sanitized original name - the
 * random part is what resists guessing; the filename inside is kept only so a file open from
 * disk (a Storyteller's own backup, a support request) still shows something recognizable.
 *
 * **Honest limit, also recorded in the admin guide**: the deny-all `.htaccess` this class
 * writes is honoured by Apache. A host serving static files straight from nginx may not read
 * `.htaccess` at all - what still protects a file there is that its path is 32 random hex
 * characters deep and never disclosed anywhere. Unguessable, not locked; `Attachments_Controller`
 * is what actually enforces who may read one, on every request, regardless of what any web
 * server would otherwise have served directly.
 */
class Attachment_Storage {

	/** Images and PDFs only (owner ruling) - matched against the file's real, sniffed type, never its claimed one. */
	const ALLOWED_MIME_TYPES = [ 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'application/pdf' ];

	/** 10 MB (owner ruling), matched against the size PHP itself reports for the uploaded file. */
	const MAX_BYTES = 10 * 1024 * 1024;

	const PRIVATE_SUBDIR = 'beyond-elysium-private';

	/**
	 * This site's own private storage root, creating it (and its deny-all guard files) the
	 * first time anything is stored. Idempotent, so a fresh install and an upgrade both reach
	 * the same state without a dedicated migration step - the identical reasoning
	 * `Schema::add_column_if_missing()` already applies to columns, applied here to a directory.
	 *
	 * `wp_upload_dir()` is already correctly scoped to the current site on a multisite network
	 * (WordPress's own `sites/{blog_id}/` structure), so this needs no game- or site-specific
	 * path component of its own.
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
	 * Validates and stores one uploaded file (the shape of a single `$_FILES` entry), returning
	 * what `Attachment::create()` needs. Validates MIME from the file's actual contents
	 * (`wp_check_filetype_and_ext()`), never the upload's own claimed type or its filename's
	 * extension - a renamed executable is refused here regardless of what it calls itself.
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

		// Trusted the same way every other upload consumer in this codebase already trusts
		// get_file_params() (Game_Import_Controller, Import_Controller, Submissions_Controller,
		// none of which call is_uploaded_file() either): tmp_name is never client-supplied, it
		// is whatever PHP's own multipart parser (or, in a test, WP_REST_Request::set_file_params())
		// put there before this code ever ran.
		$tmp_name = (string) ( $uploaded_file['tmp_name'] ?? '' );
		if ( $tmp_name === '' || ! is_file( $tmp_name ) ) {
			return new \WP_Error( 'upload_error', __( 'The file could not be uploaded.', 'beyond-elysium' ), [ 'status' => 400 ] );
		}

		$checked = wp_check_filetype_and_ext( $tmp_name, (string) ( $uploaded_file['name'] ?? '' ) );
		// 'type' is false, never absent, when the real content doesn't match any allowed
		// extension - (string) turns that into '', which the allow-list below correctly refuses.
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

		// copy(), not move_uploaded_file(): PHP's own upload tmp file is cleaned up on its own
		// at the end of the request either way, and copy() works across filesystem boundaries
		// (wp-content/uploads can be a different mount than the tmp directory) the same plain
		// rename() would not.
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
	 * Copies an already-stored file to a brand-new random directory, for an item copy (1.1.0
	 * §3.12 item 1) - so editing the copy's file can never touch the source's, and vice versa.
	 * The source file itself is never modified or moved.
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
	 * The real path to an already-stored file on disk, reconstructed from the two values a
	 * decoded `Attachment` row carries - never trusted from a caller, since both come from the
	 * database row the caller already had to look up by id first.
	 *
	 * @param string $stored_name
	 * @param string $original_name
	 * @return string
	 */
	public static function path_for( string $stored_name, string $original_name ): string {
		return self::base_dir() . '/' . $stored_name . '/' . $original_name;
	}

	/**
	 * Removes one attachment's file and its containing directory. Safe to call for a file that
	 * is already gone - a caller that fails partway through a create-then-record sequence, or a
	 * row whose file was already cleaned up by hand, does not need to check first.
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
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors -- only removes if now empty; a leftover file from a race is left rather than losing it.
		}
	}

	/**
	 * Removes the entire private storage tree for this site - called only from `uninstall.php`,
	 * only when the site's own delete-on-uninstall option is set (1.0.0's established pattern),
	 * matching the design doc's own "uninstall also removes the site's private upload
	 * directory." Never called from multisite's own subsite-deletion cleanup (`Multisite.php`)
	 * today - that gap is the same documented, accepted limitation `now/roadmap.md` already
	 * records for this plugin's database tables on a subsite deleted without the plugin loaded.
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
