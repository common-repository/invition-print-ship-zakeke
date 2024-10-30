<?php
/**
 * Class for orders with a Zakeke product
 * 
 * @author Mike Sies <support@printeers.com>
 * @copyright 2019 Printeers
 **/

namespace PrintAndShip\Zakeke;

defined('ABSPATH') or die('No script kiddies please!');

/**
 * Orders related functions
 *
 * @package   PrintAndShip\Zakeke
 * @author    Mike Sies <support@printeers.com>
 * @copyright 2019 Printeers
 * @access    public
 */
class Orders
{
    private $_client;

    /**
     * Products constructor
     */
    public function __construct()
    {
        $this->_client = new \PrintAndShip\Zakeke\Client();
    }

    /**
     * Downloads all print images for an order
     *
     * @param WC_Order $order WooCommerce order object
     * 
     * @return void
     */
    public function downloadPrintImages($order)
    {
        global $wp_version;

        // Iterating through each WC_Order_Item_Product objects
        foreach ($order->get_items() as $item_id => $item) {
            $zakeke_data = $item->get_meta('zakeke_data');

            // Is it a Zakeke product?
            if (!$zakeke_data) {
                continue;
            }

            // Was it already downloaded?
            if ($item->get_meta('print_and_ship_print_image')) {
                continue;
            }

            // Send request to Zakeke server
            $result = $this->_client->sendRequest(
                'GET',
                'v1/designs/' . $zakeke_data['design'] . '/outputfiles/zip',
                '',
                array(),
                array('Content-Type' => 'application/json'),
                array()
            );

            // Is the printfile generated and ready to download?
            if (!isset($result->url)) {
                debuglog("Received an incorrect response from Zakeke");
                return;
            }

            $fileName = basename($result->url);
            $extractFolder = IPS_ZAKEKE_TMPDIR . "/" . uniqid() . "/";
            mkdir($extractFolder);
            $zipFile = $extractFolder . $fileName;
        
            // Download the ZIP file
            if (!file_put_contents($zipFile, fopen($result->url, 'r'))) {
                debuglog("Could not download ZIP from Zakeke (" . $result->url . ")");
                $this->_recurseRmdir($extractFolder); // Cleanup
                return;
            }
        
            $zip = new \ZipArchive;

            // Can we open the ZIP file?
            if ($zip->open($zipFile) !== true) {
                debuglog("The ZIP file retreived from Zakeke is invalid and cannot be opened");
                $this->_recurseRmdir($extractFolder); // Cleanup
                return;
            }

            // Can we extract the ZIP file?
            if (!$zip->extractTo($extractFolder)) {
                debuglog("Cannot extract ZIP file");
                $this->_recurseRmdir($extractFolder); // Cleanup
                return;
            }

            $zip->close();
        
            // Iterate through retreived files and search for the file with the PNG extension
            $dh = opendir($extractFolder);
            while (($file = readdir($dh)) !== false) {
                if ($file !== '.' && $file !== '..') {
                    $path_parts = pathinfo($file);
                    
                    if (array_key_exists("extension", $path_parts)) {
                        if (strtoupper($path_parts['extension']) == "PNG") {
                            $type = pathinfo($extractFolder . $file, PATHINFO_EXTENSION);
                            $data = file_get_contents($extractFolder . $file);
                            $printFile = 'data:image/' . $type . ';base64,' . base64_encode($data);
                        }
                    }
                }
            }
        
            // Do we have a printfile?
            if (!isset($printFile)) {
                debuglog("Could not find printfile in ZIP for order " . $order->id);
                $this->_recurseRmdir($extractFolder); // Cleanup
                return;
            }

            $woo = new \PrintAndShip\Woo();
            $result = $woo->updateOrderPrintImage($order->id, $item_id, $printFile);

            if ($result["success"] == false) {
                debuglog($result["message"] . " Order " . $order->id);
                $this->_recurseRmdir($extractFolder); // Cleanup
                return;
            }
        
            $this->_recurseRmdir($extractFolder); // Cleanup
        }
        $order->update_status(preg_replace('/^wc\-/', '', get_option('print_and_ship_order_status')));
    }

    /**
     * Clean up a directory and its contents
     *
     * @param string $dir directory to be removed
     * 
     * @return bool was the directory removed succesfully?
     */
    private function _recurseRmdir($dir)
    {
        $files = array_diff(scandir($dir), array('.','..'));
        foreach ($files as $file) {
            (is_dir("$dir/$file")) ? $this->_recurseRmdir("$dir/$file") : unlink("$dir/$file");
        }
        return rmdir($dir);
    }
}
