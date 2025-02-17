<?php
/*
Plugin Name: Subscription Manager Pro
Description: Manage subscriptions and automatic emails for customers
Version: 10.0
Author: iptvgateway
Date: 2025-01-20 15:46:29
*/

if (!defined('ABSPATH')) {
    exit;
}

function smp_create_tables() {
    global $wpdb;
    $charset_collate = $wpdb->get_charset_collate();

    $table_customers = $wpdb->prefix . 'smp_customers';
    $sql_customers = "CREATE TABLE IF NOT EXISTS $table_customers (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(100) DEFAULT NULL,
        email varchar(100) NOT NULL,
        phone varchar(20) DEFAULT NULL,
        devices int(2) NOT NULL DEFAULT 1,
        plan_type varchar(20) DEFAULT '1 month',
        start_date datetime DEFAULT CURRENT_TIMESTAMP,
        end_date datetime NOT NULL,
        status varchar(20) DEFAULT 'active',
        enabled tinyint(1) DEFAULT 1,
        PRIMARY KEY  (id),
        KEY idx_email (email),
        KEY idx_name (name)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_customers);

    // Add plan_type column if it doesn't exist
    $check_column = $wpdb->get_results("SHOW COLUMNS FROM {$table_customers} LIKE 'plan_type'");
    if (empty($check_column)) {
        $wpdb->query("ALTER TABLE {$table_customers} ADD COLUMN plan_type varchar(20) DEFAULT '1 month' AFTER devices");
    }

    // Create notification systems table
    $table_notification_systems = $wpdb->prefix . 'smp_notification_systems';
    $sql_notification_systems = "CREATE TABLE IF NOT EXISTS $table_notification_systems (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        subscription_type varchar(20) NOT NULL,
        notification_timing varchar(20) NOT NULL,
        days_value int NOT NULL,
        frequency int NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    // Create notification templates table with enabled field
    $table_notification_templates = $wpdb->prefix . 'smp_notification_templates';
    $sql_notification_templates = "CREATE TABLE IF NOT EXISTS $table_notification_templates (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        system_id mediumint(9) NOT NULL,
        name varchar(100) NOT NULL,
        subject varchar(255) NOT NULL,
        content text NOT NULL,
        enabled tinyint(1) DEFAULT 1,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id),
        FOREIGN KEY (system_id) REFERENCES {$table_notification_systems}(id) ON DELETE CASCADE
    ) $charset_collate;";

    // Create notification log table
    $table_notification_log = $wpdb->prefix . 'smp_notification_log';
    $sql_notification_log = "CREATE TABLE IF NOT EXISTS $table_notification_log (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        template_id mediumint(9) NOT NULL,
        customer_id mediumint(9) NOT NULL,
        sent_at datetime DEFAULT CURRENT_TIMESTAMP,
        status varchar(20) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    dbDelta($sql_notification_systems);
    dbDelta($sql_notification_templates);
    dbDelta($sql_notification_log);
}
register_activation_hook(__FILE__, 'smp_create_tables');

function smp_admin_menu() {
    add_menu_page(
        'Subscription Manager',
        'Subscriptions',
        'manage_options',
        'subscription-manager',
        'smp_main_page',
        'dashicons-calendar-alt'
    );

    // Add Notifications submenu
    add_submenu_page(
        'subscription-manager',
        'Notification Settings',
        'Notifications',
        'manage_options',
        'subscription-notifications',
        'smp_notifications_page'
    );
}
add_action('admin_menu', 'smp_admin_menu');

function smp_main_page() {
    global $wpdb;
    $table_customers = $wpdb->prefix . 'smp_customers';

    ob_start();

    if (isset($_POST['delete_customer'])) {
        $customer_id = intval($_POST['customer_id']);
        if (isset($_POST['delete_customer_nonce']) && wp_verify_nonce($_POST['delete_customer_nonce'], 'delete_customer_' . $customer_id)) {
            $wpdb->delete(
                $table_customers,
                ['id' => $customer_id],
                ['%d']
            );
            echo '<div class="notice notice-success"><p>Customer deleted successfully!</p></div>';
        }
    }

    if (isset($_POST['add_customer'])) {
    $name = sanitize_text_field($_POST['new_name']);
    $email = sanitize_email($_POST['new_email']);
    $phone = sanitize_text_field($_POST['new_phone']);
    $devices = intval($_POST['new_devices']);
    $duration = $_POST['new_duration'];
    $enabled = isset($_POST['new_enabled']) ? 1 : 0;

    $end_date = new DateTime();  // Use current time

    if ($duration === '1min') {
        // Add 1 minute for testing
        $end_date->modify("+1 minute");
        $plan_type = '1 minute';
    } elseif ($duration === '2min') {
        $end_date->modify("+2 minutes");
        $plan_type = '2 minutes';
    } elseif ($duration === 'custom' && isset($_POST['custom_days'])) {
        // Handle custom days
        $days = intval($_POST['custom_days']);
        if ($days > 0) {
            $end_date->modify("+{$days} days");
            $plan_type = $days . ' days';
        }
    } else {
        // Handle standard months
        $interval_map = [
            '1' => 'P1M',
            '3' => 'P3M',
            '6' => 'P6M',
            '12' => 'P1Y'
        ];

        if (array_key_exists($duration, $interval_map)) {
            $interval = new DateInterval($interval_map[$duration]);
            $end_date->add($interval);
            $plan_type = $duration . ' month' . ($duration > 1 ? 's' : '');
        }
    }

    $wpdb->insert(
        $table_customers,
        [
            'name' => $name,
            'email' => $email,
            'phone' => $phone,
            'devices' => $devices,
            'plan_type' => $plan_type,
            'end_date' => $end_date->format('Y-m-d H:i:s'),
            'enabled' => $enabled
        ]
    );

            echo '<div class="notice notice-success"><p>New customer added successfully!</p></div>';
    }

    if (isset($_POST['renew_subscription'])) {
        $customer_id = intval($_POST['customer_id']);
        $renew_duration = intval($_POST['renew_duration']);

        $current_subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_customers WHERE id = %d",
            $customer_id
        ));

        if ($current_subscription) {
            $end_date = new DateTime($current_subscription->end_date);
            $interval_map = [
                '1' => 'P1M',
                '3' => 'P3M',
                '6' => 'P6M',
                '12' => 'P1Y'
            ];

            if (array_key_exists($renew_duration, $interval_map)) {
                $interval = new DateInterval($interval_map[$renew_duration]);
                $end_date->add($interval);
                $new_end_date = $end_date->format('Y-m-d H:i:s');

                // Update subscription
                $update_result = $wpdb->update(
                    $table_customers,
                    ['end_date' => $new_end_date],
                    ['id' => $customer_id]
                );

                if ($update_result !== false) {
                    // Get renewal notification template
                    $template = $wpdb->get_row($wpdb->prepare(
                        "SELECT t.* 
                        FROM {$wpdb->prefix}smp_notification_templates t
                        JOIN {$wpdb->prefix}smp_notification_systems s ON t.system_id = s.id
                        WHERE s.notification_timing = %s 
                        AND t.enabled = 1
                        LIMIT 1",
                        'renewal'
                    ));

                    if ($template) {
                        // Get updated customer data
                        $customer = $wpdb->get_row($wpdb->prepare(
                            "SELECT * FROM $table_customers WHERE id = %d",
                            $customer_id
                        ));

                        if ($customer) {
                            // Prepare email content
                            $subject = str_replace(
                                ['{customer_name}', '{expiration_date}', '{subscription_type}'],
                                [
                                    $customer->name ?: 'Valued Customer',
                                    date('Y-m-d', strtotime($new_end_date)),
                                    $customer->plan_type
                                ],
                                $template->subject
                            );

                            $message = str_replace(
                                ['{customer_name}', '{expiration_date}', '{subscription_type}'],
                                [
                                    $customer->name ?: 'Valued Customer',
                                    date('Y-m-d', strtotime($new_end_date)),
                                    $customer->plan_type
                                ],
                                $template->content
                            );

                            // Send email
                            $headers = array(
                                'Content-Type: text/html; charset=UTF-8',
                                'From: ' . get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>'
                            );

                            $sent = wp_mail($customer->email, $subject, wpautop($message), $headers);

                            if ($sent) {
                                // Log the notification
                                $wpdb->insert(
                                    $wpdb->prefix . 'smp_notification_log',
                                    [
                                        'template_id' => $template->id,
                                        'customer_id' => $customer_id,
                                        'sent_at' => current_time('mysql'),
                                        'status' => 'sent'
                                    ]
                                );
                                
                                // Add this right after the renewal template query
                                error_log('Renewal Template Query: ' . print_r($template, true));
                                error_log('Customer Email: ' . $customer->email);
                                error_log('Subject: ' . $subject);
                                error_log('Message: ' . $message);
                                
                                echo '<div class="notice notice-success"><p>Subscription renewed successfully and confirmation email sent!</p></div>';
                            } else {
                                echo '<div class="notice notice-warning"><p>Subscription renewed but failed to send confirmation email.</p></div>';
                            }
                        } else {
                            echo '<div class="notice notice-warning"><p>Subscription renewed but customer data not found.</p></div>';
                        }
                    } else {
                        echo '<div class="notice notice-warning"><p>Subscription renewed but no renewal notification template found. Please create one first.</p></div>';
                    }
                } else {
                    echo '<div class="notice notice-error"><p>Failed to renew subscription.</p></div>';
                }
            }
        }
    }

    if (isset($_POST['edit_customer'])) {
        $customer_id = intval($_POST['customer_id']);
        
        if (isset($_POST['edit_customer_nonce']) && wp_verify_nonce($_POST['edit_customer_nonce'], 'edit_customer_' . $customer_id)) {
            $name = sanitize_text_field($_POST['name']);
            $email = sanitize_email($_POST['email']);
            $phone = sanitize_text_field($_POST['phone']);
            $devices = intval($_POST['devices']);
            $enabled = isset($_POST['enabled']) ? intval($_POST['enabled']) : 0;

            $wpdb->update(
                $table_customers,
                [
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'devices' => $devices,
                    'plan_type' => $_POST['plan_type'],
                    'enabled' => $enabled
                ],
                ['id' => $customer_id]
            );

            echo '<div class="notice notice-success"><p>Customer information updated successfully!</p></div>';
        }
    }
    ?>
    <div class="wrap">
        <h1>Subscriptions Manager</h1>
        
       <div class="card" style="max-width: 1200px; margin-bottom: 20px; padding: 20px;">
        <h2>Add New Customer</h2>
        <form method="post">
            <div style="display: grid; grid-template-columns: minmax(200px, 1fr) minmax(300px, 1.5fr) minmax(200px, 1fr); gap: 30px;">
                <div>
                    <label for="new_name" style="display: block; margin-bottom: 8px;">Name</label>
                    <input type="text" id="new_name" name="new_name" class="regular-text" style="width: 100%; padding: 8px; height: 40px; box-sizing: border-box;">
                </div>
                <div>
                    <label for="new_email" style="display: block; margin-bottom: 8px;">Email</label>
                    <input type="email" id="new_email" name="new_email" required class="regular-text" style="width: 100%; padding: 8px; height: 40px; box-sizing: border-box;">
                </div>
                <div>
                    <label for="new_phone" style="display: block; margin-bottom: 8px;">Phone</label>
                    <input type="text" id="new_phone" name="new_phone" class="regular-text" style="width: 100%; padding: 8px; height: 40px; box-sizing: border-box;">
                </div>
                <div>
                    <label for="new_devices" style="display: block; margin-bottom: 8px;">Devices</label>
                    <select id="new_devices" name="new_devices" required style="width: 100%; padding: 8px; height: 40px; box-sizing: border-box;">
                        <?php
                        for ($i = 1; $i <= 10; $i++) {
                            echo "<option value=\"$i\">$i Device" . ($i > 1 ? 's' : '') . "</option>";
                        }
                        ?>
                    </select>
                </div>
                <div>
                    <label for="new_duration" style="display: block; margin-bottom: 8px;">Duration</label>
    <select id="new_duration" name="new_duration" required style="width: 100%; padding: 8px; height: 40px; box-sizing: border-box;" onchange="toggleCustomDays(this.value)">
                        <optgroup label="Testing Options">
                            <option value="1min">1 Minute (testing)</option>
                            <option value="2min">2 Minutes (testing)</option>
                        </optgroup>
                        <optgroup label="Standard Plans">
        <option value="1">1 Month</option>
        <option value="3">3 Months</option>
        <option value="6">6 Months</option>
        <option value="12">12 Months</option>
                        </optgroup>
                        <optgroup label="Custom">
        <option value="custom">Custom Days</option>
                        </optgroup>
                    </select>
                </div>
                <div id="custom_days_container" style="display: none;">
    <label for="custom_days" style="display: block; margin-bottom: 8px;">Number of Days</label>
    <input type="number" id="custom_days" name="custom_days" min="1" max="365" 
           style="width: 100%; padding: 8px; height: 40px; box-sizing: border-box;">
</div>
                <div>
                    <label for="new_enabled" style="display: block; margin-bottom: 8px;">Status</label>
                    <select id="new_enabled" name="new_enabled" style="width: 100%; padding: 8px; height: 40px; box-sizing: border-box;">
                        <option value="1">Active</option>
                        <option value="0">Disabled</option>
                    </select>
                </div>
            </div>
            <div style="margin-top: 30px; text-align: right;">
                <input type="submit" name="add_customer" class="button button-primary" value="Add Customer" style="padding: 8px 20px; height: auto;">
            </div>
        </form>
    </div>


        <?php
        // Build the query
$query = "SELECT * FROM $table_customers WHERE 1=1";
$query_params = array();

// Search functionality
if (!empty($_GET['customer_search'])) {
    $search_term = '%' . $wpdb->esc_like($_GET['customer_search']) . '%';
    $query .= " AND (name LIKE %s OR email LIKE %s OR phone LIKE %s)";
    array_push($query_params, $search_term, $search_term, $search_term);
}

// Plan type filter
if (!empty($_GET['plan_filter'])) {
    $query .= " AND plan_type = %s";
    $query_params[] = $_GET['plan_filter'];
}

// Status filter
if (isset($_GET['status_filter']) && $_GET['status_filter'] !== '') {
    $query .= " AND enabled = %d";
    $query_params[] = intval($_GET['status_filter']);
}

// Time remaining filter
if (!empty($_GET['time_filter'])) {
    $now = current_time('mysql');
    switch ($_GET['time_filter']) {
        case 'expired':
            $query .= " AND end_date < %s";
            $query_params[] = $now;
            break;
        case '24h':
            $query .= " AND end_date > %s AND end_date <= DATE_ADD(%s, INTERVAL 24 HOUR)";
            array_push($query_params, $now, $now);
            break;
        case '5d':
            $query .= " AND end_date > %s AND end_date <= DATE_ADD(%s, INTERVAL 5 DAY)";
            array_push($query_params, $now, $now);
            break;
        case '10d':
            $query .= " AND end_date > %s AND end_date <= DATE_ADD(%s, INTERVAL 10 DAY)";
            array_push($query_params, $now, $now);
            break;
        case '20d':
            $query .= " AND end_date > %s AND end_date <= DATE_ADD(%s, INTERVAL 20 DAY)";
            array_push($query_params, $now, $now);
            break;
        case '30d':
            $query .= " AND end_date > %s AND end_date <= DATE_ADD(%s, INTERVAL 30 DAY)";
            array_push($query_params, $now, $now);
            break;
    }
}

// Add order by with sort direction and current date reference
$now = '2025-01-20 22:22:34'; // Current UTC time
if (!empty($_GET['sort_order'])) {
    switch($_GET['sort_order']) {
        case 'exp_asc':
            $query .= " ORDER BY 
                CASE 
                    WHEN end_date < %s THEN 1 
                    ELSE 0 
                END ASC, 
                end_date ASC";
            $query_params[] = $now;
            break;
        case 'exp_desc':
            $query .= " ORDER BY 
                CASE 
                    WHEN end_date < %s THEN 1 
                    ELSE 0 
                END ASC, 
                end_date DESC";
            $query_params[] = $now;
            break;
        default:
            $query .= " ORDER BY id DESC";
    }
} else {
    $query .= " ORDER BY id DESC";
}

// Prepare and execute the query
$customers = empty($query_params) 
    ? $wpdb->get_results($query) 
    : $wpdb->get_results($wpdb->prepare($query, $query_params));
        ?>

        <h2>Existing Customers</h2>
        <!-- Search and Filter Section -->
<div class="search-filter-container" style="margin: 20px 0; padding: 15px; background: #fff; border: 1px solid #ccc; border-radius: 4px;">
    <!-- Search Form -->
    <form method="get" action="" class="search-form">
        <input type="hidden" name="page" value="subscription-manager">
        <div style="display: grid; grid-template-columns: 2fr 1fr 1fr 1fr 1fr 1fr; gap: 15px; align-items: end;">
            <!-- Search Input -->
            <div>
                <label for="customer_search" style="display: block; margin-bottom: 5px;"><strong>Search Customers</strong></label>
                <input type="text" id="customer_search" name="customer_search" 
                       value="<?php echo isset($_GET['customer_search']) ? esc_attr($_GET['customer_search']) : ''; ?>" 
                       placeholder="Search by Name, Email, or Phone" 
                       style="width: 100%;">
            </div>
            
            <!-- Plan Type Filter -->
            <div>
                <label for="plan_filter" style="display: block; margin-bottom: 5px;"><strong>Plan Type</strong></label>
                <select name="plan_filter" id="plan_filter" style="width: 100%;">
                    <option value="">All Plans</option>
                    <option value="1 month" <?php echo (isset($_GET['plan_filter']) && $_GET['plan_filter'] === '1 month') ? 'selected' : ''; ?>>1 Month</option>
                    <option value="3 months" <?php echo (isset($_GET['plan_filter']) && $_GET['plan_filter'] === '3 months') ? 'selected' : ''; ?>>3 Months</option>
                    <option value="6 months" <?php echo (isset($_GET['plan_filter']) && $_GET['plan_filter'] === '6 months') ? 'selected' : ''; ?>>6 Months</option>
                    <option value="12 months" <?php echo (isset($_GET['plan_filter']) && $_GET['plan_filter'] === '12 months') ? 'selected' : ''; ?>>12 Months</option>
                </select>
            </div>

            <!-- Time Remaining Filter -->
<div>
    <label for="time_filter" style="display: block; margin-bottom: 5px;"><strong>Time Remaining</strong></label>
    <select name="time_filter" id="time_filter" style="width: 100%;">
        <option value="">All Time</option>
        <option value="expired" <?php echo (isset($_GET['time_filter']) && $_GET['time_filter'] === 'expired') ? 'selected' : ''; ?>>Expired</option>
        <option value="24h" <?php echo (isset($_GET['time_filter']) && $_GET['time_filter'] === '24h') ? 'selected' : ''; ?>>Less than 24 hours</option>
        <option value="5d" <?php echo (isset($_GET['time_filter']) && $_GET['time_filter'] === '5d') ? 'selected' : ''; ?>>Less than 5 days</option>
        <option value="10d" <?php echo (isset($_GET['time_filter']) && $_GET['time_filter'] === '10d') ? 'selected' : ''; ?>>Less than 10 days</option>
        <option value="20d" <?php echo (isset($_GET['time_filter']) && $_GET['time_filter'] === '20d') ? 'selected' : ''; ?>>Less than 20 days</option>
        <option value="30d" <?php echo (isset($_GET['time_filter']) && $_GET['time_filter'] === '30d') ? 'selected' : ''; ?>>Less than 30 days</option>
    </select>
</div>

<!-- Sort Order -->
<div>
    <label for="sort_order" style="display: block; margin-bottom: 5px;"><strong>Sort By</strong></label>
    <select name="sort_order" id="sort_order" style="width: 100%;">
        <option value=""> Default </option>
        <option value="exp_asc" <?php echo (isset($_GET['sort_order']) && $_GET['sort_order'] === 'exp_asc') ? 'selected' : ''; ?>>EX (Earliest First)</option>
        <option value="exp_desc" <?php echo (isset($_GET['sort_order']) && $_GET['sort_order'] === 'exp_desc') ? 'selected' : ''; ?>>EX (Latest First)</option>
    </select>
</div>
            <!-- Status Filter -->
            <div>
                <label for="status_filter" style="display: block; margin-bottom: 5px;"><strong>Status</strong></label>
                <select name="status_filter" id="status_filter" style="width: 100%;">
                    <option value="">All Status</option>
                    <option value="1" <?php echo (isset($_GET['status_filter']) && $_GET['status_filter'] === '1') ? 'selected' : ''; ?>>Active</option>
                    <option value="0" <?php echo (isset($_GET['status_filter']) && $_GET['status_filter'] === '0') ? 'selected' : ''; ?>>Disabled</option>
                </select>
            </div>

            <!-- Search Button -->
            <div>
                <button type="submit" class="button button-primary" style="width: 100%;">Apply Filters</button>
            </div>
        </div>
    </form>
</div>
        <table class="wp-list-table widefat fixed striped">
            <thead>
                <tr>
                    <th style="width: 15%;">Name</th>
                    <th style="width: 20%;">Email</th>
                    <th style="width: 12%;">Phone</th>
                    <th style="width: 80px;">Devices</th>
                    <th style="width: 100px;">Plan Type</th>
                    <th style="width: 12%;">Start Date</th>
                    <th style="width: 12%;">End Date</th>
                    <th style="width: 10%;">Time Remaining</th>
                    <th style="width: 80px;">Status</th>
                    <th style="width: 15%;">Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($customers as $customer): ?>
                    <tr>
                        <td><?php echo empty($customer->name) ? '-' : esc_html($customer->name); ?></td>
                        <td><?php echo esc_html($customer->email); ?></td>
                        <td><?php echo esc_html($customer->phone); ?></td>
                        <td><?php echo esc_html($customer->devices); ?></td>
                        <td><?php echo isset($customer->plan_type) ? esc_html($customer->plan_type) : '1 month'; ?></td>
                        <td><?php echo esc_html($customer->start_date); ?></td>
                        <td><?php echo esc_html($customer->end_date); ?></td>
                        <td class="countdown" data-enddate="<?php echo esc_attr($customer->end_date); ?>"></td>
                        <td style="<?php echo $customer->enabled ? 'color: green;' : 'color: red;'; ?>">
                            <?php echo $customer->enabled ? 'Active' : 'Disabled'; ?>
                        </td>
                        <td>
    <div class="action-buttons-container" style="margin-left: -10px;">
        <!-- Renew Button -->
        <button type="button" class="button action-button renew-button" 
                onclick="toggleRenewForm(<?php echo $customer->id; ?>)">
            Renew
        </button>

        <!-- Edit Button -->
        <button type="button" class="button action-button edit-button"
                onclick="toggleEditForm(<?php echo $customer->id; ?>)">
            Edit
        </button>

        <!-- Delete Button -->
        <form method="post" style="display: inline-block; margin: 0;" onsubmit="return confirmDelete()">
            <input type="hidden" name="customer_id" value="<?php echo $customer->id; ?>">
            <?php wp_nonce_field('delete_customer_' . $customer->id, 'delete_customer_nonce'); ?>
            <input type="submit" name="delete_customer" class="button action-button delete-button" value="Delete">
        </form>
    </div>

                            <!-- Renew Form Modal -->
                            <div id="renew-form-<?php echo $customer->id; ?>" class="modal-form" style="display:none;">
                                <form method="post">
                                    <h3>Renew Subscription</h3>
                                    <input type="hidden" name="customer_id" value="<?php echo $customer->id; ?>">
                                    <div style="margin-bottom: 15px;">
                                        <label for="renew-duration-<?php echo $customer->id; ?>" style="display: block; margin-bottom: 5px;">
                                            Select Duration:
                                        </label>
                                        <select name="renew_duration" id="renew-duration-<?php echo $customer->id; ?>" 
                                                class="regular-text" style="width: 100%;">
                                            <option value="1">1 Month</option>
                                            <option value="3">3 Months</option>
                                            <option value="6">6 Months</option>
                                            <option value="12">12 Months</option>
                                        </select>
                                    </div>
                                    <div class="modal-actions">
                                        <button type="button" class="button button-secondary" 
                                                onclick="toggleRenewForm(<?php echo $customer->id; ?>)">Cancel</button>
                                        <input type="submit" name="renew_subscription" class="button button-primary" value="Confirm Renewal">
                                    </div>
                                </form>
                            </div>

                            <!-- Edit Form Modal -->
                            <div id="edit-form-<?php echo $customer->id; ?>" class="modal-form" style="display:none;">
                                <form method="post">
                                    <h3>Edit Customer</h3>
                                    <?php wp_nonce_field('edit_customer_' . $customer->id, 'edit_customer_nonce'); ?>
                                    <input type="hidden" name="customer_id" value="<?php echo $customer->id; ?>">
                                    <div class="edit-form-grid">
                                        <div class="form-field">
                                            <label>Name:</label>
                                            <input type="text" name="name" value="<?php echo esc_attr($customer->name); ?>" class="regular-text">
                                        </div>
                                        <div class="form-field">
                                            <label>Email:</label>
                                            <input type="email" name="email" value="<?php echo esc_attr($customer->email); ?>" required class="regular-text">
                                        </div>
                                        <div class="form-field">
                                            <label>Phone:</label>
                                            <input type="text" name="phone" value="<?php echo esc_attr($customer->phone); ?>" class="regular-text">
                                        </div>
                                        <div class="form-field">
                                            <label>Devices:</label>
                                            <select name="devices" class="regular-text">
                                                <?php for ($i = 1; $i <= 10; $i++): ?>
                                                    <option value="<?php echo $i; ?>"<?php echo ($customer->devices == $i ? ' selected' : ''); ?>>
                                                        <?php echo $i; ?> Device<?php echo ($i > 1 ? 's' : ''); ?>
                                                    </option>
                                                <?php endfor; ?>
                                            </select>
                                        </div>
                                        <div class="form-field">
                                            <label>Plan Type:</label>
                                            <select name="plan_type" class="regular-text">
                                                <option value="1 month"<?php echo (isset($customer->plan_type) && $customer->plan_type == '1 month' ? ' selected' : ''); ?>>1 Month</option>
                                                <option value="3 months"<?php echo (isset($customer->plan_type) && $customer->plan_type == '3 months' ? ' selected' : ''); ?>>3 Months</option>
                                                <option value="6 months"<?php echo (isset($customer->plan_type) && $customer->plan_type == '6 months' ? ' selected' : ''); ?>>6 Months</option>
                                                <option value="12 months"<?php echo (isset($customer->plan_type) && $customer->plan_type == '12 months' ? ' selected' : ''); ?>>12 Months</option>
                                            </select>
                                        </div>
                                        <div class="form-field">
                                            <label>Status:</label>
                                            <select name="enabled" class="regular-text">
                                                <option value="1"<?php echo ($customer->enabled ? ' selected' : ''); ?>>Active</option>
                                                <option value="0"<?php echo (!$customer->enabled ? ' selected' : ''); ?>>Disabled</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="modal-actions">
                                       <button type="button" class="button button-secondary" 
                                                onclick="toggleEditForm(<?php echo $customer->id; ?>)">Cancel</button>
                                        <input type="submit" name="edit_customer" class="button button-primary" value="Save Changes">
                                    </div>
                                </form>
                            </div>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div style="margin-top: 20px;">
        <form method="post">
            <?php wp_nonce_field('smp_trigger_notifications', 'smp_nonce'); ?>
            <input type="submit" name="trigger_notifications" class="button button-primary" value="Trigger Notifications Now">
        </form>
    </div>

    <style>
    /* Search and Filter Styles */
        .search-filter-container {
            background: #fff;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }

        .search-form input[type="text"],
        .search-form select {
            padding: 6px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .search-form input[type="text"]:focus,
        .search-form select:focus {
            border-color: #2271b1;
            box-shadow: 0 0 0 1px #2271b1;
            outline: 2px solid transparent;
        }
        /* Table Styles */
        .wp-list-table {
            margin-top: 20px;
        }

        /* Modal Form Styles */
        .modal-form {
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            background: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 0 10px rgba(0,0,0,0.5);
            z-index: 1000;
            max-width: 500px;
            width: 90%;
        }

        .modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.5);
            z-index: 999;
        }

        /* Form Grid Layout */
        .edit-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;  /* Changed from 1fr to 1fr 1fr */
            gap: 15px;
            margin-bottom: 20px;
            padding: 10px;
        }

        .form-field {
            margin-bottom: 10px;
            padding: 0 5px;
        }

        .form-field label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }

        .form-field input,
        .form-field select {
            width: 100%;
        }

        /* Modal Actions */
        .modal-actions {
            text-align: right;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #ddd;
        }

        .modal-actions button,
        .modal-actions input[type="submit"] {
            margin-left: 10px;
        }

        .action-buttons-container {
    display: flex;
    gap: 5px !important;                   /* Remove gap between buttons */
    align-items: center;
    padding: 0 !important;                 /* Remove padding */
    position: relative;
    margin-left: -20px !important;         /* Move buttons to the left */
    transform: translateX(-10px);          /* Additional left shift */
    white-space: nowrap !important;        /* Ensure buttons stay in one line */
}

/* Action Button Styles */
.action-button {
    min-width: 40px !important;            /* Keep small width */
    padding: 0px 5px !important;           /* Add horizontal padding */
    font-size: 10px !important;            /* Keep small font */
    height: 18px !important;               /* Keep small height */
    line-height: 1 !important;
    border-radius: 5px !important;         /* Make rectangular by removing border radius */
    text-align: center !important;
    cursor: pointer !important;
    font-weight: 500 !important;
    transition: all 0.2s ease !important;
    margin: 0 !important;                  /* Remove margins */
    box-sizing: border-box !important;     /* Ensure padding is included in width */
    display: inline-block !important;      /* Make buttons inline */
    border: none !important;               /* Remove borders between buttons */
}

/* Form inline display */
form[method="post"] {
    display: inline-block !important;
    margin: 0 !important;
    padding: 0 !important;
}

/* Button Colors */
.renew-button {
    background-color: #2271b1 !important;
    color: white !important;
}

.edit-button {
    background-color: #6c757d !important;
    color: white !important;
}

.delete-button {
    background-color: #dc3545 !important;
    color: white !important;
}

/* Hover effects */
.renew-button:hover {
    background-color: #135e96 !important;
}

.edit-button:hover {
    background-color: #5c636a !important;
}

.delete-button:hover {
    background-color: #bb2d3b !important;
}

        /* Expired Status Style */
        .countdown.expired {
            color: red;
            font-weight: bold;
        }
        .preview-section {
    margin-bottom: 20px;
    border-bottom: 1px solid #ddd;
    padding-bottom: 15px;
}

.preview-content {
    margin: 10px 0;
    padding: 10px;
    border: 1px solid #ddd;
    max-height: 200px;
    overflow-y: auto;
    background: #fff;
}

.email-input-section {
    margin: 15px 0;
}

.email-input-section label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
}

.email-input-section input[type="email"] {
    width: 100%;
    padding: 8px;
    margin-bottom: 15px;
}
    </style>

    <script>
        function toggleRenewForm(customerId) {
            const form = document.getElementById('renew-form-' + customerId);
            const overlay = document.getElementById('modal-overlay-' + customerId);
            
            if (form.style.display === 'none' || !form.style.display) {
                if (!overlay) {
                    const newOverlay = document.createElement('div');
                    newOverlay.id = 'modal-overlay-' + customerId;
                    newOverlay.className = 'modal-overlay';
                    document.body.appendChild(newOverlay);
                    newOverlay.onclick = function() {
                        form.style.display = 'none';
                        newOverlay.style.display = 'none';
                    };
                }
                form.style.display = 'block';
                document.getElementById('modal-overlay-' + customerId).style.display = 'block';
            } else {
                form.style.display = 'none';
                if (overlay) {
                    overlay.style.display = 'none';
                }
            }
        }

        function toggleEditForm(customerId) {
            const form = document.getElementById('edit-form-' + customerId);
            const overlay = document.getElementById('modal-overlay-edit-' + customerId);
            
            if (form.style.display === 'none' || !form.style.display) {
                if (!overlay) {
                    const newOverlay = document.createElement('div');
                    newOverlay.id = 'modal-overlay-edit-' + customerId;
                    newOverlay.className = 'modal-overlay';
                    document.body.appendChild(newOverlay);
                    newOverlay.onclick = function() {
                        form.style.display = 'none';
                        newOverlay.style.display = 'none';
                    };
                }
                form.style.display = 'block';
                document.getElementById('modal-overlay-edit-' + customerId).style.display = 'block';
            } else {
                form.style.display = 'none';
                if (overlay) {
                    overlay.style.display = 'none';
                }
            }
        }

        function confirmDelete() {
            return confirm('Are you sure you want to delete this customer? This action cannot be undone.');
        }

        // Update countdown timers
  function updateCountdowns() {
            const countdownElements = document.querySelectorAll('.countdown');
            const now = new Date();

            countdownElements.forEach(element => {
                const endDate = new Date(element.dataset.enddate);
                const timeLeft = endDate - now;

                if (timeLeft <= 0) {
                    element.textContent = 'Expired';
                    element.classList.add('expired');
                    element.style.backgroundColor = '#ffebee'; // Light red background for expired
                } else {
                    const days = Math.floor(timeLeft / (1000 * 60 * 60 * 24));
                    const hours = Math.floor((timeLeft % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));

                    element.textContent = `${days}d ${hours}h ${minutes}m`;
                    // Set green background for all active subscriptions
                    element.style.backgroundColor = '#e8f5e9'; // Green background
                    element.style.color = '#2e7d32'; // Green text
                }
            });
        }

        // Update countdowns immediately and every minute
        updateCountdowns();
        setInterval(updateCountdowns, 60000);
        function toggleCustomDays(value) {
    const customDaysContainer = document.getElementById('custom_days_container');
    if (value === 'custom') {
        customDaysContainer.style.display = 'block';
    } else {
        customDaysContainer.style.display = 'none';
    }
}
        
    </script>
    <?php
    $output = ob_get_clean();
    echo $output;
}

// 1. Ensure templates are fetched and displayed correctly
function smp_notifications_page() {
    global $wpdb;
    
    // Handle form submissions
    if (isset($_POST['save_notification_system'])) {
        smp_save_notification_system();
    }
    if (isset($_POST['save_notification_template'])) {
        smp_save_notification_template();
    }
    if (isset($_POST['test_notification'])) {
        smp_test_notification();
    }

    // Update the systems query to properly count templates
    $notification_systems = $wpdb->get_results("
        SELECT 
            ns.*,
            COUNT(DISTINCT nt.id) as template_count 
        FROM {$wpdb->prefix}smp_notification_systems ns
        LEFT JOIN {$wpdb->prefix}smp_notification_templates nt 
            ON ns.id = nt.system_id
        GROUP BY 
            ns.id, 
            ns.name, 
            ns.subscription_type, 
            ns.notification_timing, 
            ns.days_value, 
            ns.frequency, 
            ns.created_at
        ORDER BY ns.created_at DESC
    ");

    // Get and display existing templates
    $templates = $wpdb->get_results("
        SELECT t.*, s.name as system_name 
        FROM {$wpdb->prefix}smp_notification_templates t
        JOIN {$wpdb->prefix}smp_notification_systems s ON t.system_id = s.id
        ORDER BY t.created_at DESC
    ");
    ?>
    <div class="wrap">
        <h1>Notification Settings</h1>
        
        <div class="nav-tab-wrapper">
            <a href="#system" class="nav-tab nav-tab-active" onclick="showTab('system', event)">Create Notification System</a>
            <a href="#template" class="nav-tab" onclick="showTab('template', event)">Create Template</a>
        </div>

        <!-- Create System Tab -->
        <div id="system" class="tab-content">
            <h2>Create New Notification System</h2>
            <form method="post" class="notification-form">
                <table class="form-table">
                    <tr>
                        <th><label for="system_name">System Name</label></th>
                        <td><input type="text" id="system_name" name="system_name" class="regular-text" required></td>
                    </tr>
                    <tr>
                        <th><label for="plan_category">Plan Category</label></th>
                        <td>
                            <select id="plan_category" name="plan_category" required>
                                <option value="">Select Plan Category</option>
                                <optgroup label="Testing Plans">
                                    <option value="1min">1 Minute Plan</option>
                                    <option value="2min">2 Minutes Plan</option>
                                </optgroup>
                                <optgroup label="Standard Plans">
                                    <option value="1">1 Month Plan</option>
                                    <option value="3">3 Months Plan</option>
                                    <option value="6">6 Months Plan</option>
                                    <option value="12">12 Months Plan</option>
                                </optgroup>
                                <optgroup label="Custom Plans">
                                    <option value="custom">Custom Days Plan</option>
                                </optgroup>
                            </select>
                            <p class="description">Select which subscription duration this notification system applies to</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="notification_timing">When to Send</label></th>
                        <td>
                            <select id="notification_timing" name="notification_timing" required onchange="toggleDaysValue(this.value)">
                                <option value="before">Before Expiration</option>
                                <option value="after">After Expiration</option>
                                <option value="every">Every</option>
                                <option value="renewal">Renewal Confirmation</option>
                            </select>
                            <div id="days_value_container">
                            <select id="days_value" name="days_value">
                                    <optgroup label="Testing Options">
                                        <option value="1min">1 minute (testing)</option>
                                        <option value="2min">2 minutes (testing)</option>
                                    </optgroup>
                                    <optgroup label="Standard Intervals">
                                <option value="5">5 days</option>
                                <option value="10">10 days</option>
                                <option value="15">15 days</option>
                                <option value="20">20 days</option>
                                <option value="25">25 days</option>
                                <option value="30">30 days</option>
                                    </optgroup>
                                    <optgroup label="Custom">
                                <option value="custom">Custom</option>
                                    </optgroup>
                            </select>
                            <input type="number" id="custom_days" name="custom_days" min="1" max="365" 
                                   style="display:none;" placeholder="Enter days">
                            </div>
                            <p class="description">For testing: select 1 minute to receive notification when 1 minute remains</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="frequency">Send Frequency</label></th>
                        <td>
                            <select id="frequency" name="frequency" required>
                                <optgroup label="Testing">
                                    <option value="1min">Every Minute (testing)</option>
                                </optgroup>
                                <optgroup label="Standard">
                                <option value="1">Once per day</option>
                                <option value="2">Once per 2 days</option>
                                <option value="3">Once per 3 days</option>
                                <option value="7">Once per week</option>
                                </optgroup>
                            </select>
                            <p class="description">For testing, use 'Every Minute' to receive notifications more frequently</p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="save_notification_system" class="button button-primary" value="Save Notification System">
                </p>
            </form>

            <?php if (!empty($notification_systems)): ?>
                <h3 style="margin-top: 30px;">Existing Notification Systems</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>System Name</th>
                            <th>Plan Category</th>
                            <th>When to Send</th>
                            <th>Days Value</th>
                            <th>Frequency</th>
                            <th>Templates</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notification_systems as $system): ?>
                            <tr>
                                <td><?php echo esc_html($system->name); ?></td>
                                <td><?php echo esc_html($system->subscription_type); ?></td>
                                <td><?php echo esc_html($system->notification_timing); ?></td>
                                <td><?php echo esc_html($system->days_value); ?></td>
                                <td><?php 
                                    if ($system->frequency == (1/1440)) {
                                        echo 'Every Minute';
                                    } else {
                                        echo 'Every ' . $system->frequency . ' day(s)';
                                    }
                                ?></td>
                                <td><?php echo isset($system->template_count) ? intval($system->template_count) : '0'; ?></td>
                                <td>
                                    <button type="button" 
                                            class="button button-small edit-notification-system" 
                                            data-id="<?php echo esc_attr($system->id); ?>"
                                            data-name="<?php echo esc_attr($system->name); ?>"
                                            data-subscription="<?php echo esc_attr($system->subscription_type); ?>"
                                            data-timing="<?php echo esc_attr($system->notification_timing); ?>"
                                            data-days="<?php echo esc_attr($system->days_value); ?>"
                                            data-frequency="<?php echo esc_attr($system->frequency); ?>">
                                        Edit
                                    </button>
                                    <button type="button" 
                                            class="button button-small button-link-delete delete-notification-system" 
                                            data-id="<?php echo esc_attr($system->id); ?>">
                                        Delete
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>

        <!-- Create Template Tab -->
        <div id="template" class="tab-content" style="display:none">
            <h2>Create New Template</h2>
            <form method="post" class="notification-form">
                <table class="form-table">
                    <tr>
                        <th><label for="system_id">Select Notification System</label></th>
                        <td>
                            <select id="system_id" name="system_id" required>
                                <?php echo smp_get_notification_systems_options(); ?>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="template_name">Template Name</label></th>
                        <td>
                            <input type="text" id="template_name" name="template_name" class="regular-text" required>
                            <p class="description">Give your template a unique name for easy identification</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="email_subject">Email Subject</label></th>
                        <td>
                            <input type="text" id="email_subject" name="email_subject" class="regular-text" required>
                            <p class="description">Available variables: {customer_name}, {expiration_date}, {subscription_type}</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="email_content">Email Content</label></th>
                        <td>
                            <?php 
                            wp_editor('', 'email_content', array(
                                'textarea_name' => 'email_content',
                                'media_buttons' => true,
                                'textarea_rows' => 10
                            ));
                            ?>
                            <p class="description">
                                Available variables: {customer_name}, {expiration_date}, {subscription_type}
                            </p>
                        </td>
                    </tr>
                </table>
                <p class="submit">
                    <input type="submit" name="save_notification_template" class="button button-primary" value="Save Template">
                </p>
            </form>

            <!-- Separate test email section for saved templates -->
            <?php if (!empty($templates)): ?>
                <h3 style="margin-top: 30px;">Existing Templates</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Template Name</th>
                            <th>System</th>
                            <th>Subject</th>
                            <th>Status</th>
                            <th>Created At</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($templates as $template): ?>
                            <tr>
                                <td><?php echo esc_html($template->name); ?></td>
                                <td><?php echo esc_html($template->system_name); ?></td>
                                <td><?php echo esc_html($template->subject); ?></td>
                                <td>
                                    <button type="button" 
                                            class="button <?php echo $template->enabled ? 'button-primary' : 'button-secondary'; ?>"
                                            onclick="toggleTemplateStatus(<?php echo $template->id; ?>, <?php echo $template->enabled ? 'false' : 'true'; ?>)">
                                        <?php echo $template->enabled ? 'Enabled' : 'Disabled'; ?>
                                    </button>
                                </td>
                                <td><?php echo esc_html($template->created_at); ?></td>
                                <td>
                                    <button type="button" class="button button-small" onclick="showTestEmailModal(<?php echo $template->id; ?>)">
                                        Send Test
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- Test Email Modal -->
    <div id="test-email-modal" class="modal-form" style="display:none;">
        <div class="modal-overlay" id="modal-overlay" onclick="closeTestEmailModal()"></div>
        <div class="modal-content">
            <h3>Send Test Email</h3>
            <input type="hidden" id="test_template_id">
            <div class="preview-section">
                <p><strong>Template Preview:</strong></p>
                <div id="template_preview"></div>
            </div>
            <div class="email-input-section">
                <label for="test_email">Send test to:</label>
                <input type="email" id="test_email" class="regular-text" required placeholder="Enter email address">
            </div>
            <div class="modal-actions">
                <button type="button" class="button" onclick="closeTestEmailModal()">Cancel</button>
                <button type="button" class="button button-primary" onclick="sendTestEmail()">Send Test</button>
            </div>
        </div>
    </div>

    <div id="edit-system-modal" class="modal">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Edit Notification System</h2>
            <form id="edit-system-form" method="post">
                <?php wp_nonce_field('edit_system', 'edit_system_nonce'); ?>
                <input type="hidden" id="edit_system_id" name="system_id">
                <table class="form-table">
                    <tr>
                        <th><label for="edit_name">System Name</label></th>
                        <td><input type="text" id="edit_name" name="name" required></td>
                    </tr>
                    <tr>
                        <th><label for="edit_subscription">Plan Category</label></th>
                        <td>
                            <select id="edit_subscription" name="subscription_type" required>
                                <option value="1 minute">1 Minute Plan</option>
                                <option value="2 minutes">2 Minutes Plan</option>
                                <option value="1 month">1 Month Plan</option>
                                <option value="3 months">3 Months Plan</option>
                                <option value="6 months">6 Months Plan</option>
                                <option value="12 months">12 Months Plan</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="edit_timing">When to Send</label></th>
                        <td>
                            <select id="edit_timing" name="notification_timing" required>
                                <option value="before">Before Expiration</option>
                                <option value="after">After Expiration</option>
                                <option value="every">Every</option>
                                <option value="renewal">Renewal Confirmation</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="edit_days">Days Value</label></th>
                        <td><input type="number" id="edit_days" name="days_value" min="0" required></td>
                    </tr>
                    <tr>
                        <th><label for="edit_frequency">Frequency</label></th>
                        <td>
                            <select id="edit_frequency" name="frequency" required>
                                <option value="1min">Every Minute</option>
                                <option value="1">Once per day</option>
                                <option value="2">Every 2 days</option>
                                <option value="3">Every 3 days</option>
                                <option value="7">Once per week</option>
                            </select>
                        </td>
                    </tr>
                </table>
                <div class="submit-button">
                    <button type="submit" class="button button-primary">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        .notification-form {
            max-width: 800px;
            margin-top: 20px;
        }
        .tab-content {
            margin-top: 20px;
        }
        .nav-tab-wrapper {
            margin-bottom: 20px;
        }
        .modal-form {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    z-index: 1000;
    justify-content: center;
    align-items: center;
}

.modal-overlay {
    position: absolute;
    top: 0;
    left: 0;
    width: 100%;
    height: 100%;
    background: rgba(0, 0, 0, 0.5);
}

.modal-content {
    position: relative;
    background: white;
    padding: 20px;
    border-radius: 5px;
    box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
    max-width: 500px;
    width: 90%;
    z-index: 1001;
}
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.4);
    }
    .modal-content {
        background-color: #fefefe;
        margin: 5% auto;
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        max-width: 500px;
        width: 90%;
        position: relative;
    }
    .close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }
    .submit-button {
        margin-top: 20px;
        text-align: right;
    }
    </style>

    <script>
        function showTab(tabId, event) {
            // Prevent default anchor behavior
            if(event) {
                event.preventDefault();
            }
            
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.style.display = 'none';
            });
            
            // Remove active class from all tabs
            document.querySelectorAll('.nav-tab').forEach(tab => {
                tab.classList.remove('nav-tab-active');
            });
            
            // Show selected tab
            document.getElementById(tabId).style.display = 'block';
            
            // Add active class to clicked tab
            if(event) {
                event.target.classList.add('nav-tab-active');
            }
        }

        // Handle custom days input
        document.getElementById('days_value').addEventListener('change', function() {
            const customDays = document.getElementById('custom_days');
            customDays.style.display = this.value === 'custom' ? 'inline-block' : 'none';
        });

        function showTestEmailModal(templateId) {
            jQuery.post(ajaxurl, {
                action: 'get_template_preview',
                template_id: templateId,
                nonce: '<?php echo wp_create_nonce("template_preview_nonce"); ?>'
            }, function(response) {
                if (response.success) {
                    document.getElementById('test_template_id').value = templateId;
                    document.getElementById('template_preview').innerHTML = response.data.preview;
                    document.getElementById('test-email-modal').style.display = 'flex';
                    document.getElementById('test_email').value = '';
                }
            });
        }

        function sendTestEmail() {
            const emailAddress = document.getElementById('test_email').value;
            const templateId = document.getElementById('test_template_id').value;
            
            if (!emailAddress) {
                alert('Please enter an email address');
                return;
            }

            jQuery.post(ajaxurl, {
                action: 'send_test_template',
                template_id: templateId,
                email: emailAddress,
                nonce: '<?php echo wp_create_nonce("test_email_nonce"); ?>'
            }, function(response) {
                if (response.success) {
                    alert('Test email sent successfully!');
                    closeTestEmailModal();
                } else {
                    alert('Failed to send test email: ' + response.data.message);
                }
            });
        }

        function closeTestEmailModal() {
            document.getElementById('test-email-modal').style.display = 'none';
        }

        function toggleTemplateStatus(templateId, enable) {
            jQuery.post(ajaxurl, {
                action: 'toggle_template_status',
                template_id: templateId,
                enable: enable,
                nonce: '<?php echo wp_create_nonce("toggle_template_status"); ?>'
            }, function(response) {
                if (response.success) {
                    location.reload();
                }
            });
        }

        function toggleDaysValue(timing) {
            const daysContainer = document.getElementById('days_value_container');
            daysContainer.style.display = timing === 'renewal' ? 'none' : 'block';
        }

    jQuery(document).ready(function($) {
        // Debug logging
        console.log('Script loaded');

        // Edit button handler
        $('.edit-system').on('click', function(e) {
            e.preventDefault();
            console.log('Edit button clicked');
            
            var data = $(this).data();
            console.log('System data:', data);

            // Populate modal fields
            $('#edit-system-modal').show();
            $('#edit_system_id').val(data.id);
            $('#edit_name').val(data.name);
            $('#edit_subscription').val(data.subscription);
            $('#edit_timing').val(data.timing);
            $('#edit_days').val(data.days);
            $('#edit_frequency').val(data.frequency === 0.000694444444444 ? '1min' : data.frequency);
        });

        // Edit form submission
        $('#edit-system-form').on('submit', function(e) {
            e.preventDefault();
            console.log('Form submitted');

            var data = {
                action: 'edit_notification_system',
                nonce: $('#edit_system_nonce').val(),
                system_id: $('#edit_system_id').val(),
                name: $('#edit_name').val(),
                subscription_type: $('#edit_subscription').val(),
                notification_timing: $('#edit_timing').val(),
                days_value: $('#edit_days').val(),
                frequency: $('#edit_frequency').val()
            };

            console.log('Submitting data:', data);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: data,
                success: function(response) {
                    console.log('Server response:', response);
                    if (response.success) {
                        alert('System updated successfully!');
                        location.reload();
                    } else {
                        alert('Error updating system: ' + (response.data ? response.data.message : 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('AJAX Error:', error);
                    alert('Error updating system. Please try again.');
                }
            });
        });

        // Delete button handler
        $('.delete-system').on('click', function(e) {
            e.preventDefault();
            console.log('Delete button clicked');

            var systemId = $(this).data('id');
            if (confirm('Are you sure you want to delete this system? This action cannot be undone.')) {
                console.log('Deleting system:', systemId);

                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'delete_notification_system',
                        nonce: '<?php echo wp_create_nonce("delete_notification_system"); ?>',
                        system_id: systemId
                    },
                    success: function(response) {
                        console.log('Server response:', response);
                        if (response.success) {
                            alert('System deleted successfully!');
                            location.reload();
                        } else {
                            alert('Error deleting system: ' + (response.data ? response.data.message : 'Unknown error'));
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('AJAX Error:', error);
                        alert('Error deleting system. Please try again.');
                    }
                });
            }
        });

        // Modal close button
        $('.close').on('click', function() {
            $('#edit-system-modal').hide();
        });

        // Close modal when clicking outside
        $(window).on('click', function(e) {
            if (e.target === $('#edit-system-modal')[0]) {
                $('#edit-system-modal').hide();
            }
        });
    });
    </script>

    <script>
    jQuery(document).ready(function($) {
        // Edit Notification System
        $('.edit-notification-system').on('click', function() {
            var $button = $(this);
            var systemId = $button.data('id');
            var name = $button.data('name');
            var subscription = $button.data('subscription');
            var timing = $button.data('timing');
            var days = $button.data('days');
            var frequency = $button.data('frequency');

            console.log('Edit clicked:', {
                id: systemId,
                name: name,
                subscription: subscription,
                timing: timing,
                days: days,
                frequency: frequency
            });

            // Populate modal fields
            $('#edit_system_id').val(systemId);
            $('#edit_name').val(name);
            $('#edit_subscription').val(subscription);
            $('#edit_timing').val(timing);
            $('#edit_days').val(days);
            $('#edit_frequency').val(frequency === 0.000694444444444 ? '1min' : frequency);

            // Show modal
            $('#edit-system-modal').show();
        });

        // Delete Notification System
        $('.delete-notification-system').on('click', function() {
            var systemId = $(this).data('id');
            
            if (confirm('Are you sure you want to delete this notification system? This will also delete all associated templates.')) {
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'delete_notification_system',
                        system_id: systemId,
                        nonce: '<?php echo wp_create_nonce("delete_notification_system"); ?>'
                    },
                    success: function(response) {
                        if (response.success) {
                            alert('System deleted successfully!');
                            location.reload();
                        } else {
                            alert('Error deleting system: ' + (response.data ? response.data.message : 'Unknown error'));
                        }
                    },
                    error: function(xhr, status, error) {
                        console.error('Delete Error:', error);
                        alert('Error deleting system. Please try again.');
                    }
                });
            }
        });

        // Handle Edit Form Submit
        $('#edit-system-form').on('submit', function(e) {
            e.preventDefault();
            
            var formData = {
                action: 'edit_notification_system',
                system_id: $('#edit_system_id').val(),
                name: $('#edit_name').val(),
                subscription_type: $('#edit_subscription').val(),
                notification_timing: $('#edit_timing').val(),
                days_value: $('#edit_days').val(),
                frequency: $('#edit_frequency').val(),
                nonce: '<?php echo wp_create_nonce("edit_notification_system"); ?>'
            };

            console.log('Submitting form data:', formData);

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: formData,
                success: function(response) {
                    console.log('Edit Response:', response);
                    if (response.success) {
                        alert('System updated successfully!');
                        location.reload();
                    } else {
                        alert('Error updating system: ' + (response.data ? response.data.message : 'Unknown error'));
                    }
                },
                error: function(xhr, status, error) {
                    console.error('Edit Error:', error);
                    alert('Error updating system. Please try again.');
                }
            });
        });

        // Close modal handler
        $('.close, .modal').on('click', function(e) {
            if (e.target === this) {
                $('#edit-system-modal').hide();
            }
        });
    });
    </script>
    <?php
}

// Update the AJAX handlers
add_action('wp_ajax_edit_notification_system', 'smp_ajax_edit_system');
function smp_ajax_edit_system() {
    if (!check_ajax_referer('edit_notification_system', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid security token']);
        return;
    }

    global $wpdb;
    
    $system_id = intval($_POST['system_id']);
    $frequency = $_POST['frequency'] === '1min' ? (1/1440) : floatval($_POST['frequency']);
    
    $result = $wpdb->update(
        $wpdb->prefix . 'smp_notification_systems',
        [
            'name' => sanitize_text_field($_POST['name']),
            'subscription_type' => sanitize_text_field($_POST['subscription_type']),
            'notification_timing' => sanitize_text_field($_POST['notification_timing']),
            'days_value' => intval($_POST['days_value']),
            'frequency' => $frequency
        ],
        ['id' => $system_id],
        ['%s', '%s', '%s', '%d', '%f']
    );
    
    if ($result !== false) {
        wp_send_json_success(['message' => 'System updated successfully']);
    } else {
        wp_send_json_error(['message' => 'Failed to update system: ' . $wpdb->last_error]);
    }
}

add_action('wp_ajax_delete_notification_system', 'smp_ajax_delete_system');
function smp_ajax_delete_system() {
    if (!check_ajax_referer('delete_notification_system', 'nonce', false)) {
        wp_send_json_error(['message' => 'Invalid security token']);
        return;
    }

    global $wpdb;
    
    $system_id = intval($_POST['system_id']);
    
    // Delete associated templates first
    $wpdb->delete(
        $wpdb->prefix . 'smp_notification_templates',
        ['system_id' => $system_id]
    );
    
    // Then delete the system
    $result = $wpdb->delete(
        $wpdb->prefix . 'smp_notification_systems',
        ['id' => $system_id]
    );
    
    if ($result !== false) {
        wp_send_json_success(['message' => 'System and templates deleted successfully']);
    } else {
        wp_send_json_error(['message' => 'Failed to delete system: ' . $wpdb->last_error]);
    }
}

// Save notification template
function smp_save_notification_template() {
    global $wpdb;
    
    if (empty($_POST['system_id']) || empty($_POST['template_name']) || 
        empty($_POST['email_subject']) || empty($_POST['email_content'])) {
        echo '<div class="notice notice-error"><p>Please fill in all required fields!</p></div>';
        return;
    }

    $result = $wpdb->insert(
        $wpdb->prefix . 'smp_notification_templates',
        array(
            'system_id' => intval($_POST['system_id']),
            'name' => sanitize_text_field($_POST['template_name']),
            'subject' => sanitize_text_field($_POST['email_subject']),
            'content' => wp_kses_post($_POST['email_content']),
            'enabled' => 1, // Default to enabled
            'created_at' => current_time('mysql')
        ),
        array('%d', '%s', '%s', '%s', '%d', '%s')
    );

    if ($result === false) {
        echo '<div class="notice notice-error"><p>Error saving template: ' . $wpdb->last_error . '</p></div>';
    } else {
        echo '<div class="notice notice-success"><p>Template saved successfully!</p></div>';
        // Refresh the page to show the new template
        echo '<script>
            setTimeout(function() {
                window.location.href = window.location.href + "&tab=template";
            }, 1000);
        </script>';
    }
}

// Test notification
function smp_test_notification() {
    if (empty($_POST['test_email']) || empty($_POST['template_id'])) {
        echo '<div class="notice notice-error"><p>Please provide an email address!</p></div>';
        return;
    }

    global $wpdb;
    $template = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}smp_notification_templates WHERE id = %d",
        intval($_POST['template_id'])
    ));

    if (!$template) {
        echo '<div class="notice notice-error"><p>Template not found!</p></div>';
        return;
    }

    $to = sanitize_email($_POST['test_email']);
    $subject = $template->subject;
    $message = wpautop($template->content);
    $headers = array('Content-Type: text/html; charset=UTF-8');

    if (wp_mail($to, $subject, $message, $headers)) {
        echo '<div class="notice notice-success"><p>Test email sent successfully!</p></div>';
    } else {
        echo '<div class="notice notice-error"><p>Failed to send test email!</p></div>';
    }
}

// Add cron job for notifications
add_action('wp', 'smp_schedule_notification_cron');
function smp_schedule_notification_cron() {
    // Remove existing schedule if any
    wp_clear_scheduled_hook('smp_send_notifications');
    
    // Schedule new cron job
    if (!wp_next_scheduled('smp_send_notifications')) {
        wp_schedule_event(time(), 'every_minute', 'smp_send_notifications');
    }
}

// Process notifications
add_action('smp_send_notifications', 'smp_process_notifications');
function smp_process_notifications() {
    global $wpdb;
    
    smp_debug_log('Starting notification process...');
    
    $customers = $wpdb->get_results("
        SELECT c.*, ns.*, nt.id as template_id, nt.name as template_name, 
               nt.subject, nt.content, nt.enabled
        FROM {$wpdb->prefix}smp_customers c
        JOIN {$wpdb->prefix}smp_notification_systems ns ON c.plan_type = ns.subscription_type
        JOIN {$wpdb->prefix}smp_notification_templates nt ON nt.system_id = ns.id
        WHERE c.enabled = 1 
        AND nt.enabled = 1
    ");

    smp_debug_log('Found ' . count($customers) . ' customers to check');

    foreach ($customers as $customer) {
        $end_date = new DateTime($customer->end_date);
        $now = new DateTime();
        $time_diff = $end_date->getTimestamp() - $now->getTimestamp();
        $days_remaining = floor($time_diff / (24 * 60 * 60));

        smp_debug_log(sprintf(
            'Processing customer: %s, Plan: %s, End Date: %s, Days Remaining: %d, Days Value: %d',
            $customer->email,
            $customer->plan_type,
            $customer->end_date,
            $days_remaining,
            $customer->days_value
        ));

        // Check if notification should be sent based on timing
        $should_notify = false;
        
        switch ($customer->notification_timing) {
            case 'before':
                // Changed logic: Send if remaining days are less than or equal to days_value
                if ($days_remaining <= $customer->days_value && $days_remaining > 0) {
                    $should_notify = true;
                    smp_debug_log('Triggering before expiration notification - Days remaining: ' . $days_remaining);
                }
                break;
                
            case 'after':
                if ($days_remaining < 0 && abs($days_remaining) <= $customer->days_value) {
                    $should_notify = true;
                    smp_debug_log('Triggering after expiration notification');
                }
                break;
                
            case 'every':
                if ($days_remaining > 0 && $days_remaining <= $customer->days_value) {
                    $should_notify = true;
                    smp_debug_log('Triggering periodic notification');
                }
                break;
        }

        if ($should_notify) {
            // Check if we should send based on frequency
            if ($customer->frequency == (1/1440)) { // Every minute
                smp_debug_log('Sending minute-based notification');
                smp_send_customer_notification($customer);
            } else {
                // Check last notification time for other frequencies
                $last_notification = $wpdb->get_var($wpdb->prepare(
                    "SELECT MAX(sent_at) FROM {$wpdb->prefix}smp_notification_log 
                    WHERE customer_id = %d AND template_id = %d",
                    $customer->id,
                    $customer->template_id
                ));

                if (!$last_notification || 
                    (strtotime('now') - strtotime($last_notification)) >= ($customer->frequency * 24 * 60 * 60)) {
                    smp_debug_log('Sending notification based on frequency');
                    smp_send_customer_notification($customer);
                }
            }
        } else {
            smp_debug_log('No notification needed at this time');
        }
    }
}

// Function to send notification
function smp_send_customer_notification($customer) {
    $subject = str_replace(
        ['{customer_name}', '{expiration_date}', '{subscription_type}'],
        [$customer->name ?: 'Valued Customer', 
         date('Y-m-d', strtotime($customer->end_date)), 
         $customer->plan_type],
        $customer->subject
    );

    $message = str_replace(
        ['{customer_name}', '{expiration_date}', '{subscription_type}'],
        [$customer->name ?: 'Valued Customer', 
         date('Y-m-d', strtotime($customer->end_date)), 
         $customer->plan_type],
        $customer->content
    );

    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_bloginfo('name') . ' <' . get_bloginfo('admin_email') . '>'
    );

    $sent = wp_mail($customer->email, $subject, wpautop($message), $headers);

    if ($sent) {
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'smp_notification_log',
            array(
                'template_id' => $customer->template_id,
                'customer_id' => $customer->id,
                'sent_at' => current_time('mysql'),
                'status' => 'sent'
            )
        );
        smp_debug_log('Notification sent successfully to: ' . $customer->email);
    } else {
        smp_debug_log('Failed to send notification to: ' . $customer->email);
    }
}

// Add this debugging function
function smp_debug_log($message) {
    error_log('SMP Debug: ' . $message);
}

// Update the cron schedule to run more frequently
function smp_add_custom_cron_interval($schedules) {
    $schedules['every_minute'] = array(
        'interval' => 60, // Run every minute
        'display'  => __('Every Minute')
    );
    return $schedules;
}
add_filter('cron_schedules', 'smp_add_custom_cron_interval');

// Add deactivation cleanup
register_deactivation_hook(__FILE__, 'smp_cleanup_cron');
function smp_cleanup_cron() {
    wp_clear_scheduled_hook('smp_send_notifications');
}

// Function to manually trigger notifications (for testing)
function smp_trigger_notifications() {
    smp_process_notifications();
}
add_action('admin_init', 'smp_trigger_notifications');

// Add this to handle the manual trigger
if (isset($_POST['trigger_notifications']) && check_admin_referer('smp_trigger_notifications', 'smp_nonce')) {
    smp_process_notifications();
    echo '<div class="notice notice-success"><p>Notifications processed manually!</p></div>';
}

// Add this to your notification systems display table
function smp_display_notification_systems() {
    global $wpdb;
    
    // Modified query to properly count templates
    $systems = $wpdb->get_results("
        SELECT 
            ns.*,
            COALESCE(COUNT(nt.id), 0) as template_count 
        FROM {$wpdb->prefix}smp_notification_systems ns
        LEFT JOIN {$wpdb->prefix}smp_notification_templates nt 
            ON ns.id = nt.system_id
        GROUP BY 
            ns.id, 
            ns.name, 
            ns.subscription_type, 
            ns.notification_timing, 
            ns.days_value, 
            ns.frequency, 
            ns.created_at
        ORDER BY ns.created_at DESC
    ");
    ?>
    <table class="wp-list-table widefat fixed striped">
        <thead>
            <tr>
                <th>System Name</th>
                <th>Plan Category</th>
                <th>When to Send</th>
                <th>Days Value</th>
                <th>Frequency</th>
                <th>Templates</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($systems as $system): ?>
                <tr>
                    <td><?php echo esc_html($system->name); ?></td>
                    <td><?php echo esc_html($system->subscription_type); ?></td>
                    <td><?php echo esc_html($system->notification_timing); ?></td>
                    <td><?php echo esc_html($system->days_value); ?></td>
                    <td><?php 
                        if ($system->frequency == (1/1440)) {
                            echo 'Every Minute';
                        } else {
                            echo 'Every ' . $system->frequency . ' day(s)';
                        }
                    ?></td>
                    <td><?php echo isset($system->template_count) ? intval($system->template_count) : 0; ?></td>
                    <td>
                        <button type="button" class="button button-small edit-system" 
                                data-id="<?php echo esc_attr($system->id); ?>"
                                data-name="<?php echo esc_attr($system->name); ?>"
                                data-subscription="<?php echo esc_attr($system->subscription_type); ?>"
                                data-timing="<?php echo esc_attr($system->notification_timing); ?>"
                                data-days="<?php echo esc_attr($system->days_value); ?>"
                                data-frequency="<?php echo esc_attr($system->frequency); ?>">
                            Edit
                        </button>
                        <button type="button" class="button button-small button-link-delete delete-system" 
                                data-id="<?php echo esc_attr($system->id); ?>">
                            Delete
                        </button>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <!-- Edit System Modal -->
    <div id="edit-system-modal" class="modal" style="display: none;">
        <div class="modal-content">
            <span class="close">&times;</span>
            <h2>Edit Notification System</h2>
            <form id="edit-system-form" method="post">
                <input type="hidden" id="edit_system_id" name="edit_system_id">
                <?php wp_nonce_field('edit_system_nonce', 'edit_system_nonce'); ?>
                
                <table class="form-table">
                    <tr>
                        <th><label for="edit_system_name">System Name</label></th>
                        <td><input type="text" id="edit_system_name" name="edit_system_name" required></td>
                    </tr>
                    <tr>
                        <th><label for="edit_plan_category">Plan Category</label></th>
                        <td>
                            <select id="edit_plan_category" name="edit_plan_category" required>
                                <optgroup label="Testing Plans">
                                    <option value="1min">1 Minute Plan</option>
                                    <option value="2min">2 Minutes Plan</option>
                                </optgroup>
                                <optgroup label="Standard Plans">
                                    <option value="1">1 Month Plan</option>
                                    <option value="3">3 Months Plan</option>
                                    <option value="6">6 Months Plan</option>
                                    <option value="12">12 Months Plan</option>
                                </optgroup>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="edit_notification_timing">When to Send</label></th>
                        <td>
                            <select id="edit_notification_timing" name="edit_notification_timing" required>
                                <option value="before">Before Expiration</option>
                                <option value="after">After Expiration</option>
                                <option value="every">Every</option>
                                <option value="renewal">Renewal Confirmation</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="edit_days_value">Days</label></th>
                        <td>
                            <select id="edit_days_value" name="edit_days_value">
                                <option value="5">5 days</option>
                                <option value="10">10 days</option>
                                <option value="15">15 days</option>
                                <option value="20">20 days</option>
                                <option value="25">25 days</option>
                                <option value="30">30 days</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="edit_frequency">Send Frequency</label></th>
                        <td>
                            <select id="edit_frequency" name="edit_frequency" required>
                                <optgroup label="Testing">
                                    <option value="1min">Every Minute (testing)</option>
                                </optgroup>
                                <optgroup label="Standard">
                                    <option value="1">Once per day</option>
                                    <option value="2">Once per 2 days</option>
                                    <option value="3">Once per 3 days</option>
                                    <option value="7">Once per week</option>
                                </optgroup>
                            </select>
                        </td>
                    </tr>
                </table>
                <div class="submit-button">
                    <input type="submit" name="edit_system" class="button button-primary" value="Save Changes">
                </div>
            </form>
        </div>
    </div>

    <style>
    .modal {
        display: none;
        position: fixed;
        z-index: 1000;
        left: 0;
        top: 0;
        width: 100%;
        height: 100%;
        background-color: rgba(0,0,0,0.4);
    }
    .modal-content {
        background-color: #fefefe;
        margin: 5% auto;
        padding: 20px;
        border-radius: 5px;
        box-shadow: 0 0 10px rgba(0, 0, 0, 0.5);
        max-width: 500px;
        width: 90%;
        position: relative;
    }
    .close {
        color: #aaa;
        float: right;
        font-size: 28px;
        font-weight: bold;
        cursor: pointer;
    }
    .submit-button {
        margin-top: 20px;
        text-align: right;
    }
    </style>

    <script>
    function editSystem(systemId) {
        // Get system data via AJAX
        jQuery.post(ajaxurl, {
            action: 'get_system_data',
            system_id: systemId,
            nonce: '<?php echo wp_create_nonce("get_system_data"); ?>'
        }, function(response) {
            if (response.success) {
                var system = response.data;
                jQuery('#edit_system_id').val(system.id);
                jQuery('#edit_system_name').val(system.name);
                jQuery('#edit_plan_category').val(system.subscription_type);
                jQuery('#edit_notification_timing').val(system.notification_timing);
                jQuery('#edit_days_value').val(system.days_value);
                jQuery('#edit_frequency').val(system.frequency == (1/1440) ? '1min' : system.frequency);
                jQuery('#edit-system-modal').show();
            }
        });
    }

    function deleteSystem(systemId) {
        if (confirm('Are you sure you want to delete this system? This will also delete all associated templates.')) {
            jQuery.post(ajaxurl, {
                action: 'delete_system',
                system_id: systemId,
                nonce: '<?php echo wp_create_nonce("delete_system"); ?>'
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Failed to delete system: ' + response.data.message);
                }
            });
        }
    }

    jQuery(document).ready(function($) {
        $('.close').click(function() {
            $('#edit-system-modal').hide();
        });

        $(window).click(function(event) {
            if (event.target == $('#edit-system-modal')[0]) {
                $('#edit-system-modal').hide();
            }
        });
    });
    </script>
    <?php
}

// Add AJAX handlers
add_action('wp_ajax_get_system_data', 'smp_get_system_data');
function smp_get_system_data() {
    check_ajax_referer('get_system_data', 'nonce');
    
    global $wpdb;
    $system_id = intval($_POST['system_id']);
    
    $system = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}smp_notification_systems WHERE id = %d",
        $system_id
    ));
    
    if ($system) {
        wp_send_json_success($system);
    } else {
        wp_send_json_error(['message' => 'System not found']);
    }
}

add_action('wp_ajax_delete_system', 'smp_delete_system');
function smp_delete_system() {
    check_ajax_referer('delete_system', 'nonce');
    
    global $wpdb;
    $system_id = intval($_POST['system_id']);
    
    // Delete system and associated templates
    $wpdb->delete(
        $wpdb->prefix . 'smp_notification_systems',
        ['id' => $system_id],
        ['%d']
    );
    
    wp_send_json_success();
}

// Handle system edit submission
if (isset($_POST['edit_system']) && check_admin_referer('edit_system_nonce', 'edit_system_nonce')) {
    global $wpdb;
    
    $system_id = intval($_POST['edit_system_id']);
    $name = sanitize_text_field($_POST['edit_system_name']);
    $plan_category = sanitize_text_field($_POST['edit_plan_category']);
    $notification_timing = sanitize_text_field($_POST['edit_notification_timing']);
    $days_value = intval($_POST['edit_days_value']);
    $frequency = $_POST['edit_frequency'] === '1min' ? 1/1440 : intval($_POST['edit_frequency']);

    $wpdb->update(
        $wpdb->prefix . 'smp_notification_systems',
        [
            'name' => $name,
            'subscription_type' => $plan_category,
            'notification_timing' => $notification_timing,
            'days_value' => $days_value,
            'frequency' => $frequency
        ],
        ['id' => $system_id]
    );
    
    echo '<div class="notice notice-success"><p>System updated successfully!</p></div>';
}
