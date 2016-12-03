<?php

/**
 * Largest Area Fit First (LAFF) 3D box packing algorithm class
 *
 * @author Maarten de Boer <info@maartendeboer.net>
 * @copyright Maarten de Boer 2012
 * @version 1.2
 * @contributor Jamez Picard (TrueMedia.ca) jameztrue
 *
 * Also see this PDF document for an explanation about the LAFF algorithm:
 * @link http://www.zahidgurbuz.com/yayinlar/An%20Efficient%20Algorithm%20for%203D%20Rectangular%20Box%20Packing.pdf
 *
 * Copyright (C) 2012 Maarten de Boer
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated documentation files (the "Software"), to deal in the Software without restriction, including without limitation the rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit persons to whom the Software is furnished to do so, subject to the following conditions:
 *
 * The above copyright notice and this permission notice shall be included in all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE SOFTWARE.
 */
class cpwebservice_pack {
	
	/** @var array $boxes Array of boxes to pack */
	private $boxes = null;
	
	/** @var array $packed_boxes Array of boxes that have been packed */
	private $packed_boxes = null;
	
	/** @var int $level Current level we're packing (0 based) */
	private $level = -1;
	
	/** @var array $container_dimensions Current container dimensions */
	private $container_dimensions = null;
	
	/** @var bool $container_preset Container dimensions preset */
	private $container_preset = false;
	
	/** @var array $max_container Maximum container dimensions and 'weight' */
	private $max_container = null;
	
	/** @var string debug log */
	private $log = '';
	
	/** @var bool enable debug log */
	private $debug = false;
	
	/**
	 * Constructor of the BoxPacking class
	 *
	 * @access public
	 * @param array $boxes Array of boxes to pack
	 */
	function __construct($boxes = null, $container = null, $max = null)
	{		
	    // If parameters are provided in constructor: pack.
		if(isset($boxes) && is_array($boxes)) {
		    $this->pack($boxes, $container, $max);
		}
	}
	
	/**
	 * Start packing boxes
	 * 
	 * @access public
	 * @param array $boxes
	 * @param array $container Set fixed container dimensions
	 * @returns void
	 */
	function pack($boxes = null, $container = null, $max = null) {
		if(isset($boxes) && is_array($boxes)) {
			$this->boxes = $boxes;
			$this->packed_boxes = array();
			$this->level = -1;
			$this->container_dimensions = null;
			
			// Calculate container size
			if(!is_array($container) || $container == null) {
				$this->container_dimensions = $this->_calc_container_dimensions();
			}
			else 
			{
				if(!array_key_exists('length', $container) || !array_key_exists('width', $container)) {
						throw new InvalidArgumentException("Pack function only accepts array with (length, width) as argument for \$container");
				}
			
				$this->container_preset = true;
				$this->container_dimensions['length'] = $container['length'];
				$this->container_dimensions['width'] = $container['width'];
				
				// Note: do NOT set height, it will be calculated on-the-go
				$this->container_dimensions['height'] = 0;
				// Weight will be added for each box.
				$this->container_dimensions['weight'] = 0;
			}
			
			// Maximum Container size (& weight)
			if (is_array($max)){
				if(!array_key_exists('height', $max) && !array_key_exists('weight', $max)) {
					throw new InvalidArgumentException("Function _pack only accepts array (height, weight) as argument for $max");
				}
				$this->max_container = $max;
			}
		}
		
		if(!isset($this->boxes)) {
			throw new InvalidArgumentException("Pack function only accepts array (length, width, height) as argument for \$boxes or no boxes given!");
		}
		
		$this->pack_level();
	}
	
	/**
	 * Get remaining boxes to pack
	 *
	 * @access public
	 * @returns array
	 */
	function get_remaining_boxes() {
		return $this->boxes;
	}
	
	/**
	 * Get packed boxes
	 *
	 * @access public
	 * @returns array
	 */
	function get_packed_boxes() {	
		return $this->packed_boxes;
	}
	
	/**
	 * Get container dimensions
	 *
	 * @access public
	 * @returns array
	 */
	function get_container_dimensions() {
		return $this->container_dimensions;
	}
	
	/**
	 * Get container volume
	 *
	 * @access public
	 * @returns float
	 */
	function get_container_volume() {
		if(!isset($this->container_dimensions)) {
			return 0;
		}
	
		return $this->_get_volume($this->container_dimensions);
	}
	
	/**
	 * Get container weight
	 *
	 * @access public
	 * @returns float
	 */
	function get_container_weight() {
	    if(!isset($this->packed_boxes) || count($this->packed_boxes) == 0) {
	        return 0;
	    }
	    
	    // Get weight
	    $weight = 0;
	    for($i = 0; $i<count($this->packed_boxes); $i++){
	        foreach($this->packed_boxes[$i] as $p){
	            $weight += (isset($p['weight']) ? $p['weight'] : 0);
	        }
	    }
	    
	    return $weight;
	}
	
	/**
	 * Get number of levels
	 *
	 * @access public
	 * @returns int
	 */
	function get_levels() {
		return $this->level + 1;
	}
	
	/**
	 * Get total volume of packed boxes
	 *
	 * @access public
	 * @returns float
	 */
	function get_packed_volume() {
		if(!isset($this->packed_boxes)) {
			return 0;
		}
		
		$volume = 0;
		
		for($i = 0; $i < count(array_keys($this->packed_boxes)); $i++) {
			foreach($this->packed_boxes[$i] as $box) {
				$volume += $this->_get_volume($box);
			}
		}
		
		return $volume;
	}
	
	/**
	 * Get number of levels
	 *
	 * @access public
	 * @returns int
	 */
	function get_remaining_volume() {
		if(!isset($this->packed_boxes)) {
			return 0;
		}
	
		$volume = 0;
		
		foreach($this->boxes as $box) {
			$volume += $this->_get_volume($box);
		}
		
		return $volume;
	}
	
	/**
	 * Get dimensions of specified level
	 *
	 * @access public
	 * @param int $level
	 * @returns array
	 */
	function get_level_dimensions($level = 0) {
		if($level < 0 || $level > $this->level || !array_key_exists($level, $this->packed_boxes)) {
			throw new OutOfRangeException("Level {$level} not found!");
		}
	
		$boxes = $this->packed_boxes;
		$edges = array('length', 'width', 'height');
		
		// Get longest edge
		$le = $this->_calc_longest_edge($boxes[$level], $edges);
		$edges = array_diff($edges, array($le['edge_name']));
		
		// Re-iterate and get longest edge now (second longest)
		$sle = $this->_calc_longest_edge($boxes[$level], $edges);
		
		return array(
			'width' => $le['edge_size'],
			'length' => $sle['edge_size'],
			'height' => $boxes[$level][0]['height'],
			'weight' => 0
		);
	}
	
	/**
	 * Get longest edge from boxes
	 *
	 * @access public
	 * @param array $edges Edges to select the longest from
	 * @returns array
	 */
	function _calc_longest_edge($boxes, $edges = array('length', 'width', 'height')) {
		if(!isset($boxes) || !is_array($boxes)) {
			throw new InvalidArgumentException('_calc_longest_edge function requires an array of boxes, '.gettype($boxes).' given');
		}
		
		// Longest edge
		$le = 0;		// Longest edge
		$lef = null;	// Edge field (length | width | height) that is longest
		
		// Get longest edges
		foreach($boxes as $k => $box) {
			foreach($edges as $edge) {
				if(array_key_exists($edge, $box) && $box[$edge] > $le) {
					$le = $box[$edge];	
					$lef = $edge;
				}
			}
		}
		
		return array(
			'edge_size' => $le,
			'edge_name' => $lef
		);
	}
	
	/**
	 * Calculate container dimensions
	 *
	 * @access public
	 * @returns array
	 */
	function _calc_container_dimensions() {
		if(!isset($this->boxes)){
			return array(
				'length' => 0,
				'width' => 0,
				'height' => 0,
				'weight' => 0
			);
		}
		
		$boxes = $this->boxes;
		
		$edges = array('length', 'width', 'height');
		
		// Get longest edge
		$le = $this->_calc_longest_edge($boxes, $edges);
		$edges = array_diff($edges, array($le['edge_name']));
		
		// Re-iterate and get longest edge now (second longest)
		$sle = $this->_calc_longest_edge($boxes, $edges);
		
		// Calculate 2x shortest lxw edge to allow side-by-side (cube) packing for simlar boxes. Alternatively, it will end up as long boxes, which are not typical.
		if (count($boxes) >= 4){
		    // Get shortest edges x2
		    $shorth = $this->_calc_shortest_edge_xn($boxes, array('height'));
		    $shorte = $this->_calc_shortest_edge_xn($boxes);
		    // Height has to be greater than 1/2 L or W edge to ensure it is not a flat item.
		    if ($sle['edge_size'] > 0 && $shorth['edge_size'] > ($sle['edge_size']/2) 
		        &&  $shorte['edge_size'] > $sle['edge_size']){
		        $sle['edge_size'] = $shorte['edge_size'];
		        if($this->debug) { $this->log .= "Shorte: " . json_encode($shorte) . "\n"; }
		    }
		}
		
		return array(
			'length' => $sle['edge_size'],
			'width' => $le['edge_size'],
			'height' => 0,
			'weight' => 0
		);
	}
	
	/*
	 * Shortest Edge (2x boxes).
	 * Returns shortest + 2nd shortest edge. (for avg cubing).
	 */
	private function _calc_shortest_edge_xn($boxes, $edges = array('length', 'width')){
	    // Shortest edge
	    $se = 999999;	// Shortest edge
	    $se2 = 0; // 2nd Shortest edge.
	    $sef = null;	// Edge field (length | width) that is shortest
	    
	    // Get shortest edges
	    foreach($boxes as $k => $box) {
	        foreach($edges as $edge) {
	            if(array_key_exists($edge, $box) && $box[$edge] <= $se) {
	                //2nd shortest.
	                $se2 = $se;
	                $se = $box[$edge];
	                $sef = $edge;
	            }
	        }
	    }
	    
	    // Valid values.
	    $se  =  ($se != 999999 ? $se : 0);
	    $se2 =  ($se2 != 999999 ? $se2 : 0);
	    
	    // Return se+se2.
	    return array(
	        'edge_size' => $se + $se2,
	        'edge_name' => $sef
	    );
	}
	
	/**
	 * Utility function to swap two elements in an array
	 * 
	 * @access public
	 * @param array $array
	 * @param mixed $el1 Index of item to be swapped
	 * @param mixed $el2 Index of item to swap with
	 * @returns array
	 */ 
	function _swap($array, $el1, $el2) {
		if(!array_key_exists($el1, $array) || !array_key_exists($el2, $array)) {
			throw new InvalidArgumentException("Both element to be swapped need to exist in the supplied array");
		}
	
		$tmp = $array[$el1];
		$array[$el1] = $array[$el2];
		$array[$el2] = $tmp;
		
		return $array;
	}
	
	/**
	 * Utility function that returns the total volume of a box / container
	 *
	 * @access public
	 * @param array $box
	 * @returns float
	 */
	function _get_volume($box)  {	
		if(!is_array($box) || count(array_keys($box)) < 3) {
			throw new InvalidArgumentException("_get_volume function only accepts arrays with 3 values (length, width, height)");
		}
		
		return $box['length'] * $box['width'] * $box['height']; 
	}
	
	/**
	 * Check if box fits in specified space
	 *
	 * @access private
	 * @param array $box Box to fit in space
	 * @param array $space Space to fit box in
	 * @returns bool
	 */
	private function _try_fit_box($box, $space)  {
		if(count($box) < 3) {
			throw new InvalidArgumentException("_try_fit_box function parameter $box only accepts arrays with 3 values (length, width, height)");
		}
		
		if(count($space) < 3) {
			throw new InvalidArgumentException("_try_fit_box function parameter $space only accepts arrays with 3 values (length, width, height)");
		}
	
		$sides = array('length','width', 'height');
		foreach($sides as $side) {
		    if(array_key_exists($side, $space)) {
		        if($box[$side] > $space[$side]) {
		            return false;
		        }
		    }
		}
		
		return true;
	}
	
	/**
	 * Check if box fits in specified space
	 * and rotate (3d) if necessary
	 *
	 * @access public
	 * @param array $box Box to fit in space
	 * @param array $space Space to fit box in
	 * @returns bool
	 */
	function _box_fits($box, $space) {
		//$box = array_values($box);
		//$space = array_values($space);
		
		if($this->_try_fit_box($box, $space)) {
			return true;
		}
		
		$sides = array('length','width', 'height');
		foreach($sides as $side){
		    // This temp box is only to get the 2 sides to swap.
		    // Temp box size (arrays are assigned by copy)
		    $t_box = $box;
		    	
		    // Remove fixed column from list to be swapped
		    unset($t_box[$side]);
		    // Keys to be swapped
		    $t_keys = array_keys($t_box);
		    	
		    // Temp box with swapped sides
		    $s_box = $this->_swap($box, $t_keys[0], $t_keys[1]);
		    	
		    if($this->_try_fit_box($s_box, $space)){
		        return true;
		    }
		}
		
		return false;
	}

	/**
	 * Start a new packing level
	 *
	 * @access private
	 * @returns void
	 */
	private function pack_level() {
		$biggest_box_index = null;
		$biggest_surface = 0;
		
		$this->level++;
		
		// Find biggest (widest surface) box with minimum height
		foreach($this->boxes as $k => $box)
		{
			$surface = $box['length'] * $box['width'];
			
			if($surface > $biggest_surface) {
				$biggest_surface = $surface;
				$biggest_box_index = $k;
			}
			elseif($surface == $biggest_surface) {
				if(!isset($biggest_box_index) || (isset($biggest_box_index) && $box['height'] < $this->boxes[$biggest_box_index]['height']))
					$biggest_box_index = $k;
			}
		}
		
		// Get biggest box as object
		$biggest_box = $this->boxes[$biggest_box_index];
		
		// Check against max-height for container.  If it will become too big, return.
		if (isset($this->max_container['height']) && ($this->container_dimensions['height'] + $biggest_box['height']) > $this->max_container['height']){
		    if ($this->debug){ $this->log .= 'Max height: '.$biggest_box_index . "\n"; }
			return;
		}
		
		// Check against max-girth for container.  If it will become too big, return.
		if (isset($this->max_container['girth'])){
			// Girth: length + (width x 2) + (height x 2)
			$max_girth = floatval($this->container_dimensions['length'] + $this->container_dimensions['width']*2 + ($this->container_dimensions['height'] + $biggest_box['height'])*2);
			if ($max_girth > floatval($this->max_container['girth'])){
			    if ($this->debug){ $this->log .= 'Max girth: '.$biggest_box_index . "\n"; }
				return;
			}
		}

		// Check against max-weight for container.  If it will become too big, return.
		if (isset($biggest_box['weight']) && isset($this->max_container['weight']) && ($this->container_dimensions['weight'] + $biggest_box['weight']) > $this->max_container['weight']){
		    if ($this->debug){ $this->log .= 'Max weight: '.$biggest_box_index . "\n"; }
			return;
		}
		

		if ($this->container_preset){
			// Check against max-length for container.
			// Check against max-width for container.
			if (($this->container_dimensions['length'] < $biggest_box['length'] || $this->container_dimensions['width'] < $biggest_box['width'])
					&&  ($this->container_dimensions['width'] < $biggest_box['length'] || $this->container_dimensions['length'] <  $biggest_box['width']) ){
				// The box will not fit the surface area of the container (given it's length & width).  It will need to be 3d rotated in order to fit.
			    if ($this->debug){ $this->log .= 'Not fit surface area: '.$biggest_box_index . "\n"; }
					return;
			}
		}
		
		// Add to packed boxes.
		$this->packed_boxes[$this->level][] = $biggest_box;
		
		// Add to container weight
		$this->container_dimensions['weight'] += isset($biggest_box['weight']) ? $biggest_box['weight'] : 0;
		
		// Set container height (ck = ck + ci)
		$this->container_dimensions['height'] += $biggest_box['height'];
		
		// Remove box from array (ki = ki - 1)
		unset($this->boxes[$biggest_box_index]);
		
		// Check if all boxes have been packed
		if(count($this->boxes) == 0)
			return;
		
		$c_area = $this->container_dimensions['length'] * $this->container_dimensions['width'];
		$p_area = $biggest_box['length'] * $biggest_box['width'];
		
		// No space left (not even when rotated / length and width swapped)
		if($c_area - $p_area <= 0) {
		    if ($this->debug){ $this->log .= 'Next level: '. ($this->level + 1) . "\n"; }
			$this->pack_level();
		}
		else { // Space left, check if a package fits in
			$spaces = array();
			
			if($this->container_dimensions['length'] - $biggest_box['length'] > 0) {
				$spaces[] = array(
					'length' => $this->container_dimensions['length'] - $biggest_box['length'],
					'width' => $this->container_dimensions['width'],
					'height' => $biggest_box['height'],
					'weight' => $biggest_box['weight']
				);
			}
			
			if($this->container_dimensions['width'] - $biggest_box['width'] > 0) {
				$spaces[] = array(
					'length' => $biggest_box['length'],
					'width' => $this->container_dimensions['width'] - $biggest_box['width'],
					'height' => $biggest_box['height'],
					'weight' => $biggest_box['weight']
				);
			}
			
			// Fill each space with boxes
			foreach($spaces as $space) {
				$this->_fill_space($space);
			}
			if ($this->debug){ $this->log .= 'Used spaces: '.count($spaces) . "\n"; }
			
			// Start packing remaining boxes on a new level
			if(count($this->boxes) > 0)
				$this->pack_level();
		}
	}
	
	/**
	 * Fills space with boxes recursively
	 *
	 * @access private
	 * @returns void
	 */
	private function _fill_space($space) {	

		// Total space volume
		$s_volume = $this->_get_volume($space);
		
		$fitting_box_index = null;
		$fitting_box_volume = null;
		
		foreach($this->boxes as $k => $box)
		{
			// Skip boxes that have a higher volume than target space
			if($this->_get_volume($box) > $s_volume) {
				continue;
			}
			
			if($this->_box_fits($box, $space)) {
				$b_volume = $this->_get_volume($box);
			
				if(!isset($fitting_box_volume) || $b_volume > $fitting_box_volume) {
					$fitting_box_index = $k;
					$fitting_box_volume = $b_volume;
				}
			}
		}
		
		if(isset($fitting_box_index))
		{
			$box = $this->boxes[$fitting_box_index];
		
			// Pack box
			$this->packed_boxes[$this->level][] = $this->boxes[$fitting_box_index];
			unset($this->boxes[$fitting_box_index]);
			
			// Calculate remaining space left (in current space)
			$new_spaces = array();
		
			if($space['length'] - $box['length'] > 0) {
				$new_spaces[] = array(
					'length' => $space['length'] - $box['length'],
					'width' => $space['width'],
					'height' => $box['height'],
					'weight' => $box['weight']
				);					
			}
			
			if($space['width'] - $box['width'] > 0) {
				$new_spaces[] = array(
					'length' => $box['length'],
					'width' => $space['width'] - $box['width'],
					'height' => $box['height'],
					'weight' => $box['weight']
				);
			}
			
			if(count($new_spaces) > 0) {
				foreach($new_spaces as $new_space) {
					$this->_fill_space($new_space);
				}
			}
		}
	}
	
	public function enable_debug() {
	    $this->debug = true;
	}
	
	public function get_debug_log() {
	    return $this->log;
	}
}