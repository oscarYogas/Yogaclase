<?php

$product_name = 'ordercsvimportexport'; // name should match with 'Software Title' configured in server, and it should not contains white space
$product_version = '2.4.7';
$product_slug = 'order-import-export-for-woocommerce-pro/order-import-export.php'; //product base_path/file_name
$serve_url = 'https://www.webtoffee.com/';
$plugin_settings_url = admin_url( 'admin.php?page=wf_woocommerce_order_im_ex' );

//include api manager
include_once ( 'wf_api_manager.php' );
new WF_API_Manager($product_name, $product_version, $product_slug, $serve_url, $plugin_settings_url);
?>
