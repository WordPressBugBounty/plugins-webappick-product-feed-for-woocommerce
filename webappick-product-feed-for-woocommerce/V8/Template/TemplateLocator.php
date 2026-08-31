<?php
/**
 * TemplateLocator — single source of truth for the feed template asset
 * directory (channel `.txt` templates, `custom2/`, `taxonomies/`).
 *
 * The assets live under `V8/template-assets/` (relocated there during V5 removal
 * from the historical `admin/partials/templates/`). This locator is the one
 * place that path is defined, so every consumer stays decoupled from it.
 *
 * @package    CTXFeed
 * @subpackage V8/Template
 * @since      8.0.0
 */

namespace CTXFeed\V8\Template;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves the feed template asset directory (V8-first, admin fallback).
 *
 * @since 8.0.0
 */
class TemplateLocator {

	/**
	 * Base directory for feed template assets, with trailing slash.
	 *
	 * Resolves `{plugin}/V8/template-assets/` — via CTXFEED_V8_PATH, or derived from
	 * WOO_FEED_FREE_PATH as a fallback when the V8 constant is unavailable.
	 *
	 * @since 8.0.0
	 *
	 * @return string Absolute directory path with trailing slash, or '' if
	 *                no location can be resolved.
	 */
	public static function dir(): string {

		if ( defined( 'CTXFEED_V8_PATH' ) ) {
			return CTXFEED_V8_PATH . 'template-assets/';
		}

		if ( defined( 'WOO_FEED_FREE_PATH' ) ) {
			return trailingslashit( WOO_FEED_FREE_PATH ) . 'V8/template-assets/';
		}

		return '';
	}

	/**
	 * Absolute path to a template asset relative to the templates directory.
	 *
	 * @since 8.0.0
	 *
	 * @param string $relative Path relative to the templates dir (e.g.
	 *                         'google.txt', 'custom2/glami.txt').
	 *
	 * @return string Absolute path, or '' if the base directory is unresolved.
	 */
	public static function path( string $relative = '' ): string {
		$dir = self::dir();

		return '' === $dir ? '' : $dir . ltrim( $relative, '/' );
	}

	/**
	 * The `taxonomies/` subdirectory, with trailing slash.
	 *
	 * @since 8.0.0
	 *
	 * @return string Absolute path, or '' if the base directory is unresolved.
	 */
	public static function taxonomy_dir(): string {
		return self::path( 'taxonomies/' );
	}

	/**
	 * Writable uploads directory for user-refreshed taxonomy files.
	 *
	 * The bundled taxonomies live inside the plugin (see taxonomy_dir), which
	 * is wiped on every plugin update and is often read-only on managed hosts.
	 * When a user manually refreshes a taxonomy (Settings → Update taxonomies),
	 * the fresh copy is written here instead so it survives updates and the
	 * reader can prefer it over the bundled file.
	 *
	 * @since 8.0.0
	 *
	 * @return string Absolute path with trailing slash, or '' if the uploads
	 *                base directory cannot be resolved.
	 */
	public static function taxonomy_override_dir(): string {
		$upload = wp_upload_dir();

		if ( empty( $upload['basedir'] ) ) {
			return '';
		}

		return trailingslashit( $upload['basedir'] ) . 'ctxfeed/taxonomies/';
	}

	/**
	 * Absolute path to the user-refreshed Google taxonomy file in uploads.
	 *
	 * Single source of truth shared by the writer (ChannelEndpoint update
	 * handler) and the reader (CategoryMappingEndpoint) so the two can never
	 * drift on filename or location.
	 *
	 * @since 8.0.0
	 *
	 * @return string Absolute path, or '' if the uploads directory is unresolved.
	 */
	public static function google_override_file(): string {
		$dir = self::taxonomy_override_dir();

		return '' === $dir ? '' : $dir . 'google_taxonomy.txt';
	}
}
