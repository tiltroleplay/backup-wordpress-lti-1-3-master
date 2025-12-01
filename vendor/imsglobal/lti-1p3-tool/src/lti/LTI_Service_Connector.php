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
            error_log("LTI_Service_Connector get_access_token: scopes missing, using fallback 'score'");
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
        error_log('auth_url: ' . $auth_url);

        $jwt_claim = [
            "iss" => $client_id,
            "sub" => $client_id,
            "aud" => $auth_url,
            "iat" => time() - 5,
            "exp" => time() + 60,
            "jti" => 'lti-service-token' . hash('sha256', random_bytes(64))
        ];

        $jwt = JWT::encode($jwt_claim, $this->registration->get_tool_private_key(), 'RS256', $this->registration->get_kid());

        // Build auth token request exactly like Postman
        $auth_request = http_build_query([
            'grant_type' => 'client_credentials',
            'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
            'client_assertion' => $jwt,
            'scope' => implode(' ', $scopes)
        ]);

        error_log("Auth request POST: " . $auth_request);
        error_log("JWT: " . $jwt);

        $raw_body = "grant_type=client_credentials"
            . "&client_assertion_type=urn:ietf:params:oauth:client-assertion-type:jwt-bearer"
            . "&client_assertion=" . $jwt
            . "&scope=" . implode(' ', $scopes);  // raw space

        $ch = curl_init();
        curl_setopt($ch, CURLINFO_HEADER_OUT, true);
        curl_setopt_array($ch, [
            CURLOPT_URL => $auth_url,
            CURLOPT_POST => true,
            // CURLOPT_POSTFIELDS => $auth_request,
            CURLOPT_POSTFIELDS => $raw_body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/x-www-form-urlencoded',
                'User-Agent: WordPress-LTI-Tool/1.0 (+https://tiltroleplay.com)',
                'Accept: application/json '
            ]
        ]);

        $response = curl_exec($ch);
        if ($response === false) {
            error_log("cURL error: " . curl_error($ch));
            curl_close($ch);
            return false;
        }
        $info = curl_getinfo($ch);
        error_log("Request headers sent: " . print_r($info, true));

        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        error_log("AGS token HTTP status: " . $http_status);
        error_log("AGS token raw response: " . $response);

        $token_data = json_decode($response, true);
        error_log("Decoded token data: " . print_r($token_data, true));

        if (!isset($token_data['access_token'])) {
            error_log("Failed to get AGS token.");
            return false;
        }

        $this->access_tokens[$scope_key] = $token_data['access_token'];
        error_log("AGS token received: " . $token_data['access_token']);

        return $this->access_tokens[$scope_key];
    }



    // public function get_access_token($scopes)
    // {
    //     // Don't fetch the same key more than once.
    //     // sort($scopes);
    //     if (is_array($scopes)) {
    //         sort($scopes);
    //     } else {
    //         error_log("LTI_Service_Connector get_access_token received invalid scopes: " . var_export($scopes, true));
    //         $scopes = []; // fallback
    //     }
    //     $scope_key = md5(implode('|', $scopes));
    //     if (isset($this->access_tokens[$scope_key])) {
    //         return $this->access_tokens[$scope_key];
    //     }
    //     // Build up JWT to exchange for an auth token
    //     $client_id = $this->registration->get_client_id();
    //     $jwt_claim = [
    //         "iss" => $client_id,
    //         "sub" => $client_id,
    //         "aud" => $this->registration->get_auth_server(),
    //         "iat" => time() - 5,
    //         "exp" => time() + 60,
    //         "jti" => 'lti-service-token' . hash('sha256', random_bytes(64))
    //     ];
    //     // Sign the JWT with our private key (given by the platform on registration)
    //     $jwt = JWT::encode($jwt_claim, $this->registration->get_tool_private_key(), 'RS256', $this->registration->get_kid());
    //     // Build auth token request headers
    //     $auth_request = [
    //         'grant_type' => 'client_credentials',
    //         'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
    //         'client_assertion' => $jwt,
    //         'scope' => implode(' ', $scopes)
    //     ];

    //     // Make request to get auth token
    //     $ch = curl_init();
    //     curl_setopt($ch, CURLOPT_URL, $this->registration->get_auth_token_url());
    //     curl_setopt($ch, CURLOPT_POST, 1);
    //     curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($auth_request));
    //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //     curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    //     $resp = curl_exec($ch);
    //     $token_data = json_decode($resp, true);
    //     curl_close($ch);
    //     // wp_die( $scope_key );
    //     error_log("mm " . print_r($token_data['access_token'], 1));
    //     // print_r( $token_data );
    //     return $this->access_tokens[$scope_key] = $token_data['access_token'];
    // }

    // ORIGINAL
    // public function get_access_token($scopes)
    // {
    //     // Don't fetch the same key more than once.
    //     sort($scopes);
    //     $scope_key = md5(implode('|', $scopes));
    //     if (isset($this->access_tokens[$scope_key])) {
    //         return $this->access_tokens[$scope_key];
    //     }
    //     // Build up JWT to exchange for an auth token
    //     $client_id = $this->registration->get_client_id();
    //     $auth_url = $this->registration->get_auth_token_url();

    //     // Clean URL: remove backslashes, trim trailing slash
    //     $auth_url = str_replace('\\', '', $auth_url);
    //     $auth_url = rtrim($auth_url, '/');

    //     error_log('auth_url: ' . $auth_url);
    //     $stored_token_url = $this->registration->get_auth_token_url();
    //     error_log('stored registration: '.$stored_token_url );

    //     $jwt_claim = [
    //         "iss" => $client_id,
    //         "sub" => $client_id,
    //         "aud" => $this->registration->get_auth_token_url(),
    //         "iat" => time() - 5,
    //         "exp" => time() + 60,
    //         "jti" => 'lti-service-token' . hash('sha256', random_bytes(64))
    //     ];

    //     // Sign the JWT with our private key (given by the platform on registration)
    //     $jwt = JWT::encode($jwt_claim, $this->registration->get_tool_private_key(), 'RS256', $this->registration->get_kid());

    //     // Build auth token request headers
    //     $auth_request = [
    //         'grant_type' => 'client_credentials',
    //         'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
    //         'client_assertion' => $jwt,
    //         'scope' => implode(' ', $scopes)
    //     ];
    //     // Make request to get auth token

    //     error_log("Auth request POST: " . http_build_query($auth_request));
    //     error_log("JWT: " . $jwt);

    //     $options = [
    //         CURLOPT_URL => $auth_url,
    //         CURLOPT_POST => true,
    //         CURLOPT_POSTFIELDS => http_build_query($auth_request),
    //         CURLOPT_RETURNTRANSFER => true,
    //         CURLOPT_HTTPHEADER => [
    //             'Content-Type: application/x-www-form-urlencoded',
    //             'Accept: application/json'
    //         ]
    //     ];

    //     $ch = curl_init();
    //     curl_setopt_array($ch, $options);
    //     // curl_setopt($ch, CURLOPT_URL, $this->registration->get_auth_token_url());
    //     // curl_setopt($ch, CURLOPT_POST, 1);
    //     // curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($auth_request));
    //     // curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //     // curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    //     $response = curl_exec($ch);
    //     if ($response === false) {
    //         error_log("cURL error: " . curl_error($ch));
    //     }
    //     $token_data = json_decode($response, true);
    //     curl_close($ch);

    //     error_log("Auth response: " . $response);
    //     // wp_die( $scope_key );
    //     error_log("mm " . print_r($token_data, 1));
    //     // print_r($token_data);
    //     return $this->access_tokens[$scope_key] = $token_data['access_token'];
    // }


    // public function get_access_token($scopes)
    // {
    //     // // Defensive: ensure scopes is a flat array of strings
    //     // if (empty($scopes)) {
    //     //     error_log("LTI_Service_Connector get_access_token: scopes missing, using fallback 'score'");
    //     //     $scopes = ['https://purl.imsglobal.org/spec/lti-ags/scope/score'];
    //     // }

    //     // if (!is_array($scopes)) {
    //     //     // if a string (or anything else), split or wrap
    //     //     if (is_string($scopes)) {
    //     //         $scopes = preg_split('/\s+/', trim($scopes));
    //     //     } else {
    //     //         $scopes = (array) $scopes;
    //     //     }
    //     // }

    //     // $scopes = array_values(array_map('strval', $scopes));

    //     // Defensive sort
    //     sort($scopes);

    //     $scope_key = md5(implode('|', $scopes));

    //     if (isset($this->access_tokens[$scope_key])) {
    //         return $this->access_tokens[$scope_key];
    //     }

    //     // Build JWT for client_credentials
    //     $client_id = $this->registration->get_client_id();
    //     $auth_url = $this->registration->get_auth_token_url();
    //     error_log("Auth token URL: " . $auth_url);
    //     error_log("=== LTI AGS DEBUG ACCESS TOKEN ===");
    //     error_log("Client ID: " . $client_id);

    //     $jwt_claim = [
    //         "iss" => $client_id,
    //         "sub" => $client_id,
    //         "aud" => $this->registration->get_auth_token_url(),
    //         "iat" => time() - 5,
    //         "exp" => time() + 60,
    //         "jti" => 'lti-service-token' . hash('sha256', random_bytes(64))
    //     ];

    //     $jwt = JWT::encode($jwt_claim, $this->registration->get_tool_private_key(), 'RS256', $this->registration->get_kid());


    //     $private_key_pem = $this->registration->get_tool_private_key();

    //     // Extract public portion from private key
    //     $details = openssl_pkey_get_details(openssl_pkey_get_private($private_key_pem));

    //     if ($details && isset($details['rsa'])) {
    //         $n = rtrim(strtr(base64_encode($details['rsa']['n']), '+/', '-_'), '=');
    //         $e = rtrim(strtr(base64_encode($details['rsa']['e']), '+/', '-_'), '=');

    //         error_log("=== TOOL PUBLIC KEY DUMP (from private) ===");
    //         error_log("n: " . $n);
    //         error_log("e: " . $e);
    //         error_log("=== END TOOL PUBLIC KEY DUMP ===");
    //     } else {
    //         error_log("Failed to extract public key details from private key");
    //     }

    //     // Build auth token request
    //     $auth_request = [
    //         'grant_type' => 'client_credentials',
    //         'client_assertion_type' => 'urn:ietf:params:oauth:client-assertion-type:jwt-bearer',
    //         'client_assertion' => $jwt,
    //         'scope' => implode(' ', $scopes)
    //     ];

    //     $ch = curl_init();
    //     curl_setopt($ch, CURLOPT_URL, $this->registration->get_auth_token_url());
    //     curl_setopt($ch, CURLOPT_POST, 1);
    //     curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($auth_request));
    //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    //     curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);

    //     $resp = curl_exec($ch);
    //     $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    //     curl_close($ch);

    //     error_log("AGS token raw response: " . $resp);
    //     error_log("AGS token HTTP status: " . $http_status);

    //     $token_data = json_decode($resp, true);

    //     error_log("token data: " . print_r($token_data, 1));

    //     if (!isset($token_data['access_token'])) {
    //         error_log("Failed to get AGS token. Decoded response: " . print_r($token_data, true));
    //         return false;
    //     }

    //     $this->access_tokens[$scope_key] = $token_data['access_token'];
    //     error_log("AGS token received: " . $token_data['access_token']);

    //     return $this->access_tokens[$scope_key];
    // }


    // public function make_service_request($scopes, $method, $url, $body = null, $content_type = 'application/json', $accept = 'application/json')
    // {


    //     $ch = curl_init();


    //     $headers = [


    //         'Authorization: Bearer ' . $this->get_access_token($scopes),


    //         'Accept:' . $accept,


    //     ];


    //     curl_setopt($ch, CURLOPT_URL, $url);


    //     curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);


    //     curl_setopt($ch, CURLOPT_HEADER, 1);


    //     curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);


    //     if ($method === 'POST') {


    //         curl_setopt($ch, CURLOPT_POST, 1);


    //         curl_setopt($ch, CURLOPT_POSTFIELDS, strval($body));


    //         $headers[] = 'Content-Type: ' . $content_type;


    //     }


    //     curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);


    //     $response = curl_exec($ch);


    //     if (curl_errno($ch)) {


    //         echo 'Request Error:' . curl_error($ch);


    //     }


    //     $header_size = curl_getinfo($ch, CURLINFO_HEADER_SIZE);


    //     curl_close($ch);





    //     $resp_headers = substr($response, 0, $header_size);


    //     $resp_body = substr($response, $header_size);


    //     // print_r( $headers );


    //     // print_r( $this->registration );


    //     die($resp_body);


    //     return [


    //         'headers' => array_filter(explode("\r\n", $resp_headers)),


    //         'body' => json_decode($resp_body, true),


    //     ];


    // }

    public function make_service_request($scopes, $method, $url, $body = null, $content_type = null)
    {
        // 1. Get access token
        $token = $this->get_access_token($scopes);
        if (!$token) {
            error_log("make_service_request: cannot get access token");
            return false;
        }

        // 2. Build headers
        $headers = [
            'Authorization: Bearer ' . $token,
            'User-Agent: WordPress-LTI-Tool/1.0 (+https://tiltroleplay.com)'
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
                    // Pass JSON as raw POST data
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

        error_log("Headers sent: ". print_r($headers,true));
        // 4. Execute and debug
        $response = curl_exec($ch);
        $http_status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if ($response === false) {
            error_log("cURL error: " . curl_error($ch));
        }

        curl_close($ch);

        error_log("make_service_request HTTP status: " . $http_status);
        error_log("make_service_request URL: " . $url);
        error_log("make_service_request request body: " . print_r($body, true));
        error_log("make_service_request response: " . $response);

        $decoded = json_decode($response, true);

        // return $decoded ? $decoded : $response;

        return [
            'success' => ($http_status >= 200 && $http_status < 300),
            'http_code' => $http_status
        ];
    }


}


?>