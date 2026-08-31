<?php
/**
 * TXTTemplate — Renders products as tab-delimited text.
 *
 * Used for channels that require plain text format with tab separators.
 * Array values are joined with comma before tab-delimiting.
 * Fires `ctxfeed_txt_row` filter for row extensibility.
 * Supports provider-specific end-of-record markers (e.g., Trovaprezzi's
 * `<endrecord>`) via `ctxfeed_txt_eol_marker` filter.
 *
 * @package    CTXFeed
 * @subpackage V8/Template
 * @since      8.0.0
 * @implements TMPL-FRD-7.1, TMPL-FRD-7.2, TMPL-FRD-7.3
 */

namespace CTXFeed\V8\Template;

use CTXFeed\V8\Core\Config;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tab-delimited text template.
 *
 * @since 8.0.0
 */
class TXTTemplate implements TemplateInterface {

	/**
	 * Render the TXT header.
	 *
	 * Returns attribute names separated by tabs.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-7.1
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return string Tab-separated header row.
	 */
	public function render_header( Config $config ): string {
		$attributes = $config->get( 'attributes', array() );

		return implode( "\t", array_keys( $attributes ) );
	}

	/**
	 * Get the delimiter for header assembly.
	 *
	 * FeedGenerator::build_csv_header probes get_delimiter() to join
	 * the mapped header names — without this, TXT headers fell back to
	 * COMMA-join while rows are tab-joined, misaligning every column.
	 *
	 * @since 8.0.0
	 *
	 * @return string Tab character.
	 */
	public function get_delimiter() {
		return "\t";
	}

	/**
	 * Render a single product as a tab-delimited row.
	 *
	 * Array values are joined with comma. All values are cast to string.
	 * Applies the `ctxfeed_txt_row` filter for extensibility.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-7.2
	 * @hook ctxfeed_txt_row Filter to modify TXT row.
	 *
	 * @param array            $product_data Resolved product data key-value pairs.
	 * @param Config           $config       Feed configuration.
	 * @param \WC_Product|null $product      Product object (unused by TXT; part of the shared template signature).
	 *
	 * @return string Tab-separated row.
	 */
	public function render_row( array $product_data, Config $config, ?\WC_Product $product = null ): string { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Shared template signature; XML uses $product.
		/**
		 * Filter the character that replaces a raw TAB / carriage-return /
		 * line-feed found INSIDE a field value.
		 *
		 * TXT is tab-delimited and newline-terminated, so an unescaped tab in
		 * a title/description injects a spurious column and an unescaped
		 * newline splits the record into two physical rows — the exact
		 * corruption CSV/TSV avoid via fputcsv() quoting. A single space is
		 * the safe, merchant-friendly default (most TXT specs require it).
		 * Resolved ONCE per row (not per field) to keep generation cheap.
		 *
		 * @since 8.0.0
		 *
		 * @param string $replacement Replacement string. Default ' '.
		 * @param Config $config      Feed configuration.
		 */
		$replacement = (string) apply_filters( 'ctxfeed_txt_field_replacement', ' ', $config );

		$values = array_map(
			function ( $v ) use ( $replacement ) {
				$value = is_array( $v ) ? implode( ',', $v ) : (string) $v;

				// Strip the structural control chars so a tab/newline inside a
				// value can never be mistaken for the column/row separators.
				// "\r\n" is listed first so a CRLF collapses to ONE replacement.
				return str_replace( array( "\t", "\r\n", "\r", "\n" ), $replacement, $value );
			},
			array_values( $product_data )
		);

		$txt = implode( "\t", $values );

		/**
		 * Filter a single TXT product row.
		 *
		 * @since 8.0.0
		 *
		 * @param string $txt          Tab-delimited row string.
		 * @param array  $product_data Product data key-value pairs.
		 * @param Config $config       Feed configuration.
		 */
		$txt = apply_filters( 'ctxfeed_txt_row', $txt, $product_data, $config );

		// Append end-of-record marker for providers that need it (e.g., Trovaprezzi).
		$eol_marker = $this->get_eol_marker( $config );
		if ( ! empty( $eol_marker ) ) {
			$txt .= PHP_EOL . $eol_marker;
		}

		return $txt;
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
		 * Filter the end-of-record marker for TXT feeds.
		 *
		 * @since 8.0.0
		 *
		 * @param string $marker EOL marker string (empty = no marker).
		 * @param Config $config Feed configuration.
		 */
		return apply_filters( 'ctxfeed_txt_eol_marker', $marker, $config );
	}

	/**
	 * Render the TXT footer.
	 *
	 * TXT has no footer — returns empty string.
	 *
	 * @since 8.0.0
	 * @implements TMPL-FRD-7.3
	 *
	 * @param Config $config Feed configuration.
	 *
	 * @return string Empty string.
	 */
	public function render_footer( Config $config ): string { // phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Shared template signature; other formats read $config.
		return '';
	}
}
