   <?php
/**
 * Our Plugins
 *
 * @link       https://webappick.com/
 * @since      1.0.0
 *
 * @package    Woo_Feed
 * @subpackage Woo_Feed/admin/partial
 * @author     Nashir Uddin <nasir.webappick@gmail.com>
 * @version    6.5.48
 */
    if ( ! function_exists( 'add_action' ) ) die();

    $woo_feed_plugin_api_url= "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=";

    $woo_feed_challan_slug = 'webappick-pdf-invoice-for-woocommerce';
    $woo_feed_challan_url = $woo_feed_plugin_api_url . $woo_feed_challan_slug;

    // Initialize cURL
    $woo_feed_ch = curl_init();
    curl_setopt($woo_feed_ch, CURLOPT_URL, $woo_feed_challan_url);
    curl_setopt($woo_feed_ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($woo_feed_ch, CURLOPT_SSL_VERIFYPEER, false);

    $woo_feed_challan_response = curl_exec($woo_feed_ch);
    curl_close($woo_feed_ch);

    // Decode JSON response
    $woo_feed_challan_data = json_decode($woo_feed_challan_response, true);

    if (!$woo_feed_challan_data) {
        return "Failed to fetch plugin details.";
    }

    $woo_feed_challen_ratings = $woo_feed_challan_data['ratings'];

    $woo_feed_challen_rating = woo_feed_calculate_rating($woo_feed_challen_ratings);

    $woo_feed_disco_slug = 'disco';
    $woo_feed_disco_url = $woo_feed_plugin_api_url . $woo_feed_disco_slug;

    // Initialize cURL
    $woo_feed_ch = curl_init();
    curl_setopt($woo_feed_ch, CURLOPT_URL, $woo_feed_disco_url);
    curl_setopt($woo_feed_ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($woo_feed_ch, CURLOPT_SSL_VERIFYPEER, false);

    $woo_feed_disco_response = curl_exec($woo_feed_ch);
    curl_close($woo_feed_ch);

    // Decode JSON response
    $woo_feed_disco_data = json_decode($woo_feed_disco_response, true);

    if (!$woo_feed_disco_data) {
        return "Failed to fetch plugin details.";
    }

    $woo_feed_disco_ratings = $woo_feed_disco_data['ratings'];

    $woo_feed_disco_rating = woo_feed_calculate_rating($woo_feed_disco_ratings);

    function woo_feed_calculate_rating($ratings)
    {
        $totalRatings = array_sum($ratings); // Sum of all ratings
        if ($totalRatings == 0) {
            return 0;
        }

        $weightedSum = (5 * $ratings[5]) + (4 * $ratings[4]) + (3 * $ratings[3]) + (2 * $ratings[2]) + (1 * $ratings[1]);
        $averageRating = $weightedSum / $totalRatings;

        return round($averageRating, 2); // Round to 2 decimal places
    }

   if ( ! function_exists( 'woo_feed_is_plugin_activated' ) ) {
       function woo_feed_is_plugin_activated($plugin_slug)
       {

           if ($plugin_slug == 'webappick-pdf-invoice-for-woocommerce') {
               $plugin_index = 'woo-invoice';
           } else {
               $plugin_index = $plugin_slug;
           }

           $plugin_path = $plugin_slug . '/' . $plugin_index . ".php";

           if (is_plugin_active($plugin_path)) {
               return true; // Plugin is acive
           } else {
               return false; // Plugin is not active
           }
       }
   }


?>
<section class="our_plugin_main_container">

<section class="our_plugin_container">
   <h3 class="our_plugin_title">Explore Our Essential WooCommerce Plugins</h3>
   <p class="our_plugin_description">At WebAppick, we specialize in creating high-quality plugins that empower WooCommerce store owners to <br>
   streamline their workflows, improve store performance, and enhance the customer experience.</p>
   <img class="our_plugin_plus_1" src="<?php echo esc_url( WOO_FEED_PLUGIN_URL ); ?>admin/images/our_plugins/plus-icon-1.png" alt="<?php esc_attr_e( 'Add Plus logo', 'woo-feed' ); ?>">

   <img class="our_plugin_plus_2" src="<?php echo esc_url( WOO_FEED_PLUGIN_URL ); ?>admin/images/our_plugins/plus-icon-2.png" alt="<?php esc_attr_e( 'Add Plus logo', 'woo-feed' ); ?>">

   <img class="our_plugin_circle_2" src="<?php echo esc_url( WOO_FEED_PLUGIN_URL ); ?>admin/images/our_plugins/circle-2.png" alt="<?php esc_attr_e( 'Add circle icon 2', 'woo-feed' ); ?>">

   <img class="our_plugin_circle_1" src="<?php echo esc_url( WOO_FEED_PLUGIN_URL ); ?>admin/images/our_plugins/circle-1.png" alt="<?php esc_attr_e( 'Add circle icon 2', 'woo-feed' ); ?>">

   

   <div class="our_plugin_main_card">
<!-------------------------- our_plugin_card_one part start  -------------------->
      <div class="our_plugin_card_one">
      <div class="our_plugin_card_one_top">
      <img class="our_plugin_card_one_top_image" src="<?php echo esc_url( WOO_FEED_PLUGIN_URL ); ?>admin/images/our_plugins/challan-logo.png" alt="<?php esc_attr_e( 'Add challan logo in our_plugin page', 'woo-feed' ); ?>">
      <div class="our_plugin_card_one_top_review">
         <h3>Reviews on <a target="_blank" href="https://wordpress.org/support/plugin/webappick-pdf-invoice-for-woocommerce/reviews/?filter=5">wp.org</a></h3>
         <div class="rating_section">
         <span class="dashicons star_one dashicons-star-filled"></span>
         <span class="dashicons star_one dashicons-star-filled"></span>
         <span class="dashicons star_one dashicons-star-filled"></span>
         <span class="dashicons star_one dashicons-star-filled"></span>
         <span class="dashicons star_one dashicons-star-filled"></span>
          <span class="rating">(<?php echo esc_attr($woo_feed_challan_data['num_ratings']); ?>)</span>
         </div>
      </div>

         </div>

         <div class="our_plugin_card_one_middle">
            <h3><span>Challan</span> - PDF invoice and Packing Slip Plugin for Woocommerce</h3>
            <p>Challan plugin allows you to attach a fully customizable PDF invoice to order confirmation emails, offering easy downloads and more customization than standard WooCommerce invoices.</p>
         </div>

         <div class="our_plugin_card_one_bottom">
             <?php if (woo_feed_is_plugin_activated($woo_feed_challan_slug)) { ?>
                <button class="installed" type="submit" name="submit">Active</button>
             <?php } else { ?>
                 <button id="activated_<?php echo esc_attr($woo_feed_challan_slug); ?>" style="display: none;" class="installed" type="submit" name="submit">Active</button>
                 <button id="installing_<?php echo esc_attr($woo_feed_challan_slug); ?>" style="display: none;" class="installed" type="submit" name="submit">Installing...</button>
                 <button id="install_now_<?php echo esc_attr($woo_feed_challan_slug); ?>" onclick="woo_feed_plugin_install('<?php echo esc_attr($woo_feed_challan_slug); ?>')" class="install_now" type="submit" name="submit">Install Now</button>
                 <input type="hidden" id="woo_feed_plugin_slug" name="woo_feed_plugin_slug" value=<?php echo esc_attr($woo_feed_challan_slug); ?>>
             <?php } ?>
             <a class="read_doc" target="_blank" href="https://webappick.com/docs/challan/">Read Docs</a>
         </div>

      </div>


<!-------------------------- our_plugin_card_two part start  -------------------->

      <div class="our_plugin_card_two">
      <div class="our_plugin_card_two_top">
      <img class="our_plugin_card_two_top_image" src="<?php echo esc_url( WOO_FEED_PLUGIN_URL ); ?>admin/images/our_plugins/disco-icon.png" alt="<?php esc_attr_e( 'Add Disco logo in our_plugin page', 'Disco icon' ); ?>">
      <div class="our_plugin_card_two_top_review">
         <h3>Reviews on <a target="_blank" href="https://wordpress.org/support/plugin/disco/reviews/?filter=5">wp.org</a></h3>
         <div class="rating_section_two">
         <span class="dashicons dashicons-star-filled"></span>
         <span class="dashicons dashicons-star-filled"></span>
         <span class="dashicons dashicons-star-filled"></span>
         <span class="dashicons dashicons-star-filled"></span>
         <?php
         if($woo_feed_disco_rating == 5){
            echo '<span class="dashicons dashicons-star-filled"></span>';
         }else{
            echo '<span class="dashicons dashicons-star-half"></span>';
         }
         ?>
         <span class="rating"> (<?php echo esc_attr($woo_feed_disco_data['num_ratings']); ?>)</span>
         </div>
      </div>

         </div>

         <div class="our_plugin_card_two_middle">
            <h3><span>Disco </span>- The Ultimate Dynamic Discount Plugin for WooCommerce</h3>
            <p>Transform your store with advanced discount features, personalized pricing strategies, and unparalleled flexibility designed to grow your revenue effortlessly.</p>
         </div>

         <div class="our_plugin_card_two_bottom">
             <?php if (woo_feed_is_plugin_activated($woo_feed_disco_slug)) { ?>
                 <button id="installed" class="installed" type="submit" name="submit">Active</button>
             <?php } else { ?>
                 <button id="activated_<?php echo esc_attr($woo_feed_disco_slug); ?>" style="display: none;" class="installed" type="submit" name="submit">Active</button>
                 <button id="installing_<?php echo esc_attr($woo_feed_disco_slug); ?>" style="display: none;" class="installed" type="submit" name="submit">Installing...</button>
                 <button id="install_now_<?php echo esc_attr($woo_feed_disco_slug); ?>" onclick="woo_feed_plugin_install('<?php echo esc_attr($woo_feed_disco_slug); ?>')" class="install_now" type="submit" name="submit">Install Now</button>
                 <input type="hidden" id="woo_feed_plugin_slug" name="woo_feed_plugin_slug" value=<?php echo esc_attr($woo_feed_disco_slug); ?>>
             <?php } ?>
            <a class="read_doc" target="_blank" href="https://discoplugin.com/docs/">Read Docs</a>
         </div>
         <img class="our_plugin_kon_icon" src="<?php echo esc_url( WOO_FEED_PLUGIN_URL ); ?>admin/images/our_plugins/kon-icon.png" alt="<?php esc_attr_e( 'Add circle icon 2', 'woo-feed' ); ?>">
      </div>

      
   </div>
   </section>


   <!------------------  About Us Part Start ------------------->
   <section class="about_us_images">
      <div class="about_us">
         <div class="about_us_text">
            <h3>About Us</h3>
            <p class="about_us_text_1"><span>At WebAppick</span>, we develop powerful eCommerce plugins designed to supercharge your WooCommerce store. Trusted by over 1 million users globally, our solutions are designed to help businesses thrive with simplicity, innovation, and seamless experiences.</p>


            <p class="about_us_text_2"><span>Our mission</span> is to empower businesses with innovative tools that drive growth and make your eCommerce journey more efficient and successful.</p>

            <p class="about_us_text_3">WebAppick’s flagship plugins - <a target="_blank" href="https://webappick.com/plugin/woocommerce-product-feed-pro/">CTX Feed</a>, <a target="_blank" href="https://webappick.com/plugin/woocommerce-pdf-invoice-packing-slips">Challan</a>, and <a target="_blank" href="https://discoplugin.com/pricing/">Disco</a> - are built for WooCommerce to simplify product feeds, invice solutions, and dynamic discounts to ensuring your store operates smoothly and scales effortlessly.</p>
         </div>
         
         <div class="about_us_img">
         <img class="about_us_img" src="<?php echo esc_url( WOO_FEED_PLUGIN_URL ); ?>admin/images/our_plugins/about-us.png" alt="<?php esc_attr_e( 'Add Disco logo in our_plugin page', 'Disco icon' ); ?>">
         </div>
      </div>


      <img class="our_plugin_circle_3" src="<?php echo esc_url( WOO_FEED_PLUGIN_URL ); ?>admin/images/our_plugins/circle-3.png" alt="<?php esc_attr_e( 'Add Plus logo', 'woo-feed' ); ?>">

      <img class="our_plugin_multiplication" src="<?php echo esc_url( WOO_FEED_PLUGIN_URL ); ?>admin/images/our_plugins/multiplication.png" alt="<?php esc_attr_e( 'Add Plus logo', 'woo-feed' ); ?>">

      <img class="our_plugin_dottet" src="<?php echo esc_url( WOO_FEED_PLUGIN_URL ); ?>admin/images/our_plugins/dottet.png" alt="<?php esc_attr_e( 'Add Plus logo', 'woo-feed' ); ?>">

      <img class="our_plugin_kon_2" src="<?php echo esc_url( WOO_FEED_PLUGIN_URL ); ?>admin/images/our_plugins/kon-2.png" alt="<?php esc_attr_e( 'Add Plus logo', 'woo-feed' ); ?>">
   </section>

</section>
