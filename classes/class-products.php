<?php
/**
 * Class for Zakeke products in WooCommerce
 * 
 * @author Mike Sies <support@printeers.com>
 * @copyright 2019 Printeers
 **/

namespace PrintAndShip\Zakeke;

defined('ABSPATH') or die('No script kiddies please!');

/**
 * Products related functions
 *
 * @package   PrintAndShip\Zakeke
 * @author    Mike Sies <support@printeers.com>
 * @copyright 2019 Printeers
 * @access    public
 */
class Products
{
    private $_client;
    private $_db;

    /**
     * Products constructor
     */
    public function __construct()
    {
        global $wpdb;
        $this->_db = $wpdb;
    
        $this->_client = new \PrintAndShip\Zakeke\Client();
    }

    /**
     * Starts the import of a product at Zakeke
     *
     * @param array $args all arguments passed by the do_action hook
     * 
     * @return int|false Task ID on success, false on fail
     */
    public function import($args)
    {
        // Do we have a post ID and Printeers stock item?
        if (!isset($args["post_id"]) || !isset($args["invition_item"])) {
            debuglog("Invalid arguments received for Zakeke import");
            
            return false;
        }
        
        $invitionItem = $args["invition_item"];
        $post_id = $args["post_id"];
        
        // Is it a print item?
        if ($invitionItem->kind != "print") {
            debuglog("Cannot import other kinds than print item to Zakeke");
            
            return false;
        }
        
        // Do we have rendering layers?
        if (!property_exists($invitionItem, "rendering_layers")) {
            debuglog("No rendering layers found in Printeers item" . $invitionItem->name);
            
            return false;
        }

        // Create areas CSV
        $areas = array(
            array(
                "MasterProductID",
                "VariantName",
                "SideName",
                "AreaName",
                "UrlAreaMask",
                "ClipOut",
            ),
            array(
                $post_id,
                $invitionItem->attributes->case_colour . " " . $invitionItem->attributes->case_type,
                $invitionItem->attributes->print_side,
                $invitionItem->attributes->print_side,
                $invitionItem->rendering_layers->mask_url,
                "false",
            )
        );

        $areasCSV = $this->_arrayToCSV($areas);

        // Is it a valid CSV?
        if ($areasCSV === false) {
            return false;
        }

        // Create the printTypes contents
        $printTypes = array(
            array(
                "MasterProductID",
                "PrintType",
                "PrintTypeNameLocale",
                "DPI",
                "DisableSellerCliparts",
                "DisableUploadImages",
                "DisableText",
                "UseFixedImageSizes",
                "CanChangeSvgColors",
                "CanUseImageFilters",
                "CanIgnoreQualityWarning",
                "EnableUserImageUpload",
                "EnableJpgUpload",
                "EnablePngUpload",
                "EnableSvgUpload",
                "EnablePdfUpload",
                "EnablePdfWithRasterUpload",
                "EnableEpsUpload",
                "EnableFacebookUpload",
                "EnableInstagramUpload",
                "EnablePreviewDesignsPDF",
            ),
            array(
                $post_id,
                $invitionItem->attributes->print_side,
                "en-GB:".$invitionItem->attributes->print_side,
                300,
                "false",
                "false",
                "false",
                "false",
                "true",
                "true",
                "true",
                "true",
                "true",
                "true",
                "true",
                "true",
                "true",
                "true",
                "true",
                "true",
                "false",
            )
        );

        $printTypesCSV = $this->_arrayToCSV($printTypes);

        // Is it a valid CSV?
        if ($printTypesCSV === false) {
            return false;
        }

        // Create the products contents
        $products = array(
            array(
                "MasterProductID",
                "ProductID",
                "ProductName",
                "ImageLink",
                "Attributes",
                "VariantName",
                "VariantNameLocale",
            ),
            array(
                $post_id,
                $post_id,
                $invitionItem->name,
                $invitionItem->example_images[0],
                "",
                $invitionItem->attributes->case_colour . " " . $invitionItem->attributes->case_type,
            )
        );

        $productsCSV = $this->_arrayToCSV($products);

        // Is it a valid CSV?
        if ($productsCSV === false) {
            return false;
        }

        // Create the sides contents
        $sides = array(
            array(
                "MasterProductID",
                "VariantName",
                "SideName",
                "SideNameLocale",
                "UrlImageSide",
                "SideCode",
                "PPCM",
            ),
            array(
                $post_id,
                $invitionItem->attributes->case_colour . " " . $invitionItem->attributes->case_type,
                $invitionItem->attributes->print_side,
                "en-GB:".$invitionItem->attributes->print_side,
                $invitionItem->rendering_layers->mockup_url,
                $invitionItem->attributes->print_side,
                round($invitionItem->rendering_layers->ppmm * 10),
            )
        );

        $sidesCSV = $this->_arrayToCSV($sides);

        // Is it a valid CSV?
        if ($sidesCSV === false) {
            return false;
        }

        $importZIP = IPS_ZAKEKE_TMPDIR . "/" . $invitionItem->sku . ".zip";
        
        // Create ZIP archive
        $zip = new \ZipArchive;
        if ($zip->open($importZIP, \ZipArchive::CREATE) === true) {
            $zip->addFromString('areas.txt', $areasCSV);
            $zip->addFromString('printTypes.txt', $printTypesCSV);
            $zip->addFromString('products.txt', $productsCSV);
            $zip->addFromString('sides.txt', $sidesCSV);
            
            $zip->close();
        }

        $boundary = wp_generate_password(24, false);

        $body = '';
        $body .= '--' . $boundary . "\r\n";
        $body .= 'Content-Disposition: form-data; name="data"; filename="' . basename($importZIP) . '"' . "\r\n";
        $body .= 'Content-Type: application/zip' . "\r\n\r\n";
        $body .= file_get_contents($importZIP) . "\r\n";
        $body .= '--' . $boundary . '--' . "\r\n";

        $headers = array('Content-Type' => 'multipart/form-data; boundary=' . $boundary);

        $result = $this->_client->sendRequest('POST', 'v2/csv/import', $body, array(), $headers);

        // The import failed
        if ($result === false) {
            return false;
        }
        
        // Was the import succesful? If not, store the result for debugging purposes
        if (!property_exists($result, "taskID")) {
            debuglog($result);

            return false;
        }
        
        // Remove the ZIP
        if (!unlink($importZIP)) {
            debuglog("Failed to remove " . $importZIP);
        }

        return $result->taskID;
    }

    /**
     * Refresh all statuses of uncompleted imports
     *
     * @return void
     */
    public function refreshImportStatuses()
    {
        // Select all Zakeke products without a status
        $query = "
            SELECT
                import_id.post_id AS post_id,
                import_id.meta_value AS task_id,
                import_status.meta_value AS status
            FROM
                " . $this->_db->postmeta . " AS import_id
            LEFT JOIN
                " . $this->_db->postmeta . " AS import_status
                ON import_id.post_id = import_status.post_id
                AND import_status.meta_key = %s
            WHERE
                import_id.meta_key = %s
                AND import_status.meta_value = %s";
        $query = $this->_db->prepare(
            $query,
            'print_and_ship_zakeke_import_status',
            'print_and_ship_zakeke_import_id',
            'waiting'
        );
        $zakekeProducts = $this->_db->get_results($query, ARRAY_A);

        // Iterate through Zakeke products and process their status
        foreach ($zakekeProducts as $zakekeProduct) {
            $status = $this->_getImportStatus($zakekeProduct["task_id"]);

            // Is the answer valid?
            if (!property_exists($status, 'error') && !property_exists($status, 'importedProducts')) {
                debuglog("Something went wrong requesting the Zakeke status for post " . $zakekeProduct["post_id"]);
                continue;
            }
            
            // Is there an error? We only check if it exists as we don't support multi line imports for now
            if (!empty($status->errors)) {
                update_post_meta($zakekeProduct["post_id"], "print_and_ship_zakeke_import_status", "error");
                update_post_meta($zakekeProduct["post_id"], "print_and_ship_zakeke_needs_import", true);
                continue;
            }

            // Is something imported?
            if (count($status->importedProducts) > 0) {
                update_post_meta($zakekeProduct["post_id"], "print_and_ship_zakeke_import_status", "success");
                continue;
            }

            debuglog("Could not process status answer from Zakeke: " . print_r($status, true));
        }
    }

    /**
     * Check the status of a started import at Zakeke
     *
     * @param int $TaskID ID of the import task at Zakeke
     * 
     * @return array|false
     */
    private function _getImportStatus($TaskID)
    {
        $endpoint = "v2/csv/importingresult/" . $TaskID;
        $importResult = $this->_client->sendRequest("GET", $endpoint);

        if ($importResult === false) {
            debuglog("Could not retreive import result for " . $TaskID);
            return false;
        }

        return $importResult;
    }

    /**
     * Converts a single dimensional array to CSV string
     *
     * @param array $csvContents CSV contents
     * 
     * @return string|false CSV array or false on error
     */
    private function _arrayToCSV($csvContents)
    {
        // Is there at least one header row and one row with values?
        if (count($csvContents) < 2) {
            debuglog("Not enough CSV rows supplied to generate a CSV for Zakeke");

            return false;
        }

        // Are all rows the same length?
        for ($i=0; $i<count($csvContents); $i++) {
            $lastCount = count($csvContents[$i]);

            if ($lastCount != count($csvContents[$i])) {
                debuglog("Every row in a CSV should have the same number of columns");
                
                return false;
            }
        }

        // Write the CSV
        $csv = "";
        foreach ($csvContents as $row) {
            foreach ($row as $column) {
                $csv .= '"' . $column . '",';
            }
            $csv .= "\r\n";
        }
        
        return $csv;
    }
}
