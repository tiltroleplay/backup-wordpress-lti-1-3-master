<?php
namespace IMSGlobal\LTI;

use phpseclib3\Crypt\RSA;
use phpseclib3\Crypt\PublicKeyLoader;
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
                // Load the private key using phpseclib3
                $key = PublicKeyLoader::load($private_key);

                // Get the public key
                $publicKey = $key->getPublicKey();

                // Convert to array format to extract n and e
                $keyDetails = openssl_pkey_get_details(openssl_pkey_get_public($publicKey->toString('PKCS8')));

                if (!isset($keyDetails['rsa'])) {
                    continue;
                }

                $components = [
                    'kty' => 'RSA',
                    'alg' => 'RS256',
                    'use' => 'sig',
                    'e' => JWT::urlsafeB64Encode($keyDetails['rsa']['e']),
                    'n' => JWT::urlsafeB64Encode($keyDetails['rsa']['n']),
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
