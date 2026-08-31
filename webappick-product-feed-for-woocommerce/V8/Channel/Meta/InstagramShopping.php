<?php
/**
 * InstagramShopping — Uses same catalog as Facebook but branded separately.
 *
 * Extends FacebookCatalog and overrides only ID and name.
 * All attribute requirements and mappings are inherited.
 *
 * @package    CTXFeed
 * @subpackage V8/Channel/Meta
 * @since      8.0.0
 * @implements CHAN-FRD-4.2
 */

namespace CTXFeed\V8\Channel\Meta;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Instagram Shopping channel.
 *
 * @since 8.0.0
 */
class InstagramShopping extends FacebookCatalog {

	/**
	 * Get unique channel identifier.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel ID.
	 */
	public function get_id(): string {
		return 'instagram_shopping';
	}

	/**
	 * Get display name.
	 *
	 * @since 8.0.0
	 *
	 * @return string Channel name.
	 */
	public function get_name(): string {
		return 'Instagram Shopping';
	}
}
