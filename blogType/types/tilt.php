<?php
/**
 * @name Plugin TiLT
 * @abstract Plugin to implement blogType and modify wordpress for any blogType, except defined in files
 * @author Antoni Bertran (abertranb@uoc.edu)
 * @copyright 2010 Universitat Oberta de Catalunya
 * @license GPL
 * @version 1.0.0
 * Date December 2022
 */

require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'defaultType.php');

class tilt extends defaultType implements blogType
{

    /**
     * @$custom_params array
     * Force to redirect if returns false don't redirect
     */
    public function force_redirect_to_url($custom_params) {
        return $custom_params['game'] ?? false;
    }
}
