<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/wordpresslti_database.php';
require_once file_exists(__DIR__ . '/../../../wp-config.php') ? __DIR__ . '/../../../wp-config.php' : __DIR__ . '/../../../../wp-config.php';

use \IMSGlobal\LTI;

try {
    $redirect_url = plugin_dir_url(__FILE__) . "launch.php";

    LTI\LTI_OIDC_Login::new(new WordPressLTI_Database())
        ->do_oidc_login_redirect($redirect_url)
        ->do_redirect();

} catch (Exception $e) {
    error_log("[LTI] OIDC login failed: " . $e->getMessage());
    throw $e;
}