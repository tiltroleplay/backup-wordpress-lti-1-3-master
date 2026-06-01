<?php
namespace IMSGlobal\LTI;

use Firebase\JWT\JWT;

class JWKS_Endpoint {

    private array $keys;

    public function __construct(array $keys) {
        $this->keys = $keys;
    }

    public static function new(array $keys): self {
        return new JWKS_Endpoint($keys);
    }

    public static function from_issuer(Database $database, string $issuer): self {
        $registration = $database->find_registration_by_issuer($issuer);
        return new JWKS_Endpoint([$registration->get_kid() => $registration->get_tool_private_key()]);
    }

    public static function from_registration(LTI_Registration $registration): self {
        return new JWKS_Endpoint([$registration->get_kid() => $registration->get_tool_private_key()]);
    }

    public function get_public_jwks(): array {
        $jwks = [];
        foreach ($this->keys as $kid => $private_key) {
            try {
                // Load private key using native PHP OpenSSL
                $key_resource = openssl_pkey_get_private($private_key);
                if (!$key_resource) {
                    error_log("[LTI JWKS] Failed to load private key for kid: $kid");
                    continue;
                }

                // Get key details
                $key_details = openssl_pkey_get_details($key_resource);
                if (!$key_details || !isset($key_details['rsa'])) {
                    error_log("[LTI JWKS] Failed to get key details for kid: $kid");
                    continue;
                }

                $components = [
                    'kty' => 'RSA',
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'e' => JWT::urlsafeB64Encode($key_details['rsa']['e']),
                    'n' => JWT::urlsafeB64Encode($key_details['rsa']['n']),
                    'kid' => $kid,
                ];

                $jwks[] = $components;
            } catch (\Exception $e) {
                error_log("[LTI JWKS] Failed to process key: " . $e->getMessage());
                continue;
            }
        }
        return ['keys' => $jwks];
    }

    public function output_jwks(): void {
        header('Content-Type: application/json');
        echo json_encode($this->get_public_jwks());
    }

}
