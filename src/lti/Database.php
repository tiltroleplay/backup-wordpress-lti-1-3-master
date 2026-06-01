<?php
namespace IMSGlobal\LTI;

interface Database {
    /**
     * Find registration by issuer only (legacy - may return wrong result if multiple tools share same issuer)
     * @deprecated Use find_registration_by_issuer_and_client_id instead
     */
    public function find_registration_by_issuer(string $iss): ?LTI_Registration;

    /**
     * Find registration by issuer and client_id (preferred method for LTI 1.3)
     */
    public function find_registration_by_issuer_and_client_id(string $iss, string $client_id): ?LTI_Registration;

    /**
     * Find deployment by issuer and deployment_id
     */
    public function find_deployment(string $iss, string $deployment_id): ?LTI_Deployment;
}

?>
