# WordPress LTI 1.3 Plugin - Changelog

**Fork of:** [3iPunt/wordpress-lti-1-3](https://github.com/3iPunt/wordpress-lti-1-3)
**PHP Required:** 8.0+
**Last Updated:** 2026-06-02

---

## Overview

This document outlines all modifications made to the original `wordpress-lti-1-3` plugin by 3iPunt. The modified version addresses security vulnerabilities, upgrades dependencies for PHP 8.0+ compatibility, fixes a critical multi-tool registration bug, and refactors the admin interface to follow WordPress coding standards.

---

## 1. Dependency Changes

### composer.json

| Aspect | Original | Modified |
|--------|----------|----------|
| PHP-JWT Library | `fproject/php-jwt: ^4.0` | `firebase/php-jwt: ^7.0` |
| LTI Library | `imsglobal/lti-1p3-tool: dev-master` | Removed (internalized to `src/lti/`) |
| phpseclib | Required via LTI library | Removed (using native OpenSSL) |
| PHP Version | Not specified | `>=8.0` required |
| Autoload | None | PSR-4 for `IMSGlobal\LTI` namespace |

**Rationale:**
- `firebase/php-jwt` is the officially maintained JWT library with active security updates
- Internalizing the LTI library allows for local modifications and better version control
- Native OpenSSL eliminates phpseclib dependency conflicts with other plugins
- PHP 8.0+ enables modern type hints and language features

---

## 2. Security Fixes

### 2.1 SQL Injection Prevention
**File:** `ims-lti-advantage.php`

- Replaced `addslashes()` with `$wpdb->prepare()` and `$wpdb->esc_like()` for search queries
- All database operations now use parameterized queries

### 2.2 XSS Prevention
**File:** `lti/lti-advantage-management.php`

- Added `esc_html()` escaping for user-generated content output
- Input sanitization with `sanitize_key()` for GET parameters

### 2.3 Authorization Checks
**File:** `lti/lti-advantage-management.php`

Added multisite authorization verification:
```php
if ($user_id > 0) {
    $user = get_userdata($user_id);
    if (!$user || (is_multisite() && !is_user_member_of_blog($user_id, get_current_blog_id()))) {
        $user_id = 0;
    }
}
```

### 2.4 Removed Deprecated/Dangerous Functions
**File:** `blogType/utils/UtilsPropertiesWP.php`

- Removed `eval()` usage (SPL classes are standard in PHP 8+)
- Converted `preg_replace` with `/e` modifier to `preg_replace_callback()`

### 2.5 Input Validation
**File:** `blogType/class-lti-grade-table.php`

- Added whitelist validation for sortable columns to prevent SQL injection via ORDER BY

---

## 3. Critical Bug Fix: Multi-Tool Registration

### Problem
The original plugin could not handle multiple LTI tools from the same LMS/issuer. Tools were indexed only by `issuer`, so registering a second tool from the same LMS would overwrite the first.

### Solution
**Files Modified:**
- `src/lti/Database.php` - Added interface method
- `lti/wordpresslti_database.php` - Complete rewrite
- `src/lti/LTI_Message_Launch.php` - Updated registration lookup
- `src/lti/LTI_OIDC_Login.php` - Updated to use client_id when available
- `src/lti/JWKS_Endpoint.php` - Added new lookup method

**Implementation:**
```php
// Tools now stored with composite key
$composite_key = $tool->issuer . '|' . $tool->client_id;
$this->tools_by_issuer_client[$composite_key] = $tool;

// New lookup method
public function find_registration_by_issuer_and_client_id(string $iss, string $client_id): ?LTI_Registration
```

**Backward Compatibility:**
- Legacy `find_registration_by_issuer()` method retained but marked `@deprecated`
- OIDC login falls back to issuer-only lookup if `client_id` not provided

### 3.2 Deployment Validation Fix

**Problem:** Deployment validation also used issuer-only lookup, causing validation failures when multiple tools shared the same issuer.

**Solution:**
**Files Modified:**
- `src/lti/Database.php` - Added `find_deployment_by_issuer_client_and_deployment()` interface method
- `lti/wordpresslti_database.php` - Implemented composite key deployment lookup
- `src/lti/LTI_Message_Launch.php` - Updated `validate_deployment()` to use new method

```php
// New deployment lookup using issuer + client_id + deployment_id
public function find_deployment_by_issuer_client_and_deployment(
    string $iss,
    string $client_id,
    string $deployment_id
): ?LTI_Deployment
```

### 3.3 Fresh Registration for Service Calls

**Problem:** LTI launch data (including registration with token URLs) was serialized and cached in WordPress user meta. When admin settings were updated (e.g., fixing a wrong token URL), the cached launch still used the old values, requiring users to re-launch from the LMS.

**Solution:**
**File:** `src/lti/LTI_Message_Launch.php`

Added `get_fresh_registration()` method that fetches current registration from the database when making service calls (grades, NRPS, etc.), ensuring settings changes take effect immediately.

```php
private function get_fresh_registration(): ?LTI_Registration {
    // Extract issuer and client_id from cached JWT
    $issuer = $this->jwt['body']['iss'] ?? null;
    $client_id = is_array($this->jwt['body']['aud'])
        ? $this->jwt['body']['aud'][0]
        : ($this->jwt['body']['aud'] ?? null);

    // Load database class and fetch fresh registration
    $db = new \WordPressLTI_Database();
    return $db->find_registration_by_issuer_and_client_id($issuer, $client_id);
}
```

**Updated methods to use fresh registration:**
- `get_ags()` - Assignment and Grade Services
- `get_nrps()` - Names and Roles Provisioning Service
- `get_gs()` - Groups Service
- `get_deep_link()` - Deep Linking

**Benefits:**
- Settings changes take effect immediately without re-launch
- No need to clear user meta when fixing configuration errors
- Cached JWT claims still available for user info and service endpoints

---

## 4. JWKS Endpoint Rewrite

### Problem
Original used phpseclib for RSA key operations, which conflicted with SiteGround's `sg-ai-studio` plugin that bundles an incompatible phpseclib version.

### Solution
**File:** `src/lti/JWKS_Endpoint.php`

Rewrote to use native PHP OpenSSL:
```php
$key_resource = openssl_pkey_get_private($private_key);
$key_details = openssl_pkey_get_details($key_resource);

$components = [
    'kty' => 'RSA',
    'alg' => 'RS256',
    'use' => 'sig',
    'e' => JWT::urlsafeB64Encode($key_details['rsa']['e']),
    'n' => JWT::urlsafeB64Encode($key_details['rsa']['n']),
    'kid' => $kid,
];
```

---

## 5. Admin Interface Refactoring

### Problem
Original `ims-lti-advantage.php` had a monolithic 571-line function with mixed HTML and PHP logic.

### Solution
Complete refactoring to WordPress standards:

**New Function Structure:**
- `lti_client_id_admin()` - Main controller
- `lti_handle_admin_action()` - Action processor
- `lti_render_admin_page()` - Page renderer
- `lti_render_tool_settings()` - Tool URLs display
- `lti_render_search_section()` - Search functionality
- `lti_render_gradebook_settings()` - Settings form
- `lti_render_config_info()` - Configuration view
- `lti_render_keys_section()` - Keys display
- `lti_render_edit_form()` - Add/Edit form
- `lti_save_tool()` - Database persistence

**Improvements:**
- PHP 8 type hints on all functions
- Proper WordPress escaping (`esc_html()`, `esc_attr()`, `esc_url()`)
- WordPress admin UI patterns (form-table, notices, submit_button())
- Separated concerns for maintainability

---

## 6. New Feature: Configurable User-Agent

### Addition
**Files:** `ims-lti-advantage.php`, `src/lti/LTI_Message_Launch.php`, `src/lti/LTI_Service_Connector.php`

Added configurable User-Agent string for LTI HTTP requests:

- **Default:** `WordPress-LTI-Tool/1.3 (3iPunt https://tresipunt.com)`
- **Admin Setting:** LTI Clients → Plugin Settings → User-Agent String
- **Storage:** WordPress option `lti_user_agent`

**Usage:**
```php
function get_lti_user_agent(): string {
    $default = 'WordPress-LTI-Tool/1.3 (3iPunt https://tresipunt.com)';
    return (string) (is_multisite()
        ? get_site_option('lti_user_agent', $default)
        : get_option('lti_user_agent', $default));
}
```

---

## 7. Files Summary

### Modified Files
| File | Changes |
|------|---------|
| `composer.json` | Dependency updates, PHP 8.0+ requirement |
| `ims-lti-advantage.php` | Complete refactor (+287 lines), User-Agent setting |
| `lti/wordpresslti_database.php` | Multi-tool support (+68 lines) |
| `lti/launch.php` | Error handling improvements |
| `lti/lti-advantage-management.php` | Security fixes |
| `blogType/utils/UtilsPropertiesWP.php` | Removed eval(), fixed preg_replace |
| `blogType/class-lti-grade-table.php` | XSS prevention in sorting |
| `src/lti/Database.php` | New interface method |
| `src/lti/LTI_Message_Launch.php` | Multi-tool lookup, User-Agent, fresh registration fetch, deployment validation |
| `src/lti/LTI_OIDC_Login.php` | Multi-tool support |
| `src/lti/LTI_Service_Connector.php` | User-Agent setting, enhanced debug logging for token/service requests |
| `src/lti/JWKS_Endpoint.php` | Native OpenSSL, deprecation notices |

### Added Files
| File | Purpose |
|------|---------|
| `.gitignore` | Exclude vendor directory |
| `src/lti/` directory | Internalized LTI library (15+ files) |

---

## 8. Enhanced Debug Logging

**File:** `src/lti/LTI_Service_Connector.php`

Added comprehensive logging for troubleshooting LTI service calls:

```
[LTI AGS] Requesting token from: {url}
[LTI AGS] Client ID: {client_id}
[LTI AGS] Scopes: {scopes}
[LTI AGS] Token response HTTP: {status}
[LTI AGS] Token obtained successfully
[LTI AGS] Service request: {method} {url}
[LTI AGS] Request body: {body}
[LTI AGS] Service response HTTP: {status}
```

Errors are logged with cURL error codes and messages for easier debugging of SSL, DNS, and network issues.

---

## 9. Compatibility Notes

### Requirements
- PHP 8.0 or higher
- WordPress 5.0 or higher
- OpenSSL PHP extension

### Tested With
- Moodle LMS (LTI 1.3 Advantage)
- Multiple simultaneous LTI tool registrations from same issuer

### Known Deprecations
- `curl_close()` is deprecated in PHP 8.0+ but retained for compatibility
- `find_registration_by_issuer()` marked deprecated, use `find_registration_by_issuer_and_client_id()`

---

## 10. Upgrade Instructions

1. Backup existing plugin and database
2. Replace plugin files with modified version
3. Run `composer install` in plugin directory
4. Clear any PHP opcode cache
5. Test LTI launch and grade passback functionality

---

