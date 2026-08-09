<?php
/**
 * Plugin Name:       Limy AI Logger
 * Plugin URI:        https://limy.ai
 * Description:       Integrates Limy.ai custom log shipping to track AI visibility and agent traffic on your WordPress site.
 * Version:           1.1.0
 * Author:            Soso Janashvili (iDox Digital Marketing, Saban Marketing, One Marketing)
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
        add_action('wp_ajax_limy_test_ping', array($this, 'ajax_test_ping'));

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
            'sanitize_callback' => array($this, 'encrypt_api_key'),
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
     * Get encryption key derived from WordPress security salts.
     */
    private function get_encryption_key() {
        $salt = defined('AUTH_KEY') ? AUTH_KEY : 'limy_default_fallback_salt_2026';
        return hash('sha256', $salt, true);
    }

    /**
     * Encrypt API Key before saving to wp_options (AES-256-CBC).
     */
    public function encrypt_api_key($value) {
        $value = sanitize_text_field(trim($value));
        if (empty($value)) {
            return '';
        }
        if (strpos($value, 'enc:') === 0) {
            return $value;
        }

        $key = $this->get_encryption_key();
        $iv  = openssl_random_pseudo_bytes(16);
        $ciphertext = openssl_encrypt($value, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);

        if ($ciphertext === false) {
            return $value;
        }

        return 'enc:' . base64_encode($iv . $ciphertext);
    }

    /**
     * Decrypt API Key when retrieving from wp_options.
     */
    public function decrypt_api_key($value) {
        if (empty($value)) {
            return '';
        }
        if (strpos($value, 'enc:') !== 0) {
            return $value; // Legacy unencrypted key fallback
        }

        $raw = base64_decode(substr($value, 4));
        if (strlen($raw) < 17) {
            return '';
        }

        $iv         = substr($raw, 0, 16);
        $ciphertext = substr($raw, 16);
        $key        = $this->get_encryption_key();

        $decrypted = openssl_decrypt($ciphertext, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv);
        return $decrypted !== false ? $decrypted : '';
    }

    /**
     * Mask API Key for display preview.
     */
    public function mask_api_key($key) {
        $key = trim($key);
        if (empty($key)) {
            return '';
        }
        $len = strlen($key);
        if ($len <= 8) {
            return '••••••••';
        }
        return substr($key, 0, 4) . '••••••••••••••••' . substr($key, -4);
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

        $api_key = $this->decrypt_api_key(get_option('limy_api_key', ''));
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

        $raw_api_key   = $this->decrypt_api_key(get_option('limy_api_key', ''));
        $masked_key    = $this->mask_api_key($raw_api_key);
        $enabled       = get_option('limy_enabled', 1);
        $exclude_admin = get_option('limy_exclude_admin', 1);
        $exclude_cron  = get_option('limy_exclude_cron', 1);
        $auto_update   = get_option('limy_auto_update', 1);
        $is_active     = $enabled && !empty($raw_api_key);
        ?>
        <div class="wrap limy-admin-wrap">
            <style>
                .limy-admin-wrap {
                    max-width: 1100px;
                    margin: 20px 20px 40px 0;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
                }
                .limy-header {
                    background: linear-gradient(135deg, #0F172A 0%, #1E293B 100%);
                    border-radius: 16px;
                    padding: 24px 32px;
                    color: #FFFFFF;
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.3);
                    margin-bottom: 24px;
                }
                .limy-brand {
                    display: flex;
                    align-items: center;
                    gap: 16px;
                }
                .limy-title-group h1 {
                    color: #FFFFFF !important;
                    font-size: 24px;
                    font-weight: 700;
                    margin: 0 0 4px 0 !important;
                    padding: 0 !important;
                    display: flex;
                    align-items: center;
                    gap: 10px;
                }
                .limy-subtitle {
                    color: #94A3B8;
                    font-size: 13px;
                    margin: 0;
                }
                .limy-status-badge {
                    display: inline-flex;
                    align-items: center;
                    gap: 8px;
                    padding: 6px 14px;
                    border-radius: 20px;
                    font-size: 12px;
                    font-weight: 600;
                    white-space: nowrap;
                    flex-shrink: 0;
                }
                .limy-status-active {
                    background: rgba(0, 201, 80, 0.15);
                    color: #00C950;
                    border: 1px solid rgba(0, 201, 80, 0.3);
                }
                .limy-status-inactive {
                    background: rgba(245, 158, 11, 0.15);
                    color: #F59E0B;
                    border: 1px solid rgba(245, 158, 11, 0.3);
                }
                .limy-dot {
                    width: 8px;
                    height: 8px;
                    border-radius: 50%;
                    background: currentColor;
                    box-shadow: 0 0 8px currentColor;
                }
                .limy-grid {
                    display: grid;
                    grid-template-columns: 1fr 340px;
                    gap: 24px;
                }
                @media (max-width: 900px) {
                    .limy-grid { grid-template-columns: 1fr; }
                }
                .limy-card {
                    background: #FFFFFF;
                    border-radius: 14px;
                    padding: 24px 28px;
                    border: 1px solid #E2E8F0;
                    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.05);
                    margin-bottom: 24px;
                }
                .limy-card h2 {
                    font-size: 16px;
                    font-weight: 700;
                    color: #0F172A;
                    margin: 0 0 16px 0 !important;
                    padding-bottom: 12px;
                    border-bottom: 1px solid #F1F5F9;
                    display: flex;
                    align-items: center;
                    gap: 8px;
                }
                .limy-field-group {
                    margin-bottom: 20px;
                }
                .limy-field-group label {
                    display: block;
                    font-weight: 600;
                    color: #334155;
                    font-size: 13px;
                    margin-bottom: 6px;
                }
                .limy-input-wrap {
                    display: flex;
                    gap: 8px;
                }
                .limy-input-wrap input[type="password"],
                .limy-input-wrap input[type="text"] {
                    flex: 1;
                    padding: 8px 12px;
                    border: 1px solid #CBD5E1;
                    border-radius: 8px;
                    font-size: 14px;
                    background: #F8FAFC;
                    transition: all 0.2s;
                }
                .limy-input-wrap input:focus {
                    border-color: #346DDB;
                    background: #FFFFFF;
                    box-shadow: 0 0 0 3px rgba(52, 109, 219, 0.15);
                    outline: none;
                }
                .limy-btn-btn {
                    padding: 8px 14px;
                    border-radius: 8px;
                    font-size: 13px;
                    font-weight: 600;
                    border: 1px solid #CBD5E1;
                    background: #F1F5F9;
                    color: #334155;
                    cursor: pointer;
                    transition: all 0.2s;
                }
                .limy-btn-btn:hover {
                    background: #E2E8F0;
                    color: #0F172A;
                }
                .limy-toggle-row {
                    display: flex;
                    align-items: center;
                    justify-content: space-between;
                    padding: 12px 0;
                    border-bottom: 1px solid #F8FAFC;
                }
                .limy-toggle-row:last-child { border-bottom: none; }
                .limy-toggle-info { flex: 1; padding-right: 16px; }
                .limy-toggle-title { font-weight: 600; color: #1E293B; font-size: 13px; }
                .limy-toggle-desc { font-size: 12px; color: #64748B; margin-top: 2px; }
                
                /* Switch Toggle */
                .limy-switch {
                    position: relative;
                    display: inline-block;
                    width: 44px;
                    height: 24px;
                    flex-shrink: 0;
                }
                .limy-switch input { opacity: 0; width: 0; height: 0; }
                .limy-slider {
                    position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0;
                    background-color: #CBD5E1; transition: .3s; border-radius: 24px;
                }
                .limy-slider:before {
                    position: absolute; content: ""; height: 18px; width: 18px; left: 3px; bottom: 3px;
                    background-color: white; transition: .3s; border-radius: 50%;
                    box-shadow: 0 2px 4px rgba(0,0,0,0.2);
                }
                input:checked + .limy-slider { background-color: #346DDB; }
                input:checked + .limy-slider:before { transform: translateX(20px); }
                
                .limy-submit-btn {
                    background: linear-gradient(135deg, #346DDB 0%, #1D4ED8 100%);
                    color: #FFFFFF !important;
                    border: none !important;
                    padding: 10px 24px !important;
                    font-size: 14px !important;
                    font-weight: 600 !important;
                    border-radius: 8px !important;
                    cursor: pointer;
                    box-shadow: 0 4px 12px rgba(52, 109, 219, 0.3);
                    transition: all 0.2s;
                }
                .limy-submit-btn:hover {
                    box-shadow: 0 6px 16px rgba(52, 109, 219, 0.4);
                    transform: translateY(-1px);
                }
                .limy-side-btn {
                    width: 100%;
                    text-align: center;
                    padding: 10px 16px;
                    border-radius: 8px;
                    font-weight: 600;
                    font-size: 13px;
                    text-decoration: none;
                    display: inline-block;
                    box-sizing: border-box;
                    transition: all 0.2s;
                }
                .limy-side-primary {
                    background: #0F172A; color: #FFFFFF !important; border: 1px solid #0F172A;
                }
                .limy-side-primary:hover { background: #1E293B; }
                .limy-side-secondary {
                    background: #F1F5F9; color: #334155 !important; border: 1px solid #CBD5E1;
                }
                .limy-side-secondary:hover { background: #E2E8F0; }
                .limy-credits-list {
                    list-style: none; padding: 0; margin: 0; font-size: 13px; color: #475569;
                }
                .limy-credits-list li { margin-bottom: 8px; display: flex; align-items: center; gap: 8px; }
                .limy-credits-list a { color: #346DDB; text-decoration: none; font-weight: 600; }
                .limy-credits-list a:hover { text-decoration: underline; }
            </style>

            <div class="limy-header">
                <div class="limy-brand">
                    <svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 256 256" width="48" height="48" style="border-radius:12px;">
                        <defs>
                            <linearGradient id="limy-g-hdr" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#346DDB" />
                                <stop offset="100%" stop-color="#00C950" />
                            </linearGradient>
                            <linearGradient id="bg-g-hdr" x1="0%" y1="0%" x2="100%" y2="100%">
                                <stop offset="0%" stop-color="#0F172A" />
                                <stop offset="100%" stop-color="#020617" />
                            </linearGradient>
                        </defs>
                        <rect width="256" height="256" rx="56" fill="url(#bg-g-hdr)" />
                        <path d="M 72 64 L 72 176 L 184 176 C 193 176 193 160 184 160 L 96 160 L 96 64 C 96 55 72 55 72 64 Z" fill="url(#limy-g-hdr)" />
                        <circle cx="184" cy="80" r="14" fill="url(#limy-g-hdr)" />
                    </svg>
                    <div class="limy-title-group">
                        <h1>Limy AI Logger <span style="font-size:12px;font-weight:400;color:#64748B;background:#1E293B;padding:2px 8px;border-radius:12px;white-space:nowrap;">v1.1.0</span></h1>
                        <p class="limy-subtitle"><?php esc_html_e('Custom log shipping integration for Limy.ai AI visibility statistics', 'limy-ai-logger'); ?></p>
                    </div>
                </div>
                <div>
                    <?php if ($is_active): ?>
                        <span class="limy-status-badge limy-status-active">
                            <span class="limy-dot"></span> <?php esc_html_e('Shipping Active', 'limy-ai-logger'); ?>
                        </span>
                    <?php else: ?>
                        <span class="limy-status-badge limy-status-inactive">
                            <span class="limy-dot"></span> <?php esc_html_e('API Key Required', 'limy-ai-logger'); ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>

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

            <div class="limy-grid">
                <!-- Main Form Column -->
                <div>
                    <form method="post" action="options.php">
                        <?php
                        settings_fields('limy_ai_logger_group');
                        do_settings_sections('limy_ai_logger_group');
                        ?>
                        
                        <div class="limy-card">
                            <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid #F1F5F9;padding-bottom:12px;margin-bottom:16px;">
                                <h2 style="border-bottom:none;padding-bottom:0;margin-bottom:0 !important;">🔑 <?php esc_html_e('API Configuration', 'limy-ai-logger'); ?></h2>
                                <span style="font-size:11px;font-weight:600;color:#00C950;background:rgba(0,201,80,0.12);padding:4px 10px;border-radius:12px;border:1px solid rgba(0,201,80,0.3);">
                                    🔒 <?php esc_html_e('AES-256-CBC Encrypted', 'limy-ai-logger'); ?>
                                </span>
                            </div>
                            <div class="limy-field-group">
                                <label for="limy_api_key"><?php esc_html_e('Limy API Key', 'limy-ai-logger'); ?></label>
                                <div class="limy-input-wrap">
                                    <input type="password" id="limy_api_key" name="limy_api_key" value="<?php echo esc_attr($raw_api_key); ?>" placeholder="lmy_xxxxxxxxxxxxxxxxxxxx" autocomplete="off" />
                                </div>
                                <?php if (!empty($raw_api_key)): ?>
                                    <p style="margin-top:8px;font-size:12px;color:#334155;font-weight:600;">
                                        <?php esc_html_e('Active Key:', 'limy-ai-logger'); ?> 
                                        <code style="background:#F1F5F9;padding:2px 8px;border-radius:4px;color:#0F172A;"><?php echo esc_html($masked_key); ?></code>
                                    </p>
                                <?php endif; ?>
                                <p class="description" style="margin-top:6px;font-size:12px;color:#64748B;">
                                    <?php esc_html_e('Your secret Limy API Key starting with lmy_. Encrypted at rest in your database with AES-256-CBC.', 'limy-ai-logger'); ?>
                                </p>
                            </div>
                        </div>

                        <div class="limy-card">
                            <h2>⚙️ <?php esc_html_e('Logging & Filter Rules', 'limy-ai-logger'); ?></h2>

                            <div class="limy-toggle-row">
                                <div class="limy-toggle-info">
                                    <div class="limy-toggle-title"><?php esc_html_e('Enable Log Shipping', 'limy-ai-logger'); ?></div>
                                    <div class="limy-toggle-desc"><?php esc_html_e('Enable or disable sending HTTP access logs to Limy.ai stream endpoint.', 'limy-ai-logger'); ?></div>
                                </div>
                                <label class="limy-switch">
                                    <input type="checkbox" name="limy_enabled" value="1" <?php checked(1, $enabled); ?> />
                                    <span class="limy-slider"></span>
                                </label>
                            </div>

                            <div class="limy-toggle-row">
                                <div class="limy-toggle-info">
                                    <div class="limy-toggle-title"><?php esc_html_e('Exclude WP Admin Requests', 'limy-ai-logger'); ?></div>
                                    <div class="limy-toggle-desc"><?php esc_html_e('Do not ship logs for requests inside /wp-admin/ or logged-in administrators.', 'limy-ai-logger'); ?></div>
                                </div>
                                <label class="limy-switch">
                                    <input type="checkbox" name="limy_exclude_admin" value="1" <?php checked(1, $exclude_admin); ?> />
                                    <span class="limy-slider"></span>
                                </label>
                            </div>

                            <div class="limy-toggle-row">
                                <div class="limy-toggle-info">
                                    <div class="limy-toggle-title"><?php esc_html_e('Exclude WP-Cron & CLI', 'limy-ai-logger'); ?></div>
                                    <div class="limy-toggle-desc"><?php esc_html_e('Do not ship logs for background WP-Cron jobs or WP-CLI executions.', 'limy-ai-logger'); ?></div>
                                </div>
                                <label class="limy-switch">
                                    <input type="checkbox" name="limy_exclude_cron" value="1" <?php checked(1, $exclude_cron); ?> />
                                    <span class="limy-slider"></span>
                                </label>
                            </div>

                            <div class="limy-toggle-row">
                                <div class="limy-toggle-info">
                                    <div class="limy-toggle-title"><?php esc_html_e('Background Auto-Updates', 'limy-ai-logger'); ?></div>
                                    <div class="limy-toggle-desc"><?php esc_html_e('Automatically install new releases published on GitHub in the background.', 'limy-ai-logger'); ?></div>
                                </div>
                                <label class="limy-switch">
                                    <input type="checkbox" name="limy_auto_update" value="1" <?php checked(1, $auto_update); ?> />
                                    <span class="limy-slider"></span>
                                </label>
                            </div>
                        </div>

                        <div style="margin-top:20px;">
                            <input type="submit" class="limy-submit-btn" value="<?php esc_attr_e('Save Changes', 'limy-ai-logger'); ?>" />
                        </div>
                    </form>
                </div>

                <!-- Sidebar Column -->
                <div>
                    <div class="limy-card">
                        <h2>🧪 <?php esc_html_e('Test Connection', 'limy-ai-logger'); ?></h2>
                        <p style="font-size:13px;color:#64748B;margin-top:0;margin-bottom:16px;">
                            <?php esc_html_e('Send a live test ping to verify your API Key with Limy.ai.', 'limy-ai-logger'); ?>
                        </p>
                        <div id="limy-test-result" style="display:none;margin-bottom:12px;padding:10px 14px;border-radius:8px;font-size:12px;font-weight:600;"></div>
                        <button type="button" id="limy-test-ping-btn" class="limy-side-btn limy-side-secondary" <?php disabled(empty($api_key)); ?>>
                            <?php esc_html_e('Send Test Ping', 'limy-ai-logger'); ?>
                        </button>
                    </div>

                    <script>
                    document.getElementById('limy-test-ping-btn').addEventListener('click', function(e) {
                        e.preventDefault();
                        var btn = this;
                        var resDiv = document.getElementById('limy-test-result');
                        btn.disabled = true;
                        btn.textContent = '⏳ Sending Ping...';
                        resDiv.style.display = 'none';

                        var data = new FormData();
                        data.append('action', 'limy_test_ping');
                        data.append('nonce', '<?php echo wp_create_nonce('limy_test_connection_nonce'); ?>');

                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            body: data
                        })
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            btn.disabled = false;
                            btn.textContent = '<?php esc_html_e('Send Test Ping', 'limy-ai-logger'); ?>';
                            resDiv.style.display = 'block';
                            if (res.success) {
                                resDiv.style.background = 'rgba(0, 201, 80, 0.15)';
                                resDiv.style.color = '#00C950';
                                resDiv.style.border = '1px solid rgba(0, 201, 80, 0.3)';
                                resDiv.innerHTML = '<strong>✅ Success!</strong> ' + res.data.message;
                            } else {
                                resDiv.style.background = 'rgba(239, 68, 68, 0.15)';
                                resDiv.style.color = '#EF4444';
                                resDiv.style.border = '1px solid rgba(239, 68, 68, 0.3)';
                                resDiv.innerHTML = '<strong>❌ Failed:</strong> ' + (res.data ? res.data.message : 'Error');
                            }
                        })
                        .catch(function(err){
                            btn.disabled = false;
                            btn.textContent = '<?php esc_html_e('Send Test Ping', 'limy-ai-logger'); ?>';
                            resDiv.style.display = 'block';
                            resDiv.style.background = 'rgba(239, 68, 68, 0.15)';
                            resDiv.style.color = '#EF4444';
                            resDiv.style.border = '1px solid rgba(239, 68, 68, 0.3)';
                            resDiv.innerHTML = '<strong>❌ Error:</strong> Network request failed';
                        });
                    });
                    </script>

                    <div class="limy-card">
                        <h2>🔄 <?php esc_html_e('GitHub Updates', 'limy-ai-logger'); ?></h2>
                        <p style="font-size:13px;color:#64748B;margin-top:0;margin-bottom:16px;">
                            <?php esc_html_e('Check GitHub Releases for latest plugin updates.', 'limy-ai-logger'); ?>
                        </p>
                        <div id="limy-update-check-result" style="display:none;margin-bottom:12px;padding:10px 14px;border-radius:8px;font-size:12px;font-weight:600;"></div>
                        <button type="button" id="limy-check-updates-btn" class="limy-side-btn limy-side-primary">
                            <?php esc_html_e('Check Updates Now', 'limy-ai-logger'); ?>
                        </button>
                    </div>

                    <script>
                    document.getElementById('limy-check-updates-btn').addEventListener('click', function(e) {
                        e.preventDefault();
                        var btn = this;
                        var resDiv = document.getElementById('limy-update-check-result');
                        btn.disabled = true;
                        btn.textContent = '⏳ Checking GitHub...';
                        resDiv.style.display = 'none';

                        var data = new FormData();
                        data.append('action', 'limy_check_github_updates');
                        data.append('nonce', '<?php echo wp_create_nonce('limy_check_updates_nonce'); ?>');

                        fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                            method: 'POST',
                            body: data
                        })
                        .then(function(r){ return r.json(); })
                        .then(function(res){
                            btn.disabled = false;
                            btn.textContent = '<?php esc_html_e('Check Updates Now', 'limy-ai-logger'); ?>';
                            resDiv.style.display = 'block';
                            if (res.success && res.data.has_update) {
                                resDiv.style.background = 'rgba(0, 201, 80, 0.15)';
                                resDiv.style.color = '#00C950';
                                resDiv.style.border = '1px solid rgba(0, 201, 80, 0.3)';
                                resDiv.innerHTML = '🎉 <strong>Update Available (v' + res.data.remote_version + ')!</strong><br><button type="button" id="limy-do-update-now-btn" class="limy-submit-btn" style="margin-top:8px;width:100%;text-align:center;">⚡ Install Update v' + res.data.remote_version + ' Now</button>';
                                
                                document.getElementById('limy-do-update-now-btn').addEventListener('click', function(evt) {
                                    evt.preventDefault();
                                    var upgBtn = this;
                                    upgBtn.disabled = true;
                                    upgBtn.textContent = '⏳ Installing Update...';

                                    var upgData = new FormData();
                                    upgData.append('action', 'limy_do_one_click_update');
                                    upgData.append('nonce', '<?php echo wp_create_nonce('limy_check_updates_nonce'); ?>');

                                    fetch('<?php echo admin_url('admin-ajax.php'); ?>', {
                                        method: 'POST',
                                        body: upgData
                                    })
                                    .then(function(r){ return r.json(); })
                                    .then(function(upgRes){
                                        if (upgRes.success) {
                                            resDiv.innerHTML = '✅ <strong>Updated successfully!</strong> Reloading...';
                                            setTimeout(function(){ location.reload(); }, 1000);
                                        } else {
                                            upgBtn.disabled = false;
                                            upgBtn.textContent = '⚡ Try Again';
                                            alert('Update error: ' + (upgRes.data ? upgRes.data.message : 'Failed'));
                                        }
                                    });
                                });
                            } else if (res.success) {
                                resDiv.style.background = 'rgba(52, 109, 219, 0.15)';
                                resDiv.style.color = '#346DDB';
                                resDiv.style.border = '1px solid rgba(52, 109, 219, 0.3)';
                                resDiv.innerHTML = '✅ <strong>Up to date!</strong> Running version ' + res.data.current_version;
                            } else {
                                resDiv.style.background = 'rgba(239, 68, 68, 0.15)';
                                resDiv.style.color = '#EF4444';
                                resDiv.style.border = '1px solid rgba(239, 68, 68, 0.3)';
                                resDiv.innerHTML = '❌ ' + (res.data ? res.data.message : 'Error checking GitHub');
                            }
                        })
                        .catch(function(err){
                            btn.disabled = false;
                            btn.textContent = '<?php esc_html_e('Check Updates Now', 'limy-ai-logger'); ?>';
                            resDiv.style.display = 'block';
                            resDiv.style.background = 'rgba(239, 68, 68, 0.15)';
                            resDiv.style.color = '#EF4444';
                            resDiv.style.border = '1px solid rgba(239, 68, 68, 0.3)';
                            resDiv.innerHTML = '❌ Network request failed';
                        });
                    });
                    </script>

                    <div class="limy-card">
                        <h2>👨‍💻 <?php esc_html_e('Developer & Credits', 'limy-ai-logger'); ?></h2>
                        <ul class="limy-credits-list">
                            <li>
                                👤 <strong>Soso Janashvili</strong> 
                                (<a href="https://www.instagram.com/soso_janashvili/" target="_blank" rel="noopener">Instagram</a>)
                            </li>
                            <li>
                                🚀 <a href="https://idox.co.il" target="_blank" rel="noopener">iDox Digital Marketing</a>
                            </li>
                            <li>
                                💼 <a href="https://saban.marketing/" target="_blank" rel="noopener">Saban Marketing</a>
                            </li>
                            <li>
                                ⚡ <a href="https://one1.co.il" target="_blank" rel="noopener">One Marketing</a>
                            </li>
                        </ul>
                    </div>
                </div>
            </div>
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
        $api_key = trim($this->decrypt_api_key(get_option('limy_api_key', '')));
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
        $api_key = trim($this->decrypt_api_key(get_option('limy_api_key', '')));
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

    /**
     * AJAX handler for instant non-refreshing test ping.
     */
    public function ajax_test_ping() {
        check_ajax_referer('limy_test_connection_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'limy-ai-logger')));
        }

        $result = $this->send_test_log();
        if (!empty($result['success'])) {
            wp_send_json_success($result);
        } else {
            wp_send_json_error($result);
        }
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
        add_action('wp_ajax_limy_check_github_updates', array($this, 'ajax_check_updates'));
        add_action('wp_ajax_limy_do_one_click_update', array($this, 'ajax_do_one_click_update'));
    }

    public function ajax_do_one_click_update() {
        check_ajax_referer('limy_check_updates_nonce', 'nonce');
        if (!current_user_can('update_plugins')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'limy-ai-logger')));
        }

        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/plugin.php';

        delete_site_transient('update_plugins');
        delete_transient('limy_github_release_' . md5($this->github_repo));

        $update_data = $this->check_update(get_site_transient('update_plugins'));
        set_site_transient('update_plugins', $update_data);

        $skin = new Automatic_Upgrader_Skin();
        $upgrader = new Plugin_Upgrader($skin);
        $result = $upgrader->upgrade($this->plugin);

        if (is_wp_error($result)) {
            wp_send_json_error(array('message' => $result->get_error_message()));
        } elseif ($result === false) {
            wp_send_json_error(array('message' => __('Plugin upgrade failed.', 'limy-ai-logger')));
        } else {
            if (function_exists('activate_plugin')) {
                activate_plugin($this->plugin);
            }
            wp_send_json_success(array('message' => __('Plugin updated successfully!', 'limy-ai-logger')));
        }
    }

    public function ajax_check_updates() {
        check_ajax_referer('limy_check_updates_nonce', 'nonce');
        if (!current_user_can('manage_options')) {
            wp_send_json_error(array('message' => __('Permission denied.', 'limy-ai-logger')));
        }

        delete_site_transient('update_plugins');
        delete_transient('limy_github_release_' . md5($this->github_repo));

        $release = $this->get_github_release_info();
        if (!$release) {
            wp_send_json_error(array('message' => __('Could not fetch GitHub releases.', 'limy-ai-logger')));
        }

        $current_version = $this->get_plugin_version();
        $tag_name        = $release['tag_name'];
        $remote_version  = $this->parse_version($tag_name);
        $has_update      = version_compare($remote_version, $current_version, '>');

        wp_send_json_success(array(
            'has_update'      => $has_update,
            'current_version' => $current_version,
            'remote_version'  => $remote_version,
            'tag_name'        => $tag_name,
        ));
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
            return;
        }

        $current_version = $this->get_plugin_version();
        $tag_name        = $release['tag_name'];
        $remote_version  = $this->parse_version($tag_name);

        if (version_compare($remote_version, $current_version, '>')) {
            echo '<div class="notice notice-success is-dismissible"><p>';
            printf(
                __('🎉 <strong>Limy AI Logger: Update Available!</strong> Version <code>%s</code> is available on GitHub. Installed version is <code>%s</code>. <a href="%s" class="button button-primary" style="margin-left:8px;">Update Now in WP Admin</a>', 'limy-ai-logger'),
                esc_html($remote_version),
                esc_html($current_version),
                esc_url(admin_url('update-core.php'))
            );
            echo '</p></div>';
        } else {
            echo '<div class="notice notice-info is-dismissible"><p>';
            printf(
                __('✅ <strong>Limy AI Logger:</strong> You are on the latest version (<code>%s</code>).', 'limy-ai-logger'),
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
        $res->tested         = '6.8';
        $res->last_updated   = $release['published_at'];
        $res->sections       = array(
            'description' => $plugin_data['Description'],
            'changelog'   => !empty($release['body']) ? esc_html($release['body']) : 'See GitHub releases for details.',
        );

        return $res;
    }

    public function post_install($true, $hook_extra, $result) {
        if (empty($hook_extra['plugin']) || $hook_extra['plugin'] !== $this->plugin) {
            return $result;
        }

        global $wp_filesystem;
        if (!$wp_filesystem) {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            WP_Filesystem();
        }

        $proper_destination  = WP_PLUGIN_DIR . '/' . $this->slug;
        $current_destination = isset($result['destination']) ? rtrim($result['destination'], '/') : '';

        if ($current_destination && $wp_filesystem->is_dir($current_destination . '/' . $this->slug)) {
            $nested_dir = $current_destination . '/' . $this->slug;
            $temp_dir   = WP_PLUGIN_DIR . '/' . $this->slug . '_temp_' . time();
            $wp_filesystem->move($nested_dir, $temp_dir);
            $wp_filesystem->delete($current_destination, true);
            $wp_filesystem->move($temp_dir, $proper_destination);
            $result['destination'] = $proper_destination;
        } else if ($current_destination && $current_destination !== $proper_destination) {
            if ($wp_filesystem->is_dir($proper_destination)) {
                $wp_filesystem->delete($proper_destination, true);
            }
            $wp_filesystem->move($current_destination, $proper_destination);
            $result['destination'] = $proper_destination;
        }

        if (function_exists('activate_plugin') && file_exists(WP_PLUGIN_DIR . '/' . $this->plugin)) {
            activate_plugin($this->plugin);
        }

        return $result;
    }
}

// Initialize plugin
Limy_AI_Logger::get_instance();

