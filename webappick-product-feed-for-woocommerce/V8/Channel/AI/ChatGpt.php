<?php
/**
 * ChatGpt — OpenAI / ChatGPT Shopping feed channel.
 *
 * OpenAI Product Feed Spec (chatgpt.com/merchants): own schema with
 * eligibility flags (enable_search / enable_checkout), seller and
 * return-policy fields. Ingestible formats: CSV/TSV/JSONL — not XML.
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
 * ChatGPT Shopping channel.
 *
 * @since 8.0.0
 */
class ChatGpt extends AbstractChannel {

	/**
	 * Get unique channel identifier.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel ID.
	 */
	public function get_id(): string {
		return 'chatgpt';
	}

	/**
	 * Get display name.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel name.
	 */
	public function get_name(): string {
		return 'ChatGPT (OpenAI)';
	}

	/**
	 * Get supported formats — no XML (not OpenAI-ingestible).
	 *
	 * @since 8.0.0
	 *
	 * @return string[] Supported format identifiers.
	 */
	public function get_supported_formats(): array {
		return array( 'csv', 'tsv', 'json' );
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
	 * Get required attributes.
	 *
	 * @since 8.0.0
	 * @implements CHAN-FRD-4.1
	 *
	 * @return string[] Required attribute names.
	 */
	public function get_required_attributes(): array {
		return array( 'enable_search', 'enable_checkout', 'id', 'title', 'description', 'link', 'image_link', 'price', 'availability' );
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
			'enable_search'   => array(
				'type'  => 'pattern',
				'value' => 'true',
			),
			'enable_checkout' => array(
				'type'  => 'pattern',
				'value' => 'false',
			),
			'id'              => array(
				'type'    => 'attribute',
				'wc_attr' => 'id',
			),
			'title'           => array(
				'type'    => 'attribute',
				'wc_attr' => 'title',
			),
			'description'     => array(
				'type'    => 'attribute',
				'wc_attr' => 'description',
			),
			'link'            => array(
				'type'    => 'attribute',
				'wc_attr' => 'link',
			),
			'image_link'      => array(
				'type'    => 'attribute',
				'wc_attr' => 'image',
			),
			'price'           => array(
				'type'    => 'attribute',
				'wc_attr' => 'price',
			),
			'availability'    => array(
				'type'    => 'attribute',
				'wc_attr' => 'availability',
			),
		);
	}

	/**
	 * Get OpenAI merchant specification URL.
	 *
	 * @since 8.0.0
	 *
	 * @return string Specification URL.
	 */
	public function get_spec_url(): string {
		return 'https://chatgpt.com/merchants';
	}
}
