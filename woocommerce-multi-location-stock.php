<?php
/**
 * Plugin Name: WooCommerce Multi-Location Stock
 * Plugin URI: https://biznesonay.kz/
 * Description: Управление складом по локациям для WooCommerce с ролью Менеджер Локации
 * Version: 2.0.1
 * Author: BiznesOnay
 * Text Domain: wc-multi-location-stock
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 9.8.5
 * WC tested up to: 9.8.5
 * 
 * Changelog:
 * 2.0.1 - Fixed infinite jQuery loading on checkout page
 * 2.0.0 - Added automatic city ID generation from name
 * 1.7.0 - Added automatic city sync on checkout, stock sync on order completion
 * 1.6.3 - Fixed location selection and JS errors
 * 1.6.2 - Added total stock synchronization functionality
 */

defined('ABSPATH') || exit;

if (!defined('WCMLS_PLUGIN_FILE')) {
    define('WCMLS_PLUGIN_FILE', __FILE__);
}

/**
 * Main plugin class
 */
class WC_Multi_Location_Stock {
    
    private static $instance = null;
    private $locations = [];
    
    public static function instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        $this->define_constants();
        $this->includes();
        $this->init_hooks();
    }
    
    private function define_constants() {
        define('WCMLS_VERSION', '2.0.1');
        define('WCMLS_PLUGIN_URL', plugin_dir_url(__FILE__));
        define('WCMLS_PLUGIN_PATH', plugin_dir_path(__FILE__));
        
        // Define debug constant based on option
        if (!defined('WCMLS_DEBUG')) {
            define('WCMLS_DEBUG', get_option('wcmls_debug_mode', false));
        }
    }
    
    private function includes() {
        // Include necessary files when needed
    }
    
    private function init_hooks() {
        register_activation_hook(__FILE__, [$this, 'activate']);
        register_deactivation_hook(__FILE__, [$this, 'deactivate']);
        
        add_action('init', [$this, 'init']);
        add_action('admin_menu', [$this, 'add_admin_menu'], 99);
        add_action('admin_init', [$this, 'restrict_admin_access']);
        add_filter('woocommerce_product_get_stock_quantity', [$this, 'get_location_stock'], 10, 2);
        add_filter('woocommerce_product_is_in_stock', [$this, 'check_location_stock'], 10, 2);
        add_action('woocommerce_check_cart_items', [$this, 'validate_cart_location']);
        add_action('woocommerce_checkout_update_order_meta', [$this, 'process_order_stock']);
        add_action('woocommerce_order_status_cancelled', [$this, 'restore_order_stock']);
        add_action('woocommerce_order_status_refunded', [$this, 'restore_order_stock']);
        add_action('woocommerce_order_status_completed', [$this, 'sync_stock_on_order_complete']);
        add_action('woocommerce_order_status_processing', [$this, 'sync_stock_on_order_complete']);
        add_action('wp_enqueue_scripts', [$this, 'enqueue_scripts']);
        add_action('admin_enqueue_scripts', [$this, 'admin_enqueue_scripts']);
        add_shortcode('location_selector', [$this, 'location_selector_shortcode']);
        
        // HPOS compatibility
        add_action('before_woocommerce_init', function() {
            if (class_exists('\Automattic\WooCommerce\Utilities\FeaturesUtil')) {
                \Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true);
            }
        });
        
        // Frontend filters
        add_filter('woocommerce_product_query_meta_query', [$this, 'filter_products_by_location_stock']);
        add_filter('woocommerce_billing_fields', [$this, 'customize_billing_city_field']);
        add_filter('woocommerce_checkout_get_value', [$this, 'set_checkout_city_value'], 10, 2);
        add_action('woocommerce_checkout_init', [$this, 'init_checkout_city']);
        add_action('woocommerce_after_checkout_validation', [$this, 'validate_checkout_location'], 10, 2);
        add_filter('woocommerce_checkout_fields', [$this, 'make_city_field_required']);
        
        // Ajax handlers
        add_action('wp_ajax_wcmls_set_location', [$this, 'ajax_set_location']);
        add_action('wp_ajax_nopriv_wcmls_set_location', [$this, 'ajax_set_location']);
        add_action('wp_ajax_wcmls_update_stock', [$this, 'ajax_update_stock']);
        
        // Order filters for location managers
        add_action('pre_get_posts', [$this, 'filter_orders_for_location_manager']);
        add_filter('views_edit-shop_order', [$this, 'filter_order_views']);
        add_filter('woocommerce_shop_order_list_table_prepare_items_query_args', [$this, 'filter_hpos_orders_query']);
        
        // Show Orders menu for location managers
        add_action('admin_menu', [$this, 'show_orders_menu_for_location_manager'], 100);
        
        // Initialize location stock for new products
        add_action('woocommerce_new_product', [$this, 'init_stock_for_product']);
        add_action('woocommerce_update_product', [$this, 'maybe_init_stock_for_product']);
    }
    
    public function activate() {
        $this->create_location_manager_role();
        $this->update_existing_location_managers();
        $this->create_tables();
        
        // Set default options
        if (!get_option('wcmls_locations')) {
            update_option('wcmls_locations', []);
        }
        
        // Initialize locations
        $this->locations = get_option('wcmls_locations', []);
        
        // Check for plugin updates
        $this->check_plugin_updates();
        
        flush_rewrite_rules();
    }
    
    private function check_plugin_updates() {
        $current_version = get_option('wcmls_version', '0');
        
        if (version_compare($current_version, WCMLS_VERSION, '<')) {
            // Run updates if needed
            if (version_compare($current_version, '1.6.0', '<')) {
                // Recreate tables with new structure
                $this->create_tables();
            }
            
            if (version_compare($current_version, '1.6.1', '<')) {
                // Ensure table exists
                $this->create_tables();
            }
            
            if (version_compare($current_version, '1.6.2', '<')) {
                // Sync all product stocks
                $this->sync_all_products_stock();
            }
            
            if (version_compare($current_version, '1.6.3', '<')) {
                // Ensure checkout city sync is working
                flush_rewrite_rules();
            }
            
            if (version_compare($current_version, '1.7.0', '<')) {
                // Clear any cached checkout data
                if (WC()->session) {
                    WC()->session->set('billing_city', null);
                }
            }
            
            if (version_compare($current_version, '2.0.1', '<')) {
                // Force JS file recreation to fix infinite loading
                $js_file = WCMLS_PLUGIN_PATH . 'assets/js/frontend.js';
                if (file_exists($js_file)) {
                    @unlink($js_file);
                }
            }
            
            // Update version
            update_option('wcmls_version', WCMLS_VERSION);
        }
    }
    
    private function update_existing_location_managers() {
        // Update capabilities for existing location managers
        $role = get_role('location_manager');
        if ($role) {
            // Remove capabilities
            $role->remove_cap('view_admin_dashboard');
            $role->remove_cap('upload_files');
            $role->remove_cap('publish_products');
            $role->remove_cap('edit_published_products');
            $role->remove_cap('manage_product_terms');
            $role->remove_cap('edit_product_terms');
            $role->remove_cap('assign_product_terms');
            
            // Add necessary capabilities
            $role->add_cap('woocommerce_view_order');
            $role->add_cap('woocommerce_edit_order');
        }
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    private function create_location_manager_role() {
        $capabilities = [
            'read' => true,
            'edit_shop_orders' => true,
            'read_shop_order' => true,
            'view_admin_dashboard' => false,
            'manage_woocommerce' => false,
            'view_woocommerce_reports' => false,
            'edit_products' => true, // Needed for stock management
            'edit_product' => true,
            'read_product' => true,
            'delete_product' => false,
            'edit_others_products' => false,
            'publish_products' => false,
            'read_private_products' => true,
            'delete_products' => false,
            'delete_private_products' => false,
            'delete_published_products' => false,
            'delete_others_products' => false,
            'edit_private_products' => false,
            'edit_published_products' => false,
            'manage_product_terms' => false,
            'edit_product_terms' => false,
            'delete_product_terms' => false,
            'assign_product_terms' => false,
            'upload_files' => false,
            // Additional WooCommerce capabilities for orders
            'woocommerce_view_order' => true,
            'woocommerce_edit_order' => true,
        ];
        
        add_role('location_manager', __('Менеджер Локации', 'wc-multi-location-stock'), $capabilities);
    }
    
    private function create_tables() {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wcmls_location_stock';
        $charset_collate = $wpdb->get_charset_collate();
        
        // Drop and recreate if structure changed
        $current_version = get_option('wcmls_db_version', '0');
        
        $sql = "CREATE TABLE IF NOT EXISTS {$table_name} (
            id int(11) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            location_id varchar(50) NOT NULL,
            stock_quantity int(11) NOT NULL DEFAULT 0,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY product_location (product_id, location_id),
            KEY product_id (product_id),
            KEY location_id (location_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
        
        // Update DB version
        update_option('wcmls_db_version', '1.1');
        
        // Verify table was created
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name;
        
        if (!$table_exists) {
            error_log('WCMLS Error: Failed to create table ' . $table_name);
            
            // Try alternative creation method
            $wpdb->query($sql);
        }
    }
    
    public function init() {
        load_plugin_textdomain('wc-multi-location-stock', false, dirname(plugin_basename(__FILE__)) . '/languages');
        
        // Load locations from options
        $this->locations = get_option('wcmls_locations', []);
        
        // Check for plugin updates
        $this->check_plugin_updates();
        
        // Debug logging
        if (defined('WCMLS_DEBUG') && WCMLS_DEBUG && empty($this->locations)) {
            error_log('WCMLS: No locations defined');
        }
    }
    
    public function add_admin_menu() {
        // Main settings page - only for admins
        if (current_user_can('manage_woocommerce')) {
            add_submenu_page(
                'woocommerce',
                __('Локации', 'wc-multi-location-stock'),
                __('Локации', 'wc-multi-location-stock'),
                'manage_woocommerce',
                'wcmls-settings',
                [$this, 'settings_page']
            );
        }
        
        // Stock management page - for admins and location managers
        if (current_user_can('edit_products') || current_user_can('location_manager')) {
            add_submenu_page(
                'woocommerce',
                __('Склад', 'wc-multi-location-stock'),
                __('Склад', 'wc-multi-location-stock'),
                'edit_products',
                'wcmls-stock',
                [$this, 'stock_management_page']
            );
        }
    }
    
    public function show_orders_menu_for_location_manager() {
        if (current_user_can('location_manager')) {
            global $menu, $submenu;
            
            // Show WooCommerce menu
            if (isset($menu['55.5'])) {
                $menu['55.5'][1] = 'edit_shop_orders';
            }
            
            // Show Orders submenu - check for HPOS
            if (isset($submenu['woocommerce'])) {
                foreach ($submenu['woocommerce'] as $key => $item) {
                    // Check for both legacy and HPOS order pages
                    if (strpos($item[2], 'edit.php?post_type=shop_order') !== false || 
                        strpos($item[2], 'admin.php?page=wc-orders') !== false) {
                        $submenu['woocommerce'][$key][1] = 'edit_shop_orders';
                    }
                }
            }
        }
    }
    
    public function restrict_admin_access() {
        if (!current_user_can('location_manager')) {
            return;
        }
        
        // Get current page
        global $pagenow;
        $current_page = isset($_GET['page']) ? $_GET['page'] : '';
        
        // Allowed pages for location manager
        $allowed_pages = ['admin.php', 'profile.php'];
        $allowed_post_types = ['shop_order'];
        $allowed_wc_pages = ['wcmls-stock', 'wc-orders']; // Added wc-orders for HPOS
        
        // Check if accessing WooCommerce pages
        if ($pagenow === 'admin.php' && $current_page && !in_array($current_page, $allowed_wc_pages)) {
            // Allow wc-orders page and its subpages
            if (strpos($current_page, 'wc-orders') === 0) {
                return;
            }
            
            if (strpos($current_page, 'wc-') === 0 || strpos($current_page, 'woocommerce') !== false) {
                wp_die(__('У вас нет прав для доступа к этой странице.', 'wc-multi-location-stock'));
            }
        }
        
        // Block access to product pages, media library, and other restricted areas
        if ($pagenow === 'edit.php') {
            $post_type = isset($_GET['post_type']) ? $_GET['post_type'] : 'post';
            if (!in_array($post_type, $allowed_post_types)) {
                wp_die(__('У вас нет прав для доступа к этой странице.', 'wc-multi-location-stock'));
            }
        }
        
        if ($pagenow === 'post.php' || $pagenow === 'post-new.php') {
            $post_id = isset($_GET['post']) ? $_GET['post'] : 0;
            $post_type = $post_id ? get_post_type($post_id) : (isset($_GET['post_type']) ? $_GET['post_type'] : '');
            if (!in_array($post_type, $allowed_post_types)) {
                wp_die(__('У вас нет прав для доступа к этой странице.', 'wc-multi-location-stock'));
            }
        }
        
        // Block access to media library
        if ($pagenow === 'upload.php' || $pagenow === 'media-new.php') {
            wp_die(__('У вас нет прав для доступа к этой странице.', 'wc-multi-location-stock'));
        }
        
        // Block access to dashboard
        if ($pagenow === 'index.php') {
            wp_redirect(admin_url('edit.php?post_type=shop_order'));
            exit;
        }
        
        // Hide admin menu items
        add_action('admin_menu', function() {
            if (!current_user_can('location_manager')) {
                return;
            }
            
            // Remove main menu items
            $restricted_menus = [
                'index.php', 
                'edit.php', // Posts
                'upload.php', // Media
                'edit.php?post_type=page', // Pages
                'edit-comments.php', 
                'themes.php', 
                'plugins.php',
                'users.php', 
                'tools.php', 
                'options-general.php',
                'edit.php?post_type=product' // Products
            ];
            
            foreach ($restricted_menus as $menu) {
                remove_menu_page($menu);
            }
            
            // Remove WooCommerce submenus except allowed ones
            remove_submenu_page('woocommerce', 'wc-admin');
            remove_submenu_page('woocommerce', 'wc-reports');
            remove_submenu_page('woocommerce', 'wc-settings');
            remove_submenu_page('woocommerce', 'wc-status');
            remove_submenu_page('woocommerce', 'wc-addons');
            remove_submenu_page('woocommerce', 'wcmls-settings');
            
            // Remove Products submenu from WooCommerce
            remove_submenu_page('woocommerce', 'edit.php?post_type=product');
            
            // Keep only Orders and Stock for location managers
            global $submenu;
            if (isset($submenu['woocommerce'])) {
                $allowed_submenus = ['edit.php?post_type=shop_order', 'wcmls-stock', 'wc-orders', 'admin.php?page=wc-orders'];
                foreach ($submenu['woocommerce'] as $key => $item) {
                    $is_allowed = false;
                    foreach ($allowed_submenus as $allowed) {
                        if (strpos($item[2], $allowed) !== false || strpos($item[2], 'wc-orders') !== false) {
                            $is_allowed = true;
                            break;
                        }
                    }
                    if (!$is_allowed) {
                        unset($submenu['woocommerce'][$key]);
                    }
                }
            }
        }, 999);
    }
    
    public function settings_page() {
        if (isset($_POST['wcmls_save_settings'])) {
            $this->save_settings();
        }
        
        if (isset($_POST['wcmls_init_stock'])) {
            if (isset($_POST['wcmls_nonce']) && wp_verify_nonce($_POST['wcmls_nonce'], 'wcmls_settings')) {
                $this->init_all_stock_records();
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-success"><p>' . __('Запасы инициализированы для всех товаров и локаций.', 'wc-multi-location-stock') . '</p></div>';
                });
            }
        }
        
        if (isset($_POST['wcmls_sync_all_stock'])) {
            if (isset($_POST['wcmls_nonce']) && wp_verify_nonce($_POST['wcmls_nonce'], 'wcmls_settings')) {
                $synced = $this->sync_all_products_stock();
                add_action('admin_notices', function() use ($synced) {
                    echo '<div class="notice notice-success"><p>' . 
                         sprintf(__('Синхронизировано запасов для %d товаров.', 'wc-multi-location-stock'), $synced) . 
                         '</p></div>';
                });
            }
        }
        
        if (isset($_POST['wcmls_debug_mode'])) {
            if (isset($_POST['wcmls_nonce']) && wp_verify_nonce($_POST['wcmls_nonce'], 'wcmls_settings')) {
                $debug_enabled = get_option('wcmls_debug_mode', false);
                update_option('wcmls_debug_mode', !$debug_enabled);
                add_action('admin_notices', function() use ($debug_enabled) {
                    $message = !$debug_enabled ? 
                        __('Режим отладки включен.', 'wc-multi-location-stock') : 
                        __('Режим отладки отключен.', 'wc-multi-location-stock');
                    echo '<div class="notice notice-success"><p>' . $message . '</p></div>';
                });
            }
        }
        
        if (isset($_POST['wcmls_diagnose'])) {
            if (isset($_POST['wcmls_nonce']) && wp_verify_nonce($_POST['wcmls_nonce'], 'wcmls_settings')) {
                $this->run_diagnostics();
            }
        }
        
        $locations = $this->locations;
        $users = get_users(['role' => 'location_manager']);
        ?>
        <div class="wrap">
            <h1><?php _e('WooCommerce Multi-Location Stock Settings', 'wc-multi-location-stock'); ?></h1>
            
            <div class="notice notice-info">
                <p><?php _e('Шорткод для выбора локации:', 'wc-multi-location-stock'); ?> <code>[location_selector]</code></p>
                <p><?php _e('Вставьте этот шорткод на любую страницу, где покупатели должны выбирать свою локацию.', 'wc-multi-location-stock'); ?></p>
                <p><strong><?php _e('Новое в версии 2.0.1:', 'wc-multi-location-stock'); ?></strong> <?php _e('Исправлена проблема с бесконечной загрузкой jQuery на странице оформления заказа.', 'wc-multi-location-stock'); ?></p>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('wcmls_settings', 'wcmls_nonce'); ?>
                
                <h2><?php _e('Управление локациями', 'wc-multi-location-stock'); ?></h2>
                
                <table class="wp-list-table widefat fixed striped wcmls-stock-table">
                    <thead>
                        <tr>
                            <th><?php _e('ID локации', 'wc-multi-location-stock'); ?></th>
                            <th><?php _e('Название', 'wc-multi-location-stock'); ?></th>
                            <th><?php _e('Менеджер', 'wc-multi-location-stock'); ?></th>
                            <th><?php _e('По умолчанию', 'wc-multi-location-stock'); ?></th>
                            <th><?php _e('Действия', 'wc-multi-location-stock'); ?></th>
                        </tr>
                    </thead>
                    <tbody id="locations-list">
                        <?php if (!empty($locations)): ?>
                            <?php foreach ($locations as $location_id => $location): ?>
                                <tr>
                                    <td><?php echo esc_html($location_id); ?></td>
                                    <td>
                                        <input type="text" name="locations[<?php echo esc_attr($location_id); ?>][name]" 
                                               value="<?php echo esc_attr($location['name']); ?>" required />
                                    </td>
                                    <td>
                                        <select name="locations[<?php echo esc_attr($location_id); ?>][manager]">
                                            <option value=""><?php _e('Не назначен', 'wc-multi-location-stock'); ?></option>
                                            <?php foreach ($users as $user): ?>
                                                <option value="<?php echo esc_attr($user->ID); ?>" 
                                                    <?php selected($location['manager'], $user->ID); ?>>
                                                    <?php echo esc_html($user->display_name); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="radio" name="default_location" 
                                               value="<?php echo esc_attr($location_id); ?>" 
                                               <?php checked(get_option('wcmls_default_location'), $location_id); ?> />
                                    </td>
                                    <td>
                                        <button type="button" class="button remove-location" 
                                                data-location="<?php echo esc_attr($location_id); ?>">
                                            <?php _e('Удалить', 'wc-multi-location-stock'); ?>
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
                
                <h3><?php _e('Добавить новую локацию', 'wc-multi-location-stock'); ?></h3>
                <table class="form-table">
                    <tr>
                        <th><label for="new_location_id"><?php _e('ID локации', 'wc-multi-location-stock'); ?></label></th>
                        <td>
                            <input type="text" id="new_location_id" name="new_location[id]" />
                            <p class="description"><?php _e('Уникальный идентификатор (латиница, без пробелов). Генерируется автоматически из названия.', 'wc-multi-location-stock'); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="new_location_name"><?php _e('Название', 'wc-multi-location-stock'); ?></label></th>
                        <td>
                            <input type="text" id="new_location_name" name="new_location[name]" />
                        </td>
                    </tr>
                    <tr>
                        <th><label for="new_location_manager"><?php _e('Менеджер', 'wc-multi-location-stock'); ?></label></th>
                        <td>
                            <select id="new_location_manager" name="new_location[manager]">
                                <option value=""><?php _e('Не назначен', 'wc-multi-location-stock'); ?></option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?php echo esc_attr($user->ID); ?>">
                                        <?php echo esc_html($user->display_name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                    </tr>
                </table>
                
                <p class="submit">
                    <input type="submit" name="wcmls_save_settings" class="button-primary" 
                           value="<?php _e('Сохранить настройки', 'wc-multi-location-stock'); ?>" />
                    <input type="submit" name="wcmls_init_stock" class="button-secondary" 
                           value="<?php _e('Инициализировать запасы для всех товаров', 'wc-multi-location-stock'); ?>" 
                           onclick="return confirm('<?php _e('Это создаст записи о запасах для всех товаров и локаций. Продолжить?', 'wc-multi-location-stock'); ?>');" />
                    <input type="submit" name="wcmls_sync_all_stock" class="button-secondary" 
                           value="<?php _e('Синхронизировать все запасы', 'wc-multi-location-stock'); ?>" 
                           onclick="return confirm('<?php _e('Это пересчитает общие запасы для всех товаров на основе суммы локаций. Продолжить?', 'wc-multi-location-stock'); ?>');" />
                    <?php if (current_user_can('manage_options')): ?>
                    <input type="submit" name="wcmls_debug_mode" class="button-secondary" 
                           value="<?php echo get_option('wcmls_debug_mode', false) ? __('Отключить режим отладки', 'wc-multi-location-stock') : __('Включить режим отладки', 'wc-multi-location-stock'); ?>" />
                    <input type="submit" name="wcmls_diagnose" class="button-secondary" 
                           value="<?php _e('Запустить диагностику', 'wc-multi-location-stock'); ?>" />
                    <?php endif; ?>
                </p>
            </form>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.remove-location').on('click', function() {
                if (confirm('<?php _e('Вы уверены? Все данные о запасах для этой локации будут удалены.', 'wc-multi-location-stock'); ?>')) {
                    var locationId = $(this).data('location');
                    $(this).closest('tr').remove();
                    $('<input>').attr({
                        type: 'hidden',
                        name: 'remove_locations[]',
                        value: locationId
                    }).appendTo('form');
                }
            });
            
            // Функция транслитерации
            function transliterate(str) {
                var ru = {
                    'а': 'a', 'б': 'b', 'в': 'v', 'г': 'g', 'д': 'd', 
                    'е': 'e', 'ё': 'e', 'ж': 'zh', 'з': 'z', 'и': 'i', 
                    'й': 'y', 'к': 'k', 'л': 'l', 'м': 'm', 'н': 'n', 
                    'о': 'o', 'п': 'p', 'р': 'r', 'с': 's', 'т': 't', 
                    'у': 'u', 'ф': 'f', 'х': 'h', 'ц': 'c', 'ч': 'ch', 
                    'ш': 'sh', 'щ': 'sch', 'ь': '', 'ы': 'y', 'ъ': '', 
                    'э': 'e', 'ю': 'yu', 'я': 'ya',
                    'А': 'A', 'Б': 'B', 'В': 'V', 'Г': 'G', 'Д': 'D', 
                    'Е': 'E', 'Ё': 'E', 'Ж': 'Zh', 'З': 'Z', 'И': 'I', 
                    'Й': 'Y', 'К': 'K', 'Л': 'L', 'М': 'M', 'Н': 'N', 
                    'О': 'O', 'П': 'P', 'Р': 'R', 'С': 'S', 'Т': 'T', 
                    'У': 'U', 'Ф': 'F', 'Х': 'H', 'Ц': 'C', 'Ч': 'Ch', 
                    'Ш': 'Sh', 'Щ': 'Sch', 'Ь': '', 'Ы': 'Y', 'Ъ': '', 
                    'Э': 'E', 'Ю': 'Yu', 'Я': 'Ya',
                    // Казахские буквы
                    'ә': 'a', 'ғ': 'g', 'қ': 'k', 'ң': 'n', 'ө': 'o', 'ұ': 'u', 'ү': 'u', 'һ': 'h', 'і': 'i',
                    'Ә': 'A', 'Ғ': 'G', 'Қ': 'K', 'Ң': 'N', 'Ө': 'O', 'Ұ': 'U', 'Ү': 'U', 'Һ': 'H', 'І': 'I'
                };
                
                var result = '';
                for (var i = 0; i < str.length; i++) {
                    result += ru[str[i]] || str[i];
                }
                return result;
            }
            
            // Функция генерации ID из названия
            function generateLocationId(name) {
                if (!name) return '';
                
                // Транслитерация
                var id = transliterate(name);
                
                // Преобразование в нижний регистр
                id = id.toLowerCase();
                
                // Замена пробелов и специальных символов на дефисы
                id = id.replace(/[^a-z0-9]+/g, '-');
                
                // Удаление дефисов в начале и конце
                id = id.replace(/^-+|-+$/g, '');
                
                // Ограничение длины (максимум 50 символов)
                if (id.length > 50) {
                    id = id.substring(0, 50);
                }
                
                return id;
            }
            
            // Проверка уникальности ID
            function isLocationIdUnique(id) {
                var existingIds = [];
                
                // Собрать все существующие ID
                $('#locations-list tr').each(function() {
                    var existingId = $(this).find('td:first').text().trim();
                    if (existingId) {
                        existingIds.push(existingId);
                    }
                });
                
                return !existingIds.includes(id);
            }
            
            // Автоматическая генерация ID при вводе названия
            $('#new_location_name').on('input', function() {
                var name = $(this).val();
                var generatedId = generateLocationId(name);
                var $idField = $('#new_location_id');
                
                // Только если поле ID пустое или содержит ранее сгенерированное значение
                if ($idField.val() === '' || $idField.data('auto-generated')) {
                    // Проверка уникальности
                    var finalId = generatedId;
                    var counter = 1;
                    
                    while (!isLocationIdUnique(finalId) && counter < 100) {
                        finalId = generatedId + '-' + counter;
                        counter++;
                    }
                    
                    $idField.val(finalId);
                    $idField.data('auto-generated', true);
                    
                    // Подсветка если ID не уникален
                    if (!isLocationIdUnique(finalId)) {
                        $idField.css('border-color', '#dc3232');
                        if (!$idField.next('.wcmls-error').length) {
                            $idField.after('<span class="wcmls-error" style="color: #dc3232; font-size: 12px;"><?php _e('ID должен быть уникальным', 'wc-multi-location-stock'); ?></span>');
                        }
                    } else {
                        $idField.css('border-color', '');
                        $idField.next('.wcmls-error').remove();
                    }
                }
            });
            
            // Снять флаг auto-generated при ручном редактировании ID
            $('#new_location_id').on('input', function() {
                $(this).data('auto-generated', false);
                
                // Проверка уникальности при ручном вводе
                var id = $(this).val();
                
                // Проверка формата ID
                if (id && !/^[a-z0-9\-]+$/.test(id)) {
                    $(this).css('border-color', '#dc3232');
                    if (!$(this).next('.wcmls-error').length) {
                        $(this).after('<span class="wcmls-error" style="color: #dc3232; font-size: 12px;"><?php _e('ID может содержать только латинские буквы в нижнем регистре, цифры и дефисы', 'wc-multi-location-stock'); ?></span>');
                    }
                } else if (id && !isLocationIdUnique(id)) {
                    $(this).css('border-color', '#dc3232');
                    if (!$(this).next('.wcmls-error').length) {
                        $(this).after('<span class="wcmls-error" style="color: #dc3232; font-size: 12px;"><?php _e('ID должен быть уникальным', 'wc-multi-location-stock'); ?></span>');
                    }
                } else {
                    $(this).css('border-color', '');
                    $(this).next('.wcmls-error').remove();
                }
            });
            
            // Предотвратить отправку формы если ID не уникален
            $('form').on('submit', function(e) {
                var newId = $('#new_location_id').val();
                if (newId) {
                    // Проверка формата
                    if (!/^[a-z0-9\-]+$/.test(newId)) {
                        e.preventDefault();
                        alert('<?php _e('ID локации может содержать только латинские буквы в нижнем регистре, цифры и дефисы!', 'wc-multi-location-stock'); ?>');
                        $('#new_location_id').focus();
                        return false;
                    }
                    // Проверка уникальности
                    if (!isLocationIdUnique(newId)) {
                        e.preventDefault();
                        alert('<?php _e('ID локации должен быть уникальным!', 'wc-multi-location-stock'); ?>');
                        $('#new_location_id').focus();
                        return false;
                    }
                }
            });
        });
        </script>
        
        <style>
        /* Стили для полей с ошибками */
        input[type="text"].error,
        input[type="text"]:invalid {
            border-color: #dc3232 !important;
        }
        
        .wcmls-error {
            display: block;
            color: #dc3232;
            font-size: 12px;
            margin-top: 5px;
        }
        
        /* Индикация автогенерации */
        #new_location_id[data-auto-generated="true"] {
            background-color: #f0f0f1;
        }
        </style>
        <?php
    }
    
    private function save_settings() {
        if (!isset($_POST['wcmls_nonce']) || !wp_verify_nonce($_POST['wcmls_nonce'], 'wcmls_settings')) {
            return;
        }
        
        $locations = $this->locations;
        $has_errors = false;
        
        // Update existing locations
        if (isset($_POST['locations'])) {
            foreach ($_POST['locations'] as $location_id => $data) {
                if (isset($locations[$location_id])) {
                    $locations[$location_id]['name'] = sanitize_text_field($data['name']);
                    $locations[$location_id]['manager'] = absint($data['manager']);
                }
            }
        }
        
        // Add new location
        if (!empty($_POST['new_location']['id']) && !empty($_POST['new_location']['name'])) {
            $new_id = sanitize_key($_POST['new_location']['id']);
            
            // Проверка формата ID (только латиница, цифры и дефисы)
            if (!preg_match('/^[a-z0-9\-]+$/', $new_id)) {
                $has_errors = true;
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error"><p>' . 
                         __('ID локации может содержать только латинские буквы, цифры и дефисы.', 'wc-multi-location-stock') . 
                         '</p></div>';
                });
            } elseif (isset($locations[$new_id])) {
                // Проверка уникальности ID
                $has_errors = true;
                add_action('admin_notices', function() {
                    echo '<div class="notice notice-error"><p>' . 
                         __('ID локации должен быть уникальным. Локация не была добавлена.', 'wc-multi-location-stock') . 
                         '</p></div>';
                });
            } else {
                $locations[$new_id] = [
                    'name' => sanitize_text_field($_POST['new_location']['name']),
                    'manager' => absint($_POST['new_location']['manager'])
                ];
            }
        }
        
        // Remove locations
        if (isset($_POST['remove_locations'])) {
            foreach ($_POST['remove_locations'] as $location_id) {
                unset($locations[$location_id]);
                // Remove stock data for this location
                global $wpdb;
                $wpdb->delete(
                    $wpdb->prefix . 'wcmls_location_stock',
                    ['location_id' => $location_id],
                    ['%s']
                );
            }
        }
        
        // Save default location
        if (isset($_POST['default_location'])) {
            update_option('wcmls_default_location', sanitize_key($_POST['default_location']));
        }
        
        update_option('wcmls_locations', $locations);
        $this->locations = $locations;
        
        // Initialize stock records for new locations
        if (!empty($_POST['new_location']['id']) && !empty($_POST['new_location']['name'])) {
            $new_id = sanitize_key($_POST['new_location']['id']);
            // Инициализировать только если локация была успешно добавлена
            if (isset($locations[$new_id])) {
                $this->init_location_stock_for_all_products($new_id);
            }
        }
        
        if (!$has_errors) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-success"><p>' . __('Настройки сохранены.', 'wc-multi-location-stock') . '</p></div>';
            });
        }
    }
    
    public function stock_management_page() {
        // Check permissions
        if (!current_user_can('edit_products')) {
            wp_die(__('У вас недостаточно прав для доступа к этой странице.', 'wc-multi-location-stock'));
        }
        
        $current_user_id = get_current_user_id();
        $is_location_manager = current_user_can('location_manager');
        $is_admin = current_user_can('manage_woocommerce');
        $user_location = $this->get_user_location($current_user_id);
        
        // Debug mode check
        if (defined('WCMLS_DEBUG') && WCMLS_DEBUG) {
            global $wpdb;
            $table_name = $wpdb->prefix . 'wcmls_location_stock';
            
            // Check if table exists
            $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name;
            
            if ($table_exists) {
                $total_records = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
                echo '<div class="notice notice-info"><p>Debug: Table exists with ' . $total_records . ' records</p></div>';
            } else {
                echo '<div class="notice notice-error"><p>Debug: Table does not exist!</p></div>';
                $this->create_tables();
            }
        }
        
        ?>
        <div class="wrap">
            <h1><?php _e('Управление складом', 'wc-multi-location-stock'); ?></h1>
            
            <?php if ($is_location_manager && !$user_location): ?>
                <div class="notice notice-error">
                    <p><?php _e('Вы не привязаны ни к одной локации. Обратитесь к администратору.', 'wc-multi-location-stock'); ?></p>
                </div>
                <?php return; ?>
            <?php endif; ?>
            
            <?php if (empty($this->locations)): ?>
                <div class="notice notice-warning">
                    <p><?php _e('Нет созданных локаций. Пожалуйста, создайте локации в настройках плагина.', 'wc-multi-location-stock'); ?></p>
                </div>
                <?php return; ?>
            <?php endif; ?>
            
            <div class="notice notice-info">
                <p><?php _e('Используйте поле "Изменить на" для увеличения или уменьшения запасов. Например: +10 добавит 10 единиц, -5 уберет 5 единиц, 10 (без знака) добавит 10 единиц.', 'wc-multi-location-stock'); ?></p>
                <?php if ($is_admin): ?>
                    <p><?php _e('Изменения запасов локаций автоматически обновляют общие запасы WooCommerce.', 'wc-multi-location-stock'); ?></p>
                <?php endif; ?>
                <?php if (count($this->locations) > 3): ?>
                    <p><strong><?php _e('Совет:', 'wc-multi-location-stock'); ?></strong> <?php _e('Используйте горизонтальную прокрутку или кнопки навигации для просмотра всех локаций.', 'wc-multi-location-stock'); ?></p>
                <?php endif; ?>
            </div>
            
            <div class="wcmls-table-controls">
                <button type="button" class="button scroll-left" disabled>← <?php _e('Назад', 'wc-multi-location-stock'); ?></button>
                <button type="button" class="button scroll-right"><?php _e('Вперед', 'wc-multi-location-stock'); ?> →</button>
                <span class="wcmls-location-count"><?php printf(__('Локаций: %d', 'wc-multi-location-stock'), count($this->locations)); ?></span>
            </div>
            
            <div class="wcmls-table-wrapper">
                <table class="wp-list-table widefat striped wcmls-stock-table <?php echo $is_admin ? 'admin-view' : ''; ?>">
                    <thead>
                        <tr>
                            <th><?php _e('Товар', 'wc-multi-location-stock'); ?></th>
                            <?php if ($is_admin): ?>
                                <th><?php _e('Общий запас WC', 'wc-multi-location-stock'); ?></th>
                                <th><?php _e('Сумма по локациям', 'wc-multi-location-stock'); ?></th>
                            <?php endif; ?>
                            <?php foreach ($this->locations as $location_id => $location): ?>
                                <?php if ($is_admin || $user_location === $location_id): ?>
                                    <th>
                                        <?php echo esc_html($location['name']); ?>
                                        <br><small><?php _e('Текущий запас', 'wc-multi-location-stock'); ?></small>
                                    </th>
                                    <th>
                                        <?php _e('Управление', 'wc-multi-location-stock'); ?>
                                        <br><small><?php _e('Изменить на', 'wc-multi-location-stock'); ?></small>
                                    </th>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php
                        $args = [
                            'post_type' => 'product',
                            'posts_per_page' => -1,
                            'orderby' => 'title',
                            'order' => 'ASC'
                        ];
                        
                        $products = get_posts($args);
                        
                        foreach ($products as $product_post):
                            $product = wc_get_product($product_post->ID);
                            if (!$product || $product->is_type('variable')) continue;
                            
                            $product_id = $product->get_id();
                            ?>
                            <tr>
                                <td>
                                    <strong><?php echo esc_html($product->get_name()); ?></strong>
                                    <br><small>SKU: <?php echo esc_html($product->get_sku()); ?></small>
                                    <?php if (defined('WCMLS_DEBUG') && WCMLS_DEBUG): ?>
                                    <br><small>ID: <?php echo $product_id; ?></small>
                                    <?php endif; ?>
                                </td>
                                <?php if ($is_admin): ?>
                                    <td class="total-stock" data-product="<?php echo esc_attr($product_id); ?>">
                                        <strong><?php echo intval($product->get_stock_quantity()); ?></strong>
                                    </td>
                                    <td class="location-sum" data-product="<?php echo esc_attr($product_id); ?>">
                                        <?php
                                        $location_sum = 0;
                                        foreach ($this->locations as $loc_id => $loc) {
                                            $location_sum += $this->get_product_location_stock($product_id, $loc_id);
                                        }
                                        ?>
                                        <strong><?php echo $location_sum; ?></strong>
                                        <?php if ($location_sum != intval($product->get_stock_quantity())): ?>
                                            <span style="color: red;">⚠️</span>
                                        <?php endif; ?>
                                    </td>
                                <?php endif; ?>
                                <?php foreach ($this->locations as $location_id => $location): ?>
                                    <?php if ($is_admin || $user_location === $location_id): ?>
                                        <?php
                                        // Get the actual stock from database
                                        $stock = $this->get_product_location_stock($product_id, $location_id);
                                        $can_edit = $is_admin || $user_location === $location_id;
                                        
                                        if (defined('WCMLS_DEBUG') && WCMLS_DEBUG) {
                                            error_log("Display stock for product $product_id, location $location_id: $stock");
                                        }
                                        ?>
                                        <td>
                                            <strong class="current-stock" 
                                                    data-product="<?php echo esc_attr($product_id); ?>" 
                                                    data-location="<?php echo esc_attr($location_id); ?>"
                                                    id="stock-<?php echo esc_attr($product_id); ?>-<?php echo esc_attr($location_id); ?>">
                                                <?php echo intval($stock); ?>
                                            </strong>
                                        </td>
                                        <td>
                                            <?php if ($can_edit): ?>
                                                <input type="number" 
                                                       class="location-stock-change" 
                                                       data-product="<?php echo esc_attr($product_id); ?>" 
                                                       data-location="<?php echo esc_attr($location_id); ?>" 
                                                       placeholder="+/-" 
                                                       style="width: 80px;" />
                                                <button type="button" 
                                                        class="button button-small apply-stock-change" 
                                                        data-product="<?php echo esc_attr($product_id); ?>" 
                                                        data-location="<?php echo esc_attr($location_id); ?>">
                                                    <?php _e('Применить', 'wc-multi-location-stock'); ?>
                                                </button>
                                            <?php else: ?>
                                                <span class="description"><?php _e('Нет доступа', 'wc-multi-location-stock'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <style>
        .wcmls-stock-table .current-stock {
            min-width: 50px;
            display: inline-block;
            text-align: center;
        }
        
        /* Стили для контролов таблицы */
        .wcmls-table-controls {
            margin-bottom: 10px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .wcmls-table-controls .wcmls-location-count {
            margin-left: auto;
            color: #666;
            font-size: 13px;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // Существующий код для применения изменений запасов с делегированием событий
            $(document).on('click', '.apply-stock-change', function() {
                var $button = $(this);
                var productId = $button.data('product');
                var locationId = $button.data('location');
                var $input = $('.location-stock-change[data-product="' + productId + '"][data-location="' + locationId + '"]');
                var inputValue = $input.val().trim();
                
                if (!inputValue) {
                    return;
                }
                
                // Parse the input value
                var change = parseInt(inputValue);
                
                // If the input doesn't start with -, treat it as positive
                if (!inputValue.startsWith('-') && !inputValue.startsWith('+')) {
                    change = Math.abs(change);
                }
                
                if (isNaN(change) || change === 0) {
                    alert('<?php _e('Пожалуйста, введите число отличное от нуля.', 'wc-multi-location-stock'); ?>');
                    return;
                }
                
                var $currentStock = $('.current-stock[data-product="' + productId + '"][data-location="' + locationId + '"]');
                
                $button.prop('disabled', true).text('<?php _e('Обновление...', 'wc-multi-location-stock'); ?>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wcmls_update_stock',
                        product_id: productId,
                        location_id: locationId,
                        change: change,
                        nonce: '<?php echo wp_create_nonce('wcmls_update_stock'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            // Update location stock display
                            $currentStock.text(response.data.new_stock);
                            $input.val('');
                            
                            // Update total stock display if admin
                            <?php if ($is_admin): ?>
                            if (response.data.new_total_stock !== undefined) {
                                $('.total-stock[data-product="' + productId + '"]').html('<strong>' + response.data.new_total_stock + '</strong>');
                            }
                            if (response.data.location_sum !== undefined) {
                                var $sumCell = $('.location-sum[data-product="' + productId + '"]');
                                var sumHtml = '<strong>' + response.data.location_sum + '</strong>';
                                if (response.data.location_sum != response.data.new_total_stock) {
                                    sumHtml += ' <span style="color: red;">⚠️</span>';
                                }
                                $sumCell.html(sumHtml);
                            }
                            <?php endif; ?>
                            
                            // Flash success
                            $currentStock.css('background-color', '#d4edda');
                            setTimeout(function() {
                                $currentStock.css('background-color', '');
                            }, 1000);
                            
                            // Log the change for debugging
                            console.log('Stock updated:', {
                                product: productId,
                                location: locationId,
                                oldStock: response.data.old_stock,
                                change: response.data.change_applied,
                                newStock: response.data.new_stock
                            });
                        } else {
                            alert(response.data || '<?php _e('Произошла ошибка при обновлении запасов.', 'wc-multi-location-stock'); ?>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX error:', status, error);
                        alert('<?php _e('Ошибка при обновлении запасов.', 'wc-multi-location-stock'); ?>');
                    },
                    complete: function() {
                        $button.prop('disabled', false).text('<?php _e('Применить', 'wc-multi-location-stock'); ?>');
                    }
                });
            });
            
            // Allow Enter key to apply changes с делегированием
            $(document).on('keypress', '.location-stock-change', function(e) {
                if (e.which === 13) {
                    e.preventDefault();
                    var productId = $(this).data('product');
                    var locationId = $(this).data('location');
                    $('.apply-stock-change[data-product="' + productId + '"][data-location="' + locationId + '"]').click();
                }
            });
            
            // Горизонтальный скроллинг
            var $wrapper = $('.wcmls-table-wrapper');
            var $table = $wrapper.find('table');
            
            // Проверить нужен ли скроллинг
            function checkScroll() {
                if ($table.width() > $wrapper.width()) {
                    $wrapper.removeClass('no-scroll');
                } else {
                    $wrapper.addClass('no-scroll');
                }
            }
            
            // Добавить класс при скроллинге для тени
            $wrapper.on('scroll', function() {
                if ($(this).scrollLeft() > 0) {
                    $(this).addClass('wcmls-table-scrolled');
                } else {
                    $(this).removeClass('wcmls-table-scrolled');
                }
                
                // Обновление состояния кнопок
                var scrollLeft = $(this).scrollLeft();
                var maxScroll = $table.width() - $wrapper.width();
                
                $('.scroll-left').prop('disabled', scrollLeft <= 0);
                $('.scroll-right').prop('disabled', scrollLeft >= maxScroll);
            });
            
            // Проверить при загрузке и изменении размера окна
            checkScroll();
            $(window).on('resize', checkScroll);
            
            // Горизонтальный скроллинг колесом мыши при зажатом Shift
            $wrapper.on('wheel', function(e) {
                if (e.shiftKey) {
                    e.preventDefault();
                    var scrollLeft = $(this).scrollLeft();
                    $(this).scrollLeft(scrollLeft + e.originalEvent.deltaY);
                }
            });
            
            // Кнопки прокрутки
            $('.scroll-left').on('click', function() {
                $wrapper.animate({ scrollLeft: '-=300' }, 300);
            });
            
            $('.scroll-right').on('click', function() {
                $wrapper.animate({ scrollLeft: '+=300' }, 300);
            });
            
            // Инициализация состояния кнопок
            $wrapper.trigger('scroll');
        });
        </script>
        <?php
    }
    
    // Новая функция для синхронизации общего запаса товара
    private function sync_total_stock($product_id) {
        $product = wc_get_product($product_id);
        if (!$product) return false;
        
        // Вычислить сумму запасов по всем локациям
        $total_stock = 0;
        foreach ($this->locations as $location_id => $location) {
            $total_stock += $this->get_product_location_stock($product_id, $location_id);
        }
        
        // Обновить общий запас WooCommerce
        $product->set_stock_quantity($total_stock);
        $product->set_manage_stock(true);
        $product->save();
        
        if (defined('WCMLS_DEBUG') && WCMLS_DEBUG) {
            error_log("WCMLS sync_total_stock: product $product_id, total stock = $total_stock");
        }
        
        return $total_stock;
    }
    
    // Новая функция для массовой синхронизации
    private function sync_all_products_stock() {
        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids'
        ];
        
        $product_ids = get_posts($args);
        $synced = 0;
        
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product || $product->is_type('variable')) continue;
            
            $this->sync_total_stock($product_id);
            $synced++;
        }
        
        return $synced;
    }
    
    public function ajax_update_stock() {
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wcmls_update_stock')) {
            wp_send_json_error(__('Неверный nonce.', 'wc-multi-location-stock'));
        }
        
        if (!current_user_can('edit_products')) {
            wp_send_json_error(__('У вас нет прав для изменения запасов.', 'wc-multi-location-stock'));
        }
        
        $product_id = absint($_POST['product_id']);
        $location_id = sanitize_key($_POST['location_id']);
        $change = isset($_POST['change']) ? intval($_POST['change']) : 0;
        
        if ($change === 0) {
            wp_send_json_error(__('Изменение должно быть отличным от нуля.', 'wc-multi-location-stock'));
        }
        
        // Validate product exists
        $product = wc_get_product($product_id);
        if (!$product) {
            wp_send_json_error(__('Товар не найден.', 'wc-multi-location-stock'));
        }
        
        // Validate location exists
        if (!isset($this->locations[$location_id])) {
            wp_send_json_error(__('Локация не найдена.', 'wc-multi-location-stock'));
        }
        
        // Check if location manager can edit this location
        if (current_user_can('location_manager') && !current_user_can('manage_woocommerce')) {
            $user_location = $this->get_user_location(get_current_user_id());
            if ($user_location !== $location_id) {
                wp_send_json_error(__('Вы можете изменять запасы только для своей локации.', 'wc-multi-location-stock'));
            }
        }
        
        // Get current location stock from database
        $current_location_stock = $this->get_product_location_stock($product_id, $location_id);
        
        if (defined('WCMLS_DEBUG') && WCMLS_DEBUG) {
            error_log("AJAX: Current stock for product $product_id at location $location_id: $current_location_stock");
            error_log("AJAX: Change requested: $change");
        }
        
        // Calculate new stock (relative change)
        $new_location_stock = max(0, $current_location_stock + $change);
        
        // Update location stock in database
        $update_result = $this->update_location_stock($product_id, $location_id, $new_location_stock);
        
        if (!$update_result) {
            wp_send_json_error(__('Ошибка при обновлении запасов в базе данных.', 'wc-multi-location-stock'));
        }
        
        // Verify the update
        $verified_stock = $this->get_product_location_stock($product_id, $location_id);
        
        if (defined('WCMLS_DEBUG') && WCMLS_DEBUG) {
            error_log("AJAX: New stock after update: $new_location_stock, verified from DB: $verified_stock");
        }
        
        // ВАЖНО: Синхронизировать общий запас WooCommerce
        $new_total_stock = $this->sync_total_stock($product_id);
        
        // Вычислить сумму по локациям для отображения
        $location_sum = 0;
        foreach ($this->locations as $loc_id => $loc) {
            $location_sum += $this->get_product_location_stock($product_id, $loc_id);
        }
        
        // Prepare response
        $response_data = [
            'message' => __('Запас обновлен.', 'wc-multi-location-stock'),
            'new_stock' => $verified_stock, // Use verified stock from DB
            'change_applied' => $change,
            'old_stock' => $current_location_stock,
            'new_total_stock' => $new_total_stock,
            'location_sum' => $location_sum
        ];
        
        wp_send_json_success($response_data);
    }
    
    private function get_user_location($user_id) {
        foreach ($this->locations as $location_id => $location) {
            if ($location['manager'] == $user_id) {
                return $location_id;
            }
        }
        return null;
    }
    
    private function get_product_location_stock($product_id, $location_id) {
        global $wpdb;
        
        // Validate inputs
        if (!$product_id || !$location_id) {
            if (defined('WCMLS_DEBUG') && WCMLS_DEBUG) {
                error_log('WCMLS: Invalid inputs - product_id: ' . $product_id . ', location_id: ' . $location_id);
            }
            return 0;
        }
        
        $table_name = $wpdb->prefix . 'wcmls_location_stock';
        
        // Get stock quantity from database with direct query
        $query = $wpdb->prepare(
            "SELECT stock_quantity FROM {$table_name} 
            WHERE product_id = %d AND location_id = %s 
            LIMIT 1",
            $product_id,
            $location_id
        );
        
        $stock = $wpdb->get_var($query);
        
        // Debug logging
        if (defined('WCMLS_DEBUG') && WCMLS_DEBUG) {
            error_log(sprintf('WCMLS get_stock query: %s', $query));
            error_log(sprintf('WCMLS get_stock result: product=%d, location=%s, stock=%s', 
                $product_id, $location_id, var_export($stock, true)));
        }
        
        // If no record exists, return 0 but don't create empty records automatically
        if ($stock === null) {
            if (defined('WCMLS_DEBUG') && WCMLS_DEBUG) {
                error_log('WCMLS: No stock record found for product ' . $product_id . ' at location ' . $location_id);
            }
            return 0;
        }
        
        return intval($stock);
    }
    
    private function init_stock_for_product($product_id) {
        // Initialize stock records for a specific product across all locations
        if (empty($this->locations)) {
            return;
        }
        
        global $wpdb;
        $table_name = $wpdb->prefix . 'wcmls_location_stock';
        
        foreach ($this->locations as $location_id => $location) {
            // Check if record exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$table_name} 
                WHERE product_id = %d AND location_id = %s",
                $product_id,
                $location_id
            ));
            
            if (!$exists) {
                // Create record with 0 stock
                $wpdb->insert(
                    $table_name,
                    [
                        'product_id' => $product_id,
                        'location_id' => $location_id,
                        'stock_quantity' => 0
                    ],
                    ['%d', '%s', '%d']
                );
                
                if (defined('WCMLS_DEBUG') && WCMLS_DEBUG) {
                    error_log("WCMLS: Created stock record for product $product_id at location $location_id");
                }
            }
        }
    }
    
    public function maybe_init_stock_for_product($product_id) {
        // Only init if product is simple and manages stock
        $product = wc_get_product($product_id);
        if ($product && !$product->is_type('variable') && $product->get_manage_stock()) {
            $this->init_stock_for_product($product_id);
        }
    }
    
    private function run_diagnostics() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'wcmls_location_stock';
        
        $diagnostics = [];
        
        // Check table existence
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '{$table_name}'") == $table_name;
        $diagnostics[] = 'Table exists: ' . ($table_exists ? 'Yes' : 'No');
        
        if ($table_exists) {
            // Check table structure
            $columns = $wpdb->get_results("SHOW COLUMNS FROM {$table_name}");
            $diagnostics[] = 'Table columns: ' . count($columns);
            
            // Count records
            $total_records = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name}");
            $diagnostics[] = 'Total stock records: ' . $total_records;
            
            // Count records per location
            foreach ($this->locations as $location_id => $location) {
                $location_records = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$table_name} WHERE location_id = %s",
                    $location_id
                ));
                $diagnostics[] = "Records for location '{$location['name']}': " . $location_records;
            }
            
            // Sample data
            $sample = $wpdb->get_results("SELECT * FROM {$table_name} LIMIT 5");
            $diagnostics[] = 'Sample records: ' . print_r($sample, true);
            
            // Check for stock with non-zero values
            $non_zero = $wpdb->get_var("SELECT COUNT(*) FROM {$table_name} WHERE stock_quantity > 0");
            $diagnostics[] = 'Records with stock > 0: ' . $non_zero;
            
            // Check stock sync issues
            $sync_issues = 0;
            $args = [
                'post_type' => 'product',
                'posts_per_page' => -1,
                'fields' => 'ids'
            ];
            
            $product_ids = get_posts($args);
            foreach ($product_ids as $product_id) {
                $product = wc_get_product($product_id);
                if (!$product || $product->is_type('variable')) continue;
                
                $wc_stock = intval($product->get_stock_quantity());
                $location_sum = 0;
                foreach ($this->locations as $location_id => $location) {
                    $location_sum += $this->get_product_location_stock($product_id, $location_id);
                }
                
                if ($wc_stock != $location_sum) {
                    $sync_issues++;
                }
            }
            $diagnostics[] = 'Products with sync issues: ' . $sync_issues;
        } else {
            // Table doesn't exist, try to create it
            $this->create_tables();
            $diagnostics[] = 'Table did not exist, attempted to create it.';
        }
        
        // Save diagnostics to option for display
        update_option('wcmls_diagnostics', $diagnostics);
        
        add_action('admin_notices', function() use ($diagnostics) {
            echo '<div class="notice notice-info"><h3>' . __('Диагностика WCMLS', 'wc-multi-location-stock') . '</h3>';
            echo '<pre>' . implode("\n", $diagnostics) . '</pre>';
            echo '</div>';
        });
    }
    
    private function init_all_stock_records() {
        // This function is called only when needed (e.g., on activation or manual init)
        if (empty($this->locations)) {
            add_action('admin_notices', function() {
                echo '<div class="notice notice-error"><p>' . 
                     esc_html__('Нет локаций для инициализации запасов.', 'wc-multi-location-stock') . 
                     '</p></div>';
            });
            return;
        }
        
        $args = [
            'post_type' => 'product',
            'posts_per_page' => -1,
            'fields' => 'ids',
            'post_status' => 'publish',
            'meta_query' => [
                'relation' => 'OR',
                [
                    'key' => '_manage_stock',
                    'value' => 'yes'
                ],
                [
                    'key' => '_manage_stock',
                    'compare' => 'NOT EXISTS'
                ]
            ]
        ];
        
        $product_ids = get_posts($args);
        $total_products = count($product_ids);
        $initialized = 0;
        
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            if (!$product || $product->is_type('variable')) continue;
            
            $this->init_stock_for_product($product_id);
            $initialized++;
        }
        
        $message = sprintf(
            __('Инициализировано запасов для %d товаров и %d локаций.', 'wc-multi-location-stock'),
            $initialized,
            count($this->locations)
        );
        
        add_action('admin_notices', function() use ($message) {
            echo '<div class="notice notice-success"><p>' . esc_html($message) . '</p></div>';
        });
    }
    
    private function init_location_stock_for_all_products($location_id) {
        // Initialize stock records for all products for a specific location
        $args = [
            'post_type' => 'product',
            'posts_per_page' => 100, // Process in batches
            'paged' => 1,
            'fields' => 'ids'
        ];
        
        $query = new WP_Query($args);
        $total_pages = $query->max_num_pages;
        
        for ($page = 1; $page <= $total_pages; $page++) {
            $args['paged'] = $page;
            $product_ids = get_posts($args);
            
            foreach ($product_ids as $product_id) {
                $product = wc_get_product($product_id);
                if (!$product || $product->is_type('variable')) continue;
                
                // This will create the record if it doesn't exist
                $this->get_product_location_stock($product_id, $location_id);
            }
        }
    }
    
    private function update_location_stock($product_id, $location_id, $quantity) {
        global $wpdb;
        
        $table_name = $wpdb->prefix . 'wcmls_location_stock';
        
        // Ensure quantity is integer and non-negative
        $quantity = max(0, intval($quantity));
        
        // Debug logging
        if (defined('WCMLS_DEBUG') && WCMLS_DEBUG) {
            error_log(sprintf('WCMLS update_stock: product=%d, location=%s, new_quantity=%d', 
                $product_id, $location_id, $quantity));
        }
        
        // First, check if record exists
        $exists = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$table_name} 
            WHERE product_id = %d AND location_id = %s",
            $product_id,
            $location_id
        ));
        
        if ($exists > 0) {
            // Update existing record
            $result = $wpdb->update(
                $table_name,
                ['stock_quantity' => $quantity],
                [
                    'product_id' => $product_id,
                    'location_id' => $location_id
                ],
                ['%d'],
                ['%d', '%s']
            );
        } else {
            // Insert new record
            $result = $wpdb->insert(
                $table_name,
                [
                    'product_id' => $product_id,
                    'location_id' => $location_id,
                    'stock_quantity' => $quantity
                ],
                ['%d', '%s', '%d']
            );
        }
        
        // Log error if query fails
        if ($result === false) {
            error_log('WCMLS Error updating stock: ' . $wpdb->last_error);
            error_log('WCMLS Query: ' . $wpdb->last_query);
            return false;
        }
        
        if (defined('WCMLS_DEBUG') && WCMLS_DEBUG) {
            error_log('WCMLS update_stock success: affected rows = ' . $result);
        }
        
        return true;
    }
    
    public function get_location_stock($stock, $product) {
        // Only filter on frontend and AJAX requests, not in admin stock management
        if (is_admin() && !wp_doing_ajax()) {
            return $stock;
        }
        
        $selected_location = $this->get_selected_location();
        if ($selected_location) {
            $location_stock = $this->get_product_location_stock($product->get_id(), $selected_location);
            return $location_stock;
        }
        
        return $stock;
    }
    
    public function check_location_stock($is_in_stock, $product) {
        // Only filter on frontend and AJAX requests, not in admin stock management
        if (is_admin() && !wp_doing_ajax()) {
            return $is_in_stock;
        }
        
        $selected_location = $this->get_selected_location();
        if ($selected_location) {
            $location_stock = $this->get_product_location_stock($product->get_id(), $selected_location);
            return $location_stock > 0;
        }
        
        return $is_in_stock;
    }
    
    private function get_selected_location() {
        if (isset($_COOKIE['wcmls_selected_location'])) {
            return sanitize_key($_COOKIE['wcmls_selected_location']);
        }
        
        if (is_user_logged_in()) {
            $user_location = get_user_meta(get_current_user_id(), 'wcmls_selected_location', true);
            if ($user_location) {
                return $user_location;
            }
        }
        
        return get_option('wcmls_default_location', '');
    }
    
    public function location_selector_shortcode($atts) {
        $selected_location = $this->get_selected_location();
        
        ob_start();
        ?>
        <div class="wcmls-location-selector">
            <label for="wcmls-location"><?php _e('Выберите вашу локацию:', 'wc-multi-location-stock'); ?></label>
            <select id="wcmls-location" name="wcmls_location">
                <option value=""><?php _e('-- Выберите локацию --', 'wc-multi-location-stock'); ?></option>
                <?php foreach ($this->locations as $location_id => $location): ?>
                    <option value="<?php echo esc_attr($location_id); ?>" 
                            <?php selected($selected_location, $location_id); ?>>
                        <?php echo esc_html($location['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
            
            <?php if ($selected_location): ?>
                <p class="wcmls-current-location">
                    <?php printf(__('Текущая локация: %s', 'wc-multi-location-stock'), 
                            esc_html($this->locations[$selected_location]['name'])); ?>
                </p>
            <?php endif; ?>
        </div>
        
        <script type="text/javascript">
        /* Inline script as fallback */
        if (typeof wcmls_ajax === 'undefined') {
            var wcmls_ajax = {
                ajax_url: '<?php echo admin_url('admin-ajax.php'); ?>',
                nonce: '<?php echo wp_create_nonce('wcmls_nonce'); ?>',
                strings: {
                    select_location: '<?php echo esc_js(__('Пожалуйста, выберите локацию перед добавлением товара в корзину.', 'wc-multi-location-stock')); ?>',
                    location_changed: '<?php echo esc_js(__('Локация изменена. Ваша корзина была очищена.', 'wc-multi-location-stock')); ?>'
                }
            };
        }
        
        // Сохраняем выбранную локацию в глобальной переменной
        <?php if ($selected_location && isset($this->locations[$selected_location])): ?>
        window.wcmls_selected_location_name = '<?php echo esc_js($this->locations[$selected_location]['name']); ?>';
        <?php endif; ?>
        </script>
        <?php
        return ob_get_clean();
    }
    
    public function ajax_set_location() {
        // Проверяем nonce
        if (!isset($_POST['nonce']) || !wp_verify_nonce($_POST['nonce'], 'wcmls_nonce')) {
            wp_send_json_error(__('Неверный nonce.', 'wc-multi-location-stock'));
        }
        
        $location = isset($_POST['location']) ? sanitize_key($_POST['location']) : '';
        
        if ($location && isset($this->locations[$location])) {
            setcookie('wcmls_selected_location', $location, time() + (86400 * 30), '/');
            
            if (is_user_logged_in()) {
                update_user_meta(get_current_user_id(), 'wcmls_selected_location', $location);
            }
            
            // Устанавливаем город в сессии WooCommerce
            if (WC()->customer) {
                $location_name = $this->locations[$location]['name'];
                WC()->customer->set_billing_city($location_name);
                WC()->customer->save();
            }
            
            // Clear cart if location changed
            $previous_location = $this->get_selected_location();
            if (WC()->cart && $previous_location && $previous_location !== $location) {
                WC()->cart->empty_cart();
            }
            
            wp_send_json_success([
                'message' => __('Локация установлена.', 'wc-multi-location-stock'),
                'location_name' => $this->locations[$location]['name']
            ]);
        } else {
            wp_send_json_error(__('Неверная локация.', 'wc-multi-location-stock'));
        }
    }
    
    public function enqueue_scripts() {
        // Создаем файл frontend.js если его нет
        $js_file = WCMLS_PLUGIN_PATH . 'assets/js/frontend.js';
        $js_dir = dirname($js_file);
        
        if (!file_exists($js_dir)) {
            wp_mkdir_p($js_dir);
        }
        
        if (!file_exists($js_file)) {
            $this->create_frontend_js_file();
        }
        
        // Регистрируем и загружаем скрипт
        wp_register_script(
            'wcmls-frontend',
            WCMLS_PLUGIN_URL . 'assets/js/frontend.js',
            ['jquery'],
            WCMLS_VERSION,
            true
        );
        
        // Получаем выбранную локацию
        $selected_location = $this->get_selected_location();
        $location_name = '';
        if ($selected_location && isset($this->locations[$selected_location])) {
            $location_name = $this->locations[$selected_location]['name'];
        }
        
        // Локализуем скрипт
        wp_localize_script('wcmls-frontend', 'wcmls_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wcmls_nonce'),
            'selected_location_name' => $location_name,
            'strings' => [
                'select_location' => __('Пожалуйста, выберите локацию перед добавлением товара в корзину.', 'wc-multi-location-stock'),
                'location_changed' => __('Локация изменена. Ваша корзина была очищена.', 'wc-multi-location-stock')
            ]
        ]);
        
        // Загружаем скрипт
        wp_enqueue_script('wcmls-frontend');
    }
    
    private function create_frontend_js_file() {
        $frontend_js = 'jQuery(document).ready(function($) {
    // Проверяем, что wcmls_ajax определен
    if (typeof wcmls_ajax === "undefined") {
        console.error("wcmls_ajax is not defined!");
        return;
    }
    
    // Handle location selection
    $(document).on("change", "#wcmls-location", function() {
        var location = $(this).val();
        
        if (!location) {
            alert(wcmls_ajax.strings.select_location);
            return;
        }
        
        $.ajax({
            url: wcmls_ajax.ajax_url,
            type: "POST",
            data: {
                action: "wcmls_set_location",
                location: location,
                nonce: wcmls_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    alert(wcmls_ajax.strings.location_changed);
                    window.location.reload();
                }
            },
            error: function(xhr, status, error) {
                console.error("AJAX error:", status, error);
                alert("Ошибка при изменении локации. Попробуйте еще раз.");
            }
        });
    });
    
    // Validate location before add to cart
    $("form.cart").on("submit", function(e) {
        if (!document.cookie.includes("wcmls_selected_location")) {
            e.preventDefault();
            alert(wcmls_ajax.strings.select_location);
            return false;
        }
    });
    
    // Флаг для предотвращения рекурсивных обновлений
    var cityUpdateInProgress = false;
    
    // Синхронизация города на странице оформления заказа
    if ($("body").hasClass("woocommerce-checkout")) {
        // Функция для установки города
        function setCheckoutCity() {
            if (cityUpdateInProgress) return;
            
            var billingCity = $("#billing_city");
            if (billingCity.length > 0 && wcmls_ajax.selected_location_name) {
                cityUpdateInProgress = true;
                billingCity.val(wcmls_ajax.selected_location_name);
                
                // Небольшая задержка перед снятием флага
                setTimeout(function() {
                    cityUpdateInProgress = false;
                }, 100);
            }
        }
        
        // Устанавливаем город при загрузке страницы
        setCheckoutCity();
        
        // Не реагируем на событие updated_checkout чтобы избежать циклов
        // Вместо этого просто устанавливаем значение один раз при загрузке
    }
});';
        
        file_put_contents(WCMLS_PLUGIN_PATH . 'assets/js/frontend.js', $frontend_js);
    }
    
    public function admin_enqueue_scripts($hook) {
        if (in_array($hook, ['woocommerce_page_wcmls-settings', 'woocommerce_page_wcmls-stock'])) {
            // Ensure CSS file exists with latest styles
            $this->ensure_admin_css_exists();
            
            wp_enqueue_style(
                'wcmls-admin',
                WCMLS_PLUGIN_URL . 'assets/css/admin.css',
                [],
                WCMLS_VERSION
            );
        }
    }
    
    private function ensure_admin_css_exists() {
        $css_file = WCMLS_PLUGIN_PATH . 'assets/css/admin.css';
        $css_dir = dirname($css_file);
        
        if (!file_exists($css_dir)) {
            wp_mkdir_p($css_dir);
        }
        
        // Check if file exists or needs update
        $current_css_version = get_option('wcmls_admin_css_version', '0');
        
        if (!file_exists($css_file) || version_compare($current_css_version, WCMLS_VERSION, '<')) {
            $admin_css = $this->get_admin_css();
            file_put_contents($css_file, $admin_css);
            update_option('wcmls_admin_css_version', WCMLS_VERSION);
        }
    }
    
    private function get_admin_css() {
        return '.wcmls-location-selector {
    margin: 20px 0;
    padding: 15px;
    background: #f5f5f5;
    border: 1px solid #ddd;
}

.wcmls-location-selector select {
    width: 100%;
    max-width: 300px;
}

.wcmls-current-location {
    margin-top: 10px;
    font-weight: bold;
}

.location-stock-input {
    width: 80px;
}

.location-stock-input[readonly] {
    background-color: #f0f0f0;
}

.location-stock-change {
    width: 80px !important;
}

.current-stock {
    font-size: 16px;
    padding: 5px 10px;
    display: inline-block;
    min-width: 40px;
    text-align: center;
    border-radius: 3px;
    background: #f0f0f0;
}

.apply-stock-change {
    margin-left: 5px;
}

/* Better table layout for stock management */
.wp-list-table.wcmls-stock-table th,
.wp-list-table.wcmls-stock-table td {
    vertical-align: middle;
}

.wp-list-table.wcmls-stock-table th:first-child {
    width: 30%;
}

.wp-list-table.wcmls-stock-table .button-small {
    height: 26px;
    line-height: 24px;
}

/* Admin columns styling */
.wcmls-stock-table.admin-view th:nth-child(2),
.wcmls-stock-table.admin-view td:nth-child(2) {
    background-color: #f8f8f8;
    border-left: 2px solid #ddd;
    border-right: 2px solid #ddd;
}

/* Debug mode styling */
.wcmls-debug-info {
    background: #ffffcc;
    padding: 5px;
    margin: 5px 0;
    border: 1px solid #e6db55;
    font-family: monospace;
    font-size: 12px;
}

/* Контейнер для таблицы с горизонтальным скроллингом */
.wcmls-table-wrapper {
    width: 100%;
    overflow-x: auto;
    overflow-y: visible;
    margin-bottom: 20px;
    border: 1px solid #ccd0d4;
    background: #fff;
    box-shadow: 0 1px 1px rgba(0,0,0,.04);
    position: relative;
}

/* Убрать fixed у таблицы для корректной работы скроллинга */
.wcmls-table-wrapper .wp-list-table {
    table-layout: auto;
    min-width: 100%;
    width: max-content;
}

/* Стили для первого столбца (зафиксированный) */
.wcmls-table-wrapper th:first-child,
.wcmls-table-wrapper td:first-child {
    position: sticky;
    left: 0;
    background: #fff;
    z-index: 10;
    border-right: 2px solid #ccd0d4;
    min-width: 200px;
}

/* Тень для зафиксированного столбца при скроллинге */
.wcmls-table-wrapper th:first-child::after,
.wcmls-table-wrapper td:first-child::after {
    content: "";
    position: absolute;
    top: 0;
    right: -5px;
    bottom: 0;
    width: 5px;
    background: linear-gradient(to right, rgba(0,0,0,0.1), transparent);
    opacity: 0;
    transition: opacity 0.3s;
}

/* Показать тень при скроллинге */
.wcmls-table-scrolled th:first-child::after,
.wcmls-table-scrolled td:first-child::after {
    opacity: 1;
}

/* Минимальная ширина для столбцов */
.wcmls-table-wrapper th,
.wcmls-table-wrapper td {
    min-width: 150px;
    white-space: nowrap;
}

/* Столбец "Общий запас WC" и "Сумма по локациям" */
.wcmls-table-wrapper th:nth-child(2),
.wcmls-table-wrapper td:nth-child(2),
.wcmls-table-wrapper th:nth-child(3),
.wcmls-table-wrapper td:nth-child(3) {
    min-width: 120px;
    font-weight: bold;
}

/* Стили для мобильных устройств */
@media screen and (max-width: 782px) {
    .wcmls-table-wrapper {
        margin: 0 -20px;
        width: calc(100% + 40px);
        border-left: none;
        border-right: none;
    }
    
    .wcmls-table-wrapper th:first-child,
    .wcmls-table-wrapper td:first-child {
        min-width: 150px;
    }
}

/* Стили для контролов таблицы */
.wcmls-table-controls {
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 10px;
}

.wcmls-table-controls .wcmls-location-count {
    margin-left: auto;
    color: #666;
    font-size: 13px;
}

/* Кнопки навигации */
.wcmls-table-controls .button {
    min-width: 100px;
}

.wcmls-table-controls .button:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

/* Заголовки таблицы остаются видимыми при скролле */
.wcmls-table-wrapper thead th {
    position: sticky;
    top: 0;
    background: #f9f9f9;
    z-index: 11;
    box-shadow: 0 2px 2px -1px rgba(0, 0, 0, 0.1);
}

/* Первая ячейка заголовка с более высоким z-index */
.wcmls-table-wrapper thead th:first-child {
    z-index: 12;
    background: #f9f9f9;
}';
    }
    
    public function validate_cart_location() {
        if (!$this->get_selected_location() && !is_admin()) {
            wc_add_notice(
                __('Пожалуйста, выберите вашу локацию перед оформлением заказа.', 'wc-multi-location-stock'),
                'error'
            );
        }
    }
    
    public function filter_products_by_location_stock($meta_query) {
        if (is_admin() || !$this->get_selected_location()) {
            return $meta_query;
        }
        
        // This is handled by the stock check filter instead
        return $meta_query;
    }
    
    public function customize_billing_city_field($fields) {
        $locations = $this->locations;
        
        if (!empty($locations)) {
            $options = ['' => __('Выберите город', 'wc-multi-location-stock')];
            
            foreach ($locations as $location_id => $location) {
                $options[$location['name']] = $location['name'];
            }
            
            $fields['billing_city']['type'] = 'select';
            $fields['billing_city']['options'] = $options;
            
            // Set default from selected location
            $selected_location = $this->get_selected_location();
            if ($selected_location && isset($locations[$selected_location])) {
                $fields['billing_city']['default'] = $locations[$selected_location]['name'];
                
                // Принудительно устанавливаем значение для текущей сессии
                if (WC()->customer) {
                    // Проверяем, если город не установлен или не соответствует нашим локациям
                    $current_city = WC()->customer->get_billing_city();
                    $valid_city = false;
                    foreach ($locations as $loc_id => $loc) {
                        if ($loc['name'] === $current_city) {
                            $valid_city = true;
                            break;
                        }
                    }
                    
                    // Если текущий город недействителен, устанавливаем из выбранной локации
                    if (!$valid_city) {
                        WC()->customer->set_billing_city($locations[$selected_location]['name']);
                    }
                }
            }
            
            // Делаем поле обязательным
            $fields['billing_city']['required'] = true;
        }
        
        return $fields;
    }
    
    public function set_checkout_city_value($value, $input) {
        // Устанавливаем значение города из выбранной локации
        if ($input === 'billing_city') {
            $selected_location = $this->get_selected_location();
            if ($selected_location && isset($this->locations[$selected_location])) {
                // Если значение еще не установлено, используем выбранную локацию
                if (empty($value)) {
                    $value = $this->locations[$selected_location]['name'];
                }
            }
        }
        return $value;
    }
    
    public function init_checkout_city($checkout) {
        // Инициализируем город при загрузке страницы оформления заказа
        $selected_location = $this->get_selected_location();
        if ($selected_location && isset($this->locations[$selected_location])) {
            $location_name = $this->locations[$selected_location]['name'];
            
            // Устанавливаем город в объекте клиента WooCommerce
            if (WC()->customer) {
                WC()->customer->set_billing_city($location_name);
            }
            
            // Устанавливаем значение в сессии
            if (WC()->session) {
                WC()->session->set('billing_city', $location_name);
            }
            
            // Логирование для отладки
            if (defined('WCMLS_DEBUG') && WCMLS_DEBUG) {
                error_log("WCMLS: Setting checkout city to '$location_name' for location '$selected_location'");
            }
        }
    }
    
    public function validate_checkout_location($data, $errors) {
        // Проверяем, что город соответствует одной из наших локаций
        if (isset($data['billing_city']) && !empty($data['billing_city'])) {
            $valid_city = false;
            foreach ($this->locations as $location_id => $location) {
                if ($location['name'] === $data['billing_city']) {
                    $valid_city = true;
                    break;
                }
            }
            
            if (!$valid_city) {
                $errors->add('validation', __('Пожалуйста, выберите корректную локацию из списка.', 'wc-multi-location-stock'));
            }
        } else {
            $errors->add('validation', __('Пожалуйста, выберите локацию доставки.', 'wc-multi-location-stock'));
        }
    }
    
    public function make_city_field_required($fields) {
        // Делаем поле города обязательным
        if (isset($fields['billing']['billing_city'])) {
            $fields['billing']['billing_city']['required'] = true;
        }
        return $fields;
    }
    
    public function process_order_stock($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        // Check if we already processed this order
        $processed = $order->get_meta('_wcmls_stock_processed');
        if ($processed) return;
        
        $billing_city = $order->get_billing_city();
        $location_id = null;
        
        // Find location by city name
        foreach ($this->locations as $loc_id => $location) {
            if ($location['name'] === $billing_city) {
                $location_id = $loc_id;
                break;
            }
        }
        
        // Если локация не найдена по городу, пробуем получить из сессии
        if (!$location_id) {
            $selected_location = $this->get_selected_location();
            if ($selected_location && isset($this->locations[$selected_location])) {
                $location_id = $selected_location;
                // Обновляем город в заказе для консистентности
                $order->set_billing_city($this->locations[$selected_location]['name']);
                $order->save();
            }
        }
        
        if (!$location_id) {
            // Логируем проблему
            if (defined('WCMLS_DEBUG') && WCMLS_DEBUG) {
                error_log("WCMLS: Cannot determine location for order #$order_id, billing_city: $billing_city");
            }
            return;
        }
        
        // Reduce stock for each item
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $quantity = $item->get_quantity();
            
            // Update location stock
            $current_stock = $this->get_product_location_stock($product_id, $location_id);
            $new_stock = max(0, $current_stock - $quantity);
            
            $this->update_location_stock($product_id, $location_id, $new_stock);
        }
        
        // После уменьшения запасов локации, синхронизировать общие запасы
        foreach ($order->get_items() as $item) {
            $this->sync_total_stock($item->get_product_id());
        }
        
        // Mark order as processed
        $order->update_meta_data('_wcmls_stock_processed', true);
        $order->update_meta_data('_wcmls_location', $location_id);
        $order->save();
        
        // Логирование успешной обработки
        if (defined('WCMLS_DEBUG') && WCMLS_DEBUG) {
            error_log("WCMLS: Successfully processed stock for order #$order_id at location '$location_id' ({$this->locations[$location_id]['name']})");
        }
    }
    
    public function restore_order_stock($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        // Check if we processed this order
        $processed = $order->get_meta('_wcmls_stock_processed');
        if (!$processed) return;
        
        $location_id = $order->get_meta('_wcmls_location');
        if (!$location_id || !isset($this->locations[$location_id])) {
            // Fallback to billing city
            $billing_city = $order->get_billing_city();
            foreach ($this->locations as $loc_id => $location) {
                if ($location['name'] === $billing_city) {
                    $location_id = $loc_id;
                    break;
                }
            }
        }
        
        if (!$location_id) return;
        
        // Restore stock for each item
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $quantity = $item->get_quantity();
            
            // Update location stock
            $current_stock = $this->get_product_location_stock($product_id, $location_id);
            $new_stock = $current_stock + $quantity;
            
            $this->update_location_stock($product_id, $location_id, $new_stock);
        }
        
        // После восстановления запасов локации, синхронизировать общие запасы
        foreach ($order->get_items() as $item) {
            $this->sync_total_stock($item->get_product_id());
        }
        
        // Remove processed flag
        $order->delete_meta_data('_wcmls_stock_processed');
        $order->save();
    }
    
    public function sync_stock_on_order_complete($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        // Синхронизируем запасы для всех товаров в заказе
        // Вызывается при статусах "completed" и "processing"
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $this->sync_total_stock($product_id);
        }
        
        // Логирование для отладки
        if (defined('WCMLS_DEBUG') && WCMLS_DEBUG) {
            $status = $order->get_status();
            error_log("WCMLS: Synced stock for order #$order_id with status: $status");
        }
    }
    
    public function filter_orders_for_location_manager($query) {
        if (!is_admin() || !$query->is_main_query()) {
            return;
        }
        
        if (!current_user_can('location_manager')) {
            return;
        }
        
        $screen = get_current_screen();
        if (!$screen) {
            return;
        }
        
        // Check for both legacy orders and HPOS
        $is_orders_page = ($screen->id === 'edit-shop_order' || $screen->id === 'woocommerce_page_wc-orders');
        
        if (!$is_orders_page) {
            return;
        }
        
        $user_location = $this->get_user_location(get_current_user_id());
        if (!$user_location || !isset($this->locations[$user_location])) {
            return;
        }
        
        $location_name = $this->locations[$user_location]['name'];
        
        // For HPOS compatibility
        if (class_exists('\Automattic\WooCommerce\Utilities\OrderUtil') && 
            \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()) {
            // HPOS is enabled
            add_filter('woocommerce_order_query_args', function($args) use ($location_name) {
                $args['billing_city'] = $location_name;
                return $args;
            });
            
            // Also filter the orders list table query
            add_filter('woocommerce_orders_table_query_clauses', function($clauses, $query, $query_vars) use ($location_name) {
                global $wpdb;
                $orders_table = $wpdb->prefix . 'wc_orders';
                $clauses['where'] .= $wpdb->prepare(" AND {$orders_table}.billing_city = %s", $location_name);
                return $clauses;
            }, 10, 3);
        } else {
            // Legacy orders
            $query->set('meta_key', '_billing_city');
            $query->set('meta_value', $location_name);
        }
    }
    
    public function filter_order_views($views) {
        if (current_user_can('location_manager')) {
            // Remove some views for location managers
            unset($views['trash']);
        }
        return $views;
    }
    
    public function filter_hpos_orders_query($query_args) {
        if (!current_user_can('location_manager')) {
            return $query_args;
        }
        
        $user_location = $this->get_user_location(get_current_user_id());
        if (!$user_location || !isset($this->locations[$user_location])) {
            return $query_args;
        }
        
        $location_name = $this->locations[$user_location]['name'];
        
        // Add billing city filter for HPOS
        $query_args['billing_city'] = $location_name;
        
        return $query_args;
    }
}

// Initialize the plugin
function wcmls_init() {
    if (class_exists('WooCommerce')) {
        WC_Multi_Location_Stock::instance();
    } else {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>' . 
                 esc_html__('WooCommerce Multi-Location Stock требует установленный и активированный WooCommerce.', 'wc-multi-location-stock') . 
                 '</p></div>';
        });
    }
}
add_action('plugins_loaded', 'wcmls_init', 11); // Load after WooCommerce

// Create or update frontend.js file on init
add_action('init', function() {
    $js_dir = plugin_dir_path(__FILE__) . 'assets/js/';
    
    if (!file_exists($js_dir)) {
        wp_mkdir_p($js_dir);
    }
    
    // Force update the frontend.js file with the fixed version
    $frontend_js = 'jQuery(document).ready(function($) {
    // Проверяем, что wcmls_ajax определен
    if (typeof wcmls_ajax === "undefined") {
        console.error("wcmls_ajax is not defined!");
        return;
    }
    
    // Handle location selection with event delegation
    $(document).on("change", "#wcmls-location", function() {
        var location = $(this).val();
        
        if (!location) {
            alert(wcmls_ajax.strings.select_location);
            return;
        }
        
        $.ajax({
            url: wcmls_ajax.ajax_url,
            type: "POST",
            data: {
                action: "wcmls_set_location",
                location: location,
                nonce: wcmls_ajax.nonce
            },
            success: function(response) {
                if (response.success) {
                    // Если мы на странице оформления заказа, обновляем поле города
                    if ($("#billing_city").length > 0 && response.data.location_name) {
                        $("#billing_city").val(response.data.location_name);
                        $("#billing_city").trigger("change");
                    }
                    
                    alert(wcmls_ajax.strings.location_changed);
                    
                    // Перезагружаем страницу только если не на странице оформления заказа
                    if (!$("body").hasClass("woocommerce-checkout")) {
                        window.location.reload();
                    }
                }
            },
            error: function(xhr, status, error) {
                console.error("AJAX error:", status, error);
                alert("Ошибка при изменении локации. Попробуйте еще раз.");
            }
        });
    });
    
    // Validate location before add to cart
    $("form.cart").on("submit", function(e) {
        if (!document.cookie.includes("wcmls_selected_location")) {
            e.preventDefault();
            alert(wcmls_ajax.strings.select_location);
            return false;
        }
    });
    
    // Флаг для предотвращения рекурсивных обновлений
    var cityUpdateInProgress = false;
    
    // Синхронизация города на странице оформления заказа
    if ($("body").hasClass("woocommerce-checkout")) {
        // Функция для установки города
        function setCheckoutCity() {
            if (cityUpdateInProgress) return;
            
            var billingCity = $("#billing_city");
            if (billingCity.length > 0 && wcmls_ajax.selected_location_name) {
                cityUpdateInProgress = true;
                billingCity.val(wcmls_ajax.selected_location_name);
                
                // Небольшая задержка перед снятием флага
                setTimeout(function() {
                    cityUpdateInProgress = false;
                }, 100);
            }
        }
        
        // Устанавливаем город при загрузке страницы
        setCheckoutCity();
        
        // Не реагируем на событие updated_checkout чтобы избежать циклов
        // Вместо этого просто устанавливаем значение один раз при загрузке
    }
});';
    
    // Always recreate the file to ensure it has the latest version
    file_put_contents($js_dir . 'frontend.js', $frontend_js);
});
?>
