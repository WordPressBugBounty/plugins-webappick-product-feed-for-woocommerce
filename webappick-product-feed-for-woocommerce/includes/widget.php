<?php

/**
 * Created by PhpStorm.
 * User: wahid
 * Date: 5/22/20
 * Time: 9:02 PM
 */


if (! function_exists('webappick_add_dashboard_widgets')) {
    /**
     * Add a widget to the dashboard.
     *
     * This function is hooked into the 'wp_dashboard_setup' action below.
     */
    function webappick_add_dashboard_widgets()
    {
        global $wp_meta_boxes;

        add_meta_box('aaaa_webappick_latest_news_dashboard_widget', __('Latest News from WebAppick Blog', 'woo-feed'), 'webappick_dashboard_widget_render', 'dashboard', 'side', 'high');
    }
    add_action('wp_dashboard_setup', 'webappick_add_dashboard_widgets', 1);
}

if (! function_exists('webappick_dashboard_widget_render')) {
    /**
     * Function to get dashboard widget data.
     */
    function webappick_dashboard_widget_render()
    {

        $cache_key = 'woo_feed_webappick_posts';
        $cached = get_transient($cache_key);

        // ✅ If cached data exists, use it
        if ($cached !== false) {
            $posts = $cached;
        } else {
            // Enter the name of your blog here followed by /wp-json/wp/v2/posts and add filters like this one that limits the result to 2 posts.
            $response = wp_remote_get('https://webappick.com/wp-json/wp/v2/posts?per_page=5');

            // Exit if error.
            if (is_wp_error($response)) {
                return;
            }

            // Get the body.
            $posts = json_decode(wp_remote_retrieve_body($response));

            // Get custom cache duration (default 1 hour)
            $duration = (int) get_option('woo_feed_webappick_posts', 86400);

            // ✅ Cache for given duration
            set_transient($cache_key, $posts, $duration);
        }

?>
        <p> <a style="text-decoration: none;font-weight: bold;" href="<?php echo esc_url('https://webappick.com'); ?>" target=_balnk><?php echo esc_html__("WEBAPPICK.COM", 'woo-feed'); ?></a></p>
        <hr>
        <?php



        if (! \CTXFeed\V5\Common\Helper::is_pro()) {

            $woo_feed_plugin_api_url = "https://api.wordpress.org/plugins/info/1.2/?action=plugin_information&slug=";
            $woo_feed_disco_slug = 'disco';
            $woo_feed_disco_url = $woo_feed_plugin_api_url . $woo_feed_disco_slug;

            $response = wp_remote_get($woo_feed_disco_url, array(
                'timeout' => 20,
                'sslverify' => false,
            ));

            $woo_feed_disco_response = wp_remote_retrieve_body($response);

            // Decode JSON response
            $woo_feed_disco_data = json_decode($woo_feed_disco_response, true);

            if (!$woo_feed_disco_data) {
                return "Failed to fetch plugin details.";
            }

            $woo_feed_disco_ratings = $woo_feed_disco_data['ratings'];
            $woo_feed_disco_active_installs = $woo_feed_disco_data['active_installs'];

            $woo_feed_disco_rating = woo_feed_calculate_rating($woo_feed_disco_ratings);
            if (woo_feed_is_plugin_activated($woo_feed_disco_slug)) { ?>
                <a target="_blank" href="https://discoplugin.com/?utm_source=CTX&utm_medium=Feed-dSboard&utm_campaign=Banner&utm_id=1">
                    <div class="woo-feed-widget-banner-disco-free"> </div>
                </a>
                <hr />
            <?php } else {
            ?>
                <div class="ctx-widget-container">
                    <div class="ctx-widget-top-section">
                        <!-- logo section  -->
                        <div class="ctx-widget-plugin-logo">
                            <svg
                                xmlns="http://www.w3.org/2000/svg"
                                width="28"
                                height="26"
                                viewBox="0 0 28 26"
                                fill="none">
                                <rect width="27.3597" height="25.7015" rx="4.74254" fill="white" />
                                <path
                                    d="M13.7212 23.1449C19.6351 23.1449 24.4292 22.7285 24.4292 22.2149C24.4292 21.7012 19.6351 21.2848 13.7212 21.2848C7.80733 21.2848 3.01318 21.7012 3.01318 22.2149C3.01318 22.7285 7.80733 23.1449 13.7212 23.1449Z"
                                    fill="#CCCCCC" />
                                <path
                                    d="M22.4987 8.13259L22.4889 8.12439C22.1895 7.90294 21.8181 7.84717 21.5334 7.97019C21.1473 8.13751 20.6238 8.06369 20.1902 7.74383C19.7567 7.42232 19.5342 6.94333 19.5783 6.5234C19.6111 6.21501 19.4491 5.87546 19.1497 5.65237L19.0254 5.56051C18.726 5.33906 18.3546 5.28329 18.0699 5.40632C17.6838 5.57363 17.1602 5.49982 16.7267 5.17831C16.2931 4.8568 16.0706 4.37781 16.1148 3.95788C16.1475 3.6495 15.9855 3.30994 15.6862 3.08685L15.6763 3.07865C15.0415 2.60951 14.2268 2.63576 13.8554 3.14099L5.69472 14.22C5.32333 14.7235 5.53766 15.5126 6.17081 15.9817L6.18063 15.9899C6.48003 16.2113 6.85142 16.2671 7.13609 16.1441C7.5222 15.9768 8.04574 16.0506 8.4793 16.3705C8.91285 16.692 9.13536 17.171 9.09119 17.5909C9.05846 17.8993 9.22043 18.2388 9.51983 18.4619L9.64417 18.5538C9.94357 18.7752 10.315 18.831 10.5996 18.708C10.9857 18.5406 11.5093 18.6145 11.9428 18.9343C12.3764 19.2558 12.5989 19.7348 12.5547 20.1548C12.522 20.4631 12.684 20.8027 12.9834 21.0258L12.9932 21.034C13.628 21.5031 14.4427 21.4769 14.8141 20.9717L22.9764 9.89269C23.3478 9.3891 23.1335 8.60009 22.5003 8.13095L22.4987 8.13259Z"
                                    fill="#1A1D1F" />
                                <path
                                    d="M13.9568 21.6292C13.5723 21.6292 13.178 21.493 12.841 21.2437L12.8312 21.2355C12.4598 20.9599 12.2553 20.5351 12.2995 20.1283C12.3371 19.7871 12.1375 19.3999 11.7907 19.144C11.4454 18.8882 11.0184 18.8111 10.7027 18.9472C10.328 19.1096 9.86337 19.0391 9.49199 18.7635L9.36765 18.6716C8.99626 18.396 8.79175 17.9728 8.83593 17.5644C8.87356 17.2232 8.67396 16.8361 8.32711 16.5802C7.9819 16.3243 7.55489 16.2472 7.23913 16.3833C6.86447 16.5457 6.39983 16.4752 6.02844 16.1996L6.01863 16.1914C5.66524 15.9289 5.41328 15.5714 5.30694 15.1826C5.19569 14.7725 5.25949 14.3755 5.48691 14.0671L13.6492 2.98818C13.8766 2.67979 14.2349 2.50264 14.6603 2.48787C15.0611 2.47475 15.4783 2.6109 15.8317 2.87336L15.8415 2.88156C16.2129 3.15714 16.4174 3.58199 16.3733 3.9888C16.3356 4.32999 16.5352 4.71712 16.8821 4.97301C17.2273 5.22891 17.6543 5.306 17.9701 5.16985C18.3447 5.00746 18.8094 5.07799 19.1807 5.35357L19.3051 5.44543C19.6765 5.72101 19.881 6.14423 19.8368 6.55267C19.7992 6.89387 19.9988 7.28099 20.3456 7.53689C20.6908 7.79278 21.1178 7.86988 21.4336 7.73373C21.8083 7.57134 22.2729 7.64187 22.6443 7.91745L22.6541 7.92565C23.0075 8.18811 23.2594 8.54571 23.3658 8.93447C23.477 9.34456 23.4132 9.74153 23.1858 10.0499L15.0235 21.1289C14.7961 21.4373 14.4378 21.6161 14.0124 21.6292C13.9944 21.6292 13.9764 21.6292 13.9584 21.6292H13.9568ZM11.0397 18.3632C11.1231 18.3632 11.2082 18.3698 11.2949 18.3846C11.5763 18.4305 11.8545 18.5486 12.0966 18.729C12.3387 18.9078 12.5334 19.1391 12.6594 19.3967C12.787 19.6558 12.8394 19.9281 12.8099 20.1857C12.7854 20.4071 12.913 20.6565 13.1355 20.8205L13.1453 20.8287C13.4054 21.0223 13.7065 21.1223 13.9928 21.1125C14.2562 21.1043 14.4738 21.0009 14.6047 20.8221L22.767 9.74317C22.8979 9.56437 22.9339 9.32488 22.8635 9.07062C22.7883 8.7934 22.605 8.53586 22.3449 8.3423L22.3351 8.3341C22.1142 8.17007 21.8393 8.12085 21.6348 8.20943C21.3976 8.31278 21.1211 8.3423 20.8381 8.29637C20.5567 8.25044 20.2785 8.13234 20.0364 7.9519C19.7943 7.7731 19.5996 7.54181 19.4736 7.28427C19.346 7.0251 19.2936 6.7528 19.3231 6.49526C19.3476 6.27381 19.22 6.02448 18.9975 5.86044L18.8732 5.76858C18.6523 5.60455 18.3774 5.55534 18.1729 5.64392C17.9357 5.74726 17.6592 5.77679 17.3762 5.73086C17.0948 5.68493 16.8166 5.56682 16.5745 5.38638C16.3324 5.20758 16.1377 4.97629 16.0117 4.71876C15.8841 4.45958 15.8317 4.18728 15.8612 3.92975C15.8857 3.7083 15.7581 3.45896 15.5356 3.29493L15.5258 3.28673C15.2657 3.0948 14.9646 2.9931 14.6783 3.00294C14.4149 3.01115 14.1973 3.11449 14.0664 3.29329L5.90247 14.3739C5.77158 14.5527 5.73559 14.7922 5.80594 15.0464C5.8812 15.3237 6.06444 15.5812 6.32457 15.7748L6.33439 15.783C6.55526 15.947 6.83011 15.9962 7.03462 15.9076C7.27185 15.8043 7.54835 15.7748 7.83138 15.8207C8.11279 15.8666 8.39092 15.9847 8.63305 16.1652C8.87519 16.344 9.06988 16.5752 9.19586 16.8328C9.32347 17.092 9.37583 17.3643 9.34638 17.6218C9.32184 17.8432 9.44945 18.0926 9.67195 18.2566L9.79629 18.3485C10.0172 18.5125 10.292 18.5617 10.4965 18.4731C10.6618 18.401 10.8466 18.3649 11.0381 18.3649L11.0397 18.3632Z"
                                    fill="#1A1D1F" />
                                <path
                                    d="M22.7392 18.4129C22.7392 17.8519 23.0697 17.3696 23.5458 17.1498C23.8959 16.9874 24.1266 16.6429 24.1266 16.2542V16.0934C24.1266 15.7063 23.8959 15.3602 23.5458 15.1978C23.0697 14.978 22.7392 14.4941 22.7392 13.9347C22.7392 13.3753 23.0697 12.8914 23.5458 12.6716C23.8959 12.5092 24.1266 12.1648 24.1266 11.776V11.7629C24.1266 10.9427 23.464 10.2784 22.6459 10.2784H4.65416C3.83613 10.2784 3.17352 10.9427 3.17352 11.7629V11.776C3.17352 12.1631 3.40421 12.5092 3.75432 12.6716C4.23042 12.8914 4.5609 13.3753 4.5609 13.9347C4.5609 14.4941 4.23042 14.978 3.75432 15.1978C3.40421 15.3602 3.17352 15.7046 3.17352 16.0934V16.2542C3.17352 16.6413 3.40421 16.9874 3.75432 17.1498C4.23042 17.3696 4.5609 17.8535 4.5609 18.4129C4.5609 18.9722 4.23042 19.4561 3.75432 19.676C3.40421 19.8383 3.17352 20.1828 3.17352 20.5716V20.5847C3.17352 21.4049 3.83613 22.0692 4.65416 22.0692H22.6443C23.4623 22.0692 24.1249 21.4049 24.1249 20.5847V20.5716C24.1249 20.1845 23.8942 19.8383 23.5441 19.676C23.068 19.4561 22.7376 18.9722 22.7376 18.4129H22.7392Z"
                                    fill="#0BC88A" />
                                <path
                                    d="M22.6443 22.3284H4.65417C3.69544 22.3284 2.91504 21.5459 2.91504 20.5847V20.5716C2.91504 20.3321 2.98539 20.0991 3.11627 19.8974C3.24552 19.6989 3.42876 19.5414 3.64636 19.4414C4.04556 19.256 4.30242 18.8525 4.30242 18.4129C4.30242 17.9732 4.04556 17.5697 3.64636 17.3844C3.42876 17.2843 3.24552 17.1268 3.11627 16.9283C2.98375 16.7266 2.91504 16.4936 2.91504 16.2542V16.0934C2.91504 15.8539 2.98539 15.621 3.11627 15.4192C3.24552 15.2207 3.42876 15.0633 3.64636 14.9632C4.04556 14.7778 4.30242 14.3743 4.30242 13.9347C4.30242 13.4951 4.04556 13.0915 3.64636 12.9062C3.42876 12.8061 3.24552 12.6487 3.11627 12.4502C2.98375 12.2484 2.91504 12.0155 2.91504 11.776V11.7629C2.91504 10.8016 3.69544 10.0192 4.65417 10.0192H22.6443C23.603 10.0192 24.3834 10.8016 24.3834 11.7629V11.776C24.3834 12.0155 24.3131 12.2484 24.1822 12.4502C24.053 12.6487 23.8697 12.8061 23.6521 12.9062C23.2529 13.0915 22.9961 13.4951 22.9961 13.9347C22.9961 14.3743 23.2529 14.7778 23.6521 14.9632C23.8697 15.0633 24.053 15.2207 24.1822 15.4192C24.3147 15.621 24.3834 15.8539 24.3834 16.0934V16.2542C24.3834 16.4936 24.3131 16.7266 24.1822 16.9283C24.053 17.1268 23.8697 17.2843 23.6521 17.3844C23.2529 17.5697 22.9961 17.9732 22.9961 18.4129C22.9961 18.8525 23.2529 19.256 23.6521 19.4414C23.8697 19.5414 24.053 19.6989 24.1822 19.8974C24.3147 20.0991 24.3834 20.3321 24.3834 20.5716V20.5847C24.3834 21.5459 23.603 22.3284 22.6443 22.3284ZM4.65417 10.5375C3.98012 10.5375 3.43203 11.087 3.43203 11.7629V11.776C3.43203 12.0548 3.60055 12.314 3.86396 12.4354C4.14536 12.565 4.38422 12.7717 4.55274 13.0325C4.7278 13.2999 4.81942 13.6115 4.81942 13.9331C4.81942 14.2546 4.7278 14.5662 4.55274 14.8336C4.38259 15.0944 4.14536 15.3011 3.86396 15.4307C3.60219 15.5521 3.43203 15.8113 3.43203 16.0901V16.2509C3.43203 16.5297 3.60055 16.7889 3.86396 16.9103C4.14536 17.0399 4.38422 17.2466 4.55274 17.5074C4.7278 17.7748 4.81942 18.0864 4.81942 18.4079C4.81942 18.7295 4.7278 19.0411 4.55274 19.3085C4.38259 19.5693 4.14536 19.776 3.86396 19.9056C3.60219 20.027 3.43203 20.2862 3.43203 20.565V20.5781C3.43203 21.254 3.98012 21.8035 4.65417 21.8035H22.6443C23.3184 21.8035 23.8664 21.254 23.8664 20.5781V20.565C23.8664 20.2862 23.6979 20.027 23.4345 19.9056C23.1531 19.776 22.9143 19.5693 22.7457 19.3085C22.5707 19.0411 22.4791 18.7295 22.4791 18.4079C22.4791 18.0864 22.5707 17.7748 22.7457 17.5074C22.9159 17.2466 23.1531 17.0399 23.4345 16.9103C23.6963 16.7889 23.8664 16.5297 23.8664 16.2509V16.0901C23.8664 15.8113 23.6979 15.5521 23.4345 15.4307C23.1531 15.3011 22.9143 15.0944 22.7457 14.8336C22.5707 14.5662 22.4791 14.2546 22.4791 13.9331C22.4791 13.6115 22.5707 13.2999 22.7457 13.0325C22.9159 12.7717 23.1531 12.565 23.4345 12.4354C23.6963 12.314 23.8664 12.0548 23.8664 11.776V11.7629C23.8664 11.087 23.3184 10.5375 22.6443 10.5375H4.65417Z"
                                    fill="#1A1D1F" />
                                <path
                                    d="M18.6409 22.3284C18.4985 22.3284 18.3824 22.2119 18.3824 22.0692V21.1769C18.3824 21.0342 18.4985 20.9177 18.6409 20.9177C18.7832 20.9177 18.8994 21.0342 18.8994 21.1769V22.0692C18.8994 22.2119 18.7832 22.3284 18.6409 22.3284ZM18.6409 20.3468C18.4985 20.3468 18.3824 20.2304 18.3824 20.0877V18.9985C18.3824 18.8558 18.4985 18.7393 18.6409 18.7393C18.7832 18.7393 18.8994 18.8558 18.8994 18.9985V20.0877C18.8994 20.2304 18.7832 20.3468 18.6409 20.3468ZM18.6409 18.1668C18.4985 18.1668 18.3824 18.0503 18.3824 17.9076V16.8184C18.3824 16.6757 18.4985 16.5593 18.6409 16.5593C18.7832 16.5593 18.8994 16.6757 18.8994 16.8184V17.9076C18.8994 18.0503 18.7832 18.1668 18.6409 18.1668ZM18.6409 15.9868C18.4985 15.9868 18.3824 15.8703 18.3824 15.7276V14.6384C18.3824 14.4957 18.4985 14.3792 18.6409 14.3792C18.7832 14.3792 18.8994 14.4957 18.8994 14.6384V15.7276C18.8994 15.8703 18.7832 15.9868 18.6409 15.9868ZM18.6409 13.8067C18.4985 13.8067 18.3824 13.6903 18.3824 13.5476V12.4584C18.3824 12.3157 18.4985 12.1992 18.6409 12.1992C18.7832 12.1992 18.8994 12.3157 18.8994 12.4584V13.5476C18.8994 13.6903 18.7832 13.8067 18.6409 13.8067ZM18.6409 11.6267C18.4985 11.6267 18.3824 11.5102 18.3824 11.3675V10.2783C18.3824 10.1356 18.4985 10.0192 18.6409 10.0192C18.7832 10.0192 18.8994 10.1356 18.8994 10.2783V11.3675C18.8994 11.5102 18.7832 11.6267 18.6409 11.6267Z"
                                    fill="#1A1D1F" />
                                <path
                                    d="M8.49731 14.5427C8.49731 13.7225 9.16646 13.0598 9.99267 13.0598C10.8189 13.0598 11.4799 13.7208 11.4799 14.5427C11.4799 15.3645 10.8205 16.0338 9.99267 16.0338C9.16483 16.0338 8.49731 15.3727 8.49731 14.5427ZM10.6356 14.5427C10.6356 14.1801 10.3461 13.8898 9.99267 13.8898C9.63929 13.8898 9.34152 14.1801 9.34152 14.5427C9.34152 14.9052 9.63111 15.2037 9.99267 15.2037C10.3542 15.2037 10.6356 14.9134 10.6356 14.5427ZM9.51822 19.1045L12.774 13.2468C12.8182 13.1582 12.8803 13.132 12.967 13.132H13.7671C13.8996 13.132 13.9438 13.2205 13.8816 13.3354L10.634 19.1931C10.5898 19.2817 10.5277 19.3079 10.441 19.3079H9.63111C9.49858 19.3079 9.45441 19.2193 9.51658 19.1045H9.51822ZM12.0263 17.8874C12.0263 17.0672 12.6954 16.4045 13.5217 16.4045C14.3479 16.4045 15.0088 17.0655 15.0088 17.8874C15.0088 18.7092 14.3495 19.3784 13.5217 19.3784C12.6938 19.3784 12.0263 18.7174 12.0263 17.8874ZM14.1646 17.8874C14.1646 17.5248 13.8751 17.2345 13.5217 17.2345C13.1683 17.2345 12.8705 17.5248 12.8705 17.8874C12.8705 18.2499 13.1601 18.5484 13.5217 18.5484C13.8832 18.5484 14.1646 18.2581 14.1646 17.8874Z"
                                    fill="#FFFCFF" />
                            </svg>
                            <h2>Disco</h2>
                        </div>

                        <!-- heading text  -->

                        <h1 class="ctx-widget-heading heading">
                            WooCommerce Discounts — <span>Automatic, Smart, Powerful.</span>
                        </h1>

                        <!-- sub heading text  -->

                        <p class="ctx-widget-sub-heading">
                            Run smarter discounts - without coupon code.
                        </p>


                    </div>
                    <div class="ctx-widget-bottom-section">
                        <!-- stats section  -->
                        <div class="ctx-widget-bottom-section-stats">
                            <div class="ctx-widget-stats-item">
                                <h3 class="heading"><?php echo esc_attr($woo_feed_disco_active_installs); ?>+</h3>
                                <p>Active Stores</p>
                            </div>
                            <div class="divider"></div>
                            <div class="ctx-widget-stats-item">
                                <h3 class="heading">★ <?php echo esc_attr($woo_feed_disco_rating) ?></h3>
                                <p>WP Rating</p>
                            </div>
                            <div class="divider"></div>
                            <div class="ctx-widget-stats-item">
                                <h3 class="heading">6</h3>
                                <p>Discount Types</p>
                            </div>
                        </div>

                        <!-- feature section  -->

                        <div class="ctx-widget-features">
                            <div class="ctx-widget-feature">
                                <span class="ctx-widget-feature__icon">
                                    <svg
                                        width="23"
                                        height="23"
                                        viewBox="0 0 23 23"
                                        fill="none"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g filter="url(#filter0_d_7622_23164)">
                                            <rect
                                                x="4.5694"
                                                y="2.74162"
                                                width="13"
                                                height="13"
                                                rx="6.5"
                                                fill="#0BC88A"
                                                shape-rendering="crispEdges" />
                                            <g clip-path="url(#clip0_7622_23164)">
                                                <path
                                                    d="M10.4615 11.0755C10.9604 10.3086 12.7952 8.33458 14.3168 7.30907C14.3815 7.26551 14.4544 7.35101 14.4008 7.40759C12.9553 8.93419 11.4101 10.661 10.5227 12.1541C10.498 12.1957 10.4383 12.1972 10.4125 12.1562C9.96597 11.4489 9.58298 10.4322 8.76208 10.1206C8.70109 10.0974 8.70695 10.0112 8.77037 9.99595C9.55705 9.80641 9.92567 10.4792 10.4615 11.0754V11.0755Z"
                                                    fill="white" />
                                            </g>
                                        </g>
                                        <defs>
                                            <filter
                                                id="filter0_d_7622_23164"
                                                x="1.90735e-05"
                                                y="-3.8147e-06"
                                                width="22.1388"
                                                height="22.1388"
                                                filterUnits="userSpaceOnUse"
                                                color-interpolation-filters="sRGB">
                                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                                <feColorMatrix
                                                    in="SourceAlpha"
                                                    type="matrix"
                                                    values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
                                                    result="hardAlpha" />
                                                <feOffset dy="1.82775" />
                                                <feGaussianBlur stdDeviation="2.28469" />
                                                <feComposite in2="hardAlpha" operator="out" />
                                                <feColorMatrix
                                                    type="matrix"
                                                    values="0 0 0 0 0.0862745 0 0 0 0 0.639216 0 0 0 0 0.290196 0 0 0 0.25 0" />
                                                <feBlend
                                                    mode="normal"
                                                    in2="BackgroundImageFix"
                                                    result="effect1_dropShadow_7622_23164" />
                                                <feBlend
                                                    mode="normal"
                                                    in="SourceGraphic"
                                                    in2="effect1_dropShadow_7622_23164"
                                                    result="shape" />
                                            </filter>
                                            <clipPath id="clip0_7622_23164">
                                                <rect
                                                    width="6"
                                                    height="6"
                                                    fill="white"
                                                    transform="translate(8.5694 6.74162)" />
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </span>
                                <p class="ctx-widget-feature__text">BOGO & Bulk Discounts</p>
                            </div>

                            <div class="ctx-widget-feature">
                                <span class="ctx-widget-feature__icon">
                                    <svg
                                        width="23"
                                        height="23"
                                        viewBox="0 0 23 23"
                                        fill="none"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g filter="url(#filter0_d_7622_23164)">
                                            <rect
                                                x="4.5694"
                                                y="2.74162"
                                                width="13"
                                                height="13"
                                                rx="6.5"
                                                fill="#0BC88A"
                                                shape-rendering="crispEdges" />
                                            <g clip-path="url(#clip0_7622_23164)">
                                                <path
                                                    d="M10.4615 11.0755C10.9604 10.3086 12.7952 8.33458 14.3168 7.30907C14.3815 7.26551 14.4544 7.35101 14.4008 7.40759C12.9553 8.93419 11.4101 10.661 10.5227 12.1541C10.498 12.1957 10.4383 12.1972 10.4125 12.1562C9.96597 11.4489 9.58298 10.4322 8.76208 10.1206C8.70109 10.0974 8.70695 10.0112 8.77037 9.99595C9.55705 9.80641 9.92567 10.4792 10.4615 11.0754V11.0755Z"
                                                    fill="white" />
                                            </g>
                                        </g>
                                        <defs>
                                            <filter
                                                id="filter0_d_7622_23164"
                                                x="1.90735e-05"
                                                y="-3.8147e-06"
                                                width="22.1388"
                                                height="22.1388"
                                                filterUnits="userSpaceOnUse"
                                                color-interpolation-filters="sRGB">
                                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                                <feColorMatrix
                                                    in="SourceAlpha"
                                                    type="matrix"
                                                    values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
                                                    result="hardAlpha" />
                                                <feOffset dy="1.82775" />
                                                <feGaussianBlur stdDeviation="2.28469" />
                                                <feComposite in2="hardAlpha" operator="out" />
                                                <feColorMatrix
                                                    type="matrix"
                                                    values="0 0 0 0 0.0862745 0 0 0 0 0.639216 0 0 0 0 0.290196 0 0 0 0.25 0" />
                                                <feBlend
                                                    mode="normal"
                                                    in2="BackgroundImageFix"
                                                    result="effect1_dropShadow_7622_23164" />
                                                <feBlend
                                                    mode="normal"
                                                    in="SourceGraphic"
                                                    in2="effect1_dropShadow_7622_23164"
                                                    result="shape" />
                                            </filter>
                                            <clipPath id="clip0_7622_23164">
                                                <rect
                                                    width="6"
                                                    height="6"
                                                    fill="white"
                                                    transform="translate(8.5694 6.74162)" />
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </span>
                                <span class="ctx-widget-feature__text">
                                    Product & Cart Discounts
                                </span>
                            </div>

                            <div class="ctx-widget-feature">
                                <span class="ctx-widget-feature__icon">
                                    <svg
                                        width="23"
                                        height="23"
                                        viewBox="0 0 23 23"
                                        fill="none"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g filter="url(#filter0_d_7622_23164)">
                                            <rect
                                                x="4.5694"
                                                y="2.74162"
                                                width="13"
                                                height="13"
                                                rx="6.5"
                                                fill="#0BC88A"
                                                shape-rendering="crispEdges" />
                                            <g clip-path="url(#clip0_7622_23164)">
                                                <path
                                                    d="M10.4615 11.0755C10.9604 10.3086 12.7952 8.33458 14.3168 7.30907C14.3815 7.26551 14.4544 7.35101 14.4008 7.40759C12.9553 8.93419 11.4101 10.661 10.5227 12.1541C10.498 12.1957 10.4383 12.1972 10.4125 12.1562C9.96597 11.4489 9.58298 10.4322 8.76208 10.1206C8.70109 10.0974 8.70695 10.0112 8.77037 9.99595C9.55705 9.80641 9.92567 10.4792 10.4615 11.0754V11.0755Z"
                                                    fill="white" />
                                            </g>
                                        </g>
                                        <defs>
                                            <filter
                                                id="filter0_d_7622_23164"
                                                x="1.90735e-05"
                                                y="-3.8147e-06"
                                                width="22.1388"
                                                height="22.1388"
                                                filterUnits="userSpaceOnUse"
                                                color-interpolation-filters="sRGB">
                                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                                <feColorMatrix
                                                    in="SourceAlpha"
                                                    type="matrix"
                                                    values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
                                                    result="hardAlpha" />
                                                <feOffset dy="1.82775" />
                                                <feGaussianBlur stdDeviation="2.28469" />
                                                <feComposite in2="hardAlpha" operator="out" />
                                                <feColorMatrix
                                                    type="matrix"
                                                    values="0 0 0 0 0.0862745 0 0 0 0 0.639216 0 0 0 0 0.290196 0 0 0 0.25 0" />
                                                <feBlend
                                                    mode="normal"
                                                    in2="BackgroundImageFix"
                                                    result="effect1_dropShadow_7622_23164" />
                                                <feBlend
                                                    mode="normal"
                                                    in="SourceGraphic"
                                                    in2="effect1_dropShadow_7622_23164"
                                                    result="shape" />
                                            </filter>
                                            <clipPath id="clip0_7622_23164">
                                                <rect
                                                    width="6"
                                                    height="6"
                                                    fill="white"
                                                    transform="translate(8.5694 6.74162)" />
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </span>
                                <span class="ctx-widget-feature__text"> Advanced Conditions </span>
                            </div>

                            <div class="ctx-widget-feature">
                                <span class="ctx-widget-feature__icon">
                                    <svg
                                        width="23"
                                        height="23"
                                        viewBox="0 0 23 23"
                                        fill="none"
                                        xmlns="http://www.w3.org/2000/svg">
                                        <g filter="url(#filter0_d_7622_23164)">
                                            <rect
                                                x="4.5694"
                                                y="2.74162"
                                                width="13"
                                                height="13"
                                                rx="6.5"
                                                fill="#0BC88A"
                                                shape-rendering="crispEdges" />
                                            <g clip-path="url(#clip0_7622_23164)">
                                                <path
                                                    d="M10.4615 11.0755C10.9604 10.3086 12.7952 8.33458 14.3168 7.30907C14.3815 7.26551 14.4544 7.35101 14.4008 7.40759C12.9553 8.93419 11.4101 10.661 10.5227 12.1541C10.498 12.1957 10.4383 12.1972 10.4125 12.1562C9.96597 11.4489 9.58298 10.4322 8.76208 10.1206C8.70109 10.0974 8.70695 10.0112 8.77037 9.99595C9.55705 9.80641 9.92567 10.4792 10.4615 11.0754V11.0755Z"
                                                    fill="white" />
                                            </g>
                                        </g>
                                        <defs>
                                            <filter
                                                id="filter0_d_7622_23164"
                                                x="1.90735e-05"
                                                y="-3.8147e-06"
                                                width="22.1388"
                                                height="22.1388"
                                                filterUnits="userSpaceOnUse"
                                                color-interpolation-filters="sRGB">
                                                <feFlood flood-opacity="0" result="BackgroundImageFix" />
                                                <feColorMatrix
                                                    in="SourceAlpha"
                                                    type="matrix"
                                                    values="0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 0 127 0"
                                                    result="hardAlpha" />
                                                <feOffset dy="1.82775" />
                                                <feGaussianBlur stdDeviation="2.28469" />
                                                <feComposite in2="hardAlpha" operator="out" />
                                                <feColorMatrix
                                                    type="matrix"
                                                    values="0 0 0 0 0.0862745 0 0 0 0 0.639216 0 0 0 0 0.290196 0 0 0 0.25 0" />
                                                <feBlend
                                                    mode="normal"
                                                    in2="BackgroundImageFix"
                                                    result="effect1_dropShadow_7622_23164" />
                                                <feBlend
                                                    mode="normal"
                                                    in="SourceGraphic"
                                                    in2="effect1_dropShadow_7622_23164"
                                                    result="shape" />
                                            </filter>
                                            <clipPath id="clip0_7622_23164">
                                                <rect
                                                    width="6"
                                                    height="6"
                                                    fill="white"
                                                    transform="translate(8.5694 6.74162)" />
                                            </clipPath>
                                        </defs>
                                    </svg>
                                </span>
                                <span class="ctx-widget-feature__text">
                                    Display Blocks & Badges
                                </span>
                            </div>
                        </div>

                        <!-- horizontal line -->

                        <div class="ctx-widget-horizontal-line"></div>

                        <!-- action button -->
                        <?php if (current_user_can('install_plugins')) : ?>
                            <button id="activated_<?php echo esc_attr($woo_feed_disco_slug); ?>" style="display: none;" class="ctx-widget-action-button ctx-widget-active-button" type="button"><?php esc_html_e('Activated', 'woo-feed'); ?></button>
                            <button id="installing_<?php echo esc_attr($woo_feed_disco_slug); ?>" style="display: none;" class="ctx-widget-action-button ctx-widget-installing-button" type="button"><?php esc_html_e('Installing...', 'woo-feed'); ?></button>
                            <button id="install_now_<?php echo esc_attr($woo_feed_disco_slug); ?>" onclick="woo_feed_plugin_install('<?php echo esc_attr($woo_feed_disco_slug); ?>')" class="ctx-widget-action-button" type="button"><svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none">
                                    <path d="M11.1056 5.53037H7.0501L9.0721 0L2.89429 8.46963H6.94974L4.92774 14L11.1056 5.53037Z" fill="#FFD83B" />
                                </svg><?php esc_html_e('Install Disco Free', 'woo-feed'); ?> <svg xmlns="http://www.w3.org/2000/svg" width="14" height="14" viewBox="0 0 14 14" fill="none">
                                    <g clip-path="url(#clip0_7716_21078)">
                                        <path d="M13.8396 6.61289C13.8394 6.61273 13.8393 6.61254 13.8391 6.61237L10.9816 3.76862C10.7675 3.55559 10.4213 3.55638 10.2082 3.77048C9.9951 3.98456 9.99592 4.33081 10.21 4.54387L12.1285 6.45312H0.546875C0.244836 6.45312 0 6.69796 0 7C0 7.30204 0.244836 7.54687 0.546875 7.54687H12.1285L10.21 9.45612C9.99594 9.66919 9.99512 10.0154 10.2082 10.2295C10.4213 10.4436 10.7676 10.4444 10.9816 10.2314L13.8391 7.38762C13.8393 7.38746 13.8394 7.38727 13.8396 7.3871C14.0538 7.17333 14.0531 6.82596 13.8396 6.61289Z" fill="white" />
                                    </g>
                                    <defs>
                                        <clipPath id="clip0_7716_21078">
                                            <rect width="14" height="14" fill="white" />
                                        </clipPath>
                                    </defs>
                                </svg></button>
                        <?php else : ?>
                            <button class="ctx-widget-action-button" type="button"><a href="https://wordpress.org/plugins/disco/" target="_blank"><?php esc_html_e('View on WordPress.org', 'woo-feed'); ?></a></button>
                        <?php endif; ?>
                    </div>
                </div>
        <?php }
        }

        //if( !\CTXFeed\V5\Common\Helper::is_pro() ) {
        ?>
        <!--a target="_blank" href="https://discoplugin.com/?utm_source=CTX&utm_medium=Feed-dSboard&utm_campaign=Banner&utm_id=1">
                <div class="woo-feed-widget-banner-disco-free"> </div>
            </a>
            <hr-->
        <?php //}

        //if( !\CTXFeed\V5\Common\Helper::is_pro() ) {
        ?>
        <!--a target="_blank" href="https://webappick.com/discount-deal/?utm_source=wp-dashboard-H-Holiday&utm_medium=free-to-pro&utm_campaign=H-Holiday&utm_id=1">
                <div class="woo-feed-widget-banner-discount-free"> </div>
            </a>
            <hr-->
        <?php //}

        // If there are posts.
        if (! empty($posts)) {
            // For each post.
            foreach ($posts as $post) {
                $fordate = date('M j, Y', strtotime($post->modified)); ?>
                <p class="webappick-feeds"> <a style="text-decoration: none;" href="<?php echo esc_url($post->link); ?>" target=_balnk><?php echo esc_html($post->title->rendered); ?></a> - <?php echo esc_html($fordate); ?></p>
                <span><?php echo esc_html(wp_trim_words($post->content->rendered, 35, '...')); ?></span>
            <?php
            }
            ?>
            <hr>
            <p> <a style="text-decoration: none;" href="<?php echo esc_url('https://webappick.com/blog/'); ?>" target=_balnk><?php echo esc_html__("Get more woocommerce tips & news on our blog...", 'woo-feed'); ?></a></p>
<?php
        }
    }
}
