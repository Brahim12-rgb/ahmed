<?php
/*
Plugin Name: Subscription Manager Pro
Description: Manage subscriptions and automatic emails for customers
Version: 1.0
Author: Brahim12-rgb
Date: 2025-01-19 21:42:27
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

        $end_date = new DateTime('2025-01-19 21:42:27');
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
            table-layout: fixed;
            width: 100%;
        }
        
        .wp-list-table th,
        .wp-list-table td {
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            padding: 12px 8px;
            vertical-align: middle;
        }
        
        /* Action Buttons Container */
        .action-buttons-container {
            display: flex;
            gap: 8px;
            align-items: center;
            justify-content: center;
            padding: 5px;
            border-radius: 4px;
        }

        /* Action Button Styles */
        .action-button {
            min-width: 70px !important;
            padding: 4px 12px !important;
            font-size: 12px !important;
            height: 30px !important;
            line-height: 1.8 !important;
            border-radius: 3px !important;
            text-align: center !important;
            cursor: pointer !important;
            transition: all 0.2s ease !important;
            font-weight: 500 !important;
            text-transform: uppercase !important;
            letter-spacing: 0.5px !important;
            border: 1px solid transparent !important;
            margin: 0 !important;
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

        /* Modal Styles */
        .modal-form {
            display: none;
            position: fixed;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 400px;
            background: #ffffff;
            padding: 24px;
            border-radius: 8px;
            box-shadow: 0 2px 20px rgba(0, 0, 0, 0.2);
            z-index: 1000;
        }

        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0, 0, 0, 0.5);
            z-index: 999;
        }

        .modal-form h3 {
            margin: 0 0 20px 0;
            padding-bottom: 10px;
            border-bottom: 1px solid #e5e5e5;
            font-size: 18px;
            color: #23282d;
        }

        .edit-form-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            margin-bottom: 20px;
        }

        .form-field {
            margin-bottom: 12px;
        }

        .form-field label {
            display: block;
            margin-bottom: 5px;
            font-weight: 500;
        }

        .form-field input,
        .form-field select {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #ddd;
            border-radius: 4px;
        }

        .modal-actions {
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            margin-top: 20px;
            padding-top: 15px;
            border-top: 1px solid #e5e5e5;
        }

        /* Countdown Styles */
        .countdown {
            font-weight: 600;
            padding: 8px !important;
            text-align: center;
            border-radius: 4px;
            transition: background-color 0.3s ease;
        }
    </style>

    <script>
        function toggleRenewForm(customerId) {
            const form = document.getElementById('renew-form-' + customerId);
            const overlay = document.getElementById('modal-overlay-' + customerId);
            
            if (form.style.display === 'none' || !form.style.display) {
                // Create overlay if it doesn't exist
                if (!overlay) {
                    const newOverlay = document.createElement('div');
                    newOverlay.id = 'modal-overlay-' + customerId;
                    newOverlay.className = 'modal-overlay';
                    document.body.appendChild(newOverlay);
                    
                    // Close modal when clicking overlay
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
                // Create overlay if it doesn't exist
                if (!overlay) {
                    const newOverlay = document.createElement('div');
                    newOverlay.id = 'modal-overlay-edit-' + customerId;
                    newOverlay.className = 'modal-overlay';
                    document.body.appendChild(newOverlay);
                    
                    // Close modal when clicking overlay
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

        function updateCountdowns() {
            const countdowns = document.querySelectorAll('.countdown');
            const now = new Date();

            countdowns.forEach(function(element) {
                const endDate = new Date(element.dataset.enddate);
                const timeLeft = endDate - now;

                if (timeLeft <= 0) {
                    element.innerHTML = '<span style="color: red;">Expired</span>';
                    element.style.backgroundColor = '#ffe6e6';
                } else {
                    const days = Math.floor(timeLeft / (1000 * 60 * 60 * 24));
                    const hours = Math.floor((timeLeft % (1000 * 60 * 60 * 24)) / (1000 * 60 * 60));
                    const minutes = Math.floor((timeLeft % (1000 * 60 * 60)) / (1000 * 60));

                    let displayText = '';
                    if (days > 0) {
                        displayText += days + ' day' + (days > 1 ? 's' : '') + ' ';
                    }
                    if (days < 7) {
                        displayText += hours + ' hr' + (hours > 1 ? 's' : '') + ' ';
                        if (days === 0) {
                            displayText += minutes + ' min' + (minutes > 1 ? 's' : '');
                        }
                    }

                    if (days <= 3) {
                        element.style.backgroundColor = '#fff3cd';
                        element.style.color = '#856404';
                    } else if (days <= 7) {
                        element.style.backgroundColor = '#fff3e6';
                        element.style.color = '#664d03';
                    } else {
                        element.style.backgroundColor = '#e8f5e9';
                        element.style.color = '#155724';
                    }

                    element.textContent = displayText.trim();
                }
            });
        }

        // Handle ESC key to close modals
        document.addEventListener('keydown', function(event) {
            if (event.key === 'Escape') {
                const modals = document.querySelectorAll('.modal-form');
                const overlays = document.querySelectorAll('.modal-overlay');
                
                modals.forEach(function(modal) {
                    modal.style.display = 'none';
                });
                
                overlays.forEach(function(overlay) {
                    overlay.style.display = 'none';
                });
            }
        });

        // Initialize countdowns
        jQuery(document).ready(function($) {
            updateCountdowns();
            setInterval(updateCountdowns, 60000);
        });
    </script>
    <?php
    $output = ob_get_clean();
    echo $output;
}
?>