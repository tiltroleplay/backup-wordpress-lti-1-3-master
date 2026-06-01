<?php
/*
 * Plugin Name: IMS LTI 1.3
 * @name Load Blog Type
 * @abstract Processes incoming requests for IMS 1.3 LTI and apply wordpress with blogType parameter.
 * @author Antoni Bertran (antoni@tresipunt.com)
 * @copyright 2022 3ipunt and Universitat Oberta de Catalunya
 * @license Apache License
 * Date August 2022
 */

require_once(ABSPATH . '/wp-admin/includes/plugin.php');
require_once(ABSPATH . '/wp-includes/ms-functions.php');
require_once(ABSPATH . '/wp-admin/includes/bookmark.php');

require_once dirname(__FILE__) . '/blogType/blogTypeLoader.php';
require_once dirname(__FILE__) . '/lti/lib.php';
require_once dirname(__FILE__) . '/vendor/autoload.php';

use \Firebase\JWT\JWT;
use \Firebase\JWT\JWK;

require_once dirname(__FILE__) . '/blogType/Constants.php';
require_once dirname(__FILE__) . '/blogType/utils/UtilsPropertiesWP.php';

use \IMSGlobal\LTI;

/**
 * Main admin page controller
 */
function lti_client_id_admin(): void {
    global $wpdb;

    if (!lti_site_admin()) {
        return;
    }

    lti_maybe_create_db();

    $is_editing = false;
    $notice = '';
    $notice_type = '';

    // Handle form submissions
    if (!empty($_POST['action'])) {
        check_admin_referer('lti');
        $client_id = sanitize_text_field($_POST['client_id'] ?? '');
        $result = lti_handle_admin_action($_POST['action'], $client_id);
        $notice = $result['notice'] ?? '';
        $notice_type = $result['type'] ?? 'updated';
        $is_editing = $result['is_editing'] ?? false;
    }

    // Render the page
    lti_render_admin_page($is_editing, $notice, $notice_type);
}

/**
 * Handle admin form actions
 */
function lti_handle_admin_action(string $action, string $client_id): array {
    global $wpdb;

    $result = ['notice' => '', 'type' => 'updated', 'is_editing' => false];

    switch ($action) {
        case 'edit':
            $row = LTIUtils::lti_get_by_client_id($client_id);
            if ($row) {
                lti_render_edit_form($row);
                $result['is_editing'] = true;
            } else {
                $result['notice'] = __('LTI Tool not found.', 'wordpress-mu-ltiadvantage');
                $result['type'] = 'error';
            }
            break;

        case 'view_config_info':
            $row = LTIUtils::lti_get_by_client_id($client_id);
            if ($row) {
                lti_render_config_info($row);
                $result['is_editing'] = true;
            } else {
                $result['notice'] = __('LTI Tool not found.', 'wordpress-mu-ltiadvantage');
                $result['type'] = 'error';
            }
            break;

        case 'generate_priv_pub_key':
            $row = LTIUtils::lti_get_by_client_id($client_id);
            if ($row) {
                lti_generate_public_and_private_key($client_id);
                $row = LTIUtils::lti_get_by_client_id($client_id);
                lti_render_keys_section($row);
                $result['is_editing'] = true;
                $result['notice'] = __('Keys regenerated successfully.', 'wordpress-mu-ltiadvantage');
            } else {
                $result['notice'] = __('LTI Tool not found.', 'wordpress-mu-ltiadvantage');
                $result['type'] = 'error';
            }
            break;

        case 'save':
            $result['notice'] = lti_save_tool($client_id);
            break;

        case 'del':
            $wpdb->query($wpdb->prepare("DELETE FROM " . lti_13_get_table() . " WHERE client_id = %s", $client_id));
            $result['notice'] = __('LTI Tool deleted.', 'wordpress-mu-ltiadvantage');
            break;

        case 'save-settings':
            lti_store_plugin_settings(
                intval($_POST['show_gradebook_to_teachers'] ?? 0),
                intval($_POST['show_sync_members_to_teachers'] ?? 0)
            );
            $result['notice'] = __('Settings saved.', 'wordpress-mu-ltiadvantage');
            break;
    }

    return $result;
}

/**
 * Save or update an LTI tool
 */
function lti_save_tool(string $client_id): string {
    global $wpdb;

    $data = [
        'auth_login_url' => sanitize_text_field($_POST['auth_login_url'] ?? ''),
        'issuer' => sanitize_text_field($_POST['issuer'] ?? ''),
        'key_set_url' => esc_url_raw($_POST['key_set_url'] ?? ''),
        'auth_token_url' => esc_url_raw($_POST['auth_token_url'] ?? ''),
        'enabled' => intval($_POST['enabled'] ?? 0),
        'custom_username_parameter' => sanitize_text_field($_POST['custom_username_parameter'] ?? ''),
        'has_custom_username_parameter' => intval($_POST['has_custom_username_parameter'] ?? 0),
        'grade_column_tag' => sanitize_text_field($_POST['grade_column_tag'] ?? ''),
        'grade_column_name' => sanitize_text_field($_POST['grade_column_name'] ?? ''),
        'student_role' => sanitize_key($_POST['student_role'] ?? 'subscriber'),
        'deployments_ids' => sanitize_text_field($_POST['deployments_ids'] ?? ''),
    ];

    $existing = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM " . lti_13_get_table() . " WHERE client_id = %s",
        $client_id
    ));

    if ($existing) {
        $wpdb->update(
            lti_13_get_table(),
            array_merge($data, ['updated' => current_time('mysql')]),
            ['client_id' => $client_id]
        );
        return __('LTI Tool updated.', 'wordpress-mu-ltiadvantage');
    } else {
        $wpdb->insert(
            lti_13_get_table(),
            array_merge($data, [
                'client_id' => $client_id,
                'created' => current_time('mysql'),
                'updated' => current_time('mysql'),
            ])
        );
        lti_generate_public_and_private_key($client_id);
        return __('LTI Tool added.', 'wordpress-mu-ltiadvantage');
    }
}

/**
 * Render the main admin page
 */
function lti_render_admin_page(bool $is_editing, string $notice, string $notice_type): void {
    global $wpdb;

    ?>
    <div class="wrap">
        <h1><?php esc_html_e('LTI: Clients', 'wordpress-mu-ltiadvantage'); ?></h1>

        <?php if ($notice): ?>
            <div class="notice notice-<?php echo esc_attr($notice_type); ?> is-dismissible">
                <p><strong><?php echo esc_html($notice); ?></strong></p>
            </div>
        <?php endif; ?>

        <?php if (!$is_editing): ?>
            <?php lti_render_tool_settings(); ?>

            <hr />

            <?php lti_render_search_section(); ?>

            <hr />

            <?php lti_render_gradebook_settings(); ?>

            <hr />

            <?php lti_render_edit_form(); ?>
        <?php endif; ?>
    </div>
    <?php
}

/**
 * Render tool settings (URLs)
 */
function lti_render_tool_settings(): void {
    ?>
    <h2><?php esc_html_e('Current Tool Settings', 'wordpress-mu-ltiadvantage'); ?></h2>
    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row"><?php esc_html_e('Launch URL', 'wordpress-mu-ltiadvantage'); ?></th>
                <td>
                    <input type="text" id="launchUrl" class="regular-text" readonly
                           value="<?php echo esc_url(plugin_dir_url(__FILE__) . 'lti/launch.php'); ?>" />
                    <?php lti_render_copy_button('launchUrl'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Login URL', 'wordpress-mu-ltiadvantage'); ?></th>
                <td>
                    <input type="text" id="loginUrl" class="regular-text" readonly
                           value="<?php echo esc_url(plugin_dir_url(__FILE__) . 'lti/login.php'); ?>" />
                    <?php lti_render_copy_button('loginUrl'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('URL JWKS', 'wordpress-mu-ltiadvantage'); ?></th>
                <td>
                    <input type="text" id="jwksUrl" class="regular-text" readonly
                           value="<?php echo esc_url(plugin_dir_url(__FILE__) . 'lti/jwks.php'); ?>" />
                    <?php lti_render_copy_button('jwksUrl'); ?>
                </td>
            </tr>
        </tbody>
    </table>
    <?php
}

/**
 * Render copy button helper
 */
function lti_render_copy_button(string $target_id): void {
    ?>
    <button type="button" class="button button-secondary wordpress-lti-copy-btn"
            onclick="wordpress_lti_copy('<?php echo esc_js($target_id); ?>', '<?php echo esc_js($target_id); ?>Tooltip')">
        <span class="dashicons dashicons-clipboard"></span>
        <?php esc_html_e('Copy', 'wordpress-mu-ltiadvantage'); ?>
    </button>
    <?php
}

/**
 * Render search section
 */
function lti_render_search_section(): void {
    global $wpdb;

    $search_str = sanitize_text_field($_POST['search_txt'] ?? '');
    $like_search = '%' . $wpdb->esc_like($search_str) . '%';
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT * FROM " . lti_13_get_table() . " WHERE client_id LIKE %s",
        $like_search
    ));

    ?>
    <h2><?php esc_html_e('Registered LTI Tools', 'wordpress-mu-ltiadvantage'); ?></h2>

    <?php if (!empty($search_str)): ?>
        <p><?php printf(esc_html__('Searching for "%s"', 'wordpress-mu-ltiadvantage'), esc_html($search_str)); ?></p>
    <?php endif; ?>

    <?php lti_render_tools_table($rows); ?>

    <form method="post" class="lti-search-form">
        <?php wp_nonce_field('lti'); ?>
        <input type="hidden" name="action" value="search" />
        <p>
            <label for="search_txt"><?php esc_html_e('Search:', 'wordpress-mu-ltiadvantage'); ?></label>
            <input type="text" name="search_txt" id="search_txt" value="<?php echo esc_attr($search_str); ?>" class="regular-text" />
            <?php submit_button(__('Search', 'wordpress-mu-ltiadvantage'), 'secondary', 'submit', false); ?>
        </p>
    </form>
    <?php
}

/**
 * Render the tools table
 */
function lti_render_tools_table(array $rows): void {
    if (empty($rows)) {
        echo '<p>' . esc_html__('No records found', 'wordpress-mu-ltiadvantage') . '</p>';
        return;
    }
    ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th scope="col"><?php esc_html_e('Client ID', 'wordpress-mu-ltiadvantage'); ?></th>
                <th scope="col"><?php esc_html_e('Key Set URL', 'wordpress-mu-ltiadvantage'); ?></th>
                <th scope="col"><?php esc_html_e('Auth Token URL', 'wordpress-mu-ltiadvantage'); ?></th>
                <th scope="col"><?php esc_html_e('Enabled', 'wordpress-mu-ltiadvantage'); ?></th>
                <th scope="col"><?php esc_html_e('Actions', 'wordpress-mu-ltiadvantage'); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
            <tr>
                <td><?php echo esc_html($row->client_id); ?></td>
                <td><?php echo esc_html($row->key_set_url); ?></td>
                <td><?php echo esc_html($row->auth_token_url); ?></td>
                <td><?php echo $row->enabled ? esc_html__('Yes', 'wordpress-mu-ltiadvantage') : esc_html__('No', 'wordpress-mu-ltiadvantage'); ?></td>
                <td>
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field('lti'); ?>
                        <input type="hidden" name="action" value="view_config_info" />
                        <input type="hidden" name="client_id" value="<?php echo esc_attr($row->client_id); ?>" />
                        <?php submit_button(__('Config', 'wordpress-mu-ltiadvantage'), 'secondary small', 'submit', false); ?>
                    </form>
                    <form method="post" style="display:inline;">
                        <?php wp_nonce_field('lti'); ?>
                        <input type="hidden" name="action" value="edit" />
                        <input type="hidden" name="client_id" value="<?php echo esc_attr($row->client_id); ?>" />
                        <?php submit_button(__('Edit', 'wordpress-mu-ltiadvantage'), 'secondary small', 'submit', false); ?>
                    </form>
                    <form method="post" style="display:inline;" onsubmit="return confirm('<?php esc_attr_e('Are you sure you want to delete this tool?', 'wordpress-mu-ltiadvantage'); ?>');">
                        <?php wp_nonce_field('lti'); ?>
                        <input type="hidden" name="action" value="del" />
                        <input type="hidden" name="client_id" value="<?php echo esc_attr($row->client_id); ?>" />
                        <?php submit_button(__('Delete', 'wordpress-mu-ltiadvantage'), 'delete small', 'submit', false); ?>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <?php
}

/**
 * Render gradebook settings
 */
function lti_render_gradebook_settings(): void {
    $show_gradebook = get_lti_show_gradebook_to_teachers();
    $show_sync = get_lti_show_sync_members_to_teachers();
    ?>
    <h2><?php esc_html_e('Gradebook Configuration', 'wordpress-mu-ltiadvantage'); ?></h2>
    <form method="post">
        <?php wp_nonce_field('lti'); ?>
        <input type="hidden" name="action" value="save-settings" />
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Show Gradebook to instructors', 'wordpress-mu-ltiadvantage'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="show_gradebook_to_teachers" value="1" <?php checked($show_gradebook, 1); ?> />
                            <?php esc_html_e('Enable', 'wordpress-mu-ltiadvantage'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Show Sync Members to instructors', 'wordpress-mu-ltiadvantage'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="show_sync_members_to_teachers" value="1" <?php checked($show_sync, 1); ?> />
                            <?php esc_html_e('Enable', 'wordpress-mu-ltiadvantage'); ?>
                        </label>
                    </td>
                </tr>
            </tbody>
        </table>
        <?php submit_button(__('Save Settings', 'wordpress-mu-ltiadvantage')); ?>
    </form>
    <?php
}

/**
 * Render config info view
 */
function lti_render_config_info(object $row): void {
    ?>
    <div class="wrap">
        <h1><?php esc_html_e('LTI Tool Configuration', 'wordpress-mu-ltiadvantage'); ?></h1>

        <h2><?php esc_html_e('Tool URLs', 'wordpress-mu-ltiadvantage'); ?></h2>
        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><?php esc_html_e('Launch URL', 'wordpress-mu-ltiadvantage'); ?></th>
                    <td>
                        <input type="text" id="launchUrl" class="regular-text" readonly
                               value="<?php echo esc_url(plugin_dir_url(__FILE__) . 'lti/launch.php'); ?>" />
                        <?php lti_render_copy_button('launchUrl'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Login URL', 'wordpress-mu-ltiadvantage'); ?></th>
                    <td>
                        <input type="text" id="loginUrl" class="regular-text" readonly
                               value="<?php echo esc_url(plugin_dir_url(__FILE__) . 'lti/login.php'); ?>" />
                        <?php lti_render_copy_button('loginUrl'); ?>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('URL JWKS', 'wordpress-mu-ltiadvantage'); ?></th>
                    <td>
                        <input type="text" id="jwksUrl" class="regular-text" readonly
                               value="<?php echo esc_url(plugin_dir_url(__FILE__) . 'lti/jwks.php?kid=' . urlencode($row->client_id)); ?>" />
                        <?php lti_render_copy_button('jwksUrl'); ?>
                    </td>
                </tr>
                <?php if (!empty($row->private_key)): ?>
                <tr>
                    <th scope="row"><?php esc_html_e('JWKS Info', 'wordpress-mu-ltiadvantage'); ?></th>
                    <td>
                        <?php
                        try {
                            $keys = [$row->client_id => $row->private_key];
                            $jwksInfo = json_encode(LTI\JWKS_Endpoint::new($keys)->get_public_jwks(), JSON_PRETTY_PRINT);
                        } catch (Exception $e) {
                            $jwksInfo = __('Error generating JWKS: ', 'wordpress-mu-ltiadvantage') . $e->getMessage();
                        }
                        ?>
                        <textarea id="jwksInfo" class="large-text code" rows="6" readonly><?php echo esc_textarea($jwksInfo); ?></textarea>
                        <?php lti_render_copy_button('jwksInfo'); ?>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php lti_render_keys_section($row); ?>

        <p>
            <a href="<?php echo esc_url(admin_url('admin.php?page=lti_client_id_admin')); ?>" class="button">
                <?php esc_html_e('Back to List', 'wordpress-mu-ltiadvantage'); ?>
            </a>
        </p>
    </div>
    <?php
}

/**
 * Render keys section
 */
function lti_render_keys_section(object $row): void {
    ?>
    <h2><?php esc_html_e('Keys', 'wordpress-mu-ltiadvantage'); ?></h2>
    <table class="form-table" role="presentation">
        <tbody>
            <tr>
                <th scope="row"><?php esc_html_e('Client ID', 'wordpress-mu-ltiadvantage'); ?></th>
                <td>
                    <input type="text" id="client_id_display" class="regular-text" readonly
                           value="<?php echo esc_attr($row->client_id); ?>" />
                    <?php lti_render_copy_button('client_id_display'); ?>
                </td>
            </tr>
            <tr>
                <th scope="row"><?php esc_html_e('Public Key', 'wordpress-mu-ltiadvantage'); ?></th>
                <td>
                    <textarea id="publicKey" class="large-text code" rows="8" readonly><?php echo esc_textarea($row->public_key); ?></textarea>
                    <?php lti_render_copy_button('publicKey'); ?>
                </td>
            </tr>
        </tbody>
    </table>

    <form method="post">
        <?php wp_nonce_field('lti'); ?>
        <input type="hidden" name="action" value="generate_priv_pub_key" />
        <input type="hidden" name="client_id" value="<?php echo esc_attr($row->client_id); ?>" />
        <?php submit_button(__('Regenerate Keys', 'wordpress-mu-ltiadvantage'), 'secondary'); ?>
    </form>
    <?php
}

/**
 * Render edit form
 */
function lti_render_edit_form(?object $row = null): void {
    $is_new = !is_object($row);

    if ($is_new) {
        $row = (object) [
            'client_id' => '',
            'issuer' => '',
            'auth_login_url' => '',
            'key_set_url' => '',
            'auth_token_url' => '',
            'enabled' => 1,
            'grade_column_tag' => '',
            'grade_column_name' => '',
            'student_role' => 'subscriber',
            'has_custom_username_parameter' => 0,
            'custom_username_parameter' => '',
            'public_key' => '',
            'deployments_ids' => '',
        ];
    }

    ?>
    <h2><?php echo $is_new ? esc_html__('Add New LTI Tool', 'wordpress-mu-ltiadvantage') : esc_html__('Edit LTI Tool', 'wordpress-mu-ltiadvantage'); ?></h2>

    <form method="post">
        <?php wp_nonce_field('lti'); ?>
        <input type="hidden" name="action" value="save" />

        <table class="form-table" role="presentation">
            <tbody>
                <tr>
                    <th scope="row"><label for="client_id"><?php esc_html_e('Client ID', 'wordpress-mu-ltiadvantage'); ?></label></th>
                    <td>
                        <input type="text" name="client_id" id="client_id" class="regular-text"
                               value="<?php echo esc_attr($row->client_id); ?>"
                               <?php echo !$is_new ? 'readonly' : ''; ?> required />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="issuer"><?php esc_html_e('Issuer', 'wordpress-mu-ltiadvantage'); ?></label></th>
                    <td>
                        <input type="text" name="issuer" id="issuer" class="large-text"
                               value="<?php echo esc_attr($row->issuer); ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="auth_login_url"><?php esc_html_e('Auth Login URL', 'wordpress-mu-ltiadvantage'); ?></label></th>
                    <td>
                        <input type="url" name="auth_login_url" id="auth_login_url" class="large-text"
                               value="<?php echo esc_url($row->auth_login_url); ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="key_set_url"><?php esc_html_e('Key Set URL', 'wordpress-mu-ltiadvantage'); ?></label></th>
                    <td>
                        <input type="url" name="key_set_url" id="key_set_url" class="large-text"
                               value="<?php echo esc_url($row->key_set_url); ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="auth_token_url"><?php esc_html_e('Auth Token URL', 'wordpress-mu-ltiadvantage'); ?></label></th>
                    <td>
                        <input type="url" name="auth_token_url" id="auth_token_url" class="large-text"
                               value="<?php echo esc_url($row->auth_token_url); ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="deployments_ids"><?php esc_html_e('Deployment IDs', 'wordpress-mu-ltiadvantage'); ?></label></th>
                    <td>
                        <input type="text" name="deployments_ids" id="deployments_ids" class="regular-text"
                               value="<?php echo esc_attr($row->deployments_ids); ?>" />
                        <p class="description"><?php esc_html_e('Comma separated list of deployment IDs', 'wordpress-mu-ltiadvantage'); ?></p>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="custom_username_parameter"><?php esc_html_e('Custom Username Parameter', 'wordpress-mu-ltiadvantage'); ?></label></th>
                    <td>
                        <input type="text" name="custom_username_parameter" id="custom_username_parameter" class="regular-text"
                               value="<?php echo esc_attr($row->custom_username_parameter); ?>" />
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Use Custom Username', 'wordpress-mu-ltiadvantage'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="has_custom_username_parameter" value="1"
                                   <?php checked($row->has_custom_username_parameter, 1); ?> />
                            <?php esc_html_e('Enable custom username parameter', 'wordpress-mu-ltiadvantage'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><?php esc_html_e('Enabled', 'wordpress-mu-ltiadvantage'); ?></th>
                    <td>
                        <label>
                            <input type="checkbox" name="enabled" value="1"
                                   <?php checked($row->enabled, 1); ?> />
                            <?php esc_html_e('Tool is enabled', 'wordpress-mu-ltiadvantage'); ?>
                        </label>
                    </td>
                </tr>
                <tr>
                    <th scope="row"><label for="student_role"><?php esc_html_e('Student Role', 'wordpress-mu-ltiadvantage'); ?></label></th>
                    <td>
                        <select name="student_role" id="student_role">
                            <option value="subscriber" <?php selected($row->student_role, 'subscriber'); ?>>
                                <?php esc_html_e('Subscriber', 'wordpress-mu-ltiadvantage'); ?>
                            </option>
                            <option value="author" <?php selected($row->student_role, 'author'); ?>>
                                <?php esc_html_e('Author', 'wordpress-mu-ltiadvantage'); ?>
                            </option>
                        </select>
                    </td>
                </tr>
                <?php if (!$is_new && !empty($row->public_key)): ?>
                <tr>
                    <th scope="row"><?php esc_html_e('Public Key', 'wordpress-mu-ltiadvantage'); ?></th>
                    <td>
                        <textarea class="large-text code" rows="5" readonly><?php echo esc_textarea($row->public_key); ?></textarea>
                    </td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <?php submit_button($is_new ? __('Add Tool', 'wordpress-mu-ltiadvantage') : __('Save Changes', 'wordpress-mu-ltiadvantage')); ?>
    </form>

    <?php if (!$is_new): ?>
    <p>
        <a href="<?php echo esc_url(admin_url('admin.php?page=lti_client_id_admin')); ?>" class="button">
            <?php esc_html_e('Back to List', 'wordpress-mu-ltiadvantage'); ?>
        </a>
    </p>
    <?php endif;
}

/**
 * Store plugin settings
 */
function lti_store_plugin_settings(int $show_gradebook, int $show_sync): void {
    if (is_multisite()) {
        update_site_option('lti_show_gradebook_to_teachers', $show_gradebook);
        update_site_option('lti_show_sync_members_to_teachers', $show_sync);
    } else {
        update_option('lti_show_gradebook_to_teachers', $show_gradebook);
        update_option('lti_show_sync_members_to_teachers', $show_sync);
    }
}

function get_lti_show_gradebook_to_teachers(): int {
    return (int) (is_multisite()
        ? get_site_option('lti_show_gradebook_to_teachers', 1)
        : get_option('lti_show_gradebook_to_teachers', 1));
}

function get_lti_show_sync_members_to_teachers(): int {
    return (int) (is_multisite()
        ? get_site_option('lti_show_sync_members_to_teachers', 1)
        : get_option('lti_show_sync_members_to_teachers', 1));
}

function lti_generate_public_and_private_key(string $client_id): bool {
    global $wpdb;
    $keys = lti_generate_private_public_key();

    return (bool) $wpdb->query($wpdb->prepare(
        "UPDATE " . lti_13_get_table() . " SET private_key = %s, public_key = %s WHERE client_id = %s",
        $keys['privKey'],
        $keys['pubKey'],
        $client_id
    ));
}

function lti_admin_add_css_js(): void {
    wp_register_script(
        'lti-wp-backend',
        plugins_url('lti/js/backend.js', __FILE__),
        [],
        '202210301'
    );

    wp_localize_script('lti-wp-backend', 'scriptParams', [
        'copied' => __('Copied', 'wordpress-mu-ltiadvantage'),
        'copyToClipboard' => __('Copy to clipboard', 'wordpress-mu-ltiadvantage'),
    ]);

    wp_enqueue_script('lti-wp-backend');

    wp_register_style(
        'grades-management-css',
        plugins_url('lti/css/backend.css', __FILE__),
        [],
        '20221030'
    );
    wp_enqueue_style('grades-management-css');
}

function lti_network_warning(): void {
    ?>
    <div id="lti-warning" class="notice notice-warning">
        <p>
            <strong><?php esc_html_e('LTI Disabled.', 'wordpress-mu-ltiadvantage'); ?></strong>
            <?php printf(
                esc_html__('You must %screate a network%s for it to work.', 'wordpress-mu-ltiadvantage'),
                '<a href="https://developer.wordpress.org/advanced-administration/multisite/create-network/">',
                '</a>'
            ); ?>
        </p>
    </div>
    <?php
}

function lti_network_pages(): void {
    add_submenu_page(
        'settings.php',
        'LTI Clients',
        'LTI Clients',
        'manage_options',
        'lti_client_id_admin',
        'lti_client_id_admin'
    );
}

function lti_admin_page(): void {
    add_menu_page(
        'LTI Clients',
        'LTI Clients',
        'manage_options',
        'lti_client_id_admin',
        'lti_client_id_admin',
        'dashicons-admin-network'
    );
}

if (is_multisite()) {
    add_action('network_admin_menu', 'lti_network_pages');
} else {
    add_action('admin_menu', 'lti_admin_page');
}

function get_lti_hash(): string {
    $remote_login_hash = get_site_option('lti_hash');
    if (null == $remote_login_hash) {
        $remote_login_hash = md5(time());
        update_site_option('lti_hash', $remote_login_hash);
    }
    return $remote_login_hash;
}

function lti_maybe_create_db(): void {
    global $wpdb;

    get_lti_hash();

    if (lti_site_admin()) {
        $created = false;
        if ($wpdb->get_var("SHOW TABLES LIKE '" . lti_13_get_table() . "'") != lti_13_get_table()) {
            $charset_collate = $wpdb->get_charset_collate();

            $wpdb->query("CREATE TABLE IF NOT EXISTS `" . lti_13_get_table() . "` (
                client_id varchar(150) NOT NULL,
                issuer varchar(255) NOT NULL,
                auth_login_url varchar(255) NOT NULL,
                key_set_url varchar(255) NOT NULL,
                auth_token_url varchar(255) NOT NULL,
                deployments_ids tinytext null,
                enabled tinyint(1) NOT NULL,
                private_key mediumtext NULL,
                public_key mediumtext NULL,
                custom_username_parameter varchar(255) DEFAULT NULL,
                has_custom_username_parameter decimal(1,0) default 0,
                grade_column_tag varchar(255) default '',
                grade_column_name varchar(255) default '',
                student_role varchar(30) default 'subscriber',
                created datetime NOT NULL,
                updated datetime NOT NULL,
                PRIMARY KEY (client_id)
            ) $charset_collate;");

            $wpdb->query("CREATE TABLE IF NOT EXISTS `" . $wpdb->base_prefix . "lti_nonce` (
                client_id varchar(150) NOT NULL,
                nonce varchar(150) NOT NULL,
                created timestamp default current_timestamp,
                PRIMARY KEY (client_id, nonce)
            ) $charset_collate");

            $created = true;
        }

        if ($created) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success is-dismissible"><p>';
                esc_html_e('LTI database tables created.', 'wordpress-mu-ltiadvantage');
                echo '</p></div>';
            });
        }
    }
}

function lti_site_admin(): bool {
    if (function_exists('is_super_admin')) {
        return is_super_admin();
    }
    return true;
}

function lti_generate_private_public_key(): array {
    $config = [
        'digest_alg' => 'sha256',
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];

    $res = openssl_pkey_new($config);
    openssl_pkey_export($res, $privKey, null, $config);

    $pubKey = openssl_pkey_get_details($res);
    $pubKey = $pubKey['key'];

    return ['privKey' => $privKey, 'pubKey' => $pubKey];
}

function lti_13_get_table(): string {
    global $wpdb;
    $wpdb->ltitable = $wpdb->base_prefix . 'lti_clients';
    return $wpdb->ltitable;
}

function lti_13_get_tools_with_priv_key(string $kid = ''): array {
    global $wpdb;

    $sql = "SELECT * FROM " . lti_13_get_table() . " WHERE coalesce(private_key, '') != ''";

    if ($kid) {
        $sql = $wpdb->prepare($sql . ' AND client_id = %s', $kid);
    }

    return $wpdb->get_results($sql) ?: [];
}

add_action('plugins_loaded', 'lti_plugins_loaded_plugin');
add_action('admin_init', 'lti_admin_add_css_js');

function lti_plugins_loaded_plugin(): void {
    load_muplugin_textdomain('wordpress-mu-ltiadvantage', 'lang');
}

require_once dirname(__FILE__) . '/lti/lti-advantage-management.php';
