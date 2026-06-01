<?php

namespace IMSGlobal\LTI;

use Firebase\JWT\JWT;

class LTI_Service_Connector
{
    const NEXT_PAGE_REGEX = "/^Link:.*<([^>]*)>; ?rel=\"next\"/i";

    private $registration;
    private $access_tokens = [];

    public function __construct(LTI_Registration $registration)
    {
        $this->registration = $registration;
    }

    public function get_access_token($scopes)
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
        $auth_url = rtrim(str_replace('\\', '', $this->registration->get_auth_token_url()), '/');

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

        $ch = curl_init();
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt_array($ch, [
            CURLOPT_URL => $auth_url,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $raw_body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: WordPress-LTI-Tool/1.0 (Tilt Roleplay LTI Plugin; https://tiltroleplay.com)',
                'Accept: application/json'
            ]
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            error_log("[LTI AGS] Token request failed: " . curl_error($ch));
            curl_close($ch);
            return false;
        }

        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $token_data = json_decode($response, true);

        if (!isset($token_data['access_token'])) {
            error_log("[LTI AGS] Token request failed with HTTP $http_status");
            return false;
        }

        $this->access_tokens[$scope_key] = $token_data['access_token'];
        return $this->access_tokens[$scope_key];
    }

    public function make_service_request($scopes, $method, $url, $body = null, $content_type = null)
    {
        // 1. Get access token
        $token = $this->get_access_token($scopes);
        if (!$token) {
            error_log("[LTI AGS] Service request failed: could not obtain access token");
            return false;
        }

        // 2. Build headers
        $headers = [
            'Authorization: Bearer ' . $token,
            'User-Agent: WordPress-LTI-Tool/1.0 (Tilt Roleplay LTI Plugin; https://tiltroleplay.com)'
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

        switch (strtoupper($method)) {
            case 'POST':
                curl_setopt($ch, CURLOPT_POST, true);
                if ($body) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
                }
                break;
            case 'PUT':
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'PUT');
                if ($body) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
                }
                break;
            case 'GET':
                curl_setopt($ch, CURLOPT_HTTPGET, true);
                break;
            default:
                curl_setopt($ch, CURLOPT_CUSTOMREQUEST, strtoupper($method));
                if ($body) {
                    curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($body) ? json_encode($body) : $body);
                }
                break;
        }

        // 4. Execute
        $response = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            error_log("[LTI AGS] Service request failed: " . curl_error($ch));
        }

        curl_close($ch);

        return [
            'success' => ($http_status >= 200 && $http_status < 300),
            'http_code' => $http_status
        ];
    }
}

?>
