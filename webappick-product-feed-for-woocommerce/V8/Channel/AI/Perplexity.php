<?php
/**
 * Perplexity — Perplexity Shopping / Merchant Program channel.
 *
 * Reuses the Google Shopping product spec (owner-confirmed; not
 * publicly announced by Perplexity — their ToS only states "CSV or XML
 * files in one of the formats specified by Perplexity").
 *
 * @package    CTXFeed
 * @subpackage V8/Channel/AI
 * @since      8.0.0
 * @implements CHAN-FRD-4.1
 */

namespace CTXFeed\V8\Channel\AI;

use CTXFeed\V8\Channel\AbstractChannel;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Perplexity Shopping channel.
 *
 * @since 8.0.0
 */
class Perplexity extends AbstractChannel {

	/**
	 * Get unique channel identifier.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel ID.
	 */
	public function get_id(): string {
		return 'perplexity';
	}

	/**
	 * Get display name.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel name.
	 */
	public function get_name(): string {
		return 'Perplexity Shopping';
	}

	/**
	 * Get supported formats.
	 *
	 * @since 8.0.0
	 *
	 * @return string[] Supported format identifiers.
	 */
	public function get_supported_formats(): array {
		return array( 'csv', 'tsv', 'xml' );
	}

	/**
	 * Get default output format.
	 *
	 * @since 8.0.0
	 *
	 * @return string Default format.
	 */
	public function get_default_format(): string {
		return 'csv';
	}

	/**
	 * Get required attributes (Google Shopping core).
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.1
	 *
	 * @return string[] Required attribute names.
	 */
	public function get_required_attributes(): array {
		return array( 'id', 'title', 'description', 'link', 'image', 'price', 'availability' );
	}

	/**
	 * Get default attribute mappings.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.1
	 *
	 * @return array Default mappings.
	 */
	public function get_default_mappings(): array {
		return array(
			'id'           => array(
				'type'    => 'attribute',
				'wc_attr' => 'id',
			),
			'title'        => array(
				'type'    => 'attribute',
				'wc_attr' => 'title',
			),
			'description'  => array(
				'type'    => 'attribute',
				'wc_attr' => 'description',
			),
			'link'         => array(
				'type'    => 'attribute',
				'wc_attr' => 'link',
			),
			'image'        => array(
				'type'    => 'attribute',
				'wc_attr' => 'image',
			),
			'price'        => array(
				'type'    => 'attribute',
				'wc_attr' => 'price',
			),
			'availability' => array(
				'type'    => 'attribute',
				'wc_attr' => 'availability',
			),
		);
	}

	/**
	 * Get Perplexity merchant program URL (no public field spec).
	 *
	 * @since 8.0.0
	 *
	 * @return string Specification URL.
	 */
	public function get_spec_url(): string {
		return 'https://www.perplexity.ai/merchants';
	}
}
