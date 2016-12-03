<?php
/*
 Product Options class
woocommerce_cpwebservice_products.php

Copyright (c) 2013-2016 Jamez Picard

*/
class woocommerce_cpwebservice_products extends cpwebservice_products
{
    function get_resource($id) {
        return cpwebservice_r::resource($id);
    }
}