<?php
/**
 * CSVTemplate — Renders products as CSV/TSV rows.
 *
 * Supports custom delimiter and enclosure characters from feed config.
 * TSV mode is created by passing tab as delimiter: `new CSVTemplate( "\t" )`.
 * Config-driven delimiter/enclosure override constructor defaults at render
 * time via `resolve_enclosure()`. Renders QUOTE_ALL by default (every field
 * wrapped in the enclosure, empty ones included, so an empty value is "" and
 * never a bare gap — Google Merchant rejects unenclosed empty columns);
 * RFC 4180 enclosure-doubling applies. The `ctxfeed_csv_enclose_all` filter
 * restores fputcsv's minimal quoting. Fires `ctxfeed_csv_row` filter for row
 * extensibility. Supports provider-specific end-of-record markers
 * (e.g., Trovaprezzi's `<endrecord>`) via `ctxfeed_csv_eol_marker` filter.
 *
 * @package    CTXFeed
 * @subpackage V8/Template
 * @since      8.0.0
 * @implements TMPL-FRD-5.1, TMPL-FRD-5.2, TMPL-FRD-5.3, TMPL-FRD-5.4, TMPL-FRD-5.5, TMPL-FRD-5.6
 */

namespace CTXFeed\V8\Template;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CSV template with configurable delimiter and enclosure.
 *
 * @since 8.0.0
 */
class CSVTemplate implements TemplateInterface {

	/**
	 * Column delimiter character (constructor default).
	 *
	 * @since 8.0.0
	 * @var string
	 */
	private $delimiter;

	/**
	 * Field enclosure character (constructor default).
	 *
	 * @since 8.0.0
	 * @var string
	 */
	private $enclosure;

	/**
	 * Construct CSVTemplate with configurable delimiter and enclosure.
	 *
	 * These defaults may be overridden at render time by feed config
	 * values (delimiter and enclosure settings stored in feedrules).
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-5.1
	 *
	 * @param string $delimiter Column delimiter character. Default comma.
	 * @param string $enclosure Field enclosure character. Default double-quote.
	 */
	public function __construct( $delimiter = ',', $enclosure = '"' ) {
		$this->delimiter = $delimiter;
		$this->enclosure = $enclosure;
	}

	/**
	 * Render the CSV header row.
	 *
	 * Returns attribute keys from config as a CSV-formatted header.
	 * Uses config-driven delimiter and enclosure.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-5.2
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return string CSV header row.
	 */
	public function render_header( Config $config ): string {
		$attributes = $config->get( 'attributes', array() );
		$headers    = array_keys( $attributes );

		return $this->array_to_csv( $headers, $this->get_effective_delimiter( $config ), $this->get_effective_enclosure( $config ) );
	}

	/**
	 * Render an arbitrary list of column values into a delimited line using the
	 * config-driven delimiter/enclosure and the same QUOTE_ALL formatting as
	 * rows.
	 *
	 * The generator emits the MAPPED CSV/TSV header (mapped names, collapsed
	 * groups, auto-added identifier-exists) through this — not `render_header()`,
	 * which reads raw config attributes — so the header can never drift from the
	 * data rows' enclosure or quoting.
	 *
	 * @since 8.0.0
	 *
	 * @param string[] $columns Column values (header names or otherwise).
	 * @param Config   $config  Feed configuration.
	 *
	 * @return string Delimited line.
	 */
	public function render_columns( array $columns, Config $config ): string {
		return $this->array_to_csv(
			$columns,
			$this->get_effective_delimiter( $config ),
			$this->get_effective_enclosure( $config )
		);
	}

	/**
	 * Render a single product as a CSV row.
	 *
	 * Applies the `ctxfeed_csv_row` filter for extensibility.
	 * Uses config-driven delimiter and enclosure.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-5.3
	 * @hook ctxfeed_csv_row Filter to modify CSV row.
	 *
	 * @param array            $product_data Resolved product data key-value pairs.
	 * @param Config           $config       Feed configuration.
	 * @param \WC_Product|null $product      Product object (unused by CSV; part of the shared template signature).
	 *
	 * @return string CSV row.
	 */
	public function render_row( array $product_data, Config $config, ?\WC_Product $product = null ): string { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Shared template signature; XML uses $product.
		$csv = $this->array_to_csv(
			array_values( $product_data ),
			$this->get_effective_delimiter( $config ),
			$this->get_effective_enclosure( $config )
		);

		/**
		 * Filter a single CSV product row.
		 *
		 * @since 8.0.0
		 *
		 * @param string $csv          CSV row string.
		 * @param array  $product_data Product data key-value pairs.
		 * @param Config $config       Feed configuration.
		 */
		$csv = apply_filters( 'ctxfeed_csv_row', $csv, $product_data, $config );

		// Append end-of-record marker for providers that need it (e.g., Trovaprezzi).
		$eol_marker = $this->get_eol_marker( $config );
		if ( ! empty( $eol_marker ) ) {
			$csv .= PHP_EOL . $eol_marker;
		}

		return $csv;
	}

	/**
	 * Render the CSV footer.
	 *
	 * CSV has no footer — returns empty string.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-5.4
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return string Empty string.
	 */
	public function render_footer( Config $config ): string { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Shared template signature; other formats read $config.
		return '';
	}

	/**
	 * Get the constructor-default delimiter.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-5.6
	 *
	 * @return string Delimiter character.
	 */
	public function get_delimiter() {
		return $this->delimiter;
	}

	/**
	 * Get the constructor-default enclosure.
	 *
	 * @since 8.0.0
	 *
	 * @return string Enclosure character.
	 */
	public function get_enclosure() {
		return $this->enclosure;
	}

	/**
	 * Get effective delimiter from config, falling back to constructor default.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return string Delimiter character.
	 */
	private function get_effective_delimiter( Config $config ): string {
		// TSV instance: tab-delimited BY DEFINITION. Config::get_delimiter()
		// defaults to ',' when feedrules carry no explicit delimiter, so
		// letting the config "override" here silently turned every TSV
		// feed into comma-separated output — which Google rejects as
		// malformed tab-delimited data.
		if ( "\t" === $this->delimiter ) {
			return "\t";
		}

		$config_delimiter = $config->get_delimiter();

		// If config has a non-empty delimiter, use it.
		if ( ! empty( $config_delimiter ) ) {
			return $config_delimiter;
		}

		return $this->delimiter;
	}

	/**
	 * Get effective enclosure from config, falling back to constructor default.
	 *
	 * V5 stores enclosure as 'double' or 'single' string in config.
	 * This method resolves to the actual character.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return string Enclosure character.
	 */
	private function get_effective_enclosure( Config $config ): string {
		$config_enclosure = $config->get_enclosure();

		if ( ! empty( $config_enclosure ) ) {
			return $this->resolve_enclosure( $config_enclosure );
		}

		return $this->enclosure;
	}

	/**
	 * Resolve enclosure type string to character.
	 *
	 * V5 stores enclosure as a type name ('double', 'single', etc.)
	 * rather than the actual character. This converts:
	 * - 'double' → "
	 * - 'single' → '
	 * - Any other single character → used as-is
	 *
	 * @since 8.0.0
	 *
	 * @param string $enclosure Enclosure type or character.
	 *
	 * @return string Actual enclosure character.
	 */
	private function resolve_enclosure( string $enclosure ): string {
		switch ( $enclosure ) {
			case 'double':
				return '"';
			case 'single':
				return "'";
			default:
				// If it's already a character (e.g., " or '), use as-is.
				return ( strlen( $enclosure ) === 1 ) ? $enclosure : '"';
		}
	}

	/**
	 * Get end-of-record marker from feed configuration.
	 *
	 * Reads `eolMarker` from Config (set by channel TemplateDefaults).
	 * Returns empty string when no marker is configured.
	 *
	 * @since 8.0.0
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return string End-of-record marker, or empty string if none.
	 */
	private function get_eol_marker( Config $config ): string {
		$marker = $config->get_eol_marker();

		/**
		 * Filter the end-of-record marker for CSV feeds.
		 *
		 * @since 8.0.0
		 *
		 * @param string $marker EOL marker string (empty = no marker).
		 * @param Config $config Feed configuration.
		 */
		return apply_filters( 'ctxfeed_csv_eol_marker', $marker, $config );
	}

	/**
	 * Convert an array to a delimited (CSV/TSV) line.
	 *
	 * Default mode is QUOTE_ALL: EVERY field is wrapped in the enclosure —
	 * including empty ones, which therefore render as "" rather than a bare gap
	 * between delimiters. Finicky importers (Google Merchant among them) reject
	 * a delimited row whose empty column carries no enclosure, so this is the
	 * safe default. Embedded enclosures are doubled per RFC 4180; because
	 * quoting is unconditional, a delimiter or newline inside a value is safe
	 * too. The `ctxfeed_csv_enclose_all` filter can restore fputcsv's minimal
	 * (only-when-needed) quoting.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-5.5
	 * @hook ctxfeed_csv_enclose_all Filter (bool, default true) to toggle QUOTE_ALL.
	 *
	 * @param array  $fields    Array of field values.
	 * @param string $delimiter Delimiter character.
	 * @param string $enclosure Enclosure character.
	 *
	 * @return string CSV-formatted line.
	 */
	private function array_to_csv( array $fields, string $delimiter = ',', string $enclosure = '"' ) {
		/**
		 * Enclose EVERY field (QUOTE_ALL), empties included, so an empty value
		 * is emitted as "" instead of a bare gap. Default true.
		 *
		 * @since 8.0.0
		 *
		 * @param bool   $enclose_all Whether to wrap every field.
		 * @param string $enclosure   Field enclosure character.
		 * @param string $delimiter   Column delimiter character.
		 */
		$enclose_all = (bool) apply_filters( 'ctxfeed_csv_enclose_all', true, $enclosure, $delimiter );

		if ( $enclose_all && '' !== $enclosure ) {
			$wrapped = array();
			foreach ( $fields as $field ) {
				$value     = (string) $field;
				$wrapped[] = $enclosure . str_replace( $enclosure, $enclosure . $enclosure, $value ) . $enclosure;
			}

			return implode( $delimiter, $wrapped );
		}

		// Fallback (QUOTE_ALL disabled): fputcsv's minimal quoting. Strip ONLY
		// fputcsv's trailing line terminator — StreamWriter adds its own PHP_EOL
		// between rows, and a bare rtrim() would also eat the trailing delimiter
		// of an empty LAST field in tab-delimited feeds ("\t" is whitespace),
		// producing Google's "Too few column delimiters".
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Intentional use of php://temp for CSV formatting.
		$stream = fopen( 'php://temp', 'r+' );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fputcsv, WordPressVIPMinimum.Functions.RestrictedFunctions.file_ops_fputcsv -- php://temp stream (no filesystem write); produces the feed file's CSV escaping, which is the product.
		fputcsv( $stream, $fields, $delimiter, $enclosure );
		rewind( $stream );
		$line = rtrim( stream_get_contents( $stream ), "\r\n" );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Closing php://temp stream.
		fclose( $stream );

		return $line;
	}
}
