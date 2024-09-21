<?php

namespace CTXFeed\V5\Override;

class Zbozi_czTemplate {
	public function __construct()
	{
		add_filter( 'woo_feed_product_item_wrapper', [$this, 'woo_feed_product_item_wrapper_callback'] );

		add_filter( 'woo_feed_filter_product_visibility', [$this, 'woo_feed_zbozi_product_visibility_callback'], 10, 3 );
	}

	public function woo_feed_product_item_wrapper_callback( $wrapper ){
		return 'SHOPITEM';
	}

	public function woo_feed_zbozi_product_visibility_callback( $product_visibility, $product, $config  ){
		return ($product_visibility=='visible') ? 1 : 0 ;
	}
}
