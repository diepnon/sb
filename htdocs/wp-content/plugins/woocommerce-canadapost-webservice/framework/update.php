<?php
/**
 * Custom Update class
 * Allows plugins to display own versioning
* Based on code by Pippin.
* 
* Copyright (c) 2015-2016 Jamez Picard
*/
abstract class cpwebservice_update {
	public $api_url = null;
	public $api_data = null;
	public $slug = '';
	public $plugin = '';
	public $version = null;
	public $upgrade_url = null;
	public $api_response = null;

	/**
	 * Class constructor.
	 *
	 *
	 * @param string $_api_url The URL pointing to the custom API endpoint.
	 * @param string $_plugin_file Path to the plugin file.
	 * @param array $_api_data Optional data to send with API calls.
	 * @return void
	 */
	function __construct( $_api_url, $_slug, $_api_data ) {
		$this->api_url =  $_api_url;
		$this->slug = plugin_basename($_slug);
		$this->plugin = plugin_dir_path($_slug);
		$this->plugin_file = $_slug;
		$this->api_data = $_api_data;
		$this->version = $_api_data['version'];
		
		// debug
		//set_site_transient( 'update_plugins', null );
		
		// Set up filters
		add_filter( 'pre_set_site_transient_update_plugins', array( &$this, 'pre_set_site_transient_update_plugins_filter' ) );
		add_filter( 'plugins_api', array( &$this, 'plugins_api_filter' ), 10, 3);
	}	
	
	/*
	 * Return resources
	 */
	abstract function get_resource($id);
	
	/**
	 * Check for Updates at the defined API endpoint and modify the update array.
	 *
	 * This function dives into the update api just when Wordpress creates its update array,
	 * then adds a custom API call and injects the custom plugin data retrieved from the API.
	 * It is reassembled from parts of the native Wordpress plugin update code.
	 * See wp-includes/update.php line 121 for the original wp_update_plugins() function.
	 *
	 * @uses api_request()
	 *
	 * @param array $_transient_data Update array build by Wordpress.
	 * @return array Modified update array with custom plugin data.
	 */
	function pre_set_site_transient_update_plugins_filter( $_transient_data ) {

		if( empty( $_transient_data ) ) return $_transient_data;

		if (empty($this->api_response)){
    		// Add cpwebservice plugin data to update array (if version is less)
    		$this->api_response = $this->api_request();
		}
		if( empty($_transient_data->response[$this->slug]) && false !== $this->api_response && is_object( $this->api_response ) ) {
		    
		    $this->api_response->slug = $this->slug;
			// update this plugin version
			$this->update_version();
			if( version_compare( $this->version, $this->api_response->new_version, '<' ) ){				
				$_transient_data->response[$this->slug] = $this->api_response;
			} else {
				if (isset($_transient_data->response[$this->slug])) 
					unset($_transient_data->response[$this->slug]);
			}
		}
		return $_transient_data;
	}


	/**
	 * Updates information on the "View version x.x details" page with custom data.
	 *
	 * @uses api_request()
	 *
	 * @param mixed $_data
	 * @param string $_action
	 * @param object $_args
	 * @return object $_data
	 */
	function plugins_api_filter( $_data, $_action = '', $_args = null ) {
		if ( ( $_action != 'plugin_information' ) || !isset( $_args->slug ) || ( $_args->slug != $this->slug ) ) return $_data;

		$api_data = array( 'slug' => $this->slug );

		$api_response = $this->api_request( $api_data );
		if ( false !== $api_response ) $_data = $api_response;

		return $_data;
	}

	/**
	 * Calls the API and, if successfull, returns the object delivered by the API.
	 *
	 * @uses wp_remote_post()
	 * @uses is_wp_error()
	 *
	 * @param string $_action The requested action.
	 * @param array $_data Parameters for the API action.
	 * @return false||object
	 */
	private function api_request( $_data = null ) {
		
		try {
		  if (!empty($this->api_url) &&  strpos($this->api_url, $this->get_resource('version_url'))===0) {
    		  $request_body = !empty($this->api_data) ? json_encode($this->api_data) : '';
    		  $request = wp_remote_post( $this->api_url, array( 'timeout' => 15, 'ssverify' => true, 'body' => $request_body ) );
    		  if ( !is_wp_error( $request ) ){
    		      // check version
    		      $request = (object)json_decode( wp_remote_retrieve_body( $request ) );
    		      if( $request && isset($request->sections) ){
    			     $request->sections = maybe_unserialize( (array)$request->sections );
    			     // Return valid object
    			     return $request;
    		      } else {
    		          return false;
    		      }
    		  }  // endif
		  } // endif
		  return false;
		} catch (Exception $ex) {
		    return false;
		}
	}
	
	/* gets version from plugin file*/
	private function update_version() {
	    if (empty($this->version)){
    		$info = get_plugin_data($this->plugin_file);
    		$this->version = $info['Version'];
	    }
	}
}