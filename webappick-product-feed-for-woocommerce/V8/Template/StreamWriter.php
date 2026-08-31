<?php
/**
 * StreamWriter — Writes feed output to file using fwrite/fputcsv.
 *
 * Prevents memory bloat by appending rows instead of building the entire
 * feed in memory. Handles the complete file lifecycle: open, header,
 * rows, footer, close. Throws RuntimeException on file open failure.
 *
 * @package    CTXFeed
 * @subpackage V8/Template
 * @since      8.0.0
 * @implements TMPL-FRD-1.1, TMPL-FRD-1.2, TMPL-FRD-1.3
 */

namespace CTXFeed\V8\Template;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Stream writer for memory-efficient file output.
 *
 * @since 8.0.0
 */
class StreamWriter {

	/**
	 * File handle resource.
	 *
	 * @since 8.0.0
	 * @var resource|null
	 */
	private $handle;

	/**
	 * File path.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	private $file_path = '';

	/**
	 * Feed format identifier.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	private $format = '';

	/**
	 * Open a file for writing.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-1.1, TMPL-FRD-9.1
	 *
	 * @param string $file_path Absolute path to the output file.
	 * @param string $format    Feed format identifier (e.g., 'xml', 'csv').
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the file cannot be opened.
	 */
	public function open( $file_path, $format = 'xml' ) {
		$this->file_path = $file_path;
		$this->format    = $format;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Intentional direct file I/O for feed streaming.
		$this->handle = fopen( $file_path, 'w' );

		if ( ! $this->handle ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is consumed by the feed logger/WP_Error chain, never rendered as HTML.
				sprintf( 'CTXFeed: Cannot open file for writing: %s', $file_path )
			);
		}
	}

	/**
	 * Open a file for APPENDING.
	 *
	 * Batches after the first (and finalize) run in FRESH PHP processes
	 * via Action Scheduler — the handle opened at offset 0 does not
	 * exist there. Those callers must reopen the partial file in append
	 * mode; reopening with open() would TRUNCATE everything written by
	 * previous batches.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-1.1
	 *
	 * @param string $file_path Absolute path to the output file.
	 * @param string $format    Feed format identifier.
	 *
	 * @return void
	 *
	 * @throws \RuntimeException If the file cannot be opened.
	 */
	public function open_append( $file_path, $format = 'xml' ) {
		$this->file_path = $file_path;
		$this->format    = $format;

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Intentional direct file I/O for feed streaming.
		$this->handle = fopen( $file_path, 'a' );

		if ( ! $this->handle ) {
			throw new \RuntimeException(
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Message is consumed by the feed logger/WP_Error chain, never rendered as HTML.
				sprintf( 'CTXFeed: Cannot open file for appending: %s', $file_path )
			);
		}
	}

	/**
	 * Whether a file handle is currently open.
	 *
	 * @since 8.0.0
	 *
	 * @return bool
	 */
	public function is_open(): bool {
		return is_resource( $this->handle );
	}

	/**
	 * Remove a trailing suffix from the END of the open file, if present.
	 *
	 * Used by the JSON format to drop the final row's trailing ","
	 * before the closing "]" — rows are streamed with trailing commas
	 * because a streaming writer cannot know which row is last.
	 *
	 * @since 8.0.0
	 *
	 * @param string $suffix Exact byte sequence to strip from the file end.
	 *
	 * @return void
	 */
	public function trim_trailing( string $suffix ) {
		if ( ! $this->is_open() || '' === $suffix ) {
			return;
		}

		// Flush pending writes so filesize() sees them.
		fflush( $this->handle );
		clearstatcache( true, $this->file_path );

		$size = filesize( $this->file_path );
		$len  = strlen( $suffix );

		if ( false === $size || $size < $len ) {
			return;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Read-only tail check.
		$reader = fopen( $this->file_path, 'r' );
		if ( ! $reader ) {
			return;
		}
		fseek( $reader, $size - $len );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Partial tail read of the feed file; WP_Filesystem has no seek/partial-read API.
		$tail = fread( $reader, $len );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing read-only handle.
		fclose( $reader );

		if ( $tail === $suffix ) {
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_ftruncate -- Writing the feed file to uploads IS the product; trims the streamed JSON trailing comma.
			ftruncate( $this->handle, $size - $len );

			// A non-append handle keeps its position at the OLD end after
			// ftruncate — the next fwrite would leave a NUL-byte gap.
			// Reseek to the new end. (No-op for append handles, which
			// always write at true EOF).
			fseek( $this->handle, $size - $len );
		}
	}

	/**
	 * Write the UTF-8 byte-order mark (EF BB BF) as the first bytes.
	 *
	 * Must be called immediately after open() (offset 0), before any header
	 * or row. Some receivers — notably Excel — only read a delimited feed as
	 * UTF-8 when it starts with a BOM; opt-in via the `csv_bom` feed option.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function write_bom() {
		if ( ! is_resource( $this->handle ) ) {
			return;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- Writing the feed file to uploads IS the product; the UTF-8 BOM must be the file's first raw bytes (no trailing EOL).
		fwrite( $this->handle, "\xEF\xBB\xBF" );
	}

	/**
	 * Write the feed header.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-1.1
	 *
	 * @param string $header Header content.
	 *
	 * @return void
	 */
	public function write_header( $header ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- Writing the feed file to uploads IS the product; intentional direct file I/O for feed streaming.
		fwrite( $this->handle, $header . PHP_EOL );
	}

	/**
	 * Write a single feed row.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-1.1
	 *
	 * @param string $row Row content.
	 *
	 * @return void
	 */
	public function write_row( $row ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- Writing the feed file to uploads IS the product; intentional direct file I/O for feed streaming.
		fwrite( $this->handle, $row . PHP_EOL );
	}

	/**
	 * Write a CSV row using fputcsv.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-1.1
	 *
	 * @param array  $fields    Array of field values.
	 * @param string $delimiter CSV delimiter character.
	 * @param string $enclosure CSV enclosure character.
	 *
	 * @return void
	 */
	public function write_csv_row( $fields, $delimiter = ',', $enclosure = '"' ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- Writing the feed file to uploads IS the product; intentional direct file I/O for feed streaming.
		fputcsv( $this->handle, $fields, $delimiter, $enclosure );
	}

	/**
	 * Write the feed footer.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-1.1
	 *
	 * @param string $footer Footer content.
	 *
	 * @return void
	 */
	public function write_footer( $footer ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fwrite -- Writing the feed file to uploads IS the product; intentional direct file I/O for feed streaming.
		fwrite( $this->handle, $footer . PHP_EOL );
	}

	/**
	 * Close the file handle.
	 *
	 * Idempotent — safe to call multiple times.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-1.2
	 *
	 * @return void
	 */
	public function close() {
		if ( is_resource( $this->handle ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Intentional direct file I/O for feed streaming.
			fclose( $this->handle );
		}
	}

	/**
	 * Get the file path.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-1.3
	 *
	 * @return string File path passed to open().
	 */
	public function get_file_path() {
		return $this->file_path;
	}

	/**
	 * Get the file size in bytes.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-1.3
	 *
	 * @return int File size in bytes, or 0 if file does not exist.
	 */
	public function get_file_size() {
		return file_exists( $this->file_path ) ? filesize( $this->file_path ) : 0;
	}

	/**
	 * Get the format identifier.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-1.3
	 *
	 * @return string Format string passed to open().
	 */
	public function get_format() {
		return $this->format;
	}
}
