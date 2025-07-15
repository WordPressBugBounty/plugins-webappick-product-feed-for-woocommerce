<?php

/**
 * Class Shipping
 *
 * @package    CTXFeed
 * @subpackage CTXFeed\V5\Shipping
 */

namespace CTXFeed\V5\Shipping;

use CTXFeed\Compatibility\WOOMULTI_CURRENCYCompatibility;
use CTXFeed\V5\Utility\Cache;
use CTXFeed\V5\Utility\Settings;
use WC_Shipping_Flat_Rate;
use WC_Shipping_Rate;
use WC_Shipping_Zones;
use WC_Tax;



/**
 * Class representing the shipping.
 */

class Shipping {

	protected $shipping;
	private $zoneId;
	private $zoneName;
	/**
	 * @var WC_Shipping_Flat_Rate $methods
	 */
	private $methods;
	private $country;
	private $state;
	private $postcode;
	protected $l = 0;
	/**
	 * @var \WC_Product $product
	 */
	protected $product;
	/**
	 * @var \CTXFeed\V5\Utility\Config $config
	 */
	private $config;


	public function __construct( $product, $config ) {
		$this->product = $product;
		$this->config  = $config;
	}

	/**
	 * Set Shipping Zones.
	 *
	 * @throws \Exception
	 */
	protected function get_shipping_zones( $type ) {
		$shippingInfo = Cache::get( 'ctx_feed_shipping_info' );
        if( !empty ($shippingInfo) ) {
            foreach ($shippingInfo as $key => $info) {
                $shippingInfo[$key]['price'] = $this->get_shipping_price($info);
            }
        }
        if ( ! $shippingInfo ) {
			$zones = WC_Shipping_Zones::get_zones('json');
			if ( ! empty( $zones ) ) {
				foreach ( $zones as $zone ) {
					$this->zoneId   = $zone['zone_id'];
					$this->zoneName = $zone['zone_name'];
					$this->methods  = $zone['shipping_methods'];
					$this->get_locations( $zone['zone_locations'] );
				}
			}
			Cache::set( 'ctx_feed_shipping_info', $this->shipping );
			$shippingInfo = Cache::get( 'ctx_feed_shipping_info' );
		}

		$this->shipping = $shippingInfo;
	}

	/**
	 * Set shipping locations.
	 *
	 * @param $locations
	 *
	 * @return void
	 */
	private function get_locations( $locations ) {
		if ( ! empty( $locations ) ) {
			foreach ( $locations as $location ) {

				if ( 'country' === $location->type ) {
					$this->country = $location->code;
					$this->get_methods();
				} elseif ( 'state' === $location->type ) {

					$countryState  = explode( ':', $location->code );
					$this->country = $countryState[0];
					$this->state   = $countryState[1];

					$this->get_methods();

				} elseif ( 'postcode' === $location->type ) {
					$this->postcode = str_replace( "...", "-", $location->code );
				}
			}
			$this->country  = "";
			$this->state    = "";
			$this->postcode = "";
			$this->zoneId   = "";
			$this->zoneName = "";
		}
	}

	/**
	 * Set Shipping Methods.
	 *
	 * @return void
	 */
	private function get_methods() {
		if ( ! empty( $this->methods ) ) {
			foreach ( $this->methods as $method ) {
				if ( 'yes' === $method->enabled) {

					if ( empty( $this->country ) ) {
						$service = $this->zoneName . " " . $method->title;
					} else {
						$service = $this->zoneName . " " . $method->title . " " . $this->country;
					}

					$this->shipping[ $this->l ]['zone_id']            = $this->zoneId;
					$this->shipping[ $this->l ]['zone_name']          = $this->zoneName;
					$this->shipping[ $this->l ]['country']            = $this->country;
					$this->shipping[ $this->l ]['state']              = $this->state;
					$this->shipping[ $this->l ]['service']            = $service;
					$this->shipping[ $this->l ]['postcode']           = $this->postcode;
					$this->shipping[ $this->l ]['method_id']          = $method->id;
					$this->shipping[ $this->l ]['method_instance_id'] = $method->instance_id;

					if ( 'table_rate' === $method->id ) {
						$this->shipping[ $this->l ]['table_rate_id'] = $method->table_rate_id;
					}

					$this->shipping[ $this->l ]['method_title']      = $method->title;
					$this->shipping[ $this->l ]['method_min_amount'] = isset( $method->min_amount ) ? $method->min_amount : "";
					$this->shipping[ $this->l ]['price']             = $this->get_shipping_price( $this->shipping[ $this->l ] );
                    $this->l ++;
				}
			}
		}
	}

	/**
	 * Get shipping cost.
	 *
	 * @param $shipping array shipping information
	 *
	 * @return mixed $shipping_cost shipping cost
	 * @since 5.2.0
	 */
	private function get_shipping_price_new( $shipping ) {

		if ( ! is_object( $this->product ) ) {
			return "";
		}

		// Initialize shipping cost and tax
		$shipping_cost = 0;
		$tax = 0;
		defined( 'WC_ABSPATH' ) || exit;

		// Load required WooCommerce classes
		if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
			include_once WC_ABSPATH . 'includes/class-wc-shipping-zones.php';
		}
		if ( ! class_exists( 'WC_Shipping_Rate' ) ) {
			include_once WC_ABSPATH . 'includes/class-wc-shipping-rate.php';
		}

		// Set Shipping Country and State.
		WC()->customer->set_shipping_country( $shipping['country'] ?? '' );
		WC()->customer->set_shipping_state( $shipping['state'] ?? '' );

		$chosen_ship_method_id = $shipping['method_id'] . ':' . $shipping['method_instance_id'];
		// If table rate plugin installed
		if ( isset( $shipping['table_rate_id'] ) && 'table_rate' === $shipping['method_id'] && is_plugin_active( 'woocommerce-table-rate-shipping/woocommerce-table-rate-shipping.php' ) ) {
			$chosen_ship_method_id = $shipping['method_id'] . ':' . $shipping['method_instance_id'] . ':' . $shipping['table_rate_id'];
		}
		WC()->session->set( 'chosen_shipping_methods', array( $chosen_ship_method_id ) );

		// Calculate shipping cost and taxes
		$shipping_rate = new WC_Shipping_Rate( $shipping['method'] . ':' . $shipping['instance_id'], $shipping['title'], $shipping['price'], array(), $shipping['id'] );
		if ( $shipping_rate ) {
			$shipping_cost = $shipping_rate->get_cost();
			$taxes = WC_Tax::calc_shipping_tax( $shipping_cost, WC_Tax::get_shipping_tax_rates() );

			foreach ( $taxes as $tax_value ) {
				$tax += $tax_value;
			}

			$shipping_cost += $tax;
		}
		return $shipping_cost;
	}

	/**
	 * Get shipping cost.
	 *
	 * @param $shipping array shipping information
	 *
	 * @return mixed $shipping_cost shipping cost
	 * @since 5.2.0
	 */
	private function get_shipping_price( $shipping ) {

		if ( ! is_object( $this->product ) ) {
			return "";
		}

		// Set shipping cost
		$shipping_cost = 0;
		$tax           = 0;
		defined( 'WC_ABSPATH' ) || exit;

		// Load cart functions which are loaded only on the front-end.
		include_once WC_ABSPATH . 'includes/wc-cart-functions.php';
		include_once WC_ABSPATH . 'includes/class-wc-cart.php';

		wc_load_cart();
		global $woocommerce;

		// Make sure to empty the cart again
		$woocommerce->cart->empty_cart();

		// Set Shipping Country.
		if ( isset( $shipping['country'] ) && ! empty( $shipping['country'] ) ) {
			$woocommerce->customer->set_shipping_country( $shipping['country'] );
		}
		// Set Shipping Region.
		if ( isset( $shipping['state'] ) && ! empty( $shipping['state'] ) ) {
			$woocommerce->customer->set_shipping_state( $shipping['state'] );
		} else {
			$woocommerce->customer->set_shipping_state( "" );
		}

		// set shipping method in the cart
		$chosen_ship_method_id = $shipping['method_id'] . ':' . $shipping['method_instance_id'];
		// If table rate plugin installed
		if ( isset( $shipping['table_rate_id'] ) && 'table_rate' === $shipping['method_id'] && is_plugin_active( 'woocommerce-table-rate-shipping/woocommerce-table-rate-shipping.php' ) ) {
			$chosen_ship_method_id = $shipping['method_id'] . ':' . $shipping['method_instance_id'] . ':' . $shipping['table_rate_id'];
		}
		WC()->session->set( 'chosen_shipping_methods', array( $chosen_ship_method_id ) );

		// get product id
		if ( "variation" === $this->product->get_type() ) {
			$id = $this->product->get_parent_id();
		} elseif ( "grouped" === $this->product->get_type() ) {
			$id = $this->product->get_children();
			$id = reset( $id );
		} else {
			$id = $this->product->get_id();
		}

		// add product to cart
		if ( is_plugin_active( 'woocommerce-multi-currency/woocommerce-multi-currency.php' ) ) {
			do_action('woo_feed_action_shipping_currency',$this->config);
		}

		$woocommerce->cart->add_to_cart( $id, 1 );

		// Read cart and get shipping costs
		$shipping_cost = $woocommerce->cart->get_shipping_total();
		$tax           = $woocommerce->cart->get_shipping_tax();

	    WC()->session->set( 'chosen_shipping_methods', array( '' ) );

		$shipping_cost += $tax;

		// Make sure to empty the cart again
		$woocommerce->cart->empty_cart();

		return $shipping_cost;
	}
}
