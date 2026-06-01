<?php
require_once __DIR__ . '/../vendor/autoload.php';
use \IMSGlobal\LTI;

class WordPressLTI_Database implements LTI\Database {
    private array $tools = [];

    public function __construct() {
        $enabled_tools = lti_13_get_tools_with_priv_key();
        if ($enabled_tools) {
            foreach ($enabled_tools as $tool) {
                $this->tools[$tool->issuer] = $tool;
            }
        }
    }

    public function find_registration_by_issuer(string $iss): ?LTI\LTI_Registration {
        if (empty($this->tools) || empty($this->tools[$iss])) {
            return null;
        }

        return LTI\LTI_Registration::new()
            ->set_auth_login_url($this->tools[$iss]->auth_login_url)
            ->set_auth_token_url($this->tools[$iss]->auth_token_url)
            ->set_client_id($this->tools[$iss]->client_id)
            ->set_key_set_url($this->tools[$iss]->key_set_url)
            ->set_issuer($iss)
            ->set_kid($this->tools[$iss]->client_id)
            ->set_tool_private_key($this->tools[$iss]->private_key);
    }

    public function find_deployment(string $iss, string $deployment_id): ?LTI\LTI_Deployment {
        if (empty($this->tools[$iss])) {
            return null;
        }
        $deployments_id = explode(",", $this->tools[$iss]->deployments_ids);
        if (!in_array($deployment_id, $deployments_id)) {
            return null;
        }
        return LTI\LTI_Deployment::new()
            ->set_deployment_id($deployment_id);
    }

}
