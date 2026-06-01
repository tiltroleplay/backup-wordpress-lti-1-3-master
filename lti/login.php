<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/wordpresslti_database.php';
require_once file_exists(__DIR__ . '/../../../wp-config.php') ? __DIR__ . '/../../../wp-config.php' : __DIR__ . '/../../../../wp-config.php';

use \IMSGlobal\LTI;

// LTI OIDC Login entry point logging
error_log("[LTI LOGIN] ========================================");
error_log("[LTI LOGIN] OIDC login initiated at " . date('Y-m-d H:i:s'));
error_log("[LTI LOGIN] Request method: " . $_SERVER['REQUEST_METHOD']);
error_log("[LTI LOGIN] Issuer (iss): " . ($_REQUEST['iss'] ?? 'not provided'));
error_log("[LTI LOGIN] Login hint: " . ($_REQUEST['login_hint'] ?? 'not provided'));
error_log("[LTI LOGIN] Target link URI: " . ($_REQUEST['target_link_uri'] ?? 'not provided'));

try {
    $redirect_url = plugin_dir_url(__FILE__) . "launch.php";
    error_log("[LTI LOGIN] Redirect URL: " . $redirect_url);

    LTI\LTI_OIDC_Login::new(new WordPressLTI_Database())
        ->do_oidc_login_redirect($redirect_url)
        ->do_redirect();

    error_log("[LTI LOGIN] OIDC redirect completed");
} catch (Exception $e) {
    error_log("[LTI LOGIN] ERROR: OIDC login failed - " . $e->getMessage());
    error_log("[LTI LOGIN] Stack trace: " . $e->getTraceAsString());
    throw $e;
}