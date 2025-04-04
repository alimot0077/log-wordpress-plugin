<?php
if (!defined('ABSPATH')) {
    exit;
}

// تابع دریافت تنظیمات از فایل
if (!function_exists('wc_get_sku_settings')) {
    function wc_get_sku_settings()
    {
        $settings_file = plugin_dir_path(__FILE__) . 'sku-settings.json';

        if (!file_exists($settings_file)) {
            $default_settings = [
                'sku_length' => 5,
                'sku_prefix' => '',
                'sku_suffix' => '',
            ];
            file_put_contents($settings_file, json_encode($default_settings));
            return $default_settings;
        }

        $settings = json_decode(file_get_contents($settings_file), true);
        return $settings ?: [
            'sku_length' => 5,
            'sku_prefix' => '',
            'sku_suffix' => '',
        ];
    }
}

// تولید و ذخیره SKU‌های جدید با توجه به تنظیمات فعلی
if (!function_exists('regenerate_skus_based_on_settings')) {
    function regenerate_skus_based_on_settings($settings) {
        $sku_file = plugin_dir_path(__FILE__) . 'generated-skus.json';

        // حذف فایل موجود و تولید مجدد SKUها
        if (file_exists($sku_file)) {
            unlink($sku_file);
        }

        $unique_combinations = generate_unique_combinations($settings['sku_length']);
        $formatted_skus = array_map(function ($sku) use ($settings) {
            return $settings['sku_prefix'] . $sku . $settings['sku_suffix'];
        }, $unique_combinations);

        file_put_contents($sku_file, json_encode($formatted_skus));
    }
}

// چک کردن و تولید SKU جدید در صورت تکراری بودن
if (!function_exists('get_next_unique_sku')) {
    function get_next_unique_sku(&$all_combinations, $settings) {
        while (!empty($all_combinations)) {
            $sku = array_pop($all_combinations);
            $formatted_sku = $settings['sku_prefix'] . $sku . $settings['sku_suffix'];

            if (is_unique_sku($formatted_sku)) {
                return $formatted_sku;
            }
        }

        throw new Exception(__('No more unique SKUs available.', 'wc-sku-generator'));
    }
}

// ذخیره لیست تمامی SKUهای موجود
if (!function_exists('save_existing_skus')) {
    function save_existing_skus() {
        global $wpdb;
        $existing_skus_file = plugin_dir_path(__FILE__) . 'existing-skus.json';
        
        $offset = 0;
        $limit = 10;
        $existing_skus = [];
        
        do {
            $results = $wpdb->get_col($wpdb->prepare(
                "SELECT meta_value FROM {$wpdb->prefix}postmeta WHERE meta_key = '_sku' AND meta_value IS NOT NULL LIMIT %d OFFSET %d",
                $limit, $offset
            ));
            
            $existing_skus = array_merge($existing_skus, $results);
            $offset += $limit;
        } while (count($results) > 0);
        
        file_put_contents($existing_skus_file, json_encode(array_values(array_unique($existing_skus))));
    }
}

// بررسی یونیک بودن SKU تولیدی
if (!function_exists('is_unique_sku')) {
    function is_unique_sku($sku) {
        $existing_skus_file = plugin_dir_path(__FILE__) . 'existing-skus.json';
        
        if (!file_exists($existing_skus_file)) {
            return true;
        }
        
        $existing_skus = json_decode(file_get_contents($existing_skus_file), true);
        return !in_array($sku, $existing_skus);
    }
}

// تابع تولید ترکیب‌های یکتا
if (!function_exists('generate_unique_combinations')) {
    function generate_unique_combinations($length)
    {
        if ($length < 1 || $length > 10) {
            return []; // طول نامعتبر
        }

        $max_combinations = pow(10, $length); // حداکثر تعداد ترکیب ممکن
        $combinations = [];

        for ($i = 0; $i < $max_combinations; $i++) {
            $combinations[] = str_pad($i, $length, '0', STR_PAD_LEFT); // تولید اعداد با طول ثابت
        }

        shuffle($combinations); // مرتب‌سازی تصادفی ترکیب‌ها
        return $combinations;
    }
}

// اجرای فرآیند تولید SKU
if (!function_exists('wc_generate_skus')) {
    function wc_generate_skus() {
        try {
            if (!class_exists('WooCommerce')) {
                wp_send_json_error(['message' => 'WooCommerce is not active.']);
                return;
            }

            check_ajax_referer('generate_skus_nonce', 'security');

            $settings = wc_get_sku_settings();

            // بازتولید SKU‌ها بر اساس تنظیمات جدید
            regenerate_skus_based_on_settings($settings);

            $offset = isset($_POST['offset']) ? intval($_POST['offset']) : 0;
            $apply_to = isset($_POST['apply_to']) ? sanitize_text_field($_POST['apply_to']) : 'all';

            $all_combinations = json_decode(file_get_contents(plugin_dir_path(__FILE__) . 'generated-skus.json'), true);

            $args = [
                'limit' => 10,
                'offset' => $offset,
                'status' => 'publish',
            ];

            if ($apply_to === 'missing') {
                $args['meta_query'] = [
                    'relation' => 'OR',
                    [
                        'key' => '_sku',
                        'value' => '',
                        'compare' => '='
                    ],
                    [
                        'key' => '_sku',
                        'compare' => 'NOT EXISTS',
                    ],
                ];
            }

            $products = wc_get_products($args);

            if (empty($products)) {
                wp_send_json_success([
                    'log' => __('No more products to update.', 'wc-sku-generator'),
                    'progress' => 100,
                    'message' => __('SKU generation completed!', 'wc-sku-generator'),
                    'offset' => $offset,
                    'completed' => true,
                ]);
                return;
            }

            $log = '';
            foreach ($products as $product) {
                try {
                    $sku = get_next_unique_sku($all_combinations, $settings);
                    $product->set_sku($sku);
                    $product->save();

                    $log .= sprintf(
                        __('Generated SKU for %s (ID: %d): %s', 'wc-sku-generator'),
                        $product->get_name(),
                        $product->get_id(),
                        $sku
                    ) . "\n";
                } catch (Exception $e) {
                    $log .= sprintf(
                        __('Failed to generate SKU for %s (ID: %d): %s', 'wc-sku-generator'),
                        $product->get_name(),
                        $product->get_id(),
                        $e->getMessage()
                    ) . "\n";
                }
            }

            wp_send_json_success([
                'log' => nl2br($log),
                'progress' => ($offset + count($products)) * 100 / wc_count_all_products(),
                'message' => sprintf(
                    __('تولید کدهای SKU در حال انجام است: %d محصول به‌روزرسانی شد.', 'wc-sku-generator'),
                    count($products)
                ),
                'offset' => $offset + count($products),
            ]);
        } catch (Exception $e) {
            wp_send_json_error(['message' => $e->getMessage()]);
        }
    }
}

// اضافه کردن اکشن AJAX
add_action('wp_ajax_generate_skus', 'wc_generate_skus');
// شمارش تمامی محصولات
if (!function_exists('wc_count_all_products')) {
    function wc_count_all_products() {
        global $wpdb;

        $query = "
            SELECT COUNT(*)
            FROM {$wpdb->prefix}posts
            WHERE post_type = 'product'
            AND post_status = 'publish'
        ";

        return intval($wpdb->get_var($query));
    }
}

// شمارش محصولات بدون SKU
if (!function_exists('wc_count_products_missing_sku')) {
    function wc_count_products_missing_sku() {
        global $wpdb;

        $query = "
            SELECT COUNT(*)
            FROM {$wpdb->prefix}posts p
            LEFT JOIN {$wpdb->prefix}postmeta pm ON p.ID = pm.post_id AND pm.meta_key = '_sku'
            WHERE p.post_type = 'product'
            AND p.post_status = 'publish'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
        ";

        return intval($wpdb->get_var($query));
    }
}

// صفحه مدیریت تنظیمات و عملیات
if (!function_exists('wc_sku_generator_operations_page')) {
    function wc_sku_generator_operations_page() {
        $settings = wc_get_sku_settings();
        $missing_sku_count = wc_count_products_missing_sku();
        $total_products = wc_count_all_products();

        ?>
        <div class="wrap">
            <h1><?php _e('مدیریت تولید SKU', 'wc-sku-generator'); ?></h1>
            <p>
                <?php _e('تنظیمات فعلی شما:', 'wc-sku-generator'); ?><br>
                <strong><?php _e('طول:', 'wc-sku-generator'); ?></strong> <?php echo esc_html($settings['sku_length']); ?><br>
                <strong><?php _e('پیشوند:', 'wc-sku-generator'); ?></strong> <?php echo esc_html($settings['sku_prefix']); ?><br>
                <strong><?php _e('پسوند:', 'wc-sku-generator'); ?></strong> <?php echo esc_html($settings['sku_suffix']); ?><br>
            </p>
            <form id="sku-options-form">
                <label>
                    <input type="radio" name="apply_to" value="all" checked>
                    <?php printf(
                        __('اعمال تغییرات به همه محصولات (%d محصول)', 'wc-sku-generator'),
                        $total_products
                    ); ?>
                </label><br>
                <label>
                    <input type="radio" name="apply_to" value="missing">
                    <?php printf(
                        __('فقط محصولات بدون SKU (%d محصول)', 'wc-sku-generator'),
                        $missing_sku_count
                    ); ?>
                </label><br>
                <button id="start-sku-generation" class="button button-primary">
                    <?php _e('شروع تولید SKU', 'wc-sku-generator'); ?>
                </button>
            </form>
            <div id="progress-container" style="display: none;">
                <progress id="progress-bar" max="100" value="0"></progress>
                <p id="progress-status"></p>
            </div>
            <div id="sku-log" style="margin-top: 20px; max-height: 300px; overflow-y: scroll; border: 1px solid #ddd; padding: 10px;"></div>
        </div>
        <script>
            jQuery(document).ready(function ($) {
                $('#start-sku-generation').click(function (e) {
                    e.preventDefault();

                    $('#progress-container').show();
                    $('#sku-log').empty();

                    let applyTo = $('input[name="apply_to"]:checked').val();
                    let offset = 0;

                    function processBatch() {
                        $.post(ajaxurl, {
                            action: 'generate_skus',
                            apply_to: applyTo,
                            offset: offset,
                            security: '<?php echo wp_create_nonce('generate_skus_nonce'); ?>'
                        }).done(function (response) {
                            if (response.success) {
                                $('#sku-log').append('<p>' + response.data.log + '</p>');
                                $('#progress-bar').val(response.data.progress);
                                $('#progress-status').text(response.data.message);

                                if (!response.data.completed) {
                                    offset = response.data.offset;
                                    processBatch();
                                } else {
                                    alert('<?php _e('فرایند با موفقیت به پایان رسید!', 'wc-sku-generator'); ?>');
                                }
                            } else {
                                $('#sku-log').append('<p style="color: red;">' + response.data.message + '</p>');
                            }
                        }).fail(function () {
                            $('#sku-log').append('<p style="color: red;"><?php _e('خطا در درخواست AJAX.', 'wc-sku-generator'); ?></p>');
                        });
                    }

                    processBatch();
                });
            });
        </script>
        <?php
    }
}


;
