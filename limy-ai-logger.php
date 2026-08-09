<?php
/**
 * Plugin Name:       Limy AI Logger
 * Plugin URI:        https://limy.ai
 * Description:       Integrates Limy.ai custom log shipping to track AI visibility and agent traffic on your WordPress site.
 * Version:           1.0.1
 * Author:            Soso Janashvili
 * Author URI:        https://idox.co.il
 * Text Domain:       limy-ai-logger
 * License:           GPL-2.0+
 * License URI:       http://www.gnu.org/licenses/gpl-2.0.txt
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

final class Limy_AI_Logger {

    const ENDPOINT = 'https://stream.getlimy.ai';
    const USER_AGENT = 'Limy-Custom-HTTP/1.0';

    /**
     * @var float Microtime when request started.
     */
    private $start_time;

    /**
     * Singleton instance.
     * @var Limy_AI_Logger|null
     */
    private static $instance = null;

    /**
     * Main plugin instance getter.
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Constructor.
     */
    private function __construct() {
        $this->start_time = microtime(true);

        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_notices', array($this, 'show_admin_notices'));
        add_filter('plugin_action_links_' . plugin_basename(__FILE__), array($this, 'add_action_links'));

        // Core log shipping hook - runs at the end of the request
        add_action('shutdown', array($this, 'ship_log'), 999);

        // Enable automatic updates directly from GitHub Releases (svipic/Wp-Limy-cdn)
        if (is_admin()) {
            new Limy_AI_Logger_GitHub_Updater(__FILE__, 'svipic/Wp-Limy-cdn');
        }
    }

    /**
     * Add settings page under Settings menu.
     */
    public function add_admin_menu() {
        add_options_page(
            __('Limy AI Logger', 'limy-ai-logger'),
            __('Limy AI Logger', 'limy-ai-logger'),
            'manage_options',
            'limy-ai-logger',
            array($this, 'render_settings_page')
        );
    }

    /**
     * Register plugin settings.
     */
    public function register_settings() {
        register_setting('limy_ai_logger_group', 'limy_api_key', array(
            'type'              => 'string',
            'sanitize_callback' => 'sanitize_text_field',
            'default'           => '',
        ));

        register_setting('limy_ai_logger_group', 'limy_enabled', array(
            'type'              => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => 1,
        ));

        register_setting('limy_ai_logger_group', 'limy_exclude_admin', array(
            'type'              => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => 1,
        ));

        register_setting('limy_ai_logger_group', 'limy_exclude_cron', array(
            'type'              => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => 1,
        ));

        register_setting('limy_ai_logger_group', 'limy_auto_update', array(
            'type'              => 'boolean',
            'sanitize_callback' => array($this, 'sanitize_checkbox'),
            'default'           => 1,
        ));
    }

    /**
     * Checkbox sanitizer helper.
     */
    public function sanitize_checkbox($value) {
        return !empty($value) ? 1 : 0;
    }

    /**
     * Add 'Settings' link to plugin page.
     */
    public function add_action_links($links) {
        $settings_link = sprintf(
            '<a href="%s">%s</a>',
            admin_url('options-general.php?page=limy-ai-logger'),
            __('Settings', 'limy-ai-logger')
        );
        array_unshift($links, $settings_link);
        return $links;
    }

    /**
     * Admin notice if API key is missing.
     */
    public function show_admin_notices() {
        if (!current_user_can('manage_options')) {
            return;
        }

        $api_key = get_option('limy_api_key');
        $enabled = get_option('limy_enabled', 1);

        if ($enabled && empty($api_key)) {
            $settings_url = admin_url('options-general.php?page=limy-ai-logger');
            echo '<div class="notice notice-warning is-dismissible">';
            echo '<p>';
            printf(
                /* translators: %s: Settings page URL */
                __('<strong>Limy AI Logger:</strong> Please <a href="%s">enter your Limy API Key</a> to start shipping AI visibility logs.', 'limy-ai-logger'),
                esc_url($settings_url)
            );
            echo '</p>';
            echo '</div>';
        }
    }

    /**
     * Render plugin settings UI in WP Admin.
     */
    public function render_settings_page() {
        if (!current_user_can('manage_options')) {
            return;
        }

        // Handle manual test connection action
        $test_result = null;
        if (isset($_POST['limy_test_connection']) && check_admin_referer('limy_test_connection_nonce')) {
            $test_result = $this->send_test_log();
        }

        $api_key       = get_option('limy_api_key', '');
        $enabled       = get_option('limy_enabled', 1);
        $exclude_admin = get_option('limy_exclude_admin', 1);
        $exclude_cron  = get_option('limy_exclude_cron', 1);
        $auto_update   = get_option('limy_auto_update', 1);
        ?>
        <div class="wrap">
            <h1><?php esc_html_e('Limy AI Logger Settings', 'limy-ai-logger'); ?></h1>
            <p><?php esc_html_e('Configure log shipping to Limy.ai to track AI bot visits and visibility statistics.', 'limy-ai-logger'); ?></p>

            <?php if ($test_result !== null): ?>
                <?php if ($test_result['success']): ?>
                    <div class="notice notice-success is-dismissible">
                        <p><strong><?php esc_html_e('Test Connection Successful!', 'limy-ai-logger'); ?></strong> <?php echo esc_html($test_result['message']); ?></p>
                    </div>
                <?php else: ?>
                    <div class="notice notice-error is-dismissible">
                        <p><strong><?php esc_html_e('Test Connection Failed:', 'limy-ai-logger'); ?></strong> <?php echo esc_html($test_result['message']); ?></p>
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <form method="post" action="options.php">
                <?php
                settings_fields('limy_ai_logger_group');
                do_settings_sections('limy_ai_logger_group');
                ?>

                <table class="form-table" role="presentation">
                    <tr>
                        <th scope="row">
                            <label for="limy_enabled"><?php esc_html_e('Enable Log Shipping', 'limy-ai-logger'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="limy_enabled" name="limy_enabled" value="1" <?php checked(1, $enabled); ?> />
                            <span class="description"><?php esc_html_e('Enable or disable sending access logs to Limy.ai.', 'limy-ai-logger'); ?></span>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="limy_api_key"><?php esc_html_e('Limy API Key', 'limy-ai-logger'); ?></label>
                        </th>
                        <td>
                            <input type="password" id="limy_api_key" name="limy_api_key" value="<?php echo esc_attr($api_key); ?>" class="regular-text" placeholder="lmy_xxxxxxxxxxxx" />
                            <button type="button" class="button button-secondary" onclick="var el=document.getElementById('limy_api_key'); el.type = el.type === 'password' ? 'text' : 'password';">
                                <?php esc_html_e('Show / Hide', 'limy-ai-logger'); ?>
                            </button>
                            <p class="description">
                                <?php esc_html_e('Your Limy API Key starting with lmy_. Found in your Limy.ai dashboard settings.', 'limy-ai-logger'); ?>
                            </p>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="limy_exclude_admin"><?php esc_html_e('Exclude WP Admin', 'limy-ai-logger'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="limy_exclude_admin" name="limy_exclude_admin" value="1" <?php checked(1, $exclude_admin); ?> />
                            <span class="description"><?php esc_html_e('Do not ship logs for requests inside /wp-admin/ or logged-in administrators.', 'limy-ai-logger'); ?></span>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="limy_exclude_cron"><?php esc_html_e('Exclude Cron & CLI', 'limy-ai-logger'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="limy_exclude_cron" name="limy_exclude_cron" value="1" <?php checked(1, $exclude_cron); ?> />
                            <span class="description"><?php esc_html_e('Do not ship logs for internal WP-Cron or WP-CLI background requests.', 'limy-ai-logger'); ?></span>
                        </td>
                    </tr>

                    <tr>
                        <th scope="row">
                            <label for="limy_auto_update"><?php esc_html_e('Background Auto-Updates', 'limy-ai-logger'); ?></label>
                        </th>
                        <td>
                            <input type="checkbox" id="limy_auto_update" name="limy_auto_update" value="1" <?php checked(1, $auto_update); ?> />
                            <span class="description"><?php esc_html_e('Automatically install new plugin versions released on GitHub in the background.', 'limy-ai-logger'); ?></span>
                        </td>
                    </tr>
                </table>

                <?php submit_button(); ?>
            </form>

            <hr />

            <h2><?php esc_html_e('Plugin Updates', 'limy-ai-logger'); ?></h2>
            <p><?php esc_html_e('Check GitHub Releases for the latest plugin updates.', 'limy-ai-logger'); ?></p>
            <a href="<?php echo esc_url(admin_url('options-general.php?page=limy-ai-logger&force-check-limy=1')); ?>" class="button button-secondary">
                <?php esc_html_e('Check GitHub Updates Now', 'limy-ai-logger'); ?>
            </a>

            <hr />

            <h2><?php esc_html_e('Test Integration', 'limy-ai-logger'); ?></h2>
            <p><?php esc_html_e('Send a single test request to verify your API Key with Limy.ai.', 'limy-ai-logger'); ?></p>
            <form method="post" action="">
                <?php wp_nonce_field('limy_test_connection_nonce'); ?>
                <input type="submit" name="limy_test_connection" class="button button-secondary" value="<?php esc_attr_e('Send Test Ping', 'limy-ai-logger'); ?>" <?php disabled(empty($api_key)); ?> />
            </form>
        </div>
        <?php
    }

    /**
     * Core function: Collect request info and ship log entry to Limy.ai.
     */
    public function ship_log() {
        // Check if enabled
        if (!get_option('limy_enabled', 1)) {
            return;
        }

        // Check if API Key is set
        $api_key = trim(get_option('limy_api_key', ''));
        if (empty($api_key)) {
            return;
        }

        // Exclude WP Admin if option is enabled
        if (get_option('limy_exclude_admin', 1) && (is_admin() || (function_exists('is_user_logged_in') && is_user_logged_in() && current_user_can('manage_options')))) {
            return;
        }

        // Exclude Cron / CLI if option is enabled
        if (get_option('limy_exclude_cron', 1) && (defined('DOING_CRON') || (defined('WP_CLI') && WP_CLI))) {
            return;
        }

        // Prepare log data payload per Limy specifications
        $log_entry = $this->build_log_entry();

        // Send non-blocking HTTP POST request to Limy stream endpoint
        wp_remote_post(self::ENDPOINT, array(
            'method'      => 'POST',
            'timeout'     => 5,
            'blocking'    => false, // Asynchronous / non-blocking
            'headers'     => array(
                'Content-Type' => 'application/json',
                'X-API-KEY'    => $api_key,
                'User-Agent'   => self::USER_AGENT,
            ),
            'body'        => wp_json_encode(array($log_entry)),
            'data_format' => 'body',
        ));
    }

    /**
     * Build log entry array structured per Limy API specification.
     */
    private function build_log_entry() {
        $host = isset($_SERVER['HTTP_HOST']) ? sanitize_text_field($_SERVER['HTTP_HOST']) : parse_url(home_url(), PHP_URL_HOST);
        $request_uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($request_uri, PHP_URL_PATH);
        if (!$path) {
            $path = '/';
        }

        $method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper(sanitize_text_field($_SERVER['REQUEST_METHOD'])) : 'GET';
        $status_code = (int) (http_response_code() ?: 200);
        $user_agent = isset($_SERVER['HTTP_USER_AGENT']) ? sanitize_text_field($_SERVER['HTTP_USER_AGENT']) : '';
        $referer = isset($_SERVER['HTTP_REFERER']) ? sanitize_text_field($_SERVER['HTTP_REFERER']) : '';

        // Query parameters
        $query_params = array();
        if (!empty($_GET)) {
            foreach ($_GET as $key => $val) {
                if (is_scalar($val)) {
                    $query_params[sanitize_text_field($key)] = sanitize_text_field($val);
                }
            }
        }

        // Calculate duration in milliseconds
        $duration_ms = (int) round((microtime(true) - $this->start_time) * 1000);

        $entry = array(
            'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
            'method'      => $method,
            'host'        => $host,
            'path'        => $path,
            'status_code' => $status_code,
            'ip'          => $this->get_client_ip(),
            'user_agent'  => $user_agent,
            'referer'     => $referer,
            'duration_ms' => $duration_ms,
        );

        if (!empty($query_params)) {
            $entry['query_params'] = (object) $query_params;
        }

        return $entry;
    }

    /**
     * Detect client IP address (supporting Cloudflare, reverse proxies, and standard REMOTE_ADDR).
     */
    private function get_client_ip() {
        $ip_headers = array(
            'HTTP_CF_CONNECTING_IP', // Cloudflare
            'HTTP_X_FORWARDED_FOR',   // Proxy / Load balancer
            'HTTP_CLIENT_IP',
            'REMOTE_ADDR',
        );

        foreach ($ip_headers as $header) {
            if (!empty($_SERVER[$header])) {
                $raw_ip = $_SERVER[$header];

                // HTTP_X_FORWARDED_FOR may contain comma separated list of IPs
                if (strpos($raw_ip, ',') !== false) {
                    $ips = explode(',', $raw_ip);
                    $raw_ip = trim($ips[0]);
                }

                $filtered_ip = filter_var($raw_ip, FILTER_VALIDATE_IP);
                if ($filtered_ip !== false) {
                    return $filtered_ip;
                }
            }
        }

        return '127.0.0.1';
    }

    /**
     * Send synchronous test log for admin verification button.
     */
    private function send_test_log() {
        $api_key = trim(get_option('limy_api_key', ''));
        if (empty($api_key)) {
            return array('success' => false, 'message' => __('API Key is empty.', 'limy-ai-logger'));
        }

        $log_entry = array(
            'timestamp'   => gmdate('Y-m-d\TH:i:s\Z'),
            'method'      => 'GET',
            'host'        => isset($_SERVER['HTTP_HOST']) ? sanitize_text_field($_SERVER['HTTP_HOST']) : parse_url(home_url(), PHP_URL_HOST),
            'path'        => '/limy-test-ping',
            'status_code' => 200,
            'ip'          => $this->get_client_ip(),
            'user_agent'  => 'Limy-WP-Plugin-TestPing/1.0',
            'referer'     => admin_url('options-general.php?page=limy-ai-logger'),
            'duration_ms' => 10,
        );

        $response = wp_remote_post(self::ENDPOINT, array(
            'method'      => 'POST',
            'timeout'     => 10,
            'blocking'    => true, // Blocking for test ping so we can verify response
            'headers'     => array(
                'Content-Type' => 'application/json',
                'X-API-KEY'    => $api_key,
                'User-Agent'   => self::USER_AGENT,
            ),
            'body'        => wp_json_encode(array($log_entry)),
            'data_format' => 'body',
        ));

        if (is_wp_error($response)) {
            return array(
                'success' => false,
                'message' => $response->get_error_message(),
            );
        }

        $code = wp_remote_retrieve_response_code($response);
        if ($code >= 200 && $code < 300) {
            return array(
                'success' => true,
                'message' => sprintf(__('Response status code: %d. Check your Limy dashboard Agent Monitor.', 'limy-ai-logger'), $code),
            );
        }

        $body = wp_remote_retrieve_body($response);
        return array(
            'success' => false,
            'message' => sprintf(__('HTTP Status %d. Response: %s', 'limy-ai-logger'), $code, esc_html($body)),
        );
    }
}

/**
 * GitHub Auto-Updater Class for Limy AI Logger.
 * Enables 1-click automatic updates in WordPress Admin directly from GitHub Releases.
 */
final class Limy_AI_Logger_GitHub_Updater {

    private $file;
    private $plugin;
    private $slug;
    private $github_repo;
    private $github_response;

    public function __construct($file, $github_repo) {
        $this->file        = $file;
        $this->plugin      = plugin_basename($file);
        $this->slug        = dirname($this->plugin);
        $this->github_repo = $github_repo;

        add_filter('pre_set_site_transient_update_plugins', array($this, 'check_update'));
        add_filter('plugins_api', array($this, 'plugin_popup'), 20, 3);
        add_filter('upgrader_post_install', array($this, 'post_install'), 10, 3);
        add_action('admin_notices', array($this, 'show_update_check_notice'));
        add_filter('auto_update_plugin', array($this, 'maybe_auto_update'), 10, 2);
    }

    public function maybe_auto_update($update, $item) {
        if (!get_option('limy_auto_update', 1)) {
            return $update;
        }

        if (isset($item->plugin) && $item->plugin === $this->plugin) {
            return true;
        }
        return $update;
    }

    public function show_update_check_notice() {
        if (!isset($_GET['force-check-limy']) || !current_user_can('manage_options')) {
            return;
        }

        $release = $this->get_github_release_info();
        if (!$release) {
            echo '<div class="notice notice-error is-dismissible"><p>';
            printf(__('Limy AI Logger: Could not fetch GitHub releases for %s. Ensure a release is published on GitHub.', 'limy-ai-logger'), esc_html($this->github_repo));
            echo '</p></div>';
            return;
        }

        $current_version = $this->get_plugin_version();
        $tag_name        = $release['tag_name'];
        $remote_version  = $this->parse_version($tag_name);

        if (version_compare($remote_version, $current_version, '>')) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            printf(
                __('Limy AI Logger: <strong>Update Available!</strong> Latest release on GitHub is tag <code>%s</code> (Version <code>%s</code>). Installed version is <code>%s</code>. Go to <a href="%s">Dashboard > Updates</a> to install.', 'limy-ai-logger'),
                esc_html($tag_name),
                esc_html($remote_version),
                esc_html($current_version),
                esc_url(admin_url('update-core.php'))
            );
            echo '</p></div>';
        } else {
            echo '<div class="notice notice-info is-dismissible"><p>';
            printf(
                __('Limy AI Logger: Checked GitHub — Latest release tag is <code>%s</code> (parsed as version <code>%s</code>). Installed version is <code>%s</code>. You are up to date (or no newer tag was found).', 'limy-ai-logger'),
                esc_html($tag_name),
                esc_html($remote_version),
                esc_html($current_version)
            );
            echo '</p></div>';
        }
    }

    private function get_plugin_version() {
        if (!function_exists('get_plugin_data')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        $plugin_data = get_plugin_data($this->file);
        return $plugin_data['Version'];
    }

    private function parse_version($tag_name) {
        if (preg_match('/[0-9]+(?:\.[0-9]+)+/', $tag_name, $matches)) {
            return $matches[0];
        }
        return ltrim($tag_name, 'vV');
    }

    private function get_github_release_info() {
        $transient_key = 'limy_github_release_' . md5($this->github_repo);

        if (isset($_GET['force-check']) || isset($_GET['force-check-limy'])) {
            delete_transient($transient_key);
        } else if (!empty($this->github_response)) {
            return $this->github_response;
        }

        $cached = get_transient($transient_key);
        if ($cached !== false && !isset($_GET['force-check-limy'])) {
            $this->github_response = $cached;
            return $cached;
        }

        $url      = sprintf('https://api.github.com/repos/%s/releases/latest', $this->github_repo);
        $response = wp_remote_get($url, array(
            'timeout' => 10,
            'headers' => array(
                'Accept'     => 'application/vnd.github.v3+json',
                'User-Agent' => 'WordPress-Limy-AI-Logger-Updater',
            ),
        ));

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) !== 200) {
            return false;
        }

        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (empty($body) || !is_array($body) || empty($body['tag_name'])) {
            return false;
        }

        // Cache response for 10 minutes to allow fast testing
        set_transient($transient_key, $body, 10 * MINUTE_IN_SECONDS);
        $this->github_response = $body;
        return $body;
    }

    public function check_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        $release = $this->get_github_release_info();
        if (!$release) {
            return $transient;
        }

        $current_version = $this->get_plugin_version();
        $remote_version  = $this->parse_version($release['tag_name']);

        if (version_compare($remote_version, $current_version, '>')) {
            $package_url = '';
            if (!empty($release['assets']) && is_array($release['assets'])) {
                foreach ($release['assets'] as $asset) {
                    if (isset($asset['browser_download_url']) && strpos($asset['browser_download_url'], '.zip') !== false) {
                        $package_url = $asset['browser_download_url'];
                        break;
                    }
                }
            }
            if (empty($package_url)) {
                $package_url = $release['zipball_url'];
            }

            $obj              = new stdClass();
            $obj->slug        = $this->slug;
            $obj->plugin      = $this->plugin;
            $obj->new_version = $remote_version;
            $obj->url         = $release['html_url'];
            $obj->package     = $package_url;

            $transient->response[$this->plugin] = $obj;
        }

        return $transient;
    }

    public function plugin_popup($result, $action, $args) {
        if ($action !== 'plugin_information') {
            return $result;
        }

        if (!isset($args->slug) || $args->slug !== $this->slug) {
            return $result;
        }

        $release = $this->get_github_release_info();
        if (!$release) {
            return $result;
        }

        $plugin_data = get_plugin_data($this->file);
        $res = new stdClass();
        $res->name           = $plugin_data['Name'];
        $res->slug           = $this->slug;
        $res->version        = $this->parse_version($release['tag_name']);
        $res->author         = $plugin_data['AuthorName'];
        $res->homepage       = $plugin_data['PluginURI'];
        $res->requires       = '5.0';
        $res->tested         = '6.6';
        $res->last_updated   = $release['published_at'];
        $res->sections       = array(
            'description' => $plugin_data['Description'],
            'changelog'   => !empty($release['body']) ? esc_html($release['body']) : 'See GitHub releases for details.',
        );

        return $res;
    }

    public function post_install($true, $hook_extra, $result) {
        if (isset($hook_extra['plugin']) && $hook_extra['plugin'] === $this->plugin) {
            global $wp_filesystem;
            $proper_destination = WP_PLUGIN_DIR . '/' . $this->slug;
            $wp_filesystem->move($result['destination'], $proper_destination);
            $result['destination'] = $proper_destination;
            activate_plugin($this->plugin);
        }
        return $result;
    }
}

// Initialize plugin
Limy_AI_Logger::get_instance();

