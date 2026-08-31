<?php
/**
 * CustomFieldRegistrar — Registers and saves custom fields on WooCommerce product pages.
 *
 * When custom fields are enabled in Settings → Custom Fields, this class
 * hooks into WooCommerce to render text inputs on both simple product
 * and variable product (variation) edit screens, and handles saving.
 *
 * V8 equivalent of V5\CustomFields\InputCustomFiled.
 *
 * @package    CTXFeed
 * @subpackage V8/CustomFields
 * @since      8.0.0
 */

namespace CTXFeed\V8\CustomFields;

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Custom field registrar for WooCommerce product edit pages.
 *
 * @since 8.0.0
 */
class CustomFieldRegistrar {

	/**
	 * WordPress option key for plugin settings.
	 *
	 * @since 8.0.0
	 * @var string
	 */
	const SETTINGS_KEY = 'woo_feed_settings';

	/**
	 * Initialize WooCommerce hooks for custom field rendering and saving.
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function init(): void {
		// Render fields on simple product inventory tab.
		add_action(
			'woocommerce_product_options_inventory_product_data',
			array( $this, 'render_simple_product_fields' ),
			10
		);

		// Render fields on variable product variations.
		add_action(
			'woocommerce_product_after_variable_attributes',
			array( $this, 'render_variation_fields' ),
			10,
			3
		);

		// Save simple product custom field values.
		add_action(
			'save_post_product',
			array( $this, 'save_simple_product_fields' ),
			10,
			2
		);

		// Save variation custom field values.
		add_action(
			'woocommerce_save_product_variation',
			array( $this, 'save_variation_fields' ),
			10,
			2
		);
	}

	/**
	 * Get the identifier settings array.
	 *
	 * Reads woo_feed_identifier from the shared settings option.
	 *
	 * @since 8.0.0
	 *
	 * @return array Identifier settings keyed by field name.
	 */
	private function get_identifier_settings(): array {
		$settings = get_option( self::SETTINGS_KEY, array() );

		if ( ! is_array( $settings ) || empty( $settings['woo_feed_identifier'] ) ) {
			return array();
		}

		return (array) $settings['woo_feed_identifier'];
	}

	/**
	 * Check if any custom fields are enabled.
	 *
	 * @since 8.0.0
	 *
	 * @param array $identifier_settings The identifier settings array.
	 * @return bool True if at least one field is enabled.
	 */
	private function has_enabled_fields( array $identifier_settings ): bool {
		if ( empty( $identifier_settings ) ) {
			return false;
		}

		// Check for both string 'enable' and boolean true (V5 edge case).
		foreach ( $identifier_settings as $value ) {
			if ( 'enable' === $value || true === $value || 1 === $value || '1' === $value ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Check if a specific field is enabled and renderable.
	 *
	 * Only renders fields where:
	 *   - The field is present in identifier settings.
	 *   - The setting value is 'enable'.
	 *   - The field type is 'text' or 'date' (not 'taxonomy').
	 *
	 * @since 8.0.0
	 *
	 * @param string $field_key  Field key name.
	 * @param array  $field_def  Field definition [ label, default_enabled, type ].
	 * @param array  $settings   Identifier settings array.
	 * @return bool True if field should be rendered.
	 */
	private function is_field_renderable( string $field_key, array $field_def, array $settings ): bool {
		if ( ! isset( $settings[ $field_key ] ) ) {
			return false;
		}

		// Handle both string 'enable' and boolean true (V5 edge case).
		$value      = $settings[ $field_key ];
		$is_enabled = ( 'enable' === $value || true === $value || 1 === $value || '1' === $value );

		if ( ! $is_enabled ) {
			return false;
		}

		// Only render text and date fields as inputs; taxonomies are handled separately.
		return in_array( $field_def[2], array( 'text', 'date' ), true );
	}

	/**
	 * Get the current meta value for a field, with backward compatibility.
	 *
	 * Checks both new format (woo_feed_{key}) and old format (woo_feed_identifier_{key}).
	 * Also supports WPML original post ID fallback.
	 *
	 * @since 8.0.0
	 *
	 * @param int    $post_id   Post/variation ID.
	 * @param string $field_key Field key.
	 * @return string The meta value.
	 */
	private function get_field_meta_value( int $post_id, string $field_key ): string {
		$new_key = sprintf( 'woo_feed_%s', strtolower( $field_key ) );
		$old_key = sprintf( 'woo_feed_identifier_%s', strtolower( $field_key ) );

		$value = get_post_meta( $post_id, $new_key, true );

		// WPML compatibility: try original post if translated post has no value.
		if ( empty( $value ) && is_plugin_active( 'woocommerce-multilingual/wpml-woocommerce.php' ) ) {
			$original_id = apply_filters( 'woo_feed_original_post_id', $post_id );
			$value       = get_post_meta( $original_id, $new_key, true );
		}

		// Backward compatibility: fall back to old identifier meta key.
		if ( empty( $value ) ) {
			$old_value = get_post_meta( $post_id, $old_key, true );
			if ( ! empty( $old_value ) ) {
				$value = $old_value;
			}
		}

		return (string) $value;
	}

	/**
	 * Render a WC-style text input with the help-tip moved AFTER the input.
	 *
	 * `woocommerce_wp_text_input()` with `desc_tip=true` injects the tip
	 * `<span>` between the label and the input. Our UI wants it after the
	 * input (consistent with the rest of CTX Feed's product fields), so we
	 * capture WC's output and reorder the markup.
	 *
	 * The reorder is a one-shot regex on a known-stable WC string — if WC
	 * ever changes the help-tip class, the regex no-ops and the field still
	 * renders fine, just with the tip in WC's default position.
	 *
	 * @since 8.0.0
	 *
	 * @param array $args Args passed straight to `woocommerce_wp_text_input()`.
	 * @return void
	 */
	private function render_text_input_tooltip_after( array $args ): void {
		ob_start();
		woocommerce_wp_text_input( $args );
		$html = (string) ob_get_clean();

		// Swap order: <span class="woocommerce-help-tip"...></span><input...>
		// becomes:    <input...><span class="woocommerce-help-tip"...></span>.
		$swapped = preg_replace_callback(
			'#(<span\s+class="woocommerce-help-tip"[^>]*></span>)(\s*)(<input[^>]*/?>)#',
			static function ( $matches ) {
				return $matches[3] . $matches[2] . $matches[1];
			},
			$html,
			1
		);

		// On preg_replace_callback failure (returns null), fall back to the
		// original markup — never lose the field.
		echo null === $swapped ? $html : $swapped; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $html is markup this class just built from woocommerce_wp_text_input() output; escaping it would render the product-edit field as literal HTML. No user-supplied value reaches it unescaped.
	}

	/**
	 * Normalize a stored date value to YYYY-MM-DD for HTML5 <input type="date">.
	 *
	 * V5 saved availability_date as free-form text via the plain text input,
	 * so existing data may be in any format strtotime() can parse
	 * ("December 25, 2026", "12/25/2026", "2026-12-25T00:00:00+00:00", etc.).
	 * The HTML5 date picker only displays YYYY-MM-DD; anything else renders
	 * as an empty input even when the underlying value is non-empty. We
	 * round-trip through strtotime to coerce, and pass through unchanged on
	 * unparseable input so save logic can still preserve user intent.
	 *
	 * @since 8.0.0
	 *
	 * @param string $value Raw meta value.
	 * @return string YYYY-MM-DD string, or empty if unparseable.
	 */
	private function normalize_date_value( string $value ): string {
		if ( '' === $value ) {
			return '';
		}

		$timestamp = strtotime( $value );
		if ( false === $timestamp ) {
			// Unparseable — return as-is. The HTML5 input will reject it
			// visually but the value isn't lost on save (user can retype).
			return $value;
		}

		return gmdate( 'Y-m-d', $timestamp );
	}

	/**
	 * Render custom fields on simple product edit page (Inventory tab).
	 *
	 * Hooked to: woocommerce_product_options_inventory_product_data
	 *
	 * @since 8.0.0
	 *
	 * @return void
	 */
	public function render_simple_product_fields(): void {
		$settings      = $this->get_identifier_settings();
		$custom_fields = CustomFieldHelper::get_fields();

		if ( ! $this->has_enabled_fields( $settings ) ) {
			return;
		}

		echo '<div class="options_group">';
		printf(
			'<h4 class="%s" style="padding-left: 10px; color: black;">%s</h4>',
			esc_attr( 'woo-feed-option-title' ),
			esc_html__( 'CUSTOM FIELDS by CTX Feed', 'woo-feed' )
		);

		foreach ( $custom_fields as $field_key => $field_def ) {
			if ( ! $this->is_field_renderable( $field_key, $field_def, $settings ) ) {
				continue;
			}

			$post_id     = get_the_ID();
			$field_value = $this->get_field_meta_value( $post_id, $field_key );
			$field_id    = esc_attr( wp_unslash( "woo_feed_{$field_key}" ) );
			$field_label = esc_attr( wp_unslash( $field_def[0] ) );
			$is_date     = isset( $field_def[2] ) && 'date' === $field_def[2];

			// Date fields need YYYY-MM-DD value for HTML5 <input type="date">.
			// Normalize stored values that V5 may have saved in other formats
			// (e.g. "December 25, 2026") so they round-trip cleanly.
			if ( $is_date ) {
				$field_value = $this->normalize_date_value( $field_value );
			}

			$args = array(
				'id'          => $field_id,
				'label'       => $field_label,
				'placeholder' => $is_date ? 'YYYY-MM-DD' : $field_label,
				'desc_tip'    => true,
				'value'       => esc_attr( wp_unslash( $field_value ) ),
				'description' => sprintf(
					/* translators: %s: field label */
					__( 'Set product %s here.', 'woo-feed' ),
					esc_html( $field_label )
				),
			);

			if ( $is_date ) {
				// HTML5 native date picker.
				$args['type']              = 'date';
				$args['custom_attributes'] = array( 'autocomplete' => 'off' );
			}

			$this->render_text_input_tooltip_after( $args );
		}

		echo '</div>';
	}

	/**
	 * Render custom fields on variable product variation panels.
	 *
	 * Hooked to: woocommerce_product_after_variable_attributes
	 *
	 * @since 8.0.0
	 *
	 * @param int      $loop           Variation loop index.
	 * @param array    $variation_data Variation data array.
	 * @param \WP_Post $variation      Variation post object.
	 * @return void
	 */
	public function render_variation_fields( $loop, $variation_data, $variation ): void {
		$settings      = $this->get_identifier_settings();
		$custom_fields = CustomFieldHelper::get_fields();

		if ( ! $this->has_enabled_fields( $settings ) ) {
			return;
		}

		$has_fields = false;

		foreach ( $custom_fields as $field_key => $field_def ) {
			if ( $this->is_field_renderable( $field_key, $field_def, $settings ) ) {
				$has_fields = true;
				break;
			}
		}

		if ( ! $has_fields ) {
			return;
		}

		echo '<div class="woo-feed-variation-options">';
		echo '<div class="woo-feed-variation-options">';
		echo '<hr>';
		printf(
			'<h4 class="%s">%s</h4>',
			esc_attr( 'woo-feed-variation-option-title' ),
			esc_html__( 'CUSTOM FIELDS by CTX Feed', 'woo-feed' )
		);
		echo '<hr>';
		echo '<div class="woo-feed-variation-items">';

		foreach ( $custom_fields as $field_key => $field_def ) {
			if ( ! $this->is_field_renderable( $field_key, $field_def, $settings ) ) {
				continue;
			}

			$variation_id = $variation->ID;
			$field_label  = isset( $field_def[0] ) ? $field_def[0] : '';
			$is_date      = isset( $field_def[2] ) && 'date' === $field_def[2];

			// Variation meta key: prefer new format, fall back to old.
			$new_var_key = sprintf( 'woo_feed_%s_var', strtolower( $field_key ) );
			$old_var_key = sprintf( 'woo_feed_identifier_%s_var', strtolower( $field_key ) );

			if ( metadata_exists( 'post', $variation_id, $new_var_key ) ) {
				$meta_key = $new_var_key;
			} else {
				$meta_key = $old_var_key;
			}

			$field_value = get_post_meta( $variation_id, $meta_key, true );

			if ( $is_date ) {
				$field_value = $this->normalize_date_value( $field_value );
			}

			$field_id = sprintf( 'woo_feed_%s_var[%d]', strtolower( $field_key ), $variation_id );

			$args = array(
				'id'            => $field_id,
				'value'         => esc_attr( $field_value ),
				'placeholder'   => $is_date ? 'YYYY-MM-DD' : esc_html( $field_label ),
				'label'         => esc_html( $field_label ),
				'desc_tip'      => true,
				'description'   => sprintf(
					/* translators: %s: field label */
					__( 'Set Variation %s here.', 'woo-feed' ),
					esc_html( $field_label )
				),
				'wrapper_class' => 'form-row form-row-full',
			);

			if ( $is_date ) {
				$args['type']              = 'date';
				$args['custom_attributes'] = array( 'autocomplete' => 'off' );
			}

			$this->render_text_input_tooltip_after( $args );
		}

		echo '</div></div>';
		echo '<hr>';
		echo '</div>';
	}

	/**
	 * Save simple product custom field values.
	 *
	 * Hooked to: save_post_product
	 *
	 * @since 8.0.0
	 *
	 * @param int      $post_id Post ID.
	 * @param \WP_Post $post    Post object.
	 * @return void
	 */
	public function save_simple_product_fields( $post_id, $post = null ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $post is the second argument WordPress passes to save_post_product callbacks. It is unused here (only $post_id is needed) but must stay in the signature so the hook contract is documented and so a future re-add does not change the callback arity.
		$custom_fields = CustomFieldHelper::get_fields();

		if ( empty( $custom_fields ) ) {
			return;
		}

		foreach ( $custom_fields as $key => $field_def ) {
			$meta_key     = "woo_feed_{$key}";
			$old_meta_key = "woo_feed_identifier_{$key}";

			// Determine the value to save: prefer new POST key, then old, then existing meta.
			if ( isset( $_POST[ $meta_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Runs inside WooCommerce's own save_post_product / woocommerce_save_product_variation handlers, which have already verified the product-edit nonce and the user's capability. Every value read is passed through wp_unslash() + sanitize_text_field().
				$value = sanitize_text_field( wp_unslash( $_POST[ $meta_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- Runs inside WooCommerce's own save_post_product / woocommerce_save_product_variation handlers, which have already verified the product-edit nonce and the user's capability. Every value read is passed through wp_unslash() + sanitize_text_field().
			} elseif ( isset( $_POST[ $old_meta_key ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Runs inside WooCommerce's own save_post_product / woocommerce_save_product_variation handlers, which have already verified the product-edit nonce and the user's capability. Every value read is passed through wp_unslash() + sanitize_text_field().
				$value = sanitize_text_field( wp_unslash( $_POST[ $old_meta_key ] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- Runs inside WooCommerce's own save_post_product / woocommerce_save_product_variation handlers, which have already verified the product-edit nonce and the user's capability. Every value read is passed through wp_unslash() + sanitize_text_field().
			} else {
				// Preserve existing value when not in POST data.
				$existing = get_post_meta( $post_id, $meta_key, true );
				if ( empty( $existing ) ) {
					$existing = get_post_meta( $post_id, $old_meta_key, true );
				}
				$value = $existing;
			}

			if ( ! empty( $value ) ) {
				update_post_meta( $post_id, $meta_key, $value );
			} else {
				delete_post_meta( $post_id, $meta_key );
			}
		}
	}

	/**
	 * Save variation custom field values.
	 *
	 * Hooked to: woocommerce_save_product_variation
	 *
	 * @since 8.0.0
	 *
	 * @param int $variation_id Variation post ID.
	 * @param int $loop_index   Variation loop index.
	 * @return void
	 */
	public function save_variation_fields( $variation_id, $loop_index = 0 ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed, VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $loop_index is the second argument WooCommerce passes to woocommerce_save_product_variation callbacks. It is unused here (the variation ID keys the POST array) but must stay in the signature to match the hook contract.
		$custom_fields = CustomFieldHelper::get_fields();

		if ( empty( $custom_fields ) ) {
			return;
		}

		foreach ( $custom_fields as $key => $field_def ) {
			$meta_key     = "woo_feed_{$key}_var";
			$old_meta_key = "woo_feed_identifier_{$key}_var";

			// Variation fields use array POST keys: woo_feed_{key}_var[$variation_id].
			if ( isset( $_POST[ $meta_key ][ $variation_id ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Runs inside WooCommerce's own save_post_product / woocommerce_save_product_variation handlers, which have already verified the product-edit nonce and the user's capability. Every value read is passed through wp_unslash() + sanitize_text_field().
				$value = sanitize_text_field( wp_unslash( $_POST[ $meta_key ][ $variation_id ] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- Runs inside WooCommerce's own save_post_product / woocommerce_save_product_variation handlers, which have already verified the product-edit nonce and the user's capability. Every value read is passed through wp_unslash() + sanitize_text_field().
			} elseif ( isset( $_POST[ $old_meta_key ][ $variation_id ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification -- Runs inside WooCommerce's own save_post_product / woocommerce_save_product_variation handlers, which have already verified the product-edit nonce and the user's capability. Every value read is passed through wp_unslash() + sanitize_text_field().
				$value = sanitize_text_field( wp_unslash( $_POST[ $old_meta_key ][ $variation_id ] ) ); // phpcs:ignore WordPress.Security.NonceVerification -- Runs inside WooCommerce's own save_post_product / woocommerce_save_product_variation handlers, which have already verified the product-edit nonce and the user's capability. Every value read is passed through wp_unslash() + sanitize_text_field().
			} else {
				// Preserve existing value.
				$existing = get_post_meta( $variation_id, $meta_key, true );
				if ( empty( $existing ) ) {
					$existing = get_post_meta( $variation_id, $old_meta_key, true );
				}
				$value = $existing;
			}

			if ( ! empty( $value ) ) {
				update_post_meta( $variation_id, $meta_key, $value );
			} else {
				delete_post_meta( $variation_id, $meta_key );
			}
		}
	}
}
