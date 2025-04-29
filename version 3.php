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
        note text DEFAULT NULL,
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

    // Add note column if it doesn't exist
    $check_column = $wpdb->get_results("SHOW COLUMNS FROM {$table_customers} LIKE 'note'");
    if (empty($check_column)) {
        $wpdb->query("ALTER TABLE {$table_customers} ADD COLUMN note text DEFAULT NULL AFTER enabled");
    }

    // Create email templates table without dropping
    $table_templates = $wpdb->prefix . 'smp_email_templates';
    $sql_templates = "CREATE TABLE IF NOT EXISTS $table_templates (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        subject varchar(255) NOT NULL,
        name varchar(100) NOT NULL,
        content longtext NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_templates);

    // Verify table creation
    if (WP_DEBUG) {
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_templates'");
        error_log('Email templates table exists: ' . ($table_exists ? 'yes' : 'no'));
        if ($table_exists) {
            $columns = $wpdb->get_results("SHOW COLUMNS FROM $table_templates");
            error_log('Table columns: ' . print_r($columns, true));
        }
    }

    // Create reminders table without dropping it
    $table_reminders = $wpdb->prefix . 'smp_reminders';
    $sql_reminders = "CREATE TABLE IF NOT EXISTS $table_reminders (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        name varchar(100) NOT NULL,
        subject varchar(255) NOT NULL,
        content longtext NOT NULL,
        created_at datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql_reminders);

    // Verify reminders table creation
    if (WP_DEBUG) {
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table_reminders'");
        error_log('Reminders table exists: ' . ($table_exists ? 'yes' : 'no'));
    }
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

    // Single Email Composer menu
    add_submenu_page(
        'subscription-manager',
        'Email Composer',
        'Email Composer',
        'manage_options',
        'subscription-manager-email',
        'smp_email_composer_page'
    );

    // Add Reminders submenu
    add_submenu_page(
        'subscription-manager',
        'Reminders',
        'Reminders',
        'manage_options',
        'subscription-manager-reminders',
        'smp_reminders_page'
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
    
    // Check if email already exists
    $existing_customer = $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM $table_customers WHERE email = %s",
        $email
    ));

    if ($existing_customer > 0) {
        echo '<div class="notice notice-error"><p>Customer with this email already exists.</p></div>';
    } else {
        $phone = sanitize_text_field($_POST['new_phone']);
        $devices = intval($_POST['new_devices']);
        $duration = $_POST['new_duration'];
        $enabled = isset($_POST['new_enabled']) ? 1 : 0;

        $end_date = new DateTime();  // Use current time

        if ($duration === 'custom' && isset($_POST['custom_days'])) {
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
                'enabled' => $enabled,
                'note' => sanitize_textarea_field($_POST['new_note'])
            ]
        );

            echo '<div class="notice notice-success"><p>New customer added successfully!</p></div>';
    }
}

    if (isset($_POST['renew_subscription'])) {
        $customer_id = intval($_POST['customer_id']);
        $renew_duration = intval($_POST['renew_duration']);

        $current_subscription = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM $table_customers WHERE id = %d",
            $customer_id
        ));

        if ($current_subscription) {
            // Get the later date between current end date and now
            $now = new DateTime();
            $current_end = new DateTime($current_subscription->end_date);
            $start_from = $current_end > $now ? $current_end : $now;

            // Add the selected duration
            $interval_map = [
                '1' => 'P1M',
                '3' => 'P3M',
                '6' => 'P6M',
                '12' => 'P1Y'
            ];

            if (array_key_exists($renew_duration, $interval_map)) {
                $interval = new DateInterval($interval_map[$renew_duration]);
                $new_end_date = clone $start_from;
                $new_end_date->add($interval);

                $wpdb->update(
                    $table_customers,
                    ['end_date' => $new_end_date->format('Y-m-d H:i:s')],
                    ['id' => $customer_id]
                );

                echo '<div class="notice notice-success"><p>Subscription renewed successfully!</p></div>';
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
                    'enabled' => $enabled,
                    'note' => sanitize_textarea_field($_POST['note'])
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
        <option value="1">1 Month</option>
        <option value="3">3 Months</option>
        <option value="6">6 Months</option>
        <option value="12">12 Months</option>
        <option value="custom">Custom Days</option>
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
                <div>
                    <label for="new_note" style="display: block; margin-bottom: 8px;">Notes</label>
                    <textarea id="new_note" name="new_note" style="width: 100%; padding: 8px; box-sizing: border-box; height: 80px;"></textarea>
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

// Add the customer count here
$total_customers = count($customers);
?>
<div class="customer-count">Total Customers: <strong><?php echo $total_customers; ?></strong></div>

<!-- Remove the h2 "Existing Customers" and continue with the search filter container -->
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
                        <td>
                            <?php 
                            $name_display = empty($customer->name) ? '-' : esc_html($customer->name);
                            if (!empty($customer->note)) {
                                $name_display .= ' <span class="note-indicator" title="' . esc_attr($customer->note) . '">!</span>';
                            }
                            ?>
                            <a href="#" onclick="showCustomerDetails(<?php echo $customer->id; ?>); return false;" class="customer-name-link">
                                <?php echo $name_display; ?>
                            </a>
                        </td>
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
                                        <div class="form-field" style="grid-column: 1 / -1;">
                                            <label>Notes:</label>
                                            <textarea name="note" class="regular-text" style="width: 100%; height: 80px;"><?php echo esc_textarea($customer->note); ?></textarea>
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
        <!-- Customer Details Modal -->
        <div id="customer-details-modal" class="modal-form" style="display:none;">
            <div class="modal-content">
                <h3>Customer Details</h3>
                <div id="customer-details-content"></div>
                <div class="modal-actions">
                    <button type="button" class="button button-secondary" onclick="closeCustomerDetails()">Close</button>
                </div>
            </div>
        </div>
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
            gap: 6px !important;                   /* Increased spacing between buttons */
            align-items: center;
            padding: 0 !important;
            position: relative;
            margin-left: -15px !important;         /* Move buttons further left */
            transform: translateX(-5px);           /* Fine-tune the left shift */
            white-space: nowrap !important;
        }

        /* Action Button Styles */
        .action-button {
            min-width: 35px !important;           /* Slightly smaller width */
            padding: 0 4px !important;            /* Reduce padding */
            font-size: 10px !important;
            height: 18px !important;
            line-height: 18px !important;
            text-align: center !important;
            margin: 0 !important;
        }

        /* Expired Status Style */
        .countdown.expired {
            color: red;
            font-weight: bold;
        }

        .note-indicator {
            display: inline-block;
            background-color: #e65100;
            color: white;
            width: 16px;
            height: 16px;
            line-height: 16px;
            text-align: center;
            border-radius: 50%;
            font-size: 12px;
            font-weight: bold;
            margin-left: 5px;
            cursor: help;
        }

        .note-button {
            background-color: #e65100 !important;
            color: white !important;
            min-width: 20px !important;
            border-radius: 50% !important;
        }

        .note-button:hover {
            background-color: #f57c00 !important;
        }

        .note-popup {
            position: fixed;
            background: white;
            padding: 15px;
            border-radius: 4px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.2);
            z-index: 1000;
            max-width: 300px;
            word-wrap: break-word;
        }

        .customer-count {
            background: #fff;
            padding: 15px;
            margin: 20px 0;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            font-size: 16px;
        }

        .customer-name-link {
            text-decoration: none;
            color: #2271b1;
        }

        .customer-name-link:hover {
            color: #135e96;
            text-decoration: underline;
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
                    const minutes = Math.floor((timeLeft % (1000 * 60)) / (1000 * 60));

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

        function showNotePopup(button) {
            // Remove any existing popup
            const existingPopup = document.querySelector('.note-popup');
            if (existingPopup) {
                existingPopup.remove();
            }

            // Create and show new popup
            const popup = document.createElement('div');
            popup.className = 'note-popup';
            popup.textContent = button.getAttribute('data-note');

            // Position popup near the button
            const rect = button.getBoundingClientRect();
            popup.style.top = (rect.bottom + window.scrollY + 5) + 'px';
            popup.style.left = (rect.left + window.scrollX - 280) + 'px'; // Adjust popup position

            // Add click outside listener to close popup
            document.addEventListener('click', function closePopup(e) {
                if (e.target !== button && e.target !== popup) {
                    popup.remove();
                    document.removeEventListener('click', closePopup);
                }
            });

            document.body.appendChild(popup);
        }

        function showCustomerDetails(customerId) {
            const customers = <?php echo json_encode($customers); ?>;
            const customer = customers.find(c => c.id === customerId);
            
            if (!customer) return;

            const detailsHtml = `
                <div class="customer-details">
                    <p><strong>Name:</strong> ${customer.name || '-'}</p>
                    <p><strong>Email:</strong> ${customer.email}</p>
                    <p><strong>Phone:</strong> ${customer.phone || '-'}</p>
                    <p><strong>Devices:</strong> ${customer.devices}</p>
                    <p><strong>Plan Type:</strong> ${customer.plan_type}</p>
                    <p><strong>Start Date:</strong> ${customer.start_date}</p>
                    <p><strong>End Date:</strong> ${customer.end_date}</p>
                    <p><strong>Status:</strong> ${customer.enabled ? 'Active' : 'Disabled'}</p>
                    ${customer.note ? `<p><strong>Notes:</strong> ${customer.note}</p>` : ''}
                </div>
            `;

            const modal = document.getElementById('customer-details-modal');
            const content = document.getElementById('customer-details-content');
            content.innerHTML = detailsHtml;
            
            modal.style.display = 'block';
            
            // Create overlay if it doesn't exist
            let overlay = document.getElementById('modal-overlay-details');
            if (!overlay) {
                overlay = document.createElement('div');
                overlay.id = 'modal-overlay-details';
                overlay.className = 'modal-overlay';
                document.body.appendChild(overlay);
            }
            overlay.style.display = 'block';
        }

        function closeCustomerDetails() {
            const modal = document.getElementById('customer-details-modal');
            const overlay = document.getElementById('modal-overlay-details');
            
            modal.style.display = 'none';
            if (overlay) {
                overlay.style.display = 'none';
            }
        }
        
    </script>
    <?php
    $output = ob_get_clean();
    echo $output;
}

function get_enhanced_editor_settings($textarea_name) {
    return array(
        'media_buttons' => false,
        'textarea_rows' => 15,
        'teeny' => false,
        'textarea_name' => $textarea_name,
        'editor_height' => 400,
        'tinymce' => array(
            'theme_advanced_buttons1' => 'formatselect,fontselect,fontsizeselect,|,bold,italic,underline,strikethrough,|,forecolor,backcolor,|,alignleft,aligncenter,alignright,alignjustify,|,bullist,numlist',
            'theme_advanced_buttons2' => '',
            'theme_advanced_buttons3' => '',
            'forced_root_block' => 'p',
            'remove_linebreaks' => false,
            'keep_styles' => true,
            'paste_as_text' => false,
            'paste_remove_styles' => false,
            'paste_remove_spans' => false,
            'font_formats' => 'Arial=arial,helvetica,sans-serif;' .
                            'Times New Roman=times new roman,times,serif;' .
                            'Courier New=courier new,courier,monospace;' .
                            'Open Sans=Open Sans,sans-serif;' .
                            'Roboto=Roboto,sans-serif;' .
                            'Lato=Lato,sans-serif',
            'verify_html' => false,
            'cleanup' => false,
            'content_css' => get_stylesheet_directory_uri() . '/editor-style.css'
        ),
        'quicktags' => true,
        'editor_css' => '<style>
            .mce-toolbar .mce-btn button {
                padding: 4px 8px;
                line-height: 20px;
            }
            .mce-toolbar .mce-btn i {
                font-size: 16px;
            }
        </style>'
    );
}

function smp_email_composer_page() {
    global $wpdb;
    $table_templates = $wpdb->prefix . 'smp_email_templates';
    $templates = $wpdb->get_results("SELECT * FROM $table_templates ORDER BY name ASC");
    
    $active_tab = isset($_GET['tab']) ? sanitize_text_field($_GET['tab']) : 'send';

    // Get editor settings based on active tab
    $editor_settings = get_enhanced_editor_settings(
        $active_tab === 'send' ? 'email_content' : 'template_content'
    );

    // Handle email sending with improved error handling
    if (isset($_POST['send_email'])) {
        $to = sanitize_email($_POST['email_to']);
        $subject = sanitize_text_field($_POST['email_subject']);
        // Preserve HTML formatting in email content
        $message = wp_kses_post($_POST['email_content']);
        
        if (empty($to)) {
            echo '<div class="notice notice-error is-dismissible"><p>✘ Recipient email is required.</p></div>';
        } else {
            // Set up email headers
            $sitename = wp_parse_url(get_site_url(), PHP_URL_HOST);
            $from_email = 'wordpress@' . $sitename;
            $from_name = get_bloginfo('name');
            
            $headers = array(
                'Content-Type: text/html; charset=UTF-8',
                sprintf('From: %s <%s>', $from_name, $from_email),
                sprintf('Reply-To: %s <%s>', $from_name, get_option('admin_email'))
            );

            // Add error reporting
            $original_error_reporting = error_reporting();
            error_reporting($original_error_reporting | E_ERROR | E_WARNING);
            
            try {
                // Ensure proper paragraph formatting
                $message = wpautop($message);
                
                // Attempt to send email
                add_filter('wp_mail_content_type', function() { return 'text/html'; });
                $sent = wp_mail($to, $subject, $message, $headers);
                remove_filter('wp_mail_content_type', 'set_html_content_type');

                if ($sent) {
                    echo '<div class="notice notice-success is-dismissible"><p>✔ Email sent successfully to ' . esc_html($to) . '</p></div>';
                } else {
                    $error = error_get_last();
                    if ($error) {
                        echo '<div class="notice notice-error is-dismissible"><p>✘ Email error: ' . esc_html($error['message']) . '</p></div>';
                    } else {
                        echo '<div class="notice notice-error is-dismissible"><p>✘ Failed to send email. Please check your WordPress mail configuration.</p></div>';
                    }
                }
            } catch (Exception $e) {
                echo '<div class="notice notice-error is-dismissible"><p>✘ Error: ' . esc_html($e->getMessage()) . '</p></div>';
            }
            
            // Restore error reporting
            error_reporting($original_error_reporting);
        }
    }

    // Handle template operations
    if (isset($_POST['save_template'])) {
        $template_name = sanitize_text_field($_POST['template_name']);
        $template_subject = sanitize_text_field($_POST['template_subject']);
        $template_content = wp_kses_post($_POST['template_content']);
        $template_id = isset($_POST['edit_template_id']) ? intval($_POST['edit_template_id']) : 0;

        if (!empty($template_name) && !empty($template_subject) && !empty($template_content)) {
            // Ensure content is properly formatted
            $template_content = wpautop($template_content);
            
            if ($template_id > 0) {
                // Update existing template
                $result = $wpdb->update(
                    $table_templates,
                    array(
                        'name' => $template_name,
                        'subject' => $template_subject,
                        'content' => $template_content
                    ),
                    array('id' => $template_id),
                    array('%s', '%s', '%s'),
                    array('%d')
                );

                $action_type = 'updated';
            } else {
                // Insert new template
                $result = $wpdb->insert(
                    $table_templates,
                    array(
                        'name' => $template_name,
                        'subject' => $template_subject,
                        'content' => $template_content,
                        'created_at' => current_time('mysql')
                    ),
                    array('%s', '%s', '%s', '%s')
                );

                $action_type = 'saved';
            }

            if ($result === false) {
                error_log('WordPress database error: ' . $wpdb->last_error);
                echo '<div class="notice notice-error is-dismissible"><p>✘ Database Error: ' . esc_html($wpdb->last_error) . '</p></div>';
            } else {
                echo '<div class="notice notice-success is-dismissible"><p>✔ Template ' . $action_type . ' successfully!</p></div>';
                ?>
                <script type="text/javascript">
                    setTimeout(function() {
                        window.location.href = '?page=subscription-manager-email&tab=templates&success=1';
                    }, 1000);
                </script>
                <?php
            }
        } else {
            echo '<div class="notice notice-error is-dismissible"><p>✘ All fields are required.</p></div>';
        }
    }

    // Modify the templates query to ensure fresh results
    $templates = $wpdb->get_results("SELECT * FROM $table_templates ORDER BY created_at DESC");
    if ($wpdb->last_error) {
        error_log('Error fetching email templates: ' . $wpdb->last_error);
    }

    // Add debug output if needed (remove in production)
    if (WP_DEBUG) {
        error_log('Templates found: ' . count($templates));
        error_log('Last SQL query: ' . $wpdb->last_query);
    }

    // Handle template deletion
    if (isset($_POST['delete_template'])) {
        $template_id = intval($_POST['template_id']);
        if (isset($_POST['delete_template_nonce']) && wp_verify_nonce($_POST['delete_template_nonce'], 'delete_template_' . $template_id)) {
            $wpdb->delete($table_templates, array('id' => $template_id), array('%d'));
            echo '<div class="notice notice-success is-dismissible"><p>✔ Template deleted successfully!</p></div>';
        }
    }

    ?>
    <div class="wrap">
        <h1>Email Composer</h1>

        <nav class="nav-tab-wrapper">
            <a href="?page=subscription-manager-email&tab=send" 
               class="nav-tab <?php echo $active_tab === 'send' ? 'nav-tab-active' : ''; ?>">
                Send Email
            </a>
            <a href="?page=subscription-manager-email&tab=templates" 
               class="nav-tab <?php echo $active_tab === 'templates' ? 'nav-tab-active' : ''; ?>">
                Email Templates
            </a>
        </nav>

        <div class="tab-content" style="margin-top: 20px;">
            <?php if ($active_tab === 'send'): ?>
                <!-- Send Email Tab -->
                <div class="card" style="max-width: 1200px; padding: 20px; margin: 20px auto;">
                    <form method="post" id="email-form">
                        <div class="form-field" style="margin-bottom: 20px;">
                            <label for="template_select" style="display: block; margin-bottom: 8px; font-weight: 600;">
                                Load Template (Optional)
                            </label>
                            <select id="template_select" name="template_select" style="width: 100%; padding: 8px;" onchange="loadEmailTemplate(this.value)">
                                <option value="">Select a template...</option>
                                <?php foreach ($templates as $template): ?>
                                    <option value="<?php echo esc_attr($template->id); ?>">
                                        <?php echo esc_html($template->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-field" style="margin-bottom: 20px;">
                            <label for="email_to" style="display: block; margin-bottom: 8px; font-weight: 600;">
                                To
                            </label>
                            <input type="email" name="email_to" id="email_to" required 
                                   style="width: 100%; padding: 8px;" placeholder="recipient@example.com">
                        </div>

                        <div class="form-field" style="margin-bottom: 20px;">
                            <label for="email_subject" style="display: block; margin-bottom: 8px; font-weight: 600;">
                                Subject
                            </label>
                            <input type="text" name="email_subject" id="email_subject" required 
                                   style="width: 100%; padding: 8px;" placeholder="Email subject">
                        </div>

                        <div class="form-field" style="margin-bottom: 20px;">
                            <label for="email_content" style="display: block; margin-bottom: 8px; font-weight: 600;">
                                Message
                            </label>
                            <?php
                            // Use the same enhanced editor settings for email composition
                            $email_editor_settings = get_enhanced_editor_settings('email_content');
                            wp_editor('', 'email_content', $email_editor_settings);
                            ?>
                        </div>

                        <div class="form-field email-preview" style="margin-bottom: 20px; display: none;">
                            <label style="display: block; margin-bottom: 8px; font-weight: 600;">
                                Preview
                            </label>
                            <div id="email-preview-content" style="border: 1px solid #ddd; padding: 15px; background: #fff; min-height: 200px;">
                            </div>
                        </div>

                        <div class="form-field" style="text-align: right;">
                            <button type="button" class="button button-secondary" onclick="previewEmail()" style="margin-right: 10px;">
                                Preview Email
                            </button>
                            <input type="submit" name="send_email" class="button button-primary button-large" 
                                   value="Send Email" style="padding: 5px 20px;">
                        </div>
                    </form>
                </div>

                <script>
                function previewEmail() {
                    const content = tinymce.get('email_content').getContent();
                    const subject = document.getElementById('email_subject').value;
                    const preview = document.getElementById('email-preview-content');
                    const previewContainer = document.querySelector('.email-preview');
                    
                    if (content || subject) {
                        let previewHtml = '';
                        if (subject) {
                            previewHtml += `<div style="margin-bottom: 15px; padding-bottom: 15px; border-bottom: 1px solid #ddd;">
                                <strong>Subject:</strong> ${subject}
                            </div>`;
                        }
                        previewHtml += content;
                        preview.innerHTML = previewHtml;
                        previewContainer.style.display = 'block';
                        preview.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
                    } else {
                        alert('Please enter email content to preview');
                    }
                }

                // Enhanced template loading
                function loadEmailTemplate(templateId) {
                    if (!templateId) return;

                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=get_email_template&template_id=' + templateId + 
                             '&_ajax_nonce=' + '<?php echo wp_create_nonce("email_template_nonce"); ?>'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            document.getElementById('email_subject').value = data.data.subject || '';
                            
                            if (typeof tinymce !== 'undefined') {
                                const editor = tinymce.get('email_content');
                                if (editor) {
                                    editor.setContent(data.data.content || '');
                                    // Trigger preview after loading template
                                    previewEmail();
                                }
                            }
                        }
                    })
                    .catch(error => console.error('Error loading template:', error));
                }
                </script>

            <?php else: ?>
                <!-- Templates Tab -->
                <div class="card" style="max-width: 1200px; padding: 20px; margin: 20px auto;"> <!-- Added margin: 20px auto -->
                    <form method="post" id="template-form">
                        <div class="form-field" style="margin-bottom: 20px;">
                            <label for="template_name" style="display: block; margin-bottom: 8px; font-weight: 600;">
                                Template Name
                            </label>
                            <input type="text" name="template_name" id="template_name" required 
                                   style="width: 100%; padding: 8px;" placeholder="Enter template name">
                        </div>

                        <div class="form-field" style="margin-bottom: 20px;">
                            <label for="template_subject" style="display: block; margin-bottom: 8px; font-weight: 600;">
                                Subject
                            </label>
                            <input type="text" name="template_subject" id="template_subject" required 
                                   style="width: 100%; padding: 8px;" placeholder="Enter email subject">
                        </div>

                        <div class="form-field" style="margin-bottom: 20px;">
                            <label for="template_content" style="display: block; margin-bottom: 8px; font-weight: 600;">
                                Content
                            </label>
                            <?php
                            wp_editor('', 'template_content', $editor_settings);
                            ?>
                        </div>

                        <div class="form-field" style="text-align: right;">
                            <input type="submit" name="save_template" class="button button-primary button-large" 
                                   value="Save Template" style="padding: 5px 20px;">
                        </div>
                    </form>

                    <div style="margin-top: 30px;">
                        <h3>Existing Templates</h3>
                        <table class="wp-list-table widefat fixed striped" style="width: 100%;"> <!-- Added width: 100% -->
                            <thead>
                                <tr>
                                    <th>Name</th>
                                    <th>Subject</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($templates as $template): ?>
                                    <tr>
                                        <td><?php echo esc_html($template->name); ?></td>
                                        <td><?php echo esc_html($template->subject); ?></td>
                                        <td>
                                            <button type="button" class="button button-small" 
                                                    onclick="loadTemplateToEditor(<?php echo esc_attr($template->id); ?>)">
                                                Edit
                                            </button>
                                            <form method="post" style="display:inline-block; margin-left: 5px;">
                                                <?php wp_nonce_field('delete_template_' . $template->id, 'delete_template_nonce'); ?>
                                                <input type="hidden" name="template_id" value="<?php echo esc_attr($template->id); ?>">
                                                <button type="submit" name="delete_template" class="button button-small" 
                                                        onclick="return confirm('Are you sure you want to delete this template?')">
                                                    Delete
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <script>
    function loadEmailTemplate(templateId) {
        if (!templateId) {
            return;
        }

        fetch(ajaxurl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: 'action=get_email_template&template_id=' + templateId + '&_ajax_nonce=' + '<?php echo wp_create_nonce("email_template_nonce"); ?>'
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Set subject
                document.getElementById('email_subject').value = data.data.subject || '';
                
                // Set content in TinyMCE
                if (typeof tinymce !== 'undefined') {
                    const editor = tinymce.get('email_content');
                    if (editor) {
                        editor.setContent(data.data.content || '');
                    } else {
                        // Fallback to textarea if TinyMCE isn't initialized
                        document.getElementById('email_content').value = data.data.content || '';
                    }
                }
            }
        })
        .catch(error => console.error('Error loading template:', error));
    }

    // Add this function to handle loading template content for editing
    function loadTemplateToEditor(templateId) {
        // Add the template content to the form fields
        const template = document.getElementById('template-form');
        if (template) {
            fetch(ajaxurl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'action=get_email_template&template_id=' + templateId + '&_ajax_nonce=' + '<?php echo wp_create_nonce("email_template_nonce"); ?>'
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    document.getElementById('template_name').value = data.data.name || '';
                    document.getElementById('template_subject').value = data.data.subject || '';
                    
                    // Set content in TinyMCE if available, otherwise in textarea
                    if (typeof tinymce !== 'undefined' && tinymce.get('template_content')) {
                        tinymce.get('template_content').setContent(data.data.content || '');
                    } else {
                        document.getElementById('template_content').value = data.data.content || '';
                    }
                    
                    // Add template ID to form for update
                    let idInput = document.getElementById('edit_template_id');
                    if (!idInput) {
                        idInput = document.createElement('input');
                        idInput.type = 'hidden';
                        idInput.id = 'edit_template_id';
                        idInput.name = 'edit_template_id';
                        template.appendChild(idInput);
                    }
                    idInput.value = templateId;

                    // Change button text to indicate editing
                    const submitButton = template.querySelector('input[name="save_template"]');
                    submitButton.value = 'Update Template';

                    // Scroll to form
                    template.scrollIntoView({ behavior: 'smooth' });
                }
            })
            .catch(error => console.error('Error loading template:', error));
        }
    }
    </script>
    <?php
}

// Remove the duplicate function and keep only this one instance
add_action('wp_ajax_get_email_template', 'smp_get_email_template');
function smp_get_email_template() {
    if (!check_ajax_referer('email_template_nonce', false, false)) {
        wp_send_json_error('Invalid nonce');
        return;
    }

    global $wpdb;
    $template_id = intval($_POST['template_id']);
    
    $template = $wpdb->get_row($wpdb->prepare(
        "SELECT name, subject, content FROM {$wpdb->prefix}smp_email_templates WHERE id = %d",
        $template_id
    ));
    
    if ($template) {
        // Ensure content is properly formatted for the editor
        $template->content = wp_kses_post($template->content);
        wp_send_json_success($template);
    } else {
        wp_send_json_error('Template not found');
    }
}

function smp_reminders_page() {
    // Ensure user has permission
    if (!current_user_can('manage_options') && !current_user_can('manage_woocommerce')) {
        wp_die(__('You do not have sufficient permissions to access this page.'));
    }

    global $wpdb;
    $table_reminders = $wpdb->prefix . 'smp_reminders';

    // Handle form submissions
    if (isset($_POST['save_reminder'])) {
        if (!isset($_POST['reminder_nonce']) || !wp_verify_nonce($_POST['reminder_nonce'], 'save_reminder')) {
            wp_die('Security check failed');
        }

        $name = sanitize_text_field($_POST['reminder_name']);
        $subject = sanitize_text_field($_POST['reminder_subject']);
        $content = wp_kses_post($_POST['reminder_content']);
        
        if (!empty($name) && !empty($subject) && !empty($content)) {
            // Check if we're editing
            $reminder_id = isset($_POST['reminder_id']) ? intval($_POST['reminder_id']) : 0;
            
            if ($reminder_id > 0) {
                $wpdb->update(
                    $table_reminders,
                    array(
                        'name' => $name,
                        'subject' => $subject,
                        'content' => $content
                    ),
                    array('id' => $reminder_id),
                    array('%s', '%s', '%s'),
                    array('%d')
                );
            } else {
                $wpdb->insert(
                    $table_reminders,
                    array(
                        'name' => $name,
                        'subject' => $subject,
                        'content' => $content,
                        'created_at' => current_time('mysql')
                    ),
                    array('%s', '%s', '%s', '%s')
                );
            }
            echo '<div class="notice notice-success"><p>✔ Reminder ' . ($reminder_id ? 'updated' : 'saved') . ' successfully!</p></div>';
        }
    }

    // Get existing reminders
    $reminders = $wpdb->get_results("SELECT * FROM $table_reminders ORDER BY created_at DESC");
    
    // Use the same enhanced editor settings for reminders
    $editor_settings = get_enhanced_editor_settings('reminder_content');

    ?>
    <div class="wrap">
        <h1>Email Reminders</h1>
        
        <div class="card" style="max-width: 1200px; padding: 20px; margin: 20px 0;">
            <form method="post" id="reminder-form">
                <?php wp_nonce_field('save_reminder', 'reminder_nonce'); ?>
                <input type="hidden" name="reminder_id" id="reminder_id" value="">
                
                <div class="form-field">
                    <label for="reminder_name" style="display: block; margin-bottom: 5px;">
                        <strong>Reminder Name</strong>
                    </label>
                    <input type="text" name="reminder_name" id="reminder_name" class="regular-text" required
                           style="width: 100%;">
                </div>
                
                <div class="form-field" style="margin-top: 15px;">
                    <label for="reminder_subject" style="display: block; margin-bottom: 5px;">
                        <strong>Email Subject</strong>
                    </label>
                    <input type="text" name="reminder_subject" id="reminder_subject" class="regular-text" required
                           style="width: 100%;">
                </div>
                
                <div class="form-field" style="margin-top: 15px;">
                    <label for="reminder_content" style="display: block; margin-bottom: 5px;">
                        <strong>Email Content</strong>
                    </label>
                    <?php 
                    wp_editor('', 'reminder_content', $editor_settings);
                    ?>
                </div>
                
                <div style="margin-top: 20px; text-align: right;">
                    <input type="submit" name="save_reminder" class="button button-primary" 
                           value="Save Reminder" style="padding: 5px 20px;">
                </div>
            </form>
        </div>

        <!-- Display existing reminders -->
        <div class="card" style="max-width: 1200px; padding: 20px;">
            <h2>Existing Reminders</h2>
            <table class="wp-list-table widefat fixed striped">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Subject</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($reminders as $reminder): ?>
                        <tr>
                            <td><?php echo esc_html($reminder->name); ?></td>
                            <td><?php echo esc_html($reminder->subject); ?></td>
                            <td>
                                <button type="button" class="button button-small"
                                        onclick="editReminder(<?php echo esc_attr(json_encode($reminder)); ?>)">
                                    Edit
                                </button>
                                <button type="button" class="button button-small" style="margin-left: 5px;"
                                        onclick="deleteReminder(<?php echo esc_attr($reminder->id); ?>)">
                                    Delete
                                </button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
    function editReminder(reminder) {
        document.getElementById('reminder_id').value = reminder.id;
        document.getElementById('reminder_name').value = reminder.name;
        document.getElementById('reminder_subject').value = reminder.subject;
        
        if (typeof tinymce !== 'undefined' && tinymce.get('reminder_content')) {
            tinymce.get('reminder_content').setContent(reminder.content);
        } else {
            document.getElementById('reminder_content').value = reminder.content;
        }
        
        document.querySelector('input[name="save_reminder"]').value = 'Update Reminder';
        document.querySelector('#reminder-form').scrollIntoView({ behavior: 'smooth' });
    }

    function deleteReminder(id) {
        if (confirm('Are you sure you want to delete this reminder?')) {
            jQuery.post(ajaxurl, {
                action: 'delete_reminder',
                reminder_id: id,
                nonce: '<?php echo wp_create_nonce("delete_reminder"); ?>'
            }, function(response) {
                if (response.success) {
                    location.reload();
                } else {
                    alert('Error deleting reminder');
                }
            });
        }
    }
    </script>
    <?php
}

// Update the meta box registration with priority and screen check
function smp_add_order_reminder_box() {
    // Debug log to verify function is called
    error_log('Attempting to add reminder meta box');
    
    // Check if WooCommerce is active
    if (!class_exists('WC_Order')) {
        error_log('WooCommerce not active');
        return;
    }

    add_meta_box(
        'wc_order_reminders_box',
        'Payment Reminders',
        'smp_order_reminder_box_html',
        'shop_order',
        'side',
        'high' // Changed to high priority
    );
}
// Change priority to ensure it runs after WooCommerce
add_action('add_meta_boxes', 'smp_add_order_reminder_box', 20);

function smp_order_reminder_box_html($post) {
    global $wpdb;
    
    // Debug logging
    error_log('Rendering reminder box for order: ' . $post->ID);
    
    $order = wc_get_order($post->ID);
    if (!$order) {
        error_log('Could not load order');
        return;
    }

    $reminders = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}smp_reminders ORDER BY name ASC");
    if (empty($reminders)) {
        echo '<p>No reminders available. <a href="' . admin_url('admin.php?page=subscription-manager-reminders') . '">Create one</a></p>';
        return;
    }
    ?>
    <div class="reminder-box" style="padding: 10px 0;">
        <select id="reminder_select" style="width: 100%; margin-bottom: 10px;">
            <option value="">Select a reminder template...</option>
            <?php foreach ($reminders as $reminder): ?>
                <option value="<?php echo esc_attr($reminder->id); ?>">
                    <?php echo esc_html($reminder->name); ?>
                </option>
            <?php endforeach; ?>
        </select>
        <button type="button" class="button button-primary" 
                style="width: 100%; margin-top: 5px; text-align: center;" 
                onclick="sendOrderReminder(<?php echo $order->get_id(); ?>)">
            Send Payment Reminder
        </button>
    </div>

    <script>
    function sendOrderReminder(orderId) {
        const reminderId = document.getElementById('reminder_select').value;
        if (!reminderId) {
            alert('Please select a reminder template');
            return;
        }

        // Show loading state
        const button = event.target;
        const originalText = button.innerHTML;
        button.innerHTML = 'Sending...';
        button.disabled = true;
        
        jQuery.post(ajaxurl, {
            action: 'send_order_reminder',
            order_id: orderId,
            reminder_id: reminderId,
            nonce: '<?php echo wp_create_nonce("send_reminder_nonce"); ?>'
        })
        .done(function(response) {
            if (response.success) {
                alert('Reminder sent successfully!');
                // Reload page to show new order note
                location.reload();
            } else {
                alert('Error: ' + (response.data || 'Failed to send reminder'));
            }
        })
        .fail(function() {
            alert('Network error occurred');
        })
        .always(function() {
            // Restore button state
            button.innerHTML = originalText;
            button.disabled = false;
        });
    }
    </script>
    <?php
}

// Add AJAX handlers
add_action('wp_ajax_delete_reminder', 'smp_delete_reminder');
function smp_delete_reminder() {
    if (!wp_verify_nonce($_POST['nonce'], 'delete_reminder')) {
        wp_send_json_error('Invalid nonce');
        return;
    }

    global $wpdb;
    $reminder_id = intval($_POST['reminder_id']);
    
    $result = $wpdb->delete(
        $wpdb->prefix . 'smp_reminders',
        array('id' => $reminder_id),
        array('%d')
    );
    
    if ($result !== false) {
        wp_send_json_success();
    } else {
        wp_send_json_error();
    }
}

// Add AJAX handler for sending reminders
add_action('wp_ajax_send_order_reminder', 'smp_send_order_reminder');
function smp_send_order_reminder() {
    // Add detailed error logging
    error_log('Reminder send attempt started');
    
    if (!check_ajax_referer('send_reminder_nonce', false, false)) {
        error_log('Nonce verification failed');
        wp_send_json_error('Invalid security token');
        return;
    }

    global $wpdb;
    $order_id = intval($_POST['order_id']);
    $reminder_id = intval($_POST['reminder_id']);
    
    error_log("Processing reminder: Order ID {$order_id}, Reminder ID {$reminder_id}");
    
    $order = wc_get_order($order_id);
    if (!$order) {
        error_log('Order not found: ' . $order_id);
        wp_send_json_error('Order not found');
        return;
    }
    
    $reminder = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}smp_reminders WHERE id = %d",
        $reminder_id
    ));
    
    if (!$reminder) {
        error_log('Reminder not found: ' . $reminder_id);
        wp_send_json_error('Reminder template not found');
        return;
    }
    
    $to = $order->get_billing_email();
    $subject = $reminder->subject;
    $message = wpautop($reminder->content);
    
    // Set up proper email headers
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        'Reply-To: ' . get_option('admin_email')
    );

    error_log('Attempting to send email to: ' . $to);
    error_log('Subject: ' . $subject);
    error_log('Headers: ' . print_r($headers, true));
    
    // Enable HTML emails
    add_filter('wp_mail_content_type', function() { return 'text/html'; });
    
    // Send email
    $sent = wp_mail($to, $subject, $message, $headers);
    
    // Remove HTML email filter
    remove_filter('wp_mail_content_type', function() { return 'text/html'; });
    
    if ($sent) {
        $note = sprintf('Payment reminder "%s" sent to %s', $reminder->name, $to);
        $order->add_order_note($note);
        error_log('Email sent successfully');
        wp_send_json_success('Reminder sent successfully');
    } else {
        error_log('Failed to send email: ' . error_get_last()['message']);
        wp_send_json_error('Failed to send reminder. Please check email settings.');
    }
}

// Add reminder to order actions dropdown
add_filter('woocommerce_order_actions', 'smp_add_order_reminder_action');
function smp_add_order_reminder_action($actions) {
    global $wpdb;
    
    // Get all reminders
    $reminders = $wpdb->get_results("SELECT * FROM {$wpdb->prefix}smp_reminders ORDER BY name ASC");
    
    // Add each reminder as an action
    if ($reminders) {
        foreach ($reminders as $reminder) {
            $actions['send_reminder_' . $reminder->id] = sprintf('Send Reminder: %s', $reminder->name);
        }
    }
    
    return $actions;
}

// Handle the reminder action
add_action('woocommerce_order_action_send_reminder', 'smp_process_reminder_action', 10, 1);
function smp_process_reminder_action($order) {
    global $wpdb;
    
    $action = current_filter();
    $reminder_id = intval(str_replace('woocommerce_order_action_send_reminder_', '', $action));
    
    error_log("Processing order action for reminder ID: {$reminder_id}");
    
    $reminder = $wpdb->get_row($wpdb->prepare(
        "SELECT * FROM {$wpdb->prefix}smp_reminders WHERE id = %d",
        $reminder_id
    ));
    
    if (!$reminder) {
        error_log('Reminder not found in order action');
        return;
    }
    
    $to = $order->get_billing_email();
    $subject = $reminder->subject;
    $message = wpautop($reminder->content);
    
    $headers = array(
        'Content-Type: text/html; charset=UTF-8',
        'From: ' . get_bloginfo('name') . ' <' . get_option('admin_email') . '>',
        'Reply-To: ' . get_option('admin_email')
    );
    
    add_filter('wp_mail_content_type', function() { return 'text/html'; });
    $sent = wp_mail($to, $subject, $message, $headers);
    remove_filter('wp_mail_content_type', function() { return 'text/html'; });
    
    if ($sent) {
        $note = sprintf('Payment reminder "%s" sent to %s', $reminder->name, $to);
        $order->add_order_note($note);
        error_log('Reminder sent via order action');
    } else {
        error_log('Failed to send reminder via order action: ' . error_get_last()['message']);
    }
}

// Add dynamic actions for each reminder
add_action('admin_init', 'smp_add_dynamic_reminder_actions');
function smp_add_dynamic_reminder_actions() {
    global $wpdb;
    
    $reminders = $wpdb->get_results("SELECT id FROM {$wpdb->prefix}smp_reminders");
    
    if ($reminders) {
        foreach ($reminders as $reminder) {
            add_action(
                'woocommerce_order_action_send_reminder_' . $reminder->id,
                'smp_process_reminder_action'
            );
        }
    }
}

// Add this action to enqueue Google Fonts if you're using them
add_action('admin_enqueue_scripts', 'smp_enqueue_editor_fonts');
function smp_enqueue_editor_fonts($hook) {
    if (strpos($hook, 'subscription-manager') !== false) {
        wp_enqueue_style(
            'google-fonts',
            'https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;700&family=Roboto:wght@400;700&family=Lato:wght@400;700&display=swap',
            array(),
            null
        );
    }
}

add_action('admin_init', 'smp_add_editor_styles');
function smp_add_editor_styles() {
    add_editor_style(array(
        'https://fonts.googleapis.com/css2?family=Open+Sans:wght@400;700&family=Roboto:wght@400;700&family=Lato:wght@400;700&display=swap'
    ));
}