<?php
/**
 * Zakeke API client
 * 
 * @author    Mike Sies <support@printeers.com>
 * @copyright 2019 Printeers
 **/

namespace PrintAndShip\Zakeke;

defined('ABSPATH') or die('No script kiddies please!');

/**
 * Zakeke API authentication
 *
 * @package   PrintAndShip\Zakeke
 * @author    Mike Sies <support@printeers.com>
 * @copyright 2019 Printeers
 * @access    public
 */
class Client
{
    private $_clientId;
    private $_secretKey;
    private $_apiUrl;

    private $_bearer = null;
    private $_bearerExpires = 0;

    /**
     * Client constructor
     */
    public function __construct()
    {
        $zakekeSettings = get_option('woocommerce_zakeke_settings');
        $zakekeSettings = maybe_unserialize($zakekeSettings);

        $this->_clientId = $zakekeSettings['client_id'];
        $this->_secretKey = $zakekeSettings['secret_key'];
        $this->_apiUrl = "https://api.zakeke.com/";
        
        $this->_checkBearerToken();
    }

    /**
     * Send a request to the Zakeke server
     *
     * @param string $method     POST or GET
     * @param string $endpoint   Endpoint to post to
     * @param string $body       Body of the request
     * @param array  $args       Arguments for the request
     * @param array  $headers    Headers for the request
     * @param array  $parameters URL parameters for GET request
     *
     * @return array|false Decoded response of the request or false when error
     */
    public function sendRequest($method, $endpoint, $body = "", $args = array(), $headers = array(), $parameters = array())
    {
        $this->_checkBearerToken();
        global $wp_version;

        // Merge all supplied data to one big array of arguments
        $completeArgs = array_merge(
            array(
                'method'        => $method,
                'body'          => $body,
                'headers'       => array_merge(
                    array(
                        'Accept'        => 'application/json',
                        'Authorization' => 'Bearer ' . $this->_bearer,
                        'User-Agent'   => 'invition/print-and-ship; WordPress/' . $wp_version . '; ' . get_bloginfo('url'),
                    ),
                    $headers
                ),
            ),
            $args
        );

        
        // Build the URL and add parameters if supplied
        $url = $this->_apiUrl . $endpoint;
        if (!empty($parameters)) {
            $url .= http_build_query($parameters);
        }
        
        // Send the request
        $response = wp_remote_request($url, $completeArgs);

        // Did the request succeed?
        if (is_wp_error($response)) {
            debuglog('Error getting data from Zakeke API - Error: ' . $response->get_error_message());

            return false;
        }

        $data = json_decode($response['body']);

        // Is the response valid?
        if ($data===null) {
            debuglog("Error: failed to decode data from server. Response was: " . $response['body']);

            return false;
        }

        // Did we receive an error from Zakeke?
        if (property_exists($data, "error")) {
            debuglog("Zakeke returned an error: " . $data->error);
            
            return false;
        }

        return $data;
    }
    
    /**
     * Check the bearer and if neccessary, refresh it
     * 
     * @return void
     */
    private function _checkBearerToken()
    {
        if ($this->_bearer == null || $this->_bearerExpires <= time()) {
            // Refresh the bearer token because it exipred or the class was just instantiated

            $args = array(
                'method'  => 'POST',
                'headers' => array(
                    'Authorization' => 'Basic ' . base64_encode($this->_clientId . ':' . $this->_secretKey),
                    'Content-Type'  => 'application/x-www-form-urlencoded',
                    'Accept'        => 'application/json',
                ),
                'body' => 'grant_type=client_credentials&access_type=S2S',
            );

            $response = wp_remote_post($this->_apiUrl . "token", $args);
            
            $responseString = wp_remote_retrieve_body($response);
            $responseArray = json_decode($responseString);
            
            // Did we receive an error from Zakeke?
            if (property_exists($responseArray, "error")) {
                debuglog("Zakeke returned an error when requesting bearer token: " . $responseArray->error);
                return;
            }

            // Did we receive a valid bearer response?
            if (!property_exists($responseArray, "access_token")
                || !property_exists($responseArray, "token_type")
                || !property_exists($responseArray, "expires_in")
            ) {
                    debuglog("Received an invalid response from Zakeke" . $responseString);
                    return;
            }

            $this->_bearer = $responseArray->access_token;
            $this->_bearerExpires = $responseArray->expires_in + time();
        }
    }
}
