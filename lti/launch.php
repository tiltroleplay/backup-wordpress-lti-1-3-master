<?php

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/wordpresslti_database.php';
require_once file_exists(__DIR__ . '/../../../wp-config.php') ? __DIR__ . '/../../../wp-config.php' : __DIR__ . '/../../../../wp-config.php';
require_once __DIR__ . '/../blogType/blogTypeLoader.php';
require_once __DIR__ . '/../filters.php';
require_once __DIR__ . '/lib.php';
require_once ABSPATH . '/wp-admin/includes/plugin.php';
require_once ABSPATH . '/wp-admin/includes/bookmark.php';
require_once ABSPATH . '/wp-settings.php';

use \IMSGlobal\LTI;

// ============================================================================
// LTI DEBUG TOGGLE - Set to true to enable detailed logging
// ============================================================================
$LTI_DEBUG_ENABLED = true;

if ($LTI_DEBUG_ENABLED) {
    $lti_debug_id = uniqid('LAUNCH_');

    error_log("[{$lti_debug_id}] ==========================================");
    error_log("[{$lti_debug_id}] LTI LAUNCH.PHP - " . date('Y-m-d H:i:s'));
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

    // Raw POST data check
    $raw_input = file_get_contents('php://input');
    error_log("[{$lti_debug_id}] Raw php://input length: " . strlen($raw_input));
    error_log("[{$lti_debug_id}] \$_POST count: " . count($_POST));
    error_log("[{$lti_debug_id}] \$_GET count: " . count($_GET));

    // State and Token
    error_log("[{$lti_debug_id}] --- LAUNCH PARAMETERS ---");
    error_log("[{$lti_debug_id}] state (GET): " . ($_GET['state'] ?? 'MISSING'));
    error_log("[{$lti_debug_id}] state (POST): " . ($_POST['state'] ?? 'MISSING'));
    error_log("[{$lti_debug_id}] id_token present: " . (isset($_POST['id_token']) ? 'YES (' . strlen($_POST['id_token']) . ' chars)' : 'NO'));

    // Cookie State - Critical for validation
    error_log("[{$lti_debug_id}] --- COOKIES ---");
    error_log("[{$lti_debug_id}] Total cookies: " . count($_COOKIE));
    $state_cookies = array_filter(array_keys($_COOKIE), function($k) { return strpos($k, 'lti1p3_') === 0; });
    if (!empty($state_cookies)) {
        foreach ($state_cookies as $cookie_name) {
            error_log("[{$lti_debug_id}] Cookie '{$cookie_name}': " . $_COOKIE[$cookie_name]);
        }
    } else {
        error_log("[{$lti_debug_id}] !!! NO LTI STATE COOKIES - will cause validation failure");
    }

    // Diagnostic Checks
    error_log("[{$lti_debug_id}] --- DIAGNOSTICS ---");
    $request_state = $_POST['state'] ?? $_GET['state'] ?? null;
    $issues = [];
    if (empty($request_state)) $issues[] = "No state parameter";
    if (empty($state_cookies)) $issues[] = "No LTI cookies - browser may block cross-site cookies";
    if (!empty($request_state) && !isset($_COOKIE["lti1p3_{$request_state}"])) {
        $issues[] = "State mismatch - cookie 'lti1p3_{$request_state}' not found";
    }
    if (empty($_POST['id_token'])) $issues[] = "No id_token in POST";

    if (!empty($issues)) {
        error_log("[{$lti_debug_id}] !!! ISSUES: " . implode(', ', $issues));
    } else {
        error_log("[{$lti_debug_id}] Preliminary checks passed");
    }
}

try {
    $launch = LTI\LTI_Message_Launch::new(new WordPressLTI_Database())
        ->validate();
    if ($LTI_DEBUG_ENABLED) {
        error_log("[{$lti_debug_id}] Validation SUCCESSFUL");
    }
} catch (Exception $e) {
    error_log("[LTI] Launch validation failed: " . $e->getMessage());
    if ($LTI_DEBUG_ENABLED) {
        error_log("[{$lti_debug_id}] !!! VALIDATION FAILED: " . $e->getMessage());
        error_log("[{$lti_debug_id}] Trace: " . $e->getTraceAsString());
    }
    throw $e;
}

// Log launch data after validation
if ($LTI_DEBUG_ENABLED) {
    $debug_data = $launch->get_launch_data();
    error_log("[{$lti_debug_id}] Issuer: " . ($debug_data['iss'] ?? 'not set'));
    error_log("[{$lti_debug_id}] Client ID: " . ($debug_data['aud'] ?? 'not set'));
    error_log("[{$lti_debug_id}] User sub: " . ($debug_data['sub'] ?? 'not set'));
}

if ($launch->is_deep_link_launch()) {
    // TODO prepare Deeplink flow
}
$client_id = $launch->get_launch_data()['aud'];

LTIUtils::lti13_check_nonce($client_id, $launch->get_launch_data()['nonce']);
parse_launch_lti_13($client_id, $launch);


function parse_launch_lti_13($client_id, LTI\LTI_Message_Launch $launch)
{
    // Access global debug settings
    global $LTI_DEBUG_ENABLED, $lti_debug_id;

    try {

        //deactivate_plugins( $plugin, true );

        $lti_data = $launch->get_launch_data();
        // $issuer_id = $lti_data['iss'];
        // $deployment_id = $lti_data['https://purl.imsglobal.org/spec/lti/claim/deployment_id'] ?? '';
        $lti_user_id = $lti_data['sub'];
        $custom_params = $lti_data['https://purl.imsglobal.org/spec/lti/claim/custom'] ?? [];
        $blogType = new blogTypeLoader(isset($custom_params['blogtype']) ? $custom_params['blogtype'] : 'defaultType');

        if ($LTI_DEBUG_ENABLED) {
            error_log("[{$lti_debug_id}] --- PARSING LAUNCH ---");
            error_log("[{$lti_debug_id}] BlogType: " . ($custom_params['blogtype'] ?? 'defaultType'));
            error_log("[{$lti_debug_id}] LTI User ID: " . $lti_user_id);
        }

        if ($blogType->error < 0) {

            wp_die("LTI loading Types Aula Failed " . $blogType->error_miss);
            return;
        }
        if ($blogType->requires_user_authorized() && !$blogType->isAuthorizedUserInCourse($lti_data['https://purl.imsglobal.org/spec/lti/claim/roles'])) {
            wp_die("You are not authorized to access");
            return;
        }

        $overwrite_roles = isset($lti_data['https://purl.imsglobal.org/spec/lti/claim/custom'][OVERWRITE_ROLES]) ? $lti_data['https://purl.imsglobal.org/spec/lti/claim/custom'][OVERWRITE_ROLES] : false;

        // Set up the user...
        $userkey = LTIUtils::getUserkeyLTI($client_id, $lti_user_id, $custom_params);

        if (empty($userkey)) {
            wp_die(__('<p>Empty username</p><p>Cannot create a user without username</p>',
                'wordpress-mu-ltiadvantage'));
        }

        $uinfo = get_user_by('login', $userkey);

        $created_user = false;
        $given_name = apply_filters('lti_get_given_name', $lti_data['given_name'] ?? '', $userkey);
        $family_name = apply_filters('lti_get_family_name', $lti_data['family_name'] ?? '', $userkey);
        $email = apply_filters('lti_get_email', $lti_data['email'] ?? '', $userkey);
        $name = apply_filters('lti_get_name', $lti_data['name'] ?? '', $userkey);

        if (empty($email)) {
            wp_die(__('<p>Empty email</p><p>Cannot create a user without email</p>', 'wordpress-mu-ltiadvantage'));
        }

        $user_data = [
            'user_login' => $userkey,
            'user_nicename' => $name,
            'first_name' => $given_name,
            'last_name' => $family_name,
            'user_email' => $email,
            'display_name' => $name
        ];
        if (isset($uinfo) && $uinfo != false) {
            $user_data['ID'] = $uinfo->ID;
            if (is_multisite()) {
                $user_data['role'] = get_option('default_role');
            }
            $ret_id = wp_insert_user($user_data);
            if (is_wp_error($ret_id)) {
                wp_die('<p>' . $ret_id->get_error_message() . '</p>',
                    __('User updating Failure', 'wordpress-mu-ltiadvantage'));
            }
            if ($LTI_DEBUG_ENABLED) {
                error_log("[{$lti_debug_id}] User UPDATED: {$userkey} (ID: {$uinfo->ID})");
            }
        } else { // new user!!!!
            $user_data['user_pass'] = wp_generate_password(10, true, true);
            $ret_id = wp_insert_user($user_data);
            if (is_wp_error($ret_id)) {
                wp_die('<p>' . $ret_id->get_error_message() . '</p>',
                    __('User updating Failure', 'wordpress-mu-ltiadvantage'));
            }
            $uinfo = get_user_by('login', $userkey);
            $created_user = true;
            if ($LTI_DEBUG_ENABLED) {
                error_log("[{$lti_debug_id}] User CREATED: {$userkey} (ID: {$uinfo->ID})");
            }
        }

        update_user_meta($uinfo->ID, LTIAdvantageManagement::$LTI_METAKEY_USER_ID, $lti_user_id);

        $user = new WP_User($uinfo->ID);
        $_SERVER['REMOTE_USER'] = $userkey;
        $password = md5($uinfo->user_pass);


        $blog_created = false;
        $overwrite_plugins_theme = isset($lti_data['https://purl.imsglobal.org/spec/lti/claim/custom'][OVERWRITE_PLUGINS_THEME]) ? $lti_data['https://purl.imsglobal.org/spec/lti/claim/custom'][OVERWRITE_PLUGINS_THEME] : false;

        $blog_is_new = false;
        $blog_id = 0;
        $domain = '';
        if (is_multisite()) {

            // User is now authorized; force WordPress to use the generated password
            //login, set cookies, and set current user
            $current_site = get_current_site();
            $domain = $current_site->domain;
            $subject_code = sanitize_user($blogType->getCoursePath($lti_data, $domain), true);
            $subject_code = str_replace('_', '-', $subject_code);

            if (is_subdomain_install()) {
                $domain = $subject_code . '.' . $domain;
                $path = '/';
            } else {
                $path = $current_site->path . $subject_code . '/';
            }
            $blog_id = domain_exists($domain, $path);
            if (!isset($blog_id)) {
                $title = $blogType->getCourseName($lti_data);
                $blog_is_new = true;

                $meta = $blogType->getMetaBlog($lti_data);
                $old_site_language = get_site_option('WPLANG');
                $blogType->setLanguage();
                update_site_option('WPLANG', $blogType->getLanguage());
                $blog_id = wpmu_create_blog($domain, $path, $title, $uinfo->ID, $meta);

                $blogType->checkErrorCreatingBlog($blog_id, $path);
                switch_to_blog($blog_id);

                update_site_option('WPLANG', $old_site_language);

                $blog_created = true;
                if ($LTI_DEBUG_ENABLED) {
                    error_log("[{$lti_debug_id}] Blog CREATED: {$domain}{$path} (ID: {$blog_id})");
                }
            } else {
                if ($LTI_DEBUG_ENABLED) {
                    error_log("[{$lti_debug_id}] Blog EXISTS: {$domain}{$path} (ID: {$blog_id})");
                }
            }
        } else {
            $blog_id = get_current_blog_id();
            if ($LTI_DEBUG_ENABLED) {
                error_log("[{$lti_debug_id}] Single site mode, Blog ID: {$blog_id}");
            }
        }
        update_option('lti_clientid', $client_id);
        update_option('lti_issuer', $lti_data['iss']);
        $deployment_id = $lti_data['https://purl.imsglobal.org/spec/lti/claim/deployment_id'];
        $custom_params = $lti_data['https://purl.imsglobal.org/spec/lti/claim/custom'];
        update_option('lti_deployment_id', $deployment_id);
        update_option('lti_custom_params', $custom_params);
        // Connect the user to the blog
        if (isset($blog_id)) {

            if (is_multisite()) {
                switch_to_blog($blog_id);
            }

            if ($overwrite_plugins_theme || $blog_created) {
                $blogType->loadPlugins();
                $blogType->changeTheme();
            }
            //Agafem el rol anterior
            $old_role = null;
            if (!$created_user && !$blog_created && !$overwrite_roles) {
                $old_role = LTIUtils::get_current_user_role($uinfo->ID);
            }
            $obj = new stdClass();
            $obj->blog_id = $blog_id;
            $obj->userkey = $userkey;
            $obj->domain = $domain;
            $obj->context = $lti_data;
            $obj->uinfoID = $uinfo->ID;
            $obj->blog_is_new = $blog_is_new;
            if ($overwrite_roles || ($old_role == null) || $old_role == false) {
                $obj->role = get_lti_13_role($client_id, $lti_data, $blogType);
                if (is_multisite()) {
                    add_user_to_blog($blog_id, $uinfo->ID, $obj->role);
                } else {
                    wp_update_user(array('ID' => $uinfo->ID, 'role' => $obj->role));
                }
                if ($LTI_DEBUG_ENABLED) {
                    error_log("[{$lti_debug_id}] Role ASSIGNED: {$obj->role} (from LTI)");
                }
            } else {
                $obj->role = $old_role;
                if ($LTI_DEBUG_ENABLED) {
                    error_log("[{$lti_debug_id}] Role KEPT: {$obj->role} (existing)");
                }
            }
            $blogType->postActions($obj);
        }

        // Log successful completion
        if ($LTI_DEBUG_ENABLED) {
            error_log("[{$lti_debug_id}] --- LAUNCH PROCESSING COMPLETE ---");
        }
    } catch (Exception $e) {
        error_log("Error exception " . $e->getMessage());
        if ($LTI_DEBUG_ENABLED) {
            error_log("[{$lti_debug_id}] !!! EXCEPTION in parse_launch: " . $e->getMessage());
        }
    } finally {
        //error_reporting(E_ALL);
        //error_log("activate_plugin $plugin");
        //activate_plugin( array_pop($plugin), '', false, true );
    }

    $credentials = array(
        'user_login' => $userkey,
        'user_password' => $password,
        'remember' => true
    );
    wp_signon($credentials);
    wp_set_auth_cookie($user->ID, true);
    wp_set_current_user($user->ID, $userkey);
    do_action('uoc_create_site_user_login', $user);

    add_user_meta($user->ID, 'lti_launch_' . $blog_id, $launch);

    if ($LTI_DEBUG_ENABLED) {
        error_log("[{$lti_debug_id}] User SIGNED IN: {$userkey} (ID: {$user->ID})");
    }

    if ($redirecturl = $blogType->force_redirect_to_url($custom_params)) {
        if ($LTI_DEBUG_ENABLED) {
            error_log("[{$lti_debug_id}] Redirecting to custom URL: " . $redirecturl);
        }
        wp_redirect($redirecturl);
        exit();
    }

    // Check for fallback redirect slug when custom params are missing
    $lti_client = LTIUtils::lti_get_by_client_id($client_id);
    if ($lti_client && !empty($lti_client->fallback_redirect_slug)) {
        $fallback_url = get_home_url($blog_id) . '/' . ltrim($lti_client->fallback_redirect_slug, '/');
        if ($LTI_DEBUG_ENABLED) {
            error_log("[{$lti_debug_id}] Custom params missing - using fallback redirect: " . $fallback_url);
        }
        wp_redirect($fallback_url);
        exit();
    }

    $home_url = get_home_url($blog_id);
    if ($LTI_DEBUG_ENABLED) {
        error_log("[{$lti_debug_id}] Redirecting to homepage: " . $home_url);
    }
    wp_redirect($home_url);
    exit();

    /**    $redirecturl = get_option("siteurl");
     * if (!endsWith('/', $redirecturl)) {
     * $redirecturl.='/';
     * }
     * wp_redirect($redirecturl);
     * exit();*/
}

function get_lti_13_role($client_id, $lti_data, $blogType) {

    $lti_conf = LTIUtils::lti_get_by_client_id($client_id);
    $student_role = false;
    if (isset($lti_conf)) {
        $student_role = $lti_conf->student_role;
    }
    $role = $blogType->roleMapping($lti_data['https://purl.imsglobal.org/spec/lti/claim/roles'], $student_role);
    return $role;
}