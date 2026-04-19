<?php

use WHMCS\Database\Capsule;

if (!defined("WHMCS")) {
    die("This file cannot be accessed directly");
}

function megabre_turnstile_verify($response)
{
    $secretKey = Capsule::table('tbladdonmodules')->where('module', 'megabre_turnstile')->where('setting', 'secret_key')->value('value');
    if (!$secretKey) return false;

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://challenges.cloudflare.com/turnstile/v0/siteverify');
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'secret' => $secretKey,
        'response' => $response,
        'remoteip' => $_SERVER['REMOTE_ADDR']
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    $result = curl_exec($ch);
    curl_close($ch);

    $json = json_decode($result, true);
    return $json['success'] ?? false;
}

function megabre_turnstile_get_setting($name)
{
    // Simple cache-like static variable could be used but Capsule is fast enough for low traffic
    return Capsule::table('tbladdonmodules')->where('module', 'megabre_turnstile')->where('setting', $name)->value('value');
}

function megabre_turnstile_is_enabled($pageSetting)
{
    return megabre_turnstile_get_setting($pageSetting) === 'on';
}

function megabre_turnstile_get_site_key()
{
    return megabre_turnstile_get_setting('site_key');
}

/**
 * Early interception for pages without dedicated validation hooks.
 * hooks.php is loaded during init.php, BEFORE contact.php processes the form,
 * so this check can block spam before the email is sent.
 */
if (
    php_sapi_name() !== 'cli'
    && basename($_SERVER['SCRIPT_NAME'] ?? '') === 'contact.php'
    && $_SERVER['REQUEST_METHOD'] === 'POST'
    && isset($_POST['action']) && $_POST['action'] === 'send'
    && megabre_turnstile_is_enabled('enable_contact')
) {
    if (!isset($_POST['cf-turnstile-response']) || !megabre_turnstile_verify($_POST['cf-turnstile-response'])) {
        unset($_POST['action']);
        $_REQUEST['action'] = '';
    }
}

/**
 * Early interception: WHMCS 8+ posts client login to index.php (routed URL), not dologin.php.
 * UserLoginVerification is not documented and is not invoked on current WHMCS builds, so login was effectively unchecked.
 */
if (
    php_sapi_name() !== 'cli'
    && (!defined('ADMINAREA') || !ADMINAREA)
    && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
    && megabre_turnstile_is_enabled('enable_login')
) {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $path = strtolower((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (strpos($path, '/admin/') === false && strpos($path, '\\admin\\') === false
        && ($script === 'index.php' || $script === 'dologin.php')
        && isset($_POST['username'], $_POST['password'])
        && is_string($_POST['username']) && is_string($_POST['password'])
        && $_POST['username'] !== '' && $_POST['password'] !== ''
    ) {
        $token = isset($_POST['cf-turnstile-response']) ? trim((string) $_POST['cf-turnstile-response']) : '';
        if ($token === '' || !megabre_turnstile_verify($token)) {
            header('Location: login.php?error=captcha');
            exit;
        }
    }
}

/**
 * Early interception: password reset email step posts to index.php (routed). ClientAreaPagePasswordReset is for template data,
 * not a reliable pre-submit gate on all WHMCS versions.
 */
if (
    php_sapi_name() !== 'cli'
    && (!defined('ADMINAREA') || !ADMINAREA)
    && isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST'
    && megabre_turnstile_is_enabled('enable_pwreset')
    && isset($_POST['action']) && $_POST['action'] === 'reset'
    && isset($_POST['email']) && is_string($_POST['email']) && trim($_POST['email']) !== ''
) {
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    $path = strtolower((string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (strpos($path, '/admin/') === false && strpos($path, '\\admin\\') === false
        && ($script === 'index.php' || $script === 'pwreset.php')
    ) {
        $token = isset($_POST['cf-turnstile-response']) ? trim((string) $_POST['cf-turnstile-response']) : '';
        if ($token === '' || !megabre_turnstile_verify($token)) {
            header('Location: pwreset.php?error=captcha');
            exit;
        }
    }
}

/**
 * Register Smarty Function {display_turnstile}
 */
add_hook('ClientAreaPageHooks', 1, function ($vars) {
    return [
        'display_turnstile' => function($params, $smarty) {
            $siteKey = megabre_turnstile_get_site_key();
            if (!$siteKey) return '';
            $theme = megabre_turnstile_get_setting('theme') ?: 'auto';
            return '<div class="cf-turnstile" data-sitekey="' . $siteKey . '" data-theme="' . $theme . '"></div>';
        }
    ];
});


/**
 * Inject Cloudflare Turnstile Script
 */
add_hook('ClientAreaHeadOutput', 1, function ($vars) {
    if (!megabre_turnstile_get_site_key()) return;
    return '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async defer></script>';
});

/**
 * Inject Widget into Forms via Footer JS
 */
add_hook('ClientAreaFooterOutput', 1, function ($vars) {
    $siteKey = megabre_turnstile_get_site_key();
    if (!$siteKey) return;

    $templatefile = $vars['templatefile'];
    $filename = $vars['filename'];

    $theme = megabre_turnstile_get_setting('theme') ?: 'auto';
    $widgetHtml = '<div class="cf-turnstile" data-sitekey="' . $siteKey . '" data-theme="' . $theme . '" style="margin: 15px 0;"></div>';
    
    // CSS to hide default captcha
    $css = '<style>
        .g-recaptcha, #google-recaptcha-domainchecker, .recaptcha-container { display: none !important; }
        div[class*="captcha"] { display: none !important; } 
        /* Re-show our widget if it got hidden by generic selector */
        .cf-turnstile { display: block !important; }
    </style>';

    $jsCode = '';

    // Helper to get selector
    $getSelector = function($configName, $defaults) {
        $custom = megabre_turnstile_get_setting($configName);
        if ($custom && trim($custom) !== '') {
            // If custom selector is provided, just assume prepend
            return 'jQuery("' . addslashes($custom) . '").before(\'' . $this->widgetHtml . '\');'; 
        }
        return false;
    };

    // Note: We can't access $widgetHtml or $getSelector easily inside helper without passing classes or global.
    // Let's just do inline logic for simplicity.

    // Login
    if ($templatefile == 'login' && megabre_turnstile_is_enabled('enable_login')) {
        $custom = megabre_turnstile_get_setting('custom_login_sel');
        if ($custom) {
            $sel = addslashes($custom);
            $jsCode .= 'jQuery("' . $sel . '").before(\'' . $widgetHtml . '\');';
            $jsCode .= 'jQuery("' . $sel . '").closest("form").on("submit", function(e) {
                var token = jQuery(this).find("[name=\'cf-turnstile-response\']").val();
                if (!token) { e.preventDefault(); alert("Lütfen captcha doğrulamasını tamamlayın."); return false; }
            });';
        } else {
            // Default logic including Megatech + WHMCS 8+ routed login (login.php / index.php?rp=.../login/validate)
            $jsCode .= 'if(jQuery(".megabre-login-wrap").length) {
                 jQuery(".megabre-login-wrap form button[type=\'submit\']").closest("button").before(\'' . $widgetHtml . '\');
            } else {
                 jQuery("form.login-form, form[action*=\'dologin\'], form[action*=\'login/validate\'], form[action*=\'login%2fvalidate\']")
                    .find("button[type=\'submit\'], input[type=\'submit\']").first().closest("div.form-group, div.mb-3, .float-left, .text-center").before(\'' . $widgetHtml . '\');
            }';
        }
        $jsCode .= '
            jQuery("form.login-form, .megabre-login-wrap form, form[action*=\'dologin\'], form[action*=\'login/validate\'], form[action*=\'login%2fvalidate\']").on("submit", function(e) {
                var token = jQuery(this).find("[name=\'cf-turnstile-response\']").val();
                if (!token) {
                    e.preventDefault();
                    alert("Lütfen captcha doğrulamasını tamamlayın.");
                    return false;
                }
            });
            if (window.location.search.indexOf("error=captcha") !== -1) {
                jQuery(".megabre-login-wrap, form.login-form").closest("section, .container, .card, main").first()
                    .prepend(\'<div class="alert alert-danger" style="margin-bottom:20px;">Captcha doğrulaması başarısız oldu. Lütfen tekrar deneyin.</div>\');
            }';
    }

    // Register
    if ($templatefile == 'clientregister' && megabre_turnstile_is_enabled('enable_register')) {
        $custom = megabre_turnstile_get_setting('custom_register_sel');
        if ($custom) {
             $jsCode .= 'jQuery("' . $custom . '").before(\'' . $widgetHtml . '\');';
        } else {
            // WHMCS 8+ Twenty-One: no #btnRegister — form is #frmCheckout with hidden name="register"
            $jsCode .= 'if(jQuery(".megabre-register-wrap").length) {
                jQuery(".megabre-register-wrap form button[type=\'submit\']").closest("button").before(\'' . $widgetHtml . '\');
            } else {
                var $regBtn = jQuery("#btnRegister");
                if ($regBtn.length) {
                    $regBtn.closest("div.form-group, div.mb-3").before(\'' . $widgetHtml . '\');
                } else {
                    jQuery(\'form:has(input[name="register"][value="true"]), form#frmCheckout\').find("input[type=\'submit\'], button[type=\'submit\']").first()
                        .closest("p.text-center, div.text-center, div.form-group, .card-body").before(\'' . $widgetHtml . '\');
                }
            }';
        }
    }

    // Password Reset
    if ($templatefile == 'password-reset-container' && megabre_turnstile_is_enabled('enable_pwreset')) {
        $custom = megabre_turnstile_get_setting('custom_pwreset_sel');
        if ($custom) {
            $sel = addslashes($custom);
            $jsCode .= 'jQuery("' . $sel . '").before(\'' . $widgetHtml . '\');';
            $jsCode .= 'jQuery("' . $sel . '").closest("form").on("submit", function(e) {
                var token = jQuery(this).find("[name=\'cf-turnstile-response\']").val();
                if (!token) { e.preventDefault(); alert("Lütfen captcha doğrulamasını tamamlayın."); return false; }
            });';
        } else {
            // Prefer hidden action=reset (email step); fall back to action URL — avoids fragile .has() on action strings
            $jsCode .= 'var $pwForm = jQuery(\'form:has(input[type="hidden"][name="action"][value="reset"])\').first();
                if (!$pwForm.length) {
                    $pwForm = jQuery("form[action*=\'password-reset-validate-email\'], form[action*=\'password%2freset%2fvalidate-email\'], form[action*=\'pwreset\'], form[action*=\'password-reset\']");
                }
                $pwForm.find("button[type=\'submit\'], input[type=\'submit\']").first()
                    .closest("div.form-group, div.text-center, .col-12, p, div").before(\'' . $widgetHtml . '\');';
        }
        $jsCode .= '
            jQuery(\'form:has(input[type="hidden"][name="action"][value="reset"]), form[action*=\'password-reset-validate-email\'], form[action*=\'password%2freset%2fvalidate-email\'], form[action*=\'pwreset\']").on("submit", function(e) {
                if (jQuery(this).find(\'input[name="email"]\').length === 0) return;
                var token = jQuery(this).find("[name=\'cf-turnstile-response\']").val();
                if (!token) {
                    e.preventDefault();
                    alert("Lütfen captcha doğrulamasını tamamlayın.");
                    return false;
                }
            });
            if (window.location.search.indexOf("error=captcha") !== -1) {
                jQuery(\'form:has(input[type="hidden"][name="action"][value="reset"]), form[action*=\'password-reset\'], form[action*=\'pwreset\']").closest("section, .container, .card, main").first()
                    .prepend(\'<div class="alert alert-danger" style="margin-bottom:20px;">Captcha doğrulaması başarısız oldu. Lütfen tekrar deneyin.</div>\');
            }';
    }

    // Support Ticket
    if (($templatefile == 'supportticketsubmit-stepone' || $templatefile == 'supportticketsubmit-steptwo') && megabre_turnstile_is_enabled('enable_ticket')) {
        $custom = megabre_turnstile_get_setting('custom_ticket_sel');
        if($custom) {
             $jsCode .= 'jQuery("' . $custom . '").before(\'' . $widgetHtml . '\');';
        } else {
             $jsCode .= 'jQuery("#openTicketSubmit").closest("p, div.form-group").before(\'' . $widgetHtml . '\');';
        }
    }

    // Contact
    if ($templatefile == 'contact' && megabre_turnstile_is_enabled('enable_contact')) {
        $custom = megabre_turnstile_get_setting('custom_contact_sel');
        if($custom) {
             $jsCode .= 'jQuery("' . $custom . '").before(\'' . $widgetHtml . '\');';
        } else {
             $jsCode .= 'if(jQuery(".megabre-contact-form-wrap").length) {
                jQuery(".megabre-contact-form-wrap form button[type=\'submit\']").closest(".col-12").before(\'<div class="col-12">' . $widgetHtml . '</div>\');
            } else {
                jQuery("form[action*=\'contact\'] button[type=\'submit\']").closest("p, div.text-center, div.col-12, div.form-group").before(\'' . $widgetHtml . '\');
            }';
        }
        $jsCode .= '
            jQuery("form[action*=\'contact.php\'], .megabre-contact-form-wrap form").on("submit", function(e) {
                var token = jQuery(this).find("[name=\'cf-turnstile-response\']").val();
                if (!token) {
                    e.preventDefault();
                    alert("Lütfen captcha doğrulamasını tamamlayın.");
                    return false;
                }
            });
            if (window.location.search.indexOf("error=captcha") !== -1) {
                jQuery(".megabre-contact-form-wrap, form[action*=\'contact.php\']").closest("section, .container").first()
                    .prepend(\'<div class="alert alert-danger" style="margin-bottom:20px;">Captcha doğrulaması başarısız oldu. Lütfen tekrar deneyin.</div>\');
            }';
    }

    // Shopping Cart / Checkout
    if ((strpos($templatefile, 'checkout') !== false || $filename == 'cart') && megabre_turnstile_is_enabled('enable_cart')) {
        $custom = megabre_turnstile_get_setting('custom_cart_sel');
        if($custom) {
             $jsCode .= 'jQuery("' . $custom . '").before(\'' . $widgetHtml . '\');';
        } else {
             $jsCode .= 'jQuery("#btnCompleteOrder").closest("div").before(\'' . $widgetHtml . '\');';
        }
    }

    if ($jsCode) {
        return $css . '<script>jQuery(document).ready(function() { ' . $jsCode . ' });</script>';
    }
});

/**
 * Validation Hooks
 */

// Login Validation
add_hook('UserLoginVerification', 1, function ($vars) {
    if (megabre_turnstile_is_enabled('enable_login')) {
        $token = isset($_POST['cf-turnstile-response']) ? trim((string) $_POST['cf-turnstile-response']) : '';
        if ($token === '' || !megabre_turnstile_verify($token)) {
            return "Captcha doğrulaması başarısız oldu. Lütfen tekrar deneyin.";
        }
    }
});

// Registration Validation
add_hook('ClientDetailsValidation', 1, function ($vars) {
    if (!isset($_SESSION['uid']) && megabre_turnstile_is_enabled('enable_register')) {
        if (!isset($_POST['cf-turnstile-response']) || !megabre_turnstile_verify($_POST['cf-turnstile-response'])) {
            return ["Captcha doğrulaması başarısız oldu."];
        }
    }
});

// Shopping Cart Validation
add_hook('ShoppingCartValidateCheckout', 1, function ($vars) {
    if (megabre_turnstile_is_enabled('enable_cart')) {
        if (!isset($_POST['cf-turnstile-response']) || !megabre_turnstile_verify($_POST['cf-turnstile-response'])) {
            return "Captcha doğrulaması başarısız oldu. Lütfen tekrar deneyin.";
        }
    }
});

// Ticket Validation
add_hook('TicketOpenValidation', 1, function ($vars) {
    if (megabre_turnstile_is_enabled('enable_ticket')) {
        if (!isset($_POST['cf-turnstile-response']) || !megabre_turnstile_verify($_POST['cf-turnstile-response'])) {
            return "Captcha doğrulaması başarısız oldu.";
        }
    }
});

// Contact Form Validation
add_hook('ClientAreaPageContact', 1, function ($vars) {
    if (megabre_turnstile_is_enabled('enable_contact') && $_SERVER['REQUEST_METHOD'] === 'POST') {
        if (!isset($_POST['action']) || $_POST['action'] !== 'send') return;

        if (!isset($_POST['cf-turnstile-response']) || !megabre_turnstile_verify($_POST['cf-turnstile-response'])) {
            header("Location: contact.php?error=captcha");
            exit;
        }
    }
});

