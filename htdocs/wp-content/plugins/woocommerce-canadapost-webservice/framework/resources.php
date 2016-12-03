<?php
/*
 Resource class
 cpwebservice_resources.php

 Copyright (c) 2013-2016 Jamez Picard

 */
abstract class cpwebservice_resources
{
    
    public static function resource($id) {
        switch($id)
        {
            // Main
            case 'method_title': return 'TrueMedia';
            case 'method_logo_url': return 'img/cpwebservice.png';
    
            // Label Descriptions
            case 'parcel_services' : return __('(ie. if Ground and Express are same cost, keep the Express Service.)', 'woocommerce-canadapost-webservice');
            case 'lettermail_default' : return __('Lettermail', 'woocommerce-canadapost-webservice');
            
            // Shipments
            case 'shipment_country' : return 'CA';
            case 'shipment_country_label' : return 'Canada';
            // Sender Shipment Countries (Can be used as Domestic).
            case 'sender_shipment_countries' : return array('US' => 'United States', 'CA'=> 'Canada');
            case 'postalcode_warning': return __('Warning: Postal Code is invalid.', 'woocommerce-canadapost-webservice');
            case 'hscode_search_url' : return 'https://www.canadapost.ca/cpotools/apps/wtz/personal/findHsCode';
        }
        return '';
    }
    
    
    // BEGIN Section Static Conversions
    
    /*
     * Convert size cm to inches
     */
    public static function cm_to_in($size) {
        return floatval($size) * 0.393701;
    }
    /*
     * Converts cm3 to in3
     */
    public static function cm3_to_in3($size) {
        return floatval($size) * 0.0610237;
    }
    /*
     * Convert size inches to cm
     */
    public static function in_to_cm($size) {
        return floatval($size) * 2.54;
    }
    
    /*
     * Convert weight kg to lbs
     */
    public static function kg_to_lb($weight) {
        return (floatval($weight) * 2.20462);
    }
    
    /*
     * Convert weight kg to lbs
     */
    public static function lb_to_kg($weight) {
        return (floatval($weight) * 0.453592);
    }
    
    /*
     * Utility method to safely round.  Even if the locale has a problem decimal separator ',' it'll be fine.
     */
    
    public static function round_decimal($number,$precision=0)
    {
        return str_replace(',','.', round($number, $precision));
    }
    
    // END Section Static Conversions
    
    // Begin Convenient Display Functions
    public static function display_unit($cm, $display_units_option){
        return $display_units_option == 'in' ?  self::round_decimal(self::cm_to_in($cm),3) :  self::round_decimal($cm,3);
    }
    
    public static function display_unit_cubed($cm3, $display_units_option){
        return $display_units_option == 'in' ? self::round_decimal(self::cm3_to_in3($cm3),3) :  self::round_decimal($cm3,3);
    }
    
    // Display Weight
    public static function display_weight($kg, $display_weights_option){
        return $display_weights_option == 'lbs' ? self::round_decimal(self::kg_to_lb($kg),3) : self::round_decimal($kg,3);
    }
    
    // Save Size (to cm)
    // Returns cm
    public static function save_unit($size, $display_units_option){
        return $display_units_option == 'in' ?  floatval(number_format(self::in_to_cm($size),4,'.','')) :  floatval(number_format($size,4,'.',''));
    }
    
    // Save weight (to kg)
    // Returns kg.
    public static function save_weight($weight, $display_weights_option){
        return $display_weights_option == 'lbs' ? floatval(number_format(self::lb_to_kg($weight),4,'.','')) : floatval(number_format($weight,4,'.',''));
    }
    
    // END Section Size/Weight Display Options.
    
}