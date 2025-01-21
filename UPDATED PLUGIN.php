

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
        $duration = intval($_POST['new_duration']);
        $enabled = isset($_POST['new_enabled']) ? 1 : 0;

        $end_date = new DateTime('2025-01-20 15:47:58');
        $interval_map = [
            '1' => 'P1M',
            '3' => 'P3M',
            '6' => 'P6M',
            '12' => 'P1Y'
        ];

        if (array_key_exists($duration, $interval_map)) {
            $interval = new DateInterval($interval_map[$duration]);
            $end_date->add($interval);

            $wpdb->insert(
                $table_customers,
                [
                    'name' => $name,
                    'email' => $email,
                    'phone' => $phone,
                    'devices' => $devices,
                    'plan_type' => $duration . ' month' . ($duration > 1 ? 's' : ''),
                    'end_date' => $end_date->format('Y-m-d H:i:s'),
                    'enabled' => $enabled
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

                $wpdb->update(
                    $table_customers,
                    ['end_date' => $end_date->format('Y-m-d H:i:s')],
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
                    'enabled' => $enabled
                ],
                ['id' => $customer_id]
            );

            echo '<div class="notice notice-success"><p>Customer information updated successfully!</p></div>';
        }
    }
    ?>
    <div class="wrap">
        <h1>Subscription Manager</h1>
        
        <div class="card" style="max-width: 1200px; margin-bottom: 20px; padding: 20px;">
    <h2>Add New Customer</h2>
    <form method="post">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 25px; max-width: 1100px;">
            <div class="form-field">
                <label for="new_name" style="display: block; margin-bottom: 8px;">Name</label>
                <input type="text" id="new_name" name="new_name" class="regular-text">
            </div>
            <div class="form-field">
                <label for="new_email" style="display: block; margin-bottom: 8px;">Email</label>
                <input type="email" id="new_email" name="new_email" required class="regular-text">
            </div>
            <div class="form-field">
                <label for="new_phone" style="display: block; margin-bottom: 8px;">Phone</label>
                <input type="text" id="new_phone" name="new_phone" class="regular-text">
            </div>
            <div class="form-field">
                <label for="new_devices" style="display: block; margin-bottom: 8px;">Devices</label>
                <select id="new_devices" name="new_devices" required class="regular-text">
                    <?php for ($i = 1; $i <= 10; $i++): ?>
                        <option value="<?php echo $i; ?>"><?php echo $i; ?> Device<?php echo ($i > 1 ? 's' : ''); ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="form-field">
                <label for="new_duration" style="display: block; margin-bottom: 8px;">Duration</label>
                <select id="new_duration" name="new_duration" required class="regular-text">
                    <option value="1">1 Month</option>
                    <option value="3">3 Months</option>
                    <option value="6">6 Months</option>
                    <option value="12">12 Months</option>
                </select>
            </div>
            <div class="form-field">
                <label for="new_enabled" style="display: block; margin-bottom: 8px;">Status</label>
                <select id="new_enabled" name="new_enabled" class="regular-text">
                    <option value="1">Active</option>
                    <option value="0">Disabled</option>
                </select>
            </div>
        </div>
        <div style="margin-top: 20px;">
            <input type="submit" name="add_customer" class="button button-primary" value="Add Customer">
        </div>
    </form>
</div><!-- Edit Form Modal -->
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
            <button type="button" class="button button-secondary" onclick="toggleEditForm(<?php echo $customer->id; ?>)">Cancel</button>
            <input type="submit" name="edit_customer" class="button button-primary" value="Save Changes">
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
            <button type="button" class="button button-secondary" onclick="toggleEditForm(<?php echo $customer->id; ?>)">Cancel</button>
            <input type="submit" name="edit_customer" class="button button-primary" value="Save Changes">
        </div>
    </form>
</div>

    <style>
    /* Input Field Dimensions */
.regular-text,
input[type="text"],
input[type="email"],
select {
    width: 350px !important; /* Increased width */
    height: 35px !important; /* Comfortable height */
    padding: 6px 12px !important;
    font-size: 14px !important;
}

/* Add New Customer Form specific styles */
.card input[type="text"],
.card input[type="email"],
.card select {
    width: 100% !important; /* Full width within their container */
    max-width: 350px !important;
}

/* Edit Form Modal specific styles */
.modal-form input[type="text"],
.modal-form input[type="email"],
.modal-form select {
    width: 100% !important;
    max-width: 350px !important;
}

/* Make the modal wider to accommodate larger input fields */
.modal-form {
    max-width: 800px !important; /* Increased from 500px */
    width: 90% !important;
    padding: 25px !important;
}

/* Adjust the edit form grid layout */
.edit-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 20px;
    margin-bottom: 20px;
}

/* Form field container adjustments */
.form-field {
    margin-bottom: 15px;
    padding: 0 5px;
}
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
    </script>
    <?php
    $output = ob_get_clean();
    echo $output;
}
