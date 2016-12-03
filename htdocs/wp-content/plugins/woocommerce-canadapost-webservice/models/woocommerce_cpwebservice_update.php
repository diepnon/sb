<?php
/**
 * Custom Update class
*/
class woocommerce_cpwebservice_update extends cpwebservice_update
{
    /*
     * Constructor
     */
    function __construct( $_api_url, $_slug, $_api_data ) {
        parent::__construct($_api_url, $_slug, $_api_data);
    }
    
    function get_resource($id) {
        return cpwebservice_r::resource($id);
    }
}