<?php
require_once __DIR__ . '/../vendor/autoload.php';
use \IMSGlobal\LTI;

class WordPressLTI_Database implements LTI\Database {
    /** @var array Tools indexed by issuer (for legacy lookups) */
    private array $tools_by_issuer = [];

    /** @var array Tools indexed by issuer|client_id combination */
    private array $tools_by_issuer_client = [];

    /** @var array All tools indexed by client_id */
    private array $tools_by_client_id = [];

    /** @var array All tools indexed by deployment_id */
    private array $tools_by_deployment_id = [];

    public function __construct() {
        $enabled_tools = lti_13_get_tools_with_priv_key();
        if ($enabled_tools) {
            foreach ($enabled_tools as $tool) {
                // Store by issuer (legacy - last one wins if duplicates)
                $this->tools_by_issuer[$tool->issuer] = $tool;

                // Store by issuer + client_id combination (unique key)
                $composite_key = $tool->issuer . '|' . $tool->client_id;
                $this->tools_by_issuer_client[$composite_key] = $tool;

                // Store by client_id for direct lookup
                $this->tools_by_client_id[$tool->client_id] = $tool;

                // Store by deployment_id for direct lookup
                $deployment_ids = array_map('trim', explode(',', $tool->deployments_ids ?? ''));
                foreach ($deployment_ids as $dep_id) {
                    if (!empty($dep_id)) {
                        $this->tools_by_deployment_id[$dep_id] = $tool;
                    }
                }
            }
        }
    }

    /**
     * Find registration by issuer only (legacy method)
     * Warning: May return wrong result if multiple tools share the same issuer
     * @deprecated Use find_registration_by_issuer_and_client_id instead
     */
    public function find_registration_by_issuer(string $iss): ?LTI\LTI_Registration {
        if (empty($this->tools_by_issuer) || empty($this->tools_by_issuer[$iss])) {
            return null;
        }

        $tool = $this->tools_by_issuer[$iss];
        return $this->build_registration($tool);
    }

    /**
     * Find registration by issuer and client_id (preferred method)
     * This properly handles multiple tools from the same LMS/issuer
     */
    public function find_registration_by_issuer_and_client_id(string $iss, string $client_id): ?LTI\LTI_Registration {
        // First try the composite key (issuer + client_id)
        $composite_key = $iss . '|' . $client_id;
        if (!empty($this->tools_by_issuer_client[$composite_key])) {
            return $this->build_registration($this->tools_by_issuer_client[$composite_key]);
        }

        // Fallback: try by client_id only (some platforms may use different issuer URLs)
        if (!empty($this->tools_by_client_id[$client_id])) {
            $tool = $this->tools_by_client_id[$client_id];
            // Verify the issuer matches or is compatible
            if ($tool->issuer === $iss) {
                return $this->build_registration($tool);
            }
        }

        return null;
    }

    /**
     * Find registration by client_id only (fallback when issuer is missing)
     * Uses the issuer stored in the tool configuration
     */
    public function find_registration_by_client_id(string $client_id): ?LTI\LTI_Registration {
        if (!empty($this->tools_by_client_id[$client_id])) {
            $tool = $this->tools_by_client_id[$client_id];
            error_log("[LTI DB] Fallback: Found registration by client_id={$client_id}, using stored issuer={$tool->issuer}");
            return $this->build_registration($tool);
        }
        error_log("[LTI DB] Fallback: No registration found for client_id={$client_id}");
        return null;
    }

    /**
     * Find registration by deployment_id only (fallback when issuer is missing)
     * Uses the issuer stored in the tool configuration
     */
    public function find_registration_by_deployment_id(string $deployment_id): ?LTI\LTI_Registration {
        if (!empty($this->tools_by_deployment_id[$deployment_id])) {
            $tool = $this->tools_by_deployment_id[$deployment_id];
            error_log("[LTI DB] Fallback: Found registration by deployment_id={$deployment_id}, using stored issuer={$tool->issuer}");
            return $this->build_registration($tool);
        }
        error_log("[LTI DB] Fallback: No registration found for deployment_id={$deployment_id}");
        return null;
    }

    /**
     * Find registration by client_id and deployment_id (fallback when issuer is missing)
     * Uses the issuer stored in the tool configuration
     */
    public function find_registration_by_client_and_deployment(string $client_id, string $deployment_id): ?LTI\LTI_Registration {
        // First try to find by client_id
        if (!empty($this->tools_by_client_id[$client_id])) {
            $tool = $this->tools_by_client_id[$client_id];
            // Verify this tool has the deployment_id
            $deployment_ids = array_map('trim', explode(',', $tool->deployments_ids ?? ''));
            if (in_array($deployment_id, $deployment_ids)) {
                error_log("[LTI DB] Fallback: Found registration by client_id={$client_id} + deployment_id={$deployment_id}");
                return $this->build_registration($tool);
            }
        }
        // Fallback to deployment_id only
        return $this->find_registration_by_deployment_id($deployment_id);
    }

    /**
     * Build an LTI_Registration object from a tool record
     */
    private function build_registration(object $tool): LTI\LTI_Registration {
        error_log("[LTI DB] Building registration for client_id={$tool->client_id}");
        error_log("[LTI DB] auth_token_url from DB: {$tool->auth_token_url}");
        error_log("[LTI DB] auth_login_url from DB: {$tool->auth_login_url}");

        return LTI\LTI_Registration::new()
            ->set_auth_login_url($tool->auth_login_url)
            ->set_auth_token_url($tool->auth_token_url)
            ->set_client_id($tool->client_id)
            ->set_key_set_url($tool->key_set_url)
            ->set_issuer($tool->issuer)
            ->set_kid($tool->client_id)
            ->set_tool_private_key($tool->private_key);
    }

    /**
     * Find deployment by issuer and deployment_id
     * @deprecated Use find_deployment_by_issuer_client_and_deployment instead
     */
    public function find_deployment(string $iss, string $deployment_id): ?LTI\LTI_Deployment {
        // Try to find by issuer first
        $tool = $this->tools_by_issuer[$iss] ?? null;

        // If not found, search through all tools for matching issuer
        if (!$tool) {
            foreach ($this->tools_by_issuer_client as $t) {
                if ($t->issuer === $iss) {
                    $tool = $t;
                    break;
                }
            }
        }

        if (!$tool) {
            return null;
        }

        $deployments_ids = array_map('trim', explode(',', $tool->deployments_ids ?? ''));
        if (!in_array($deployment_id, $deployments_ids)) {
            return null;
        }

        return LTI\LTI_Deployment::new()
            ->set_deployment_id($deployment_id);
    }

    /**
     * Find deployment by issuer, client_id, and deployment_id (preferred method)
     * This properly handles multiple tools from the same LMS/issuer
     */
    public function find_deployment_by_issuer_client_and_deployment(string $iss, string $client_id, string $deployment_id): ?LTI\LTI_Deployment {
        // Look up the specific tool by composite key
        $composite_key = $iss . '|' . $client_id;
        $tool = $this->tools_by_issuer_client[$composite_key] ?? null;

        if (!$tool) {
            error_log("[LTI] find_deployment: Tool not found for issuer=$iss, client_id=$client_id");
            return null;
        }

        $deployments_ids = array_map('trim', explode(',', $tool->deployments_ids ?? ''));
        error_log("[LTI] find_deployment: Looking for deployment_id=$deployment_id in [" . implode(', ', $deployments_ids) . "]");

        if (!in_array($deployment_id, $deployments_ids)) {
            error_log("[LTI] find_deployment: deployment_id=$deployment_id NOT found in tool's deployments");
            return null;
        }

        return LTI\LTI_Deployment::new()
            ->set_deployment_id($deployment_id);
    }
}
