<?php
/**
 * @name Plugin TiLT
 * @abstract Plugin to implement blogTypeLTI12 and modify wordpress for any blogTypeLTI12, except defined in files
 * @author Antoni Bertran (abertranb@uoc.edu)
 * @copyright 2010 Universitat Oberta de Catalunya
 * @license GPL
 * @version 1.0.0
 * Date December 2022
 */

require_once(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'defaultType.php');

class tilt extends defaultTypeLTI implements blogTypeLTI
{


    /**
     * get the course path
     * @see blogType::getCoursePath()
     */
    public function getCoursePath($jwt_body) {
        $game = $this->getGame($jwt_body);
        if ($game) {
            return $game;
        }
        $context_id = $jwt_body["https://purl.imsglobal.org/spec/lti/claim/context"]["id"];
        $client_id = is_array($jwt_body['aud']) ? $jwt_body['aud'][0] : $jwt_body['aud'];

        $course = str_replace(':','-', $client_id.'-'.$context_id);  // TO make it past sanitize_user
        return $course;


    }

    private function getGame($jwt_body) {
        // $custom_params = $lti_data['https://purl.imsglobal.org/spec/lti/claim/custom'] ?? [];
        $custom_params = $jwt_body['https://purl.imsglobal.org/spec/lti/claim/custom'] ?? [];
        return $custom_params['game'] ?? false;
    }


    /**
     * Gets the course name
     * @see blogType::getCourseName()
     */
    public function getCourseName($jwt_body) {
        $game = $this->getGame($jwt_body);
        if ($game) {
            return $game;
        }
        $title = $jwt_body["https://purl.imsglobal.org/spec/lti/claim/context"]["title"];
        return $title;
    }
    /**
     * @$custom_params array
     * Force to redirect if returns false don't redirect
     */
    public function force_redirect_to_url($custom_params) {
        return $custom_params['game'] ?? false;
    }
}