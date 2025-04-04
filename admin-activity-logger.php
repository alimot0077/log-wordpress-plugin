<?php
/**
 * Plugin Name: Admin Activity Logger
 * Description: ثبت فعالیت‌های مدیران وردپرس
 * Version: 1.0.0
 * Author: Your Name
 */

// امنیت
defined('ABSPATH') or die('دسترسی مستقیم ممنوع است!');

// ایجاد جدول در دیتابیس هنگام نصب پلاگین
function aal_create_log_table() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'admin_activity_log';
    
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE IF NOT EXISTS $table_name (
        id bigint(20) NOT NULL AUTO_INCREMENT,
        user_id bigint(20) NOT NULL,
        username varchar(60) NOT NULL,
        action_type varchar(255) NOT NULL,
        action_description text NOT NULL,
        ip_address varchar(45) NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}
register_activation_hook(__FILE__, 'aal_create_log_table');

// افزودن منو به بخش تنظیمات
function aal_add_menu() {
    add_menu_page(
        'گزارش فعالیت مدیران',
        'گزارش فعالیت‌ها',
        'manage_options',
        'admin-activity-log',
        'aal_display_logs',
        'dashicons-list-view'
    );
}
add_action('admin_menu', 'aal_add_menu');

// ثبت فعالیت‌ها
function aal_log_activity($action_type, $description) {
    global $wpdb;
    $current_user = wp_get_current_user();
    
    $wpdb->insert(
        $wpdb->prefix . 'admin_activity_log',
        array(
            'user_id' => $current_user->ID,
            'username' => $current_user->user_login,
            'action_type' => $action_type,
            'action_description' => $description,
            'ip_address' => $_SERVER['REMOTE_ADDR']
        ),
        array('%d', '%s', '%s', '%s', '%s')
    );
}

// ثبت ورود کاربران مدیر
function aal_log_login($user_login, $user) {
    if (in_array('administrator', $user->roles)) {
        aal_log_activity('login', sprintf('مدیر %s وارد سیستم شد', $user_login));
    }
}
add_action('wp_login', 'aal_log_login', 10, 2);

// ثبت تغییرات در پست‌ها
function aal_log_post_changes($post_id) {
    if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
    
    $post = get_post($post_id);
    aal_log_activity(
        'post_update',
        sprintf('پست "%s" بروزرسانی شد', $post->post_title)
    );
}
add_action('save_post', 'aal_log_post_changes');

// نمایش لاگ‌ها
function aal_display_logs() {
    global $wpdb;
    $table_name = $wpdb->prefix . 'admin_activity_log';
    $logs = $wpdb->get_results("SELECT * FROM $table_name ORDER BY created_at DESC");
    ?>
    <div class="wrap">
        <h1>گزارش فعالیت مدیران</h1>
        <table class="widefat fixed" style="margin-top: 20px;">
            <thead>
                <tr>
                    <th>نام کاربری</th>
                    <th>نوع فعالیت</th>
                    <th>توضیحات</th>
                    <th>آی‌پی</th>
                    <th>تاریخ و زمان</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($logs as $log): ?>
                <tr>
                    <td><?php echo esc_html($log->username); ?></td>
                    <td><?php echo esc_html($log->action_type); ?></td>
                    <td><?php echo esc_html($log->action_description); ?></td>
                    <td><?php echo esc_html($log->ip_address); ?></td>
                    <td><?php echo esc_html($log->created_at); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php
}

// ثبت تغییرات در تنظیمات
function aal_log_option_changes($option_name) {
    aal_log_activity(
        'setting_update',
        sprintf('تنظیمات "%s" تغییر کرد', $option_name)
    );
}
add_action('updated_option', 'aal_log_option_changes');

// ثبت نصب و حذف افزونه‌ها
function aal_log_plugin_changes($plugin_name) {
    aal_log_activity(
        'plugin_change',
        sprintf('افزونه "%s" تغییر کرد', $plugin_name)
    );
}
add_action('activated_plugin', 'aal_log_plugin_changes');
add_action('deactivated_plugin', 'aal_log_plugin_changes');