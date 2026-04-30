<?php
declare(strict_types=1);

namespace ConfigKit\Import;

/**
 * Single source of truth for the configkit-owned uploads directory.
 *
 * Layout (all under WordPress's `wp-content/uploads/`):
 *
 *   uploads/
 *     └── configkit/
 *         └── imports/   ← raw Excel uploads, .htaccess "Require all denied"
 *
 * `ensure()` is safe to call repeatedly (idempotent mkdir + .htaccess
 * write). It also runs once on plugin activation so the directory is
 * already in place by the time the wizard tries to store an upload.
 */
final class UploadPaths {

	public const SUBDIR_IMPORTS = 'configkit/imports';

	/**
	 * Return the absolute path to the imports dir. May return null if
	 * `wp_upload_dir()` is unavailable (e.g. in raw CLI tests).
	 */
	public static function imports_dir(): ?string {
		$base = self::base();
		if ( $base === null ) return null;
		return rtrim( $base, '/\\' ) . '/' . self::SUBDIR_IMPORTS;
	}

	/**
	 * Idempotently create the imports dir + a .htaccess that denies
	 * direct HTTP access to uploaded XLSX. Returns the absolute path
	 * on success, null on filesystem failure.
	 */
	public static function ensure(): ?string {
		$dir = self::imports_dir();
		if ( $dir === null ) return null;
		if ( ! is_dir( $dir ) ) {
			if ( function_exists( 'wp_mkdir_p' ) ) {
				if ( ! \wp_mkdir_p( $dir ) ) return null;
			} else {
				if ( ! @mkdir( $dir, 0755, true ) && ! is_dir( $dir ) ) return null;
			}
		}
		// Drop a .htaccess "Require all denied" so the raw upload
		// can't be served by Apache. Nginx setups will need an
		// explicit location block — out of scope for this plugin.
		$htaccess = $dir . '/.htaccess';
		if ( ! file_exists( $htaccess ) ) {
			@file_put_contents(
				$htaccess,
				"# ConfigKit imports — direct HTTP access denied.\nRequire all denied\n"
			);
		}
		// And a tiny index.html for older Apache.
		$index = $dir . '/index.html';
		if ( ! file_exists( $index ) ) {
			@file_put_contents( $index, '' );
		}
		if ( ! is_writable( $dir ) ) return null;
		return $dir;
	}

	private static function base(): ?string {
		if ( ! function_exists( 'wp_upload_dir' ) ) return null;
		$uploads = \wp_upload_dir();
		if ( ! is_array( $uploads ) ) return null;
		if ( ! empty( $uploads['error'] ) ) return null;
		$base = (string) ( $uploads['basedir'] ?? '' );
		return $base !== '' ? $base : null;
	}
}
