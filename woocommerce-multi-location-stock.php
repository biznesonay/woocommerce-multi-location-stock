<?php
/**
 * Plugin Name: WooCommerce Multi-Location Stock
 * Plugin URI: https://biznesonay.kz/
 * Description: Управление складом по локациям для WooCommerce с ролью Менеджер Локации
 * Version: 1.0.0
 * Author: BiznesOnay
 * Text Domain: wc-multi-location-stock
 * Domain Path: /languages
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 9.8.5
 * WC tested up to: 9.8.5
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
        define('WCMLS_VERSION', '1.0.0');
        define('WCMLS_PLUGIN_URL', plugin_dir_url(__FILE__));
        define('WCMLS_PLUGIN_PATH', plugin_dir_path(__FILE__));
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
        
        // Ajax handlers
        add_action('wp_ajax_wcmls_set_location', [$this, 'ajax_set_location']);
        add_action('wp_ajax_nopriv_wcmls_set_location', [$this, 'ajax_set_location']);
        add_action('wp_ajax_wcmls_update_stock', [$this, 'ajax_update_stock']);
        
        // Order filters for location managers
        add_action('pre_get_posts', [$this, 'filter_orders_for_location_manager']);
        add_filter('views_edit-shop_order', [$this, 'filter_order_views']);
    }
    
    public function activate() {
        $this->create_location_manager_role();
        $this->create_tables();
        
        // Set default options
        if (!get_option('wcmls_locations')) {
            update_option('wcmls_locations', []);
        }
        
        flush_rewrite_rules();
    }
    
    public function deactivate() {
        flush_rewrite_rules();
    }
    
    private function create_location_manager_role() {
        $capabilities = [
            'read' => true,
            'edit_shop_orders' => true,
            'read_shop_order' => true,
            'edit_products' => true,
            'edit_product' => true,
            'read_product' => true,
            'delete_product' => false,
            'edit_others_products' => false,
            'publish_products' => true,
            'read_private_products' => true,
            'delete_products' => false,
            'delete_private_products' => false,
            'delete_published_products' => false,
            'delete_others_products' => false,
            'edit_private_products' => false,
            'edit_published_products' => true,
            'manage_product_terms' => true,
            'edit_product_terms' => true,
            'delete_product_terms' => false,
            'assign_product_terms' => true,
            'upload_files' => true,
        ];
        
        add_role('location_manager', __('Менеджер Локации', 'wc-multi-location-stock'), $capabilities);
    }
    
    private function create_tables() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$wpdb->prefix}wcmls_location_stock (
            id int(11) NOT NULL AUTO_INCREMENT,
            product_id bigint(20) NOT NULL,
            location_id varchar(50) NOT NULL,
            stock_quantity int(11) DEFAULT 0,
            PRIMARY KEY (id),
            UNIQUE KEY product_location (product_id, location_id)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    public function init() {
        load_plugin_textdomain('wc-multi-location-stock', false, dirname(plugin_basename(__FILE__)) . '/languages');
        $this->locations = get_option('wcmls_locations', []);
    }
    
    public function add_admin_menu() {
        // Main settings page
        add_submenu_page(
            'woocommerce',
            __('Multi-Location Stock', 'wc-multi-location-stock'),
            __('Multi-Location Stock', 'wc-multi-location-stock'),
            'manage_woocommerce',
            'wcmls-settings',
            [$this, 'settings_page']
        );
        
        // Stock management page
        add_submenu_page(
            'woocommerce',
            __('Склад', 'wc-multi-location-stock'),
            __('Склад', 'wc-multi-location-stock'),
            'edit_products',
            'wcmls-stock',
            [$this, 'stock_management_page']
        );
    }
    
    public function restrict_admin_access() {
        if (!current_user_can('location_manager')) {
            return;
        }
        
        // Get current page
        global $pagenow;
        $current_page = isset($_GET['page']) ? $_GET['page'] : '';
        
        // Allowed pages for location manager
        $allowed_pages = ['edit.php', 'post.php', 'post-new.php', 'upload.php', 'profile.php'];
        $allowed_post_types = ['product', 'shop_order'];
        $allowed_wc_pages = ['wcmls-stock'];
        
        // Check if accessing WooCommerce pages
        if ($pagenow === 'admin.php' && $current_page && !in_array($current_page, $allowed_wc_pages)) {
            if (strpos($current_page, 'wc-') === 0 || strpos($current_page, 'woocommerce') !== false) {
                wp_die(__('У вас нет прав для доступа к этой странице.', 'wc-multi-location-stock'));
            }
        }
        
        // Check post type restrictions
        if (in_array($pagenow, ['edit.php', 'post.php', 'post-new.php'])) {
            $post_type = isset($_GET['post_type']) ? $_GET['post_type'] : '';
            if (!$post_type && isset($_GET['post'])) {
                $post_type = get_post_type($_GET['post']);
            }
            
            if ($post_type && !in_array($post_type, $allowed_post_types)) {
                wp_die(__('У вас нет прав для доступа к этой странице.', 'wc-multi-location-stock'));
            }
        }
        
        // Hide admin menu items
        add_action('admin_menu', function() {
            if (!current_user_can('location_manager')) {
                return;
            }
            
            // Remove main menu items
            $restricted_menus = [
                'index.php', 'edit-comments.php', 'themes.php', 'plugins.php',
                'users.php', 'tools.php', 'options-general.php'
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
        }, 999);
    }
    
    public function settings_page() {
        if (isset($_POST['wcmls_save_settings'])) {
            $this->save_settings();
        }
        
        $locations = $this->locations;
        $users = get_users(['role' => 'location_manager']);
        ?>
        <div class="wrap">
            <h1><?php _e('WooCommerce Multi-Location Stock Settings', 'wc-multi-location-stock'); ?></h1>
            
            <div class="notice notice-info">
                <p><?php _e('Шорткод для выбора локации:', 'wc-multi-location-stock'); ?> <code>[location_selector]</code></p>
                <p><?php _e('Вставьте этот шорткод на любую страницу, где покупатели должны выбирать свою локацию.', 'wc-multi-location-stock'); ?></p>
            </div>
            
            <form method="post" action="">
                <?php wp_nonce_field('wcmls_settings', 'wcmls_nonce'); ?>
                
                <h2><?php _e('Управление локациями', 'wc-multi-location-stock'); ?></h2>
                
                <table class="wp-list-table widefat fixed striped">
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
                            <p class="description"><?php _e('Уникальный идентификатор (латиница, без пробелов)', 'wc-multi-location-stock'); ?></p>
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
        });
        </script>
        <?php
    }
    
    private function save_settings() {
        if (!isset($_POST['wcmls_nonce']) || !wp_verify_nonce($_POST['wcmls_nonce'], 'wcmls_settings')) {
            return;
        }
        
        $locations = $this->locations;
        
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
            if (!isset($locations[$new_id])) {
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
        
        add_action('admin_notices', function() {
            echo '<div class="notice notice-success"><p>' . __('Настройки сохранены.', 'wc-multi-location-stock') . '</p></div>';
        });
    }
    
    public function stock_management_page() {
        $current_user_id = get_current_user_id();
        $is_location_manager = current_user_can('location_manager');
        $user_location = $this->get_user_location($current_user_id);
        
        ?>
        <div class="wrap">
            <h1><?php _e('Управление складом', 'wc-multi-location-stock'); ?></h1>
            
            <?php if ($is_location_manager && !$user_location): ?>
                <div class="notice notice-error">
                    <p><?php _e('Вы не привязаны ни к одной локации. Обратитесь к администратору.', 'wc-multi-location-stock'); ?></p>
                </div>
                <?php return; ?>
            <?php endif; ?>
            
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th><?php _e('Товар', 'wc-multi-location-stock'); ?></th>
                        <?php foreach ($this->locations as $location_id => $location): ?>
                            <?php if (!$is_location_manager || $user_location === $location_id): ?>
                                <th><?php echo esc_html($location['name']); ?></th>
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
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($product->get_name()); ?></strong>
                                <br><small>SKU: <?php echo esc_html($product->get_sku()); ?></small>
                            </td>
                            <?php foreach ($this->locations as $location_id => $location): ?>
                                <?php if (!$is_location_manager || $user_location === $location_id): ?>
                                    <td>
                                        <?php
                                        $stock = $this->get_product_location_stock($product->get_id(), $location_id);
                                        $readonly = $is_location_manager && $user_location !== $location_id ? 'readonly' : '';
                                        ?>
                                        <input type="number" 
                                               class="location-stock-input" 
                                               data-product="<?php echo esc_attr($product->get_id()); ?>" 
                                               data-location="<?php echo esc_attr($location_id); ?>" 
                                               value="<?php echo esc_attr($stock); ?>" 
                                               min="0" 
                                               <?php echo $readonly; ?> />
                                    </td>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('.location-stock-input').on('change', function() {
                var $input = $(this);
                var productId = $input.data('product');
                var locationId = $input.data('location');
                var quantity = $input.val();
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'wcmls_update_stock',
                        product_id: productId,
                        location_id: locationId,
                        quantity: quantity,
                        nonce: '<?php echo wp_create_nonce('wcmls_update_stock'); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            $input.css('background-color', '#d4edda');
                            setTimeout(function() {
                                $input.css('background-color', '');
                            }, 1000);
                        } else {
                            alert(response.data);
                            $input.val($input.data('original-value'));
                        }
                    }
                });
            }).on('focus', function() {
                $(this).data('original-value', $(this).val());
            });
        });
        </script>
        <?php
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
        $quantity = absint($_POST['quantity']);
        
        // Check if location manager can edit this location
        if (current_user_can('location_manager')) {
            $user_location = $this->get_user_location(get_current_user_id());
            if ($user_location !== $location_id) {
                wp_send_json_error(__('Вы можете изменять запасы только для своей локации.', 'wc-multi-location-stock'));
            }
        }
        
        $this->update_location_stock($product_id, $location_id, $quantity);
        
        wp_send_json_success(__('Запас обновлен.', 'wc-multi-location-stock'));
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
        
        $stock = $wpdb->get_var($wpdb->prepare(
            "SELECT stock_quantity FROM {$wpdb->prefix}wcmls_location_stock 
            WHERE product_id = %d AND location_id = %s",
            $product_id,
            $location_id
        ));
        
        return $stock !== null ? $stock : 0;
    }
    
    private function update_location_stock($product_id, $location_id, $quantity) {
        global $wpdb;
        
        $wpdb->replace(
            $wpdb->prefix . 'wcmls_location_stock',
            [
                'product_id' => $product_id,
                'location_id' => $location_id,
                'stock_quantity' => $quantity
            ],
            ['%d', '%s', '%d']
        );
    }
    
    public function get_location_stock($stock, $product) {
        if (!is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            $selected_location = $this->get_selected_location();
            if ($selected_location) {
                $location_stock = $this->get_product_location_stock($product->get_id(), $selected_location);
                return $location_stock;
            }
        }
        return $stock;
    }
    
    public function check_location_stock($is_in_stock, $product) {
        if (!is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) {
            $selected_location = $this->get_selected_location();
            if ($selected_location) {
                $location_stock = $this->get_product_location_stock($product->get_id(), $selected_location);
                return $location_stock > 0;
            }
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
        <?php
        return ob_get_clean();
    }
    
    public function ajax_set_location() {
        $location = isset($_POST['location']) ? sanitize_key($_POST['location']) : '';
        
        if ($location && isset($this->locations[$location])) {
            setcookie('wcmls_selected_location', $location, time() + (86400 * 30), '/');
            
            if (is_user_logged_in()) {
                update_user_meta(get_current_user_id(), 'wcmls_selected_location', $location);
            }
            
            // Clear cart if location changed
            if (WC()->cart && $this->get_selected_location() !== $location) {
                WC()->cart->empty_cart();
            }
            
            wp_send_json_success(['message' => __('Локация установлена.', 'wc-multi-location-stock')]);
        } else {
            wp_send_json_error(__('Неверная локация.', 'wc-multi-location-stock'));
        }
    }
    
    public function enqueue_scripts() {
        wp_enqueue_script(
            'wcmls-frontend',
            WCMLS_PLUGIN_URL . 'assets/js/frontend.js',
            ['jquery'],
            WCMLS_VERSION,
            true
        );
        
        wp_localize_script('wcmls-frontend', 'wcmls_ajax', [
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wcmls_nonce'),
            'strings' => [
                'select_location' => __('Пожалуйста, выберите локацию перед добавлением товара в корзину.', 'wc-multi-location-stock'),
                'location_changed' => __('Локация изменена. Ваша корзина была очищена.', 'wc-multi-location-stock')
            ]
        ]);
    }
    
    public function admin_enqueue_scripts($hook) {
        if (in_array($hook, ['woocommerce_page_wcmls-settings', 'woocommerce_page_wcmls-stock'])) {
            wp_enqueue_style(
                'wcmls-admin',
                WCMLS_PLUGIN_URL . 'assets/css/admin.css',
                [],
                WCMLS_VERSION
            );
        }
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
            }
        }
        
        return $fields;
    }
    
    public function process_order_stock($order_id) {
        $order = wc_get_order($order_id);
        if (!$order) return;
        
        $billing_city = $order->get_billing_city();
        $location_id = null;
        
        // Find location by city name
        foreach ($this->locations as $loc_id => $location) {
            if ($location['name'] === $billing_city) {
                $location_id = $loc_id;
                break;
            }
        }
        
        if (!$location_id) return;
        
        // Reduce stock for each item
        foreach ($order->get_items() as $item) {
            $product_id = $item->get_product_id();
            $quantity = $item->get_quantity();
            
            $current_stock = $this->get_product_location_stock($product_id, $location_id);
            $new_stock = max(0, $current_stock - $quantity);
            
            $this->update_location_stock($product_id, $location_id, $new_stock);
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
        if (!$screen || $screen->id !== 'edit-shop_order') {
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
}

// Initialize the plugin
function wcmls_init() {
    if (class_exists('WooCommerce')) {
        WC_Multi_Location_Stock::instance();
    } else {
        add_action('admin_notices', function() {
            echo '<div class="error"><p>' . 
                 __('WooCommerce Multi-Location Stock требует установленный и активированный WooCommerce.', 'wc-multi-location-stock') . 
                 '</p></div>';
        });
    }
}
add_action('plugins_loaded', 'wcmls_init');

// Create frontend.js file content
add_action('init', function() {
    $js_dir = plugin_dir_path(__FILE__) . 'assets/js/';
    $css_dir = plugin_dir_path(__FILE__) . 'assets/css/';
    
    if (!file_exists($js_dir)) {
        wp_mkdir_p($js_dir);
    }
    
    if (!file_exists($css_dir)) {
        wp_mkdir_p($css_dir);
    }
    
    $frontend_js = 'jQuery(document).ready(function($) {
    // Handle location selection
    $("#wcmls-location").on("change", function() {
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
                    location.reload();
                }
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
});';
    
    $admin_css = '.wcmls-location-selector {
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
}';
    
    file_put_contents($js_dir . 'frontend.js', $frontend_js);
    file_put_contents($css_dir . 'admin.css', $admin_css);
});
