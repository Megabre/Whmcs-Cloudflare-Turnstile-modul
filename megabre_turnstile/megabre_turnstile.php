<?php

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function megabre_turnstile_config()
{
    // Keeping fields here ensures WHMCS creates the rows in proper format for `tbladdonmodules`
    // and allows fallback if someone uses the modal config. 
    return [
        'name' => 'Cloudflare Turnstile Manager',
        'description' => 'Replaces reCAPTCHA with Cloudflare Turnstile. Manage settings below in the output area.',
        'author' => 'Megabre',
        'language' => 'english',
        'version' => '1.2',
        'fields' => [
            'site_key' => ['FriendlyName' => 'Site Key', 'Type' => 'text', 'Size' => '50', 'Description' => 'Managed via main interface'],
            'secret_key' => ['FriendlyName' => 'Secret Key', 'Type' => 'password', 'Size' => '50', 'Description' => 'Managed via main interface'],
            'theme' => ['FriendlyName' => 'Theme', 'Type' => 'dropdown', 'Options' => 'auto,light,dark', 'Default' => 'auto', 'Description' => 'Managed via main interface'],
            'enable_login' => ['FriendlyName' => 'Enable on Login', 'Type' => 'yesno', 'Description' => 'Managed via main interface'],
            'enable_register' => ['FriendlyName' => 'Enable on Register', 'Type' => 'yesno', 'Description' => 'Managed via main interface'],
            'enable_pwreset' => ['FriendlyName' => 'Enable on Password Reset', 'Type' => 'yesno', 'Description' => 'Managed via main interface'],
            'enable_contact' => ['FriendlyName' => 'Enable on Contact', 'Type' => 'yesno', 'Description' => 'Managed via main interface'],
            'enable_ticket' => ['FriendlyName' => 'Enable on Ticket Submit', 'Type' => 'yesno', 'Description' => 'Managed via main interface'],
            'enable_cart' => ['FriendlyName' => 'Enable on Shopping Cart', 'Type' => 'yesno', 'Description' => 'Managed via main interface'],
            'custom_login_sel' => ['FriendlyName' => 'Login Selector', 'Type' => 'text', 'Size' => '50', 'Description' => 'Managed via main interface'],
            'custom_register_sel' => ['FriendlyName' => 'Register Selector', 'Type' => 'text', 'Size' => '50', 'Description' => 'Managed via main interface'],
            'custom_pwreset_sel' => ['FriendlyName' => 'PW Reset Selector', 'Type' => 'text', 'Size' => '50', 'Description' => 'Managed via main interface'],
            'custom_contact_sel' => ['FriendlyName' => 'Contact Selector', 'Type' => 'text', 'Size' => '50', 'Description' => 'Managed via main interface'],
            'custom_ticket_sel' => ['FriendlyName' => 'Ticket Selector', 'Type' => 'text', 'Size' => '50', 'Description' => 'Managed via main interface'],
            'custom_cart_sel' => ['FriendlyName' => 'Cart Selector', 'Type' => 'text', 'Size' => '50', 'Description' => 'Managed via main interface'],
        ]
    ];
}

function megabre_turnstile_activate()
{
    return ['status' => 'success', 'description' => 'Cloudflare Turnstile Manager activated successfully.'];
}

function megabre_turnstile_deactivate()
{
    return ['status' => 'success', 'description' => 'Cloudflare Turnstile Manager deactivated successfully.'];
}

function megabre_turnstile_output($vars)
{
    $moduleName = 'megabre_turnstile';
    $validSettings = [
        'site_key', 'secret_key', 'theme', 
        'enable_login', 'enable_register', 'enable_pwreset', 'enable_contact', 'enable_ticket', 'enable_cart',
        'custom_login_sel', 'custom_register_sel', 'custom_pwreset_sel', 'custom_contact_sel', 'custom_ticket_sel', 'custom_cart_sel'
    ];

    // Handle Save
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'save') {
        foreach ($validSettings as $setting) {
            $value = isset($_POST[$setting]) ? trim($_POST[$setting]) : '';
            
            // Checkbox logic for WHMCS 'yesno' fields
            if (strpos($setting, 'enable_') === 0) {
                 $value = ($value === 'on') ? 'on' : '';
            }

            Capsule::table('tbladdonmodules')->updateOrInsert(
                ['module' => $moduleName, 'setting' => $setting],
                ['value' => $value]
            );
        }
        echo '<div class="alert alert-success">Settings saved successfully!</div>';
    }

    // Retrieve settings
    $settings = [];
    foreach ($validSettings as $key) {
        $settings[$key] = Capsule::table('tbladdonmodules')->where('module', $moduleName)->where('setting', $key)->value('value');
    }

    // Render Form
    echo '<style>
        .megabre-card { background: #fff; padding: 25px; border-radius: 6px; border: 1px solid #e0e0e0; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.05); }
        .megabre-card h3 { margin-top: 0; border-bottom: 1px solid #eee; padding-bottom: 15px; margin-bottom: 20px; color: #333; font-size: 18px; }
        .form-group { margin-bottom: 15px; }
        label { display: block; font-weight: 600; margin-bottom: 8px; color: #555; }
        input[type="text"], input[type="password"], select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; box-sizing: border-box; font-size: 14px; }
        input[type="text"]:focus, input[type="password"]:focus, select:focus { border-color: #2196F3; outline: none; }
        .help-block { color: #888; font-size: 0.85em; margin-top: 5px; }
        .row { display: flex; flex-wrap: wrap; margin: 0 -15px; }
        .col-half { flex: 0 0 50%; padding: 0 15px; box-sizing: border-box; }
        
        /* Switch UI */
        .toggle-row { display: flex; align-items: center; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #f5f5f5; }
        .toggle-row:last-child { border-bottom: none; }
        .switch { position: relative; display: inline-block; width: 46px; height: 26px; }
        .switch input { opacity: 0; width: 0; height: 0; }
        .slider { position: absolute; cursor: pointer; top: 0; left: 0; right: 0; bottom: 0; background-color: #ccc; transition: .4s; border-radius: 34px; }
        .slider:before { position: absolute; content: ""; height: 20px; width: 20px; left: 3px; bottom: 3px; background-color: white; transition: .4s; border-radius: 50%; }
        input:checked + .slider { background-color: #4CAF50; }
        input:checked + .slider:before { transform: translateX(20px); }
        
        .btn-save { background: #007bff; color: #fff; padding: 12px 30px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; font-weight: 600; transition: background 0.2s; }
        .btn-save:hover { background: #0056b3; }
        .actions-row { margin-top: 20px; text-align: right; }
    </style>';

    echo '<form method="post" action="">
        <input type="hidden" name="action" value="save">
        
        <div class="megabre-card">
            <h3>API Configuration</h3>
            <div class="row">
                <div class="col-half">
                    <div class="form-group">
                        <label>Site Key</label>
                        <input type="text" name="site_key" value="' . htmlspecialchars($settings['site_key']) . '" placeholder="0x4AAAAAA..." autocomplete="off">
                    </div>
                </div>
                <div class="col-half">
                    <div class="form-group">
                        <label>Secret Key</label>
                        <input type="password" name="secret_key" value="' . htmlspecialchars($settings['secret_key']) . '" placeholder="0x4AAAAAA..." autocomplete="off">
                    </div>
                </div>
            </div>
             <div class="form-group" style="max-width: 200px;">
                <label>Theme</label>
                <select name="theme">
                    <option value="auto" ' . ($settings['theme'] == 'auto' ? 'selected' : '') . '>Auto</option>
                    <option value="light" ' . ($settings['theme'] == 'light' ? 'selected' : '') . '>Light</option>
                    <option value="dark" ' . ($settings['theme'] == 'dark' ? 'selected' : '') . '>Dark</option>
                </select>
            </div>
        </div>

        <div class="row">
            <div class="col-half">
                <div class="megabre-card">
                    <h3>Page Visibility Settings</h3>
                    <div class="toggle-row">
                        <span>Enable on Login</span>
                        <label class="switch">
                            <input type="checkbox" name="enable_login" ' . ($settings['enable_login'] == 'on' ? 'checked' : '') . '>
                            <span class="slider"></span>
                        </label>
                    </div>
                     <div class="toggle-row">
                        <span>Enable on Register</span>
                        <label class="switch">
                            <input type="checkbox" name="enable_register" ' . ($settings['enable_register'] == 'on' ? 'checked' : '') . '>
                            <span class="slider"></span>
                        </label>
                    </div>
                     <div class="toggle-row">
                        <span>Enable on Password Reset</span>
                        <label class="switch">
                            <input type="checkbox" name="enable_pwreset" ' . ($settings['enable_pwreset'] == 'on' ? 'checked' : '') . '>
                            <span class="slider"></span>
                        </label>
                    </div>
                     <div class="toggle-row">
                        <span>Enable on Contact</span>
                        <label class="switch">
                            <input type="checkbox" name="enable_contact" ' . ($settings['enable_contact'] == 'on' ? 'checked' : '') . '>
                            <span class="slider"></span>
                        </label>
                    </div>
                     <div class="toggle-row">
                        <span>Enable on Ticket Submit</span>
                        <label class="switch">
                            <input type="checkbox" name="enable_ticket" ' . ($settings['enable_ticket'] == 'on' ? 'checked' : '') . '>
                            <span class="slider"></span>
                        </label>
                    </div>
                     <div class="toggle-row">
                        <span>Enable on Shopping Cart</span>
                        <label class="switch">
                            <input type="checkbox" name="enable_cart" ' . ($settings['enable_cart'] == 'on' ? 'checked' : '') . '>
                            <span class="slider"></span>
                        </label>
                    </div>
                </div>
            </div>
            
            <div class="col-half">
                <div class="megabre-card">
                    <h3>Advanced: Custom Selectors</h3>
                    <p class="help-block" style="margin-bottom: 15px;">Enter jQuery selectors (e.g., <code>.btn-submit</code>) to automatically inject the widget before specific elements. Leave empty to use auto-detection.</p>
                    
                    <div class="form-group">
                        <label>Login Form Selector</label>
                        <input type="text" name="custom_login_sel" value="' . htmlspecialchars($settings['custom_login_sel']) . '">
                    </div>
                    <div class="form-group">
                        <label>Register Form Selector</label>
                        <input type="text" name="custom_register_sel" value="' . htmlspecialchars($settings['custom_register_sel']) . '">
                    </div>
                    <div class="form-group">
                        <label>PW Reset Selector</label>
                        <input type="text" name="custom_pwreset_sel" value="' . htmlspecialchars($settings['custom_pwreset_sel']) . '">
                    </div>
                    <div class="form-group">
                        <label>Contact Form Selector</label>
                        <input type="text" name="custom_contact_sel" value="' . htmlspecialchars($settings['custom_contact_sel']) . '">
                    </div>
                    <div class="form-group">
                        <label>Ticket Form Selector</label>
                        <input type="text" name="custom_ticket_sel" value="' . htmlspecialchars($settings['custom_ticket_sel']) . '">
                    </div>
                    <div class="form-group">
                        <label>Cart/Checkout Selector</label>
                        <input type="text" name="custom_cart_sel" value="' . htmlspecialchars($settings['custom_cart_sel']) . '">
                    </div>
                </div>
            </div>
        </div>

        <div class="actions-row">
            <button type="submit" class="btn-save">Save Configuration</button>
        </div>
    </form>';
}
