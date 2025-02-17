function woo_feed_plugin_install(plugin_slug) {
    jQuery(document).ready( function($){
        // Some event will trigger the ajax call, you can push whatever data to the server,
        // simply passing it to the "data" object in ajax call

        var installing = document.getElementById("installing_"+plugin_slug);
        var install_now = document.getElementById("install_now_"+plugin_slug);
        var activated = document.getElementById("activated_"+plugin_slug);

        installing.style.display = "block"; // Show loader
        install_now.style.display = "none";

        $.ajax({
            url:woo_feed_our_plugins_info.url,
            type: 'POST',
            data:{
                'action': 'woo_feed_plugin_installing',
                _ajax_nonce:woo_feed_our_plugins_info.nonce,  // Action defined in step 3
                'data': plugin_slug // Data to be sent
            },
            success: function( response ){
                if(response.status==200){
                    console.log( response );
                    activated.style.display = "block";
                    installing.style.display = "none";
                }
            },
            error : function(error){ console.log(error) }
        });

    })
}
