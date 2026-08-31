<?php
/**
 * Compatibility class for All in One SEO plugin.
 *
 * @package CTXFeed\Compat\Shims
 * @link    https://wordpress.org/plugins/all-in-one-seo-pack/
 */

namespace CTXFeed\Compat\Shims;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class AIOSEOCompatibility
 *
 * Handles AIOSEO product attributes.
 *
 * @package CTXFeed\Compat\Shims
 */
class AIOSEOCompatibility {

	/**
	 * AIOSEOCompatibility Constructor.
	 */
	public function __construct() {
		// SEO plugin attributes for dropdown.
		add_filter( 'woo_feed_seo_plugin_attributes', array( $this, 'get_seo_attributes' ), 10, 1 );

		// AIOSEO title hook.
		add_filter( 'woo_feed_filter_product_aioseop_title', array( $this, 'aioseop_title' ), 10, 3 );

		// AIOSEO description hook.
		add_filter( 'woo_feed_filter_product_aioseop_description', array( $this, 'aioseop_description' ), 10, 3 );

		// AIOSEO canonical URL hook.
		add_filter( 'woo_feed_filter_product_aioseop_canonical_url', array( $this, 'aioseop_canonical_url' ), 10, 3 );

		// The AIOSEO dropdown source keys carry a LEADING underscore
		// (`_aioseop_title`), so V8's Legacy Bridge builds the DOUBLE-underscore
		// hook `woo_feed_filter_product__aioseop_title`. Without listening on it,
		// AIOSEO fields ship EMPTY: the provider seam reads `_aioseo_*` post
		// meta, which AIOSEO v4 leaves empty (its data lives in the
		// wp_aioseo_posts table this shim reads through the model). Register the
		// double-underscore variants too so the correct v4 values resolve.
		add_filter( 'woo_feed_filter_product__aioseop_title', array( $this, 'aioseop_title' ), 10, 3 );
		add_filter( 'woo_feed_filter_product__aioseop_description', array( $this, 'aioseop_description' ), 10, 3 );
		add_filter( 'woo_feed_filter_product__aioseop_canonical_url', array( $this, 'aioseop_canonical_url' ), 10, 3 );
	}

	/**
	 * Get AIOSEO attributes for dropdown.
	 *
	 * @param array $attributes Current SEO attributes.
	 *
	 * @return array
	 */
	public function get_seo_attributes( $attributes ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Filter callback signature; the incoming value is intentionally replaced, not merged.
		return array(
			'optionGroup' => esc_html__( 'ALL IN ONE SEO', 'woo-feed' ),
			'options'     => array(
				'_aioseop_title'         => esc_html__( 'Title [All in One SEO]', 'woo-feed' ),
				'_aioseop_description'   => esc_html__( 'Description [All in One SEO]', 'woo-feed' ),
				'_aioseop_canonical_url' => esc_html__( 'Canonical URL [All in One SEO]', 'woo-feed' ),
			),
		);
	}

	/**
	 * Check if AIOSEO plugin is active.
	 *
	 * @return bool
	 */
	private function is_aioseo_active() {
		return is_plugin_active( 'all-in-one-seo-pack/all_in_one_seo_pack.php' )
			&& class_exists( 'AIOSEO\Plugin\Common\Models\Post' );
	}

	/**
	 * Get AIOSEO title.
	 *
	 * @param string      $title   Current title value.
	 * @param \WC_Product $product Product object.
	 * @param mixed       $config  Config object.
	 *
	 * @return string
	 */
	public function aioseop_title( $title, $product, $config ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the arity registered with add_filter()/add_action() must be preserved even though this adapter does not read every argument.
		if ( ! $this->is_aioseo_active() ) {
			return $title;
		}

		$post         = \AIOSEO\Plugin\Common\Models\Post::getPost( $product->get_id() );
		$aioseo_title = ! empty( $post->title ) ? $post->title : '';
		if ( '' === $aioseo_title && function_exists( 'aioseo' ) ) {
			$aioseo = aioseo();
			if ( is_object( $aioseo ) && isset( $aioseo->meta->title ) && method_exists( $aioseo->meta->title, 'getPostTypeTitle' ) ) {
				$aioseo_title = $aioseo->meta->title->getPostTypeTitle( 'product' );
			}
		}

		return ! empty( $aioseo_title ) ? $aioseo_title : $title;
	}

	/**
	 * Get AIOSEO description.
	 *
	 * @param string      $description Current description value.
	 * @param \WC_Product $product     Product object.
	 * @param mixed       $config      Config object.
	 *
	 * @return string
	 */
	public function aioseop_description( $description, $product, $config ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the arity registered with add_filter()/add_action() must be preserved even though this adapter does not read every argument.
		if ( ! $this->is_aioseo_active() ) {
			return $description;
		}

		$post               = \AIOSEO\Plugin\Common\Models\Post::getPost( $product->get_id() );
		$aioseo_description = ! empty( $post->description ) ? $post->description : '';
		if ( '' === $aioseo_description && function_exists( 'aioseo' ) ) {
			$aioseo = aioseo();
			if ( is_object( $aioseo ) && isset( $aioseo->meta->description ) && method_exists( $aioseo->meta->description, 'getPostTypeDescription' ) ) {
				$aioseo_description = $aioseo->meta->description->getPostTypeDescription( 'product' );
			}
		}

		return ! empty( $aioseo_description ) ? $aioseo_description : $description;
	}

	/**
	 * Get AIOSEO canonical URL.
	 *
	 * @param string      $canonical_url Current canonical URL.
	 * @param \WC_Product $product       Product object.
	 * @param mixed       $config        Config object.
	 *
	 * @return string
	 */
	public function aioseop_canonical_url( $canonical_url, $product, $config ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- Hook callback signature: the arity registered with add_filter()/add_action() must be preserved even though this adapter does not read every argument.
		if ( ! empty( $canonical_url ) ) {
			return $canonical_url;
		}

		if ( ! $this->is_aioseo_active() ) {
			return $canonical_url;
		}

		$post = \AIOSEO\Plugin\Common\Models\Post::getPost( $product->get_id() );

		return ! empty( $post->canonical_url ) ? $post->canonical_url : $canonical_url;
	}
}
