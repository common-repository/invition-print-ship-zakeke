<?php
/**
 * Plugin Name: Printeers Print & Ship Zakeke
 * Plugin URI: https://printeers.com/getting-started/woocommerce/
 * Description: Extend the Printeers Print & Ship plugin with Zakeke functionality
 * Author: Printeers
 * Version: 1.5.3
 * Author URI: http://printeers.com/
 *
 * @author Mike Sies <support@printeers.com>
 * @copyright 2019 Printeers
 **/

namespace PrintAndShip\Zakeke;

defined('ABSPATH') or die('No script kiddies please!');

define('IPS_ZAKEKE_VERSION', '1.5.3');
define('IPS_ZAKEKE_BASEDIR', dirname(__FILE__));
define('IPS_ZAKEKE_CLASSES', IPS_ZAKEKE_BASEDIR . '/classes/');
define('IPS_ZAKEKE_TMPDIR', getTmpDir());

// ******** WP FUNCTIONS, ACTIONS AND FILTERS ******** //

/**
 * Activation of plugin
 *
 * @return void
 */
function activatePlugin()
{
    update_option('ips_zakeke_version', IPS_ZAKEKE_VERSION);
}

/**
 * Action hook: init
 *
 * @return void
 */
function initialisePlugin()
{
    add_action('wp_loaded', 'PrintAndShip\Zakeke\scheduleCron');
    
    // Cronjobs
    add_action('print_and_ship_zakeke_cron_download', 'PrintAndShip\Zakeke\cronDownloadPrintImages');
    add_action('print_and_ship_zakeke_cron_run_product_import', 'PrintAndShip\Zakeke\cronImportProductsToZakeke');
    add_action('print_and_ship_zakeke_cron_import_status', 'PrintAndShip\Zakeke\cronCheckImportStatus');

    // Printeers actions
    add_action('print_and_ship_print_size_changed', 'PrintAndShip\Zakeke\needsImport');
    add_action('print_and_ship_product_created', 'PrintAndShip\Zakeke\needsImport');

    // Filters
    add_filter('manage_product_posts_columns', 'PrintAndShip\Zakeke\addZakekeColumnHeader', 10, 1);
    add_filter('manage_product_posts_custom_column', 'PrintAndShip\Zakeke\populateZakekeColumn', 10, 3);
}

/**
 * Schedule the cronjobs
 *
 * @return void
 */
function scheduleCron()
{
    if (!wp_next_scheduled('print_and_ship_zakeke_cron_download')) {
        wp_schedule_event(time(), 'print_and_ship_1_minute', 'print_and_ship_zakeke_cron_download');
    }

    if (!wp_next_scheduled('print_and_ship_zakeke_cron_run_product_import')) {
        wp_schedule_event(time(), 'print_and_ship_1_minute', 'print_and_ship_zakeke_cron_run_product_import');
    }

    if (!wp_next_scheduled('print_and_ship_zakeke_cron_import_status')) {
        wp_schedule_event(time(), 'print_and_ship_1_minute', 'print_and_ship_zakeke_cron_import_status');
    }
}

/**
 * Unschedule cronjobs when deactivating plugin
 *
 * @return void
 */
function unscheduleCron()
{
    $timestamp = wp_next_scheduled('print_and_ship_zakeke_cron_download');
    wp_unschedule_event($timestamp, 'print_and_ship_zakeke_cron_download');

    $timestamp = wp_next_scheduled('print_and_ship_zakeke_cron_run_product_import');
    wp_unschedule_event($timestamp, 'print_and_ship_zakeke_cron_run_product_import');

    $timestamp = wp_next_scheduled('print_and_ship_zakeke_cron_import_status');
    wp_unschedule_event($timestamp, 'print_and_ship_zakeke_cron_import_status');
}

/**
 * Run the cronjob to download all Zakeke print images
 *
 * @return void
 */
function cronDownloadPrintImages()
{
    // Check for open orders with missing print images
    $openOrders = wc_get_orders(
        array(
            'status' => 'processing',
            'orderby' => 'date',
            'order' => 'ASC'
        )
    );
    $ordersObject = new \PrintAndShip\Zakeke\Orders();
    foreach ($openOrders as $order) {
        $ordersObject->downloadPrintImages($order);
    }
}

/**
 * Start importing the products to Zakeke
 *
 * @return void
 */
function cronImportProductsToZakeke()
{
    global $wpdb;

    // Select all Zakeke products with Needs Import flag
    $query = "
        SELECT 
            import.post_id,
            sku.meta_value AS sku
        FROM " . $wpdb->postmeta . " AS import
        INNER JOIN " . $wpdb->postmeta . " AS sku
            ON import.post_id = sku.post_id
            AND sku.meta_key = 'print_and_ship_sku'
        WHERE 
            import.meta_key = 'print_and_ship_zakeke_needs_import' 
            AND import.meta_value = '1'";
    $products = $wpdb->get_results($query);

    // Is there anything to do?
    if (count($products) == 0) {
        return;
    }

    debuglog(count($products) . " products need a Zakeke import");

    // Do 5 products max to prevent from hitting max execution time
    $products = array_slice($products, 0, 5);

    $ipp = new \PrintAndShip\IPP();
    $stockList = $ipp->getStockList();

    // Import the new product to Zakeke
    foreach ($products as $product) {
        $item = "";

        // Get Printeers item from stocklist
        foreach ($stockList->items as $stockItem) {
            if ($stockItem->sku == $product->sku) {
                $item = $stockItem;
            }
        }

        // Was the item found?
        if ($item == "") {
            continue; // skipping
        }


        $args = array(
            'post_id'       => $product->post_id,
            'invition_item' => $item,
        );

        $productsObject = new \PrintAndShip\Zakeke\Products();
        $importTaskID = $productsObject->import($args);

        // Was the product queued for import at Zakeke?
        if (is_numeric($importTaskID)) {
            // The import was queued succesfully, mark as waiting for import to finish
            update_post_meta($args['post_id'], 'print_and_ship_zakeke_import_id', $importTaskID);
            update_post_meta($args['post_id'], 'print_and_ship_zakeke_import_status', 'waiting');
            update_post_meta($args['post_id'], 'print_and_ship_zakeke_needs_import', false);
        } else {
            // The import failed, manual action required. Mark the product as error and remove from queue
            update_post_meta($args['post_id'], 'print_and_ship_zakeke_import_status', 'error');
            update_post_meta($args['post_id'], 'print_and_ship_zakeke_needs_import', false);
        }
    }

}

/**
 * Check the status of imported products at Zakeke
 *
 * @return void
 */
function cronCheckImportStatus()
{
    $productsObject = new \PrintAndShip\Zakeke\Products();
    $productsObject->refreshImportStatuses();
}

/**
 * Returns the IPS_ZAKEKE_TMPDIR and if not exists, creates it
 *
 * @return string tmp directory
 */
function getTmpDir()
{
    $wp_upload_dir = wp_upload_dir();
    $ips_zakeke_tmp = $wp_upload_dir["basedir"] . "/ips_zakeke_tmp";
    if (!file_exists($ips_zakeke_tmp)) {
        wp_mkdir_p($ips_zakeke_tmp);
    }

    return $ips_zakeke_tmp;
}

/**
 * This product needs a new import
 * 
 * @param $productID The ID of the product to flag as needsImport
 */
function needsImport($productID)
{
    delete_post_meta($productID, "print_and_ship_zakeke_import_status");
    update_post_meta($productID, 'print_and_ship_zakeke_needs_import', true);
}

/**
 * Downloads all required designs for an order

 * @param int $orderID ID of the order that needs print images
 * 
 * @return void
 */
function downloadPrintImages($orderID)
{
    $ordersObject = new \PrintAndShip\Zakeke\Orders();
    $ordersObject->downloadPrintImages($orderID);
}

/**
 * Add a Zakeke column to product list
 *
 * @return $columns
 */
function addZakekeColumnHeader($columns)
{
    $columns["zakeke_import_status"] = "Zakeke";
    return $columns;
}

/**
 * Populate the Zakeke column
 *
 * @return $output
 */
function populateZakekeColumn($column, $id)
{
    if ($column == 'zakeke_import_status') {
        $status = get_post_meta($id, 'print_and_ship_zakeke_import_status', true);

        switch ($status) {
        case 'success':
            echo '<span style="color: green; font-size: 20px;">&check;</span>';
            break;
            
        case 'error':
            echo '<span style="color: red; font-size: 20px;">&#x78;</span>';
            break;
        
        case 'waiting':
            echo '<span style="color: orange; font-size: 20px;">&circlearrowleft;</span>';
            break;
        }
    }
}

/**
 * Add bulk action to admin products list
 * 
 * @param array $actions Array of existing actions
 * 
 * @return array Updated actions
 */ 
function addNeedsImportBulkAction($actions)
{
    $actions['zakeke_needs_import'] = __('Update Zakeke settings', 'woocommerce');

    return $actions;
}

/**
 * Handle the requested action
 */
function handleNeedsImportBulkAction($redirect_to, $action, $post_ids)
{
    // Is this the action we want to run?
    if ($action !== 'zakeke_needs_import') {
        return $redirect_to;
    }

    $processed_ids = array();

    // Flag all IDs as needsImport to trigger Zakeke importer
    foreach ($post_ids as $post_id) {

        // Is it an ID?
        if (!\is_numeric($post_id)) {
            debuglog('Non numeric post ID requested for update, investigate!');
            continue;
        }

        needsImport($post_id);

        $processed_ids[] = $post_id;
    }

    return $redirect_to = add_query_arg(
        array(
            'processed_count'  => count($processed_ids),
            'processed_ids'    => implode(',', $processed_ids),
            'processed_action' => 'zakeke_needs_import'
        ), $redirect_to
    );
}

/**
 * Display the result of the bulk action
 */
function displayNeedsImportBulkActionResult() 
{
    // Was it a Print and Ship action?
    if (!isset($_GET['processed_action'])) {
        return;
    }
    
    // Is it the right action?
    if ($_GET['processed_action'] != 'zakeke_needs_import') {
        return;
    }

    // Echo the notice
    echo '<div id="message" class="' . ( $_GET['processed_count'] > 0 ? 'updated' : 'error' ) . '">';

    // Was there at least one product scheduled?
    if ($_GET['processed_count'] > 0 ) {
        echo '<p>' . $_GET['processed_count'] . ' scheduled for import.</p>';
    } else {
        echo '<p>' . __('No products were scheduled', 'wordpress') . '</p>';
    }

    echo '</div>';
}

/**
 * Extend the debuglog to this namespace for easier access
 *
 * @return void
 */
function debuglog($log)
{
    \PrintAndShip\debuglog($log);
}

// ********* HOOKS ********* //

register_activation_hook(__FILE__, 'PrintAndShip\Zakeke\activatePlugin');
register_deactivation_hook(__FILE__, 'PrintAndShip\Zakeke\unscheduleCron');

// ******** CLASSES ******** //

require_once IPS_ZAKEKE_CLASSES . 'class-client.php';
require_once IPS_ZAKEKE_CLASSES . 'class-orders.php';
require_once IPS_ZAKEKE_CLASSES . 'class-products.php';

// ******** ACTIONS ******** //

add_action('init', 'PrintAndShip\Zakeke\initialisePlugin');
add_action('admin_notices', 'PrintAndShip\Zakeke\displayNeedsImportBulkActionResult');

// ******** FILTERS ******** //
add_filter('bulk_actions-edit-product', 'PrintAndShip\Zakeke\addNeedsImportBulkAction', 20, 1);
add_filter('handle_bulk_actions-edit-product', 'PrintAndShip\Zakeke\handleNeedsImportBulkAction', 10, 3);
