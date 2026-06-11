<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/wordpresslti_database.php';
require_once file_exists(__DIR__ . '/../../../wp-config.php') ? __DIR__ . '/../../../wp-config.php' : __DIR__ . '/../../../../wp-config.php';

use \IMSGlobal\LTI;

// ============================================================================
// LTI DEBUG TOGGLE - Set to true to enable detailed logging
// ============================================================================
$LTI_DEBUG_ENABLED = true;

if ($LTI_DEBUG_ENABLED) {
    $lti_debug_id = uniqid('LOGIN_');

    error_log("[{$lti_debug_id}] ==========================================");
    error_log("[{$lti_debug_id}] LTI LOGIN.PHP - " . date('Y-m-d H:i:s'));
    error_log("[{$lti_debug_id}] ==========================================");

    // HTTP Request Details
    error_log("[{$lti_debug_id}] REQUEST_METHOD: " . ($_SERVER['REQUEST_METHOD'] ?? 'NOT SET'));
    error_log("[{$lti_debug_id}] REQUEST_URI: " . ($_SERVER['REQUEST_URI'] ?? 'NOT SET'));
    error_log("[{$lti_debug_id}] CONTENT_TYPE: " . ($_SERVER['CONTENT_TYPE'] ?? 'NOT SET'));
    error_log("[{$lti_debug_id}] CONTENT_LENGTH: " . ($_SERVER['CONTENT_LENGTH'] ?? 'NOT SET'));
    error_log("[{$lti_debug_id}] HTTP_HOST: " . ($_SERVER['HTTP_HOST'] ?? 'NOT SET'));
    error_log("[{$lti_debug_id}] HTTPS: " . (isset($_SERVER['HTTPS']) ? $_SERVER['HTTPS'] : 'NOT SET'));
    error_log("[{$lti_debug_id}] HTTP_REFERER: " . ($_SERVER['HTTP_REFERER'] ?? 'NOT SET'));
    error_log("[{$lti_debug_id}] HTTP_USER_AGENT: " . ($_SERVER['HTTP_USER_AGENT'] ?? 'NOT SET'));
    error_log("[{$lti_debug_id}] REMOTE_ADDR: " . ($_SERVER['REMOTE_ADDR'] ?? 'NOT SET'));

    // Proxy/Network Headers (for diagnosing institutional proxy issues)
    error_log("[{$lti_debug_id}] --- PROXY/NETWORK HEADERS ---");
    error_log("[{$lti_debug_id}] X-Forwarded-For: " . ($_SERVER['HTTP_X_FORWARDED_FOR'] ?? 'NOT SET'));
    error_log("[{$lti_debug_id}] X-Forwarded-Proto: " . ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? 'NOT SET'));
    error_log("[{$lti_debug_id}] X-Forwarded-Host: " . ($_SERVER['HTTP_X_FORWARDED_HOST'] ?? 'NOT SET'));
    error_log("[{$lti_debug_id}] X-Real-IP: " . ($_SERVER['HTTP_X_REAL_IP'] ?? 'NOT SET'));
    error_log("[{$lti_debug_id}] Via: " . ($_SERVER['HTTP_VIA'] ?? 'NOT SET'));
    error_log("[{$lti_debug_id}] HTTP_CONNECTION: " . ($_SERVER['HTTP_CONNECTION'] ?? 'NOT SET'));
    error_log("[{$lti_debug_id}] HTTP_CACHE_CONTROL: " . ($_SERVER['HTTP_CACHE_CONTROL'] ?? 'NOT SET'));
    error_log("[{$lti_debug_id}] SERVER_PROTOCOL: " . ($_SERVER['SERVER_PROTOCOL'] ?? 'NOT SET'));

    // Request Data
    error_log("[{$lti_debug_id}] --- REQUEST DATA ---");
    error_log("[{$lti_debug_id}] \$_GET count: " . count($_GET));
    error_log("[{$lti_debug_id}] \$_POST count: " . count($_POST));
    error_log("[{$lti_debug_id}] \$_REQUEST count: " . count($_REQUEST));

    $raw_input = file_get_contents('php://input');
    error_log("[{$lti_debug_id}] Raw php://input length: " . strlen($raw_input));
    if (!empty($raw_input)) {
        error_log("[{$lti_debug_id}] Raw input (first 500 chars): " . substr($raw_input, 0, 500));
    }

    // Key LTI Parameters
    error_log("[{$lti_debug_id}] --- KEY LTI PARAMETERS ---");
    error_log("[{$lti_debug_id}] iss: " . ($_REQUEST['iss'] ?? 'MISSING'));
    error_log("[{$lti_debug_id}] login_hint: " . ($_REQUEST['login_hint'] ?? 'MISSING'));
    error_log("[{$lti_debug_id}] target_link_uri: " . ($_REQUEST['target_link_uri'] ?? 'MISSING'));
    error_log("[{$lti_debug_id}] lti_message_hint: " . (isset($_REQUEST['lti_message_hint']) ? 'PRESENT (' . strlen($_REQUEST['lti_message_hint']) . ' chars)' : 'MISSING'));
    error_log("[{$lti_debug_id}] client_id: " . ($_REQUEST['client_id'] ?? 'MISSING'));

    // Cookie State
    error_log("[{$lti_debug_id}] --- COOKIES ---");
    error_log("[{$lti_debug_id}] Cookie count: " . count($_COOKIE));

    // Diagnostic Checks
    error_log("[{$lti_debug_id}] --- DIAGNOSTICS ---");
    $issues = [];
    if (empty($_REQUEST['iss'])) $issues[] = "Missing 'iss' parameter";
    if (empty($_REQUEST['login_hint'])) $issues[] = "Missing 'login_hint' parameter";
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && empty($raw_input)) {
        $issues[] = "POST request but no data received";
    }

    if (!empty($issues)) {
        error_log("[{$lti_debug_id}] !!! ISSUES: " . implode(', ', $issues));
    } else {
        error_log("[{$lti_debug_id}] All required parameters present");
    }
}

// ============================================================================
// GET FALLBACK - Handle institutional proxies that convert POST to GET
// ============================================================================
$lti_request = $_REQUEST;

// Check if this is a GET request with LTI parameters (proxy converted POST to GET)
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['iss']) && !empty($_GET['login_hint'])) {
    // Proxy likely converted POST to GET - use GET parameters
    $lti_request = $_GET;

    if ($LTI_DEBUG_ENABLED) {
        error_log("[{$lti_debug_id}] !!! GET FALLBACK ACTIVATED !!!");
        error_log("[{$lti_debug_id}] Detected LTI params in GET request (proxy likely converted POST to GET)");
        error_log("[{$lti_debug_id}] Using \$_GET as request source");
    }
}

// Also check if POST is empty but query string has data
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && !empty($_SERVER['QUERY_STRING'])) {
    parse_str($_SERVER['QUERY_STRING'], $query_params);
    if (!empty($query_params['iss']) || !empty($query_params['login_hint'])) {
        $lti_request = array_merge($_REQUEST, $query_params);

        if ($LTI_DEBUG_ENABLED) {
            error_log("[{$lti_debug_id}] !!! QUERY STRING FALLBACK ACTIVATED !!!");
            error_log("[{$lti_debug_id}] POST was empty but query string has LTI params");
            error_log("[{$lti_debug_id}] Merged query params into request");
        }
    }
}

// Inject the request array into $_REQUEST superglobal for LTI library
if ($lti_request !== $_REQUEST) {
    foreach ($lti_request as $key => $value) {
        $_REQUEST[$key] = $value;
    }

    if ($LTI_DEBUG_ENABLED) {
        error_log("[{$lti_debug_id}] Injected fallback params into \$_REQUEST");
        error_log("[{$lti_debug_id}] Final \$_REQUEST keys: " . implode(', ', array_keys($_REQUEST)));
    }
}

try {
    $redirect_url = plugin_dir_url(__FILE__) . "launch.php";

    $oidc_login = LTI\LTI_OIDC_Login::new(new WordPressLTI_Database())
        ->do_oidc_login_redirect($redirect_url, $lti_request);

    // Log success before redirect
    if ($LTI_DEBUG_ENABLED) {
        error_log("[{$lti_debug_id}] OIDC login SUCCESSFUL");
        error_log("[{$lti_debug_id}] Redirect URL: " . $redirect_url);
        error_log("[{$lti_debug_id}] Issuer: " . ($_REQUEST['iss'] ?? 'unknown'));
        error_log("[{$lti_debug_id}] ==========================================");
    }

    $oidc_login->do_redirect();

} catch (Exception $e) {
    error_log("[LTI] OIDC login failed: " . $e->getMessage());
    if ($LTI_DEBUG_ENABLED) {
        error_log("[{$lti_debug_id}] !!! OIDC LOGIN FAILED !!!");
        error_log("[{$lti_debug_id}] Exception: " . $e->getMessage());
        error_log("[{$lti_debug_id}] Trace: " . $e->getTraceAsString());
        error_log("[{$lti_debug_id}] ==========================================");
    }
    throw $e;
}