<?php

namespace IMSGlobal\LTI;

use Firebase\JWT\JWT;

class LTI_Service_Connector
{
    const NEXT_PAGE_REGEX = "/^Link:.*<([^>]*)>; ?rel=\"next\"/i";

    private LTI_Registration $registration;
    private array $access_tokens = [];

    public function __construct(LTI_Registration $registration)
    {
        $this->registration = $registration;
    }

    /**
     * Get the User-Agent string for HTTP requests
     */
    private function get_user_agent(): string
    {
        return function_exists('get_lti_user_agent') ? \get_lti_user_agent() : 'WordPress-LTI-Tool/1.3';
    }

    public function get_access_token(array|string $scopes): string|false
    {
        // Defensive: ensure scopes is a flat array of strings
        if (empty($scopes)) {
            error_log("[LTI AGS] Warning: scopes missing, using fallback 'score'");
            $scopes = ['https://purl.imsglobal.org/spec/lti-ags/scope/score'];
        }
        if (!is_array($scopes)) {
            if (is_string($scopes)) {
                $scopes = preg_split('/\s+/', trim($scopes));
            } else {
                $scopes = (array) $scopes;
            }
        }
        $scopes = array_values(array_map('strval', $scopes));
        sort($scopes);
        $scope_key = md5(implode('|', $scopes));
        if (isset($this->access_tokens[$scope_key])) {
            return $this->access_tokens[$scope_key];
        }

        $client_id = $this->registration->get_client_id();
        $auth_url = rtrim(str_replace('\\', '', $this->registration->get_auth_token_url() ?? ''), '/');

        $jwt_claim = [
            "iss" => $client_id,
            "sub" => $client_id,
            "aud" => $auth_url,
            "iat" => time() - 5,
            "exp" => time() + 60,
            "jti" => 'lti-service-token' . hash('sha256', random_bytes(64))
        ];

        $jwt = JWT::encode($jwt_claim, $this->registration->get_tool_private_key(), 'RS256', $this->registration->get_kid());

        $raw_body = "grant_type=client_credentials"
            . "&client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer"
            . "&client_assertion=" . $jwt
            . "&scope=" . implode(' ', $scopes);

        error_log("[LTI AGS] Requesting token from: $auth_url");
        error_log("[LTI AGS] Client ID: $client_id");
        error_log("[LTI AGS] Scopes: " . implode(' ', $scopes));

        $ch = curl_init();
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt_array($ch, [
            CURLOPT_URL => $auth_url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $raw_body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: ' . $this->get_user_agent(),
                'Accept: application/json'
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_VERBOSE => false
        ]);

        $response = curl_exec($ch);
        $curl_errno = curl_errno($ch);
        $curl_error = curl_error($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false || $curl_errno !== 0) {
            error_log("[LTI AGS] Token request CURL failed - Error #$curl_errno: $curl_error");
            error_log("[LTI AGS] Token request HTTP status: $http_status");
            curl_close($ch);
            return false;
        }

        curl_close($ch);

        error_log("[LTI AGS] Token response HTTP: $http_status");

        $token_data = json_decode($response, true);

        if (!isset($token_data['access_token'])) {
            error_log("[LTI AGS] Token request failed with HTTP $http_status - Response: " . substr($response, 0, 500));
            return false;
        }

        error_log("[LTI AGS] Token obtained successfully");

        $this->access_tokens[$scope_key] = $token_data['access_token'];
        return $this->access_tokens[$scope_key];
    }

    public function make_service_request(
        array|string $scopes,
        string $method,
        string $url,
        array|string|null $body = null,
        ?string $content_type = null
    ): array|false {
        error_log("[LTI AGS] Service request: $method $url");

        // 1. Get access token
        $token = $this->get_access_token($scopes);
        if (!$token) {
            error_log("[LTI AGS] Service request ABORTED: could not obtain access token");
            return [
                'success' => false,
                'http_code' => 0,
                'error' => 'Could not obtain access token'
            ];
        }

        // 2. Build headers
        $headers = [
            'Authorization: Bearer ' . $token,
            'User-Agent: ' . $this->get_user_agent()
        ];
        if ($content_type) {
            $headers[] = 'Content-Type: ' . $content_type;
        }
        $headers[] = 'Accept: application/json';

        // 3. Initialize cURL
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_setopt($ch, CURLOPT_TIMEOUT, 30);

        $post_body = null;
        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($body) {
                    $post_body = is_array($body) ? json_encode($body) : $body;
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_body);
                }
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($body) {
                    $post_body = is_array($body) ? json_encode($body) : $body;
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_body);
                }
                break;
            case 'GET':
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                break;
            default:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
                if ($body) {
                    $post_body = is_array($body) ? json_encode($body) : $body;
                    curl_setopt($ch, CURLOPT_POSTFIELDS, $post_body);
                }
                break;
        }

        if ($post_body) {
            error_log("[LTI AGS] Request body: " . substr($post_body, 0, 500));
        }

        // 4. Execute
        $response = curl_exec($ch);
        $curl_errno = curl_errno($ch);
        $curl_error = curl_error($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($response === false || $curl_errno !== 0) {
            error_log("[LTI AGS] Service request CURL failed - Error #$curl_errno: $curl_error");
            error_log("[LTI AGS] Service request URL was: $url");
        }

        error_log("[LTI AGS] Service response HTTP: $http_status");
        if ($response && $http_status >= 400) {
            error_log("[LTI AGS] Error response: " . substr($response, 0, 500));
        }

        curl_close($ch);

        return [
            'success' => ($http_status >= 200 && $http_status < 300),
            'http_code' => $http_status,
            'curl_error' => $curl_error ?: null
        ];
    }
}

?>
