<?php

namespace IMSGlobal\LTI;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;

JWT::$leeway = 5;

class LTI_Message_Launch {

    private $db;
    private $cache;
    private $request;
    private $cookie;
    private $jwt;
    private $registration;
    private $launch_id;

    /**
     * Constructor
     */
    function __construct(Database $database, Cache $cache = null, Cookie $cookie = null) {
        $this->db = $database;
        $this->launch_id = uniqid("lti1p3_launch_", true);

        if ($cache === null) {
            $cache = new Cache();
        }
        $this->cache = $cache;

        if ($cookie === null) {
            $cookie = new Cookie();
        }
        $this->cookie = $cookie;
    }

    public static function new(Database $database, Cache $cache = null, Cookie $cookie = null) {
        return new LTI_Message_Launch($database, $cache, $cookie);
    }

    public static function from_cache($launch_id, Database $database, Cache $cache = null) {
        $new = new LTI_Message_Launch($database, $cache, null);
        $new->launch_id = $launch_id;
        $new->jwt = [ 'body' => $new->cache->get_launch_data($launch_id) ];
        return $new->validate_registration();
    }

    public function validate(array $request = null) {
        if ($request === null) {
            $request = $_POST;
        }
        $this->request = $request;

        return $this->validate_state()
            ->validate_jwt_format()
            ->validate_nonce()
            ->validate_registration()
            ->validate_jwt_signature()
            ->validate_deployment()
            ->validate_message()
            ->cache_launch_data();
    }

    public function has_nrps() {
        return !empty($this->jwt['body']['https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice']['context_memberships_url']);
    }

    public function get_nrps() {
        return new LTI_Names_Roles_Provisioning_Service(
            new LTI_Service_Connector($this->get_fresh_registration()),
            $this->jwt['body']['https://purl.imsglobal.org/spec/lti-nrps/claim/namesroleservice']);
    }

    public function has_gs() {
        return !empty($this->jwt['body']['https://purl.imsglobal.org/spec/lti-gs/claim/groupsservice']['context_groups_url']);
    }

    public function get_gs() {
        return new LTI_Course_Groups_Service(
            new LTI_Service_Connector($this->get_fresh_registration()),
            $this->jwt['body']['https://purl.imsglobal.org/spec/lti-gs/claim/groupsservice']);
    }

    public function has_ags() {
        return !empty($this->jwt['body']['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint']);
    }

    public function get_ags() {
        return new LTI_Assignments_Grades_Service(
            new LTI_Service_Connector($this->get_fresh_registration()),
            $this->jwt['body']['https://purl.imsglobal.org/spec/lti-ags/claim/endpoint']);
    }

    public function get_deep_link() {
        return new LTI_Deep_Link(
            $this->get_fresh_registration(),
            $this->jwt['body']['https://purl.imsglobal.org/spec/lti/claim/deployment_id'],
            $this->jwt['body']['https://purl.imsglobal.org/spec/lti-dl/claim/deep_linking_settings']);
    }

    public function is_deep_link_launch() {
        return $this->jwt['body']['https://purl.imsglobal.org/spec/lti/claim/message_type'] === 'LtiDeepLinkingRequest';
    }

    public function is_submission_review_launch() {
        return $this->jwt['body']['https://purl.imsglobal.org/spec/lti/claim/message_type'] === 'LtiSubmissionReviewRequest';
    }

    public function is_resource_launch() {
        return $this->jwt['body']['https://purl.imsglobal.org/spec/lti/claim/message_type'] === 'LtiResourceLinkRequest';
    }

    public function get_launch_data() {
        return $this->jwt['body'];
    }

    public function get_launch_id() {
        return $this->launch_id;
    }

    /**
     * Get fresh registration from database
     * This ensures we always use current settings (token URL, etc.) even if
     * the launch object was cached/serialized with old values
     */
    private function get_fresh_registration(): ?LTI_Registration {
        $issuer = $this->jwt['body']['iss'] ?? null;
        $client_id = is_array($this->jwt['body']['aud'])
            ? $this->jwt['body']['aud'][0]
            : ($this->jwt['body']['aud'] ?? null);

        if (!$issuer || !$client_id) {
            error_log("[LTI] Cannot get fresh registration: missing issuer or client_id");
            return $this->registration; // Fallback to cached
        }

        // Create fresh database instance (needed because $this->db may be null after unserialization)
        if (class_exists('WordPressLTI_Database')) {
            $db = new \WordPressLTI_Database();
            $fresh_registration = $db->find_registration_by_issuer_and_client_id($issuer, $client_id);

            if ($fresh_registration) {
                error_log("[LTI] Using fresh registration for client_id=$client_id");
                return $fresh_registration;
            }
        }

        error_log("[LTI] Fresh registration lookup failed, using cached registration");
        return $this->registration; // Fallback to cached
    }

    private function get_public_key() {
        $key_set_url = $this->registration->get_key_set_url();

        // Download key set
        $user_agent = function_exists('get_lti_user_agent') ? \get_lti_user_agent() : 'WordPress-LTI-Tool/1.3';
        $options = [
            'http' => [
                'method' => 'GET',
                'header' => "User-Agent: $user_agent\r\n"
            ]
        ];

        $context = stream_context_create($options);
        $public_key_set = json_decode(file_get_contents($key_set_url, false, $context), true);

        if (empty($public_key_set)) {
            error_log("[LTI] Failed to fetch public key set");
            throw new LTI_Exception("Failed to fetch public key", 1);
        }

        // Find key used to sign the JWT (matches the KID in the header)
        $target_kid = $this->jwt['header']['kid'] ?? null;

        foreach ($public_key_set['keys'] as $key) {
            if ($key['kid'] == $target_kid) {
                try {
                    // Firebase JWT 6.x/7.x returns a Key object, extract the key material
                    $alg = $key['alg'] ?? 'RS256';
                    $parsedKey = JWK::parseKey($key, $alg);
                    $keyMaterial = $parsedKey->getKeyMaterial();
                    return openssl_pkey_get_details($keyMaterial);
                } catch(\Exception $e) {
                    error_log("[LTI] Failed to parse public key: " . $e->getMessage());
                    return false;
                }
            }
        }

        error_log("[LTI] Public key not found for KID");
        throw new LTI_Exception("Unable to find public key", 1);
    }

    private function cache_launch_data() {
        $this->cache->cache_launch_data($this->launch_id, $this->jwt['body']);
        return $this;
    }

    private function validate_state() {
        $state = $this->request['state'] ?? null;
        $cookie_value = $this->cookie->get_cookie('lti1p3_' . $state);

        if ($cookie_value !== $state) {
            error_log("[LTI] State validation failed");
            throw new LTI_Exception("State not found", 1);
        }

        return $this;
    }

    private function validate_jwt_format() {
        $jwt = $this->request['id_token'];

        if (empty($jwt)) {
            throw new LTI_Exception("Missing id_token", 1);
        }

        $jwt_parts = explode('.', $jwt);

        if (count($jwt_parts) !== 3) {
            throw new LTI_Exception("Invalid id_token, JWT must contain 3 parts", 1);
        }

        $this->jwt['header'] = json_decode(JWT::urlsafeB64Decode($jwt_parts[0]), true);
        $this->jwt['body'] = json_decode(JWT::urlsafeB64Decode($jwt_parts[1]), true);

        return $this;
    }

    private function validate_nonce() {
        if (!$this->cache->check_nonce($this->jwt['body']['nonce'])) {
            //throw new LTI_Exception("Invalid Nonce");
        }
        return $this;
    }

    private function validate_registration() {
        // Extract client_id from JWT (can be string or array per LTI 1.3 spec)
        $client_id = is_array($this->jwt['body']['aud']) ? $this->jwt['body']['aud'][0] : $this->jwt['body']['aud'];
        $issuer = $this->jwt['body']['iss'];

        // Look up registration by both issuer and client_id to properly support
        // multiple tools from the same LMS/issuer
        $this->registration = $this->db->find_registration_by_issuer_and_client_id($issuer, $client_id);

        if (empty($this->registration)) {
            throw new LTI_Exception("Registration not found for issuer: $issuer, client_id: $client_id", 1);
        }

        return $this;
    }

    private function validate_jwt_signature() {
        $public_key = $this->get_public_key();

        if (!$public_key || !isset($public_key['key'])) {
            error_log("[LTI] Public key not available for JWT validation");
            throw new LTI_Exception("Public key not available", 1);
        }

        try {
            // Firebase JWT 6.x/7.x requires Key object wrapper
            $key = new Key($public_key['key'], 'RS256');
            JWT::decode($this->request['id_token'], $key);
        } catch(\Exception $e) {
            error_log("[LTI] JWT signature validation failed: " . $e->getMessage());
            throw new LTI_Exception("Invalid signature on id_token", 1);
        }

        return $this;
    }

    private function validate_deployment() {
        $issuer = $this->jwt['body']['iss'];
        $client_id = $this->registration->get_client_id();
        $deployment_id = $this->jwt['body']['https://purl.imsglobal.org/spec/lti/claim/deployment_id'];

        // Use the new method that properly handles multiple tools from same issuer
        $deployment = $this->db->find_deployment_by_issuer_client_and_deployment($issuer, $client_id, $deployment_id);

        if (empty($deployment)) {
            error_log("[LTI] Deployment validation failed: issuer=$issuer, client_id=$client_id, deployment_id=$deployment_id");
            throw new LTI_Exception("Unable to find deployment (issuer=$issuer, deployment_id=$deployment_id)", 1);
        }

        return $this;
    }

    private function validate_message() {
        if (empty($this->jwt['body']['https://purl.imsglobal.org/spec/lti/claim/message_type'])) {
            throw new LTI_Exception("Invalid message type", 1);
        }

        // Import all validators
        foreach (glob(__DIR__ . "/message_validators/*.php") as $filename) {
            include_once $filename;
        }

        // Create instances of all validators
        $classes = get_declared_classes();
        $validators = array();
        foreach ($classes as $class_name) {
            $reflect = new \ReflectionClass($class_name);
            if ($reflect->implementsInterface('\IMSGlobal\LTI\Message_Validator')) {
                $validators[] = new $class_name();
            }
        }

        $message_validator = false;
        foreach ($validators as $validator) {
            if ($validator->can_validate($this->jwt['body'])) {
                if ($message_validator !== false) {
                    throw new LTI_Exception("Validator conflict", 1);
                }
                $message_validator = $validator;
            }
        }

        if ($message_validator === false) {
            throw new LTI_Exception("Unrecognized message type.", 1);
        }

        if (!$message_validator->validate($this->jwt['body'])) {
            throw new LTI_Exception("Message validation failed.", 1);
        }

        return $this;
    }
}

?>
