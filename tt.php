<?php
/*
Plugin Name: Subscription Manager Pro
Description: Manage subscriptions and automatic emails for customers
Version: 1.0
Author: Brahim12-rgb
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
        
        <div class="card" style="max-width: 800px; margin-bottom: 20px; padding: 20px;">
            <h2>Add New Customer</h2>
            <form method="post">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                    <div>
                        <label for="new_name" style="display: block; margin-bottom: 5px;">Name</label>
                        <input type="text" id="new_name" name="new_name" class="regular-text" style="width: 100%;">
                    </div>
                    <div>
                        <label for="new_email" style="display: block; margin-bottom: 5px;">Email</label>
                        <input type="email" id="new_email" name="new_email" required class="regular-text" style="width: 100%;">
                    </div>
                    <div>
                        <label for="new_phone" style="display: block; margin-bottom: 5px;">Phone</label>
                        <input type="text" id="new_phone" name="new_phone" class="regular-text" style="width: 100%;">
                    </div>
                    <div>
                        <label for="new_devices" style="display: block; margin-bottom: 5px;">Devices</label>
                        <select id="new_devices" name="new_devices" required style="width: 100%;">
                            <?php
                            for ($i = 1; $i <= 10; $i++) {
                                echo "<option value=\"$i\">$i Device" . ($i > 1 ? 's' : '') . "</option>";
                            }
                            ?>
                        </select>
                    </div>
                    <div>
                        <label for="new_duration" style="display: block; margin-bottom: 5px;">Duration</label>
                        <select id="new_duration" name="new_duration" required style="width: 100%;">
                            <option value="1">1 Month</option>
                            <option value="3">3 Months</option>
                            <option value="6">6 Months</option>
                            <option value="12">12 Months</option>
                        </select>
                    </div>
                    <div>
                        <label for="new_enabled" style="display: block; margin-bottom: 5px;">Status</label>
                        <select id="new_enabled" name="new_enabled" style="width: 100%;">
                            <option value="1">Active</option>
                            <option value="0">Disabled</option>
                        </select>
                    </div>
                </div>
                <div style="margin-top: 20px; text-align: right;">
                    <input type="submit" name="add_customer" class="button button-primary" value="Add Customer">
                </div>
            </form>
        </div>

        <?php
        $customers = $wpdb->get_results("SELECT * FROM $table_customers ORDER BY id DESC");
        ?>

        <h2>Existing Customers</h2>
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
                            <div class="action-buttons-container">
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

    <style>
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
            grid-template-columns: 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .form-field {
            margin-bottom: 10px;
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

        /* Action Buttons Container */
        .action-buttons-container {
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: flex-start;
            padding: 5px;
        }

        /* Action Button Styles */
        .action-button {
            min-width: 60px !important;
            padding: 4px 8px !important;
            font-size: 12px !important;
            height: 28px !important;
            line-height: 1.5 !important;
            border-radius: 3px !important;
            text-align: center !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
        }

        /* Button Colors */
        .renew-button {
            background-color: #2271b1 !important;
            color: white !important;
            border-color: #2271b1 !important;
        }

        .renew-button:hover {
            background-color: #135e96 !important;
            border-color: #135e96 !important;
        }

        .edit-button {
            background-color: #6c757d !important;
            color: white !important;
            border-color: #6c757d !important;
        }

        .edit-button:hover {
            background-color: #5c636a !important;
            border-color: #565e64 !important;
        }

        .delete-button {
            background-color: #dc3545 !important;
            color: white !important;
            border-color: #dc3545 !important;
        }

        .delete-button:hover {
            background-color: #bb2d3b !important;
            border-color: #b02a37 !important;
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
                } else {
                    const days = Math.floor(timeLeft / (1000 * 60 * 60 * 24));
                    const hours = Math.floor((timeLeft % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));

                    element.textContent = `${days}d ${hours}h ${minutes}m`;
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