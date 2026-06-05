<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/wordpresslti_database.php';
require_once file_exists(__DIR__ . '/../../../wp-config.php') ? __DIR__ . '/../../../wp-config.php' : __DIR__ . '/../../../../wp-config.php';

use \IMSGlobal\LTI;

// Debug: Log all incoming request data
error_log("[LTI DEBUG] ========== OIDC LOGIN REQUEST ==========");
error_log("[LTI DEBUG] Request Method: " . $_SERVER['REQUEST_METHOD']);
error_log("[LTI DEBUG] Content-Type: " . ($_SERVER['CONTENT_TYPE'] ?? 'not set'));
error_log("[LTI DEBUG] \$_REQUEST: " . print_r($_REQUEST, true));
error_log("[LTI DEBUG] \$_GET: " . print_r($_GET, true));
error_log("[LTI DEBUG] \$_POST: " . print_r($_POST, true));
error_log("[LTI DEBUG] Raw input: " . file_get_contents('php://input'));
error_log("[LTI DEBUG] Query string: " . ($_SERVER['QUERY_STRING'] ?? 'empty'));
error_log("[LTI DEBUG] Request URI: " . ($_SERVER['REQUEST_URI'] ?? 'not set'));
error_log("[LTI DEBUG] ==========================================");

try {
    $redirect_url = plugin_dir_url(__FILE__) . "launch.php";

    LTI\LTI_OIDC_Login::new(new WordPressLTI_Database())
        ->do_oidc_login_redirect($redirect_url)
        ->do_redirect();

} catch (Exception $e) {
    error_log("[LTI] OIDC login failed: " . $e->getMessage());
    throw $e;
}