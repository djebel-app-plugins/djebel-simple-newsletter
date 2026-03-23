<?php
/*
plugin_name: Djebel Simple Newsletter
plugin_uri: https://djebel.com/plugins/djebel-simple-newsletter
description: Simple newsletter subscription form with double opt-in, honeypot spam protection. Supports title, CTA content, and GDPR compliance.
version: 1.0.1
load_priority:20
tags: newsletter, email, marketing, subscription, double-opt-in
stable_version: 1.0.1
min_php_ver: 5.6
min_dj_app_ver: 1.0.0
tested_with_dj_app_ver: 1.0.0
author_name: Svetoslav Marinov (Slavi)
company_name: Orbisius
author_uri: https://orbisius.com
text_domain: djebel-simple-newsletter
license: gpl2
*/


/**
 * Custom exception for honeypot detection - silently handled
 */
class Djebel_Plugin_Simple_Newsletter_Honeypot_Exception extends \Exception
{
    // This exception is intentionally silent - no message needed
}

$obj = Djebel_Plugin_Simple_Newsletter::getInstance();

class Djebel_Plugin_Simple_Newsletter
{
    private $file = '';
    private $auth_salt = '';

    public function __construct()
    {
        $file = Dj_App_Util::getCorePrivateDataDir() . '/plugins/djebel-simple-newsletter/{YYYY}/{MM}/data_{YYYY}-{MM}-{DD}.csv';

        $replace_str = [
            '{YYYY}' => date('Y'),
            '{MM}' => date('m'),
            '{DD}' => date('d'),
        ];

        $file = str_ireplace(array_keys($replace_str), array_values($replace_str), $file);
        $this->setFile($file);

        // Cache auth salt for code generation
        $salt_params = [
            'prefix' => 'dj_snl_',
            'context' => __FILE__,
        ];

        $this->auth_salt = Dj_App_String_Util::generateAuthSalt($salt_params);

        $shortcode_obj = Dj_App_Shortcode::getInstance();
        $shortcode_obj->addShortcode('djebel-simple-newsletter', [ $this, 'renderNewsletterForm', ] );

        Dj_App_Hooks::addAction('app.core.init', [ $this, 'handleAjax', ]);
    }

    /**
     * Route AJAX requests to the appropriate handler
     * @return void
     */
    public function handleAjax()
    {
        $req_obj = Dj_App_Request::getInstance();
        $action = $req_obj->get('simple_newsletter_action');

        if (empty($action)) {
            return;
        }

        if (!$req_obj->isPost('simple_newsletter_action')) {
            return;
        }

        if ($action === 'join') {
            $this->ajaxJoin();
        } elseif ($action === 'verify') {
            $this->ajaxVerify();
        }
    }

    /**
     * AJAX handler: user submits email, generate code and send verification email
     * @return void
     */
    private function ajaxJoin()
    {
        $req_obj = Dj_App_Request::getInstance();
        $response = [
            'status' => false,
            'msg' => 'An error occurred. Please try again.',
        ];

        try {
            // Honeypot check
            $honeypot_website = $req_obj->get('djebel_simple_newsletter_website');
            $honeypot_phone = $req_obj->get('djebel_simple_newsletter_phone');

            if (!empty($honeypot_website) || !empty($honeypot_phone)) {
                throw new Djebel_Plugin_Simple_Newsletter_Honeypot_Exception();
            }

            $email = $req_obj->get('simple_newsletter_email');

            if (empty($email)) {
                throw new Dj_App_Exception('Please enter your email');
            }

            $is_valid_email = filter_var($email, FILTER_VALIDATE_EMAIL);

            if (!$is_valid_email) {
                throw new Dj_App_Exception('Invalid email');
            }

            // Server-side GDPR check (client-side required attr is not enough)
            $render_agree = $req_obj->get('simple_newsletter_gdpr_required');

            if (!empty($render_agree)) {
                $gdpr_accept = $req_obj->get('simple_newsletter_gdpr_accept');

                if (empty($gdpr_accept)) {
                    throw new Dj_App_Exception('Please accept the terms to continue');
                }
            }

            // Allow custom validation via hook
            $ctx = [];
            $ctx['data'] = [ 'email' => $email, ];
            Dj_App_Hooks::doAction('app.plugin.simple_newsletter.validate_data', $ctx);

            // Generate deterministic auth code
            $code_params = [
                'email' => $email,
                'salt' => $this->auth_salt,
                'action' => 'newsletter_subscribe',
            ];

            $code = Dj_App_String_Util::generateAuthCode($code_params);

            if (empty($code)) {
                throw new Dj_App_Exception('An error occurred. Please try again.');
            }

            // Save to unconfirmed list
            $unconfirmed_params = [ 'email' => $email, ];
            $this->saveUnconfirmed($unconfirmed_params);

            // Format and send verification email
            $format_params = [ 'code' => $code, ];
            $code_formatted = Dj_App_String_Util::formatAuthCode($format_params);

            $send_params = [
                'email' => $email,
                'code_formatted' => $code_formatted,
            ];

            $this->sendVerificationEmail($send_params);

            // Always show generic message (prevent email enumeration)
            $response['status'] = true;
            $response['msg'] = 'Check your email and enter the verification code below.';

        } catch (Djebel_Plugin_Simple_Newsletter_Honeypot_Exception $e) {
            // Fake success for bots
            $response['status'] = true;
            $response['msg'] = 'Done';

        } catch (Exception $e) {
            $response['msg'] = $e->getMessage();
        }

        $req_obj->json($response);
    }

    /**
     * AJAX handler: user submits code, verify and save to confirmed list
     * @return void
     */
    private function ajaxVerify()
    {
        $req_obj = Dj_App_Request::getInstance();
        $response = [
            'status' => false,
            'msg' => 'An error occurred. Please try again.',
        ];

        try {
            // Honeypot check
            $honeypot_website = $req_obj->get('djebel_simple_newsletter_website');
            $honeypot_phone = $req_obj->get('djebel_simple_newsletter_phone');

            if (!empty($honeypot_website) || !empty($honeypot_phone)) {
                throw new Djebel_Plugin_Simple_Newsletter_Honeypot_Exception();
            }

            $email = $req_obj->get('simple_newsletter_email');
            $code = $req_obj->get('simple_newsletter_code');

            if (empty($email) || empty($code)) {
                throw new Dj_App_Exception('Invalid or expired code. Please try again.');
            }

            // Fire before verify action
            $ctx = [
                'email' => $email,
                'code' => $code,
            ];

            Dj_App_Hooks::doAction('app.plugin.simple_newsletter.before_verify', $ctx);

            // Verify the code
            $verify_params = [
                'email' => $email,
                'code' => $code,
                'salt' => $this->auth_salt,
                'action' => 'newsletter_subscribe',
            ];

            $is_valid = Dj_App_String_Util::verifyAuthCode($verify_params);

            if (!$is_valid) {
                throw new Dj_App_Exception('Invalid or expired code. Please try again.');
            }

            // Code is valid - save to confirmed CSV
            $subscriber_params = [ 'email' => $email, ];
            $data = $this->buildSubscriberData($subscriber_params);

            $ctx = [ 'email' => $email, ];
            $data = Dj_App_Hooks::applyFilter('app.plugin.simple_newsletter.data', $data, $ctx);

            $file = $this->getFile();
            $res = $this->writeCsv($file, $data);

            if ($res->isError()) {
                throw new Dj_App_Exception('Failed to subscribe. Please try again later');
            }

            // Fire after verify action
            $after_ctx = [
                'email' => $email,
            ];

            Dj_App_Hooks::doAction('app.plugin.simple_newsletter.after_verify', $after_ctx);

            $response['status'] = true;
            $response['msg'] = 'Done';

        } catch (Djebel_Plugin_Simple_Newsletter_Honeypot_Exception $e) {
            // Fake success for bots
            $response['status'] = true;
            $response['msg'] = 'Done';

        } catch (Exception $e) {
            $response['msg'] = $e->getMessage();
        }

        $req_obj->json($response);
    }

    /**
     * Build subscriber data array (email, date, user agent, IP)
     * @param array $params Must contain 'email'
     * @return array
     */
    private function buildSubscriberData($params = [])
    {
        $email = empty($params['email']) ? '' : $params['email'];
        $req_obj = Dj_App_Request::getInstance();
        $ip = empty($_SERVER['REMOTE_ADDR']) ? '' : $_SERVER['REMOTE_ADDR'];

        $data = [];
        $data['email'] = $email;
        $data['creation_date'] = date('r');
        $data['user_agent'] = $req_obj->getUserAgent();
        $data['ip'] = $ip;

        return $data;
    }

    /**
     * Get path to unconfirmed subscribers CSV (single rolling file)
     * @return string
     */
    public function getUnconfirmedFile()
    {
        $dir = Dj_App_Util::getCorePrivateDataDir() . '/plugins/djebel-simple-newsletter';
        $file = $dir . '/unconfirmed.csv';
        $file = Dj_App_Hooks::applyFilter('app.plugin.simple_newsletter.unconfirmed_file', $file);

        return $file;
    }

    /**
     * Save email to unconfirmed list
     * @param array $params Must contain 'email'
     * @return Dj_App_Result
     */
    public function saveUnconfirmed($params = [])
    {
        $email = empty($params['email']) ? '' : $params['email'];

        if (empty($email)) {
            $res_obj = new Dj_App_Result();
            $res_obj->msg = 'Email is required';

            return $res_obj;
        }

        $data = $this->buildSubscriberData($params);

        $file = $this->getUnconfirmedFile();
        $res_obj = $this->writeCsv($file, $data);

        return $res_obj;
    }

    /**
     * Send verification code email via djebel-mailer
     * @param array $params Must contain 'email' and 'code_formatted'
     * @return Dj_App_Result|string
     */
    public function sendVerificationEmail($params = [])
    {
        $email = empty($params['email']) ? '' : $params['email'];
        $code_formatted = empty($params['code_formatted']) ? '' : $params['code_formatted'];

        $subject = 'Your verification code';
        $body = "Your verification code is: " . $code_formatted . "\n\n";
        $body .= "This code expires in 24 hours.\n\n";
        $body .= "If you did not request this subscription, you can ignore this email.";

        // Allow customization via filter
        $email_data = [
            'subject' => $subject,
            'body' => $body,
            'email' => $email,
            'code_formatted' => $code_formatted,
        ];

        $ctx = [];
        $email_data = Dj_App_Hooks::applyFilter('app.plugin.simple_newsletter.verification_email', $email_data, $ctx);

        $mail_params = [
            'to' => $email,
            'subject' => $email_data['subject'],
            'body' => $email_data['body'],
            'is_html' => false,
        ];

        $result = Dj_App_Hooks::applyFilter('app.plugins.mailer.send_email', '', $mail_params);

        return $result;
    }

    /**
     * Render the two-step newsletter form (email step + code verification step)
     * Uses AJAX for smooth step transitions without page reload.
     * @return void
     */
    public function renderNewsletterForm($params = [])
    {
        $title = empty($params['title']) ? '' : $params['title'];
        $title = Dj_App_String_Util::trim($title);

        $cta_text = empty($params['cta_text']) ? '' : $params['cta_text'];
        $cta_text = Dj_App_String_Util::trim($cta_text);

        $render_agree = empty($params['render_agree']) ? 0 : 1;
        $auto_focus = empty($params['auto_focus']) ? 0 : 1;

        $agree_text = '';

        if ($render_agree) {
            $agree_text = empty($params['agree_text']) ? "I agree to be notified" : $params['agree_text'];
            $agree_text = Djebel_App_HTML::encodeEntities($agree_text);
        }

        // Unique ID for multiple forms on the same page
        $rand = mt_rand(1000, 9999);
        $form_id = 'djebel-snl-' . $rand;
        ?>
        <style>
        /* Newsletter Plugin Styles */
        .djebel-simple-newsletter-optional-field {
            position: absolute;
            left: -9999px;
            top: -9999px;
            width: 1px;
            height: 1px;
            overflow: hidden;
            opacity: 0;
            pointer-events: none;
        }
        .djebel-simple-newsletter-optional-field input {
            position: absolute;
            left: -9999px;
            top: -9999px;
            width: 1px;
            height: 1px;
            border: none;
            background: transparent;
            color: transparent;
        }
        .djebel-snl-code-input {
            max-width: 140px;
            text-align: center;
            letter-spacing: 3px;
            font-size: 1.1em;
        }
        </style>

        <div id="<?php echo $form_id; ?>" class="djebel-simple-newsletter-wrapper">

            <?php if (!empty($title)) { ?>
                <h3 class="djebel-simple-newsletter-title"><?php echo Djebel_App_HTML::encodeEntities($title); ?></h3>
            <?php } ?>

            <div class="djebel-simple-newsletter-msg"></div>

            <!-- Step 1: Email form -->
            <form class="djebel-snl-email-step djebel-simple-newsletter-form" method="post" action="">
                <?php if (!empty($cta_text)) { ?>
                    <div class="djebel-simple-newsletter-cta"><?php echo Djebel_App_HTML::encodeEntities($cta_text); ?></div>
                <?php } ?>

                <?php Dj_App_Hooks::doAction('app.plugin.simple_newsletter.form_start'); ?>

                <input type="hidden"
                       name="djebel_simple_newsletter_website"
                       value=""
                       tabindex="-1"
                       autocomplete="off" />

                <div class="djebel-simple-newsletter-optional-field">
                    <input type="text"
                           name="djebel_simple_newsletter_phone"
                           value=""
                           tabindex="-1"
                           autocomplete="off"
                           placeholder="Phone number (optional)" />
                </div>

                <div class="newsletter-input-group">
                    <input type="email"
                           name="simple_newsletter_email"
                           value=""
                           <?php echo $auto_focus ? 'autofocus="autofocus"' : ''; ?>
                           placeholder="Enter your email address"
                           required="required"
                           class="newsletter-email-input" />

                    <button type="submit"
                            class="newsletter-submit-btn djebel-snl-join-btn">
                        Subscribe
                    </button>
                </div>

                <?php if ($render_agree) { ?>
                    <input type="hidden" name="simple_newsletter_gdpr_required" value="1" />
                    <div class="newsletter-agree-section">
                        <label class="newsletter-checkbox-label">
                            <input type="checkbox"
                                   name="simple_newsletter_gdpr_accept"
                                   value="1"
                                   required="required"
                                   class="newsletter-checkbox" />
                            <span class="newsletter-agree-text"><?php echo $agree_text; ?></span>
                        </label>
                    </div>
                <?php } ?>

                <?php Dj_App_Hooks::doAction('app.plugin.simple_newsletter.form_end'); ?>
            </form>

            <!-- Step 2: Code verification form (hidden by default) -->
            <form class="djebel-snl-code-step djebel-simple-newsletter-form" style="display:none" method="post" action="">
                <?php Dj_App_Hooks::doAction('app.plugin.simple_newsletter.verify_form_start'); ?>

                <input type="hidden"
                       name="djebel_simple_newsletter_website"
                       value=""
                       tabindex="-1"
                       autocomplete="off" />

                <div class="djebel-simple-newsletter-optional-field">
                    <input type="text"
                           name="djebel_simple_newsletter_phone"
                           value=""
                           tabindex="-1"
                           autocomplete="off"
                           placeholder="Phone number (optional)" />
                </div>

                <input type="hidden" name="simple_newsletter_email" value="" class="djebel-snl-hidden-email" />

                <div class="newsletter-input-group">
                    <input type="text"
                           name="simple_newsletter_code"
                           value=""
                           placeholder="Enter code"
                           required="required"
                           maxlength="10"
                           autocomplete="off"
                           class="newsletter-email-input djebel-snl-code-input" />

                    <button type="submit"
                            class="newsletter-submit-btn djebel-snl-verify-btn">
                        Verify
                    </button>
                </div>

                <?php Dj_App_Hooks::doAction('app.plugin.simple_newsletter.verify_form_end'); ?>
            </form>
        </div>

        <script>
        (function() {
            var wrapperId = '<?php echo $form_id; ?>';
            var wrapper = document.getElementById(wrapperId);

            if (!wrapper) {
                return;
            }

            var emailForm = wrapper.querySelector('.djebel-snl-email-step');
            var codeForm = wrapper.querySelector('.djebel-snl-code-step');
            var msgDiv = wrapper.querySelector('.djebel-simple-newsletter-msg');

            function showMsg(text, isSuccess) {
                var cls = isSuccess ? 'app_msg app_success' : 'app_msg app_error';
                var div = document.createElement('div');
                div.className = cls;
                div.textContent = text;
                msgDiv.innerHTML = '';
                msgDiv.appendChild(div);
            }

            function clearMsg() {
                msgDiv.innerHTML = '';
            }

            function setButtonState(btn, disabled, text) {
                btn.disabled = disabled;

                if (text) {
                    btn.textContent = text;
                }
            }

            // Step 1: Email submit
            emailForm.addEventListener('submit', function(e) {
                e.preventDefault();
                clearMsg();

                var emailInput = emailForm.querySelector('input[name="simple_newsletter_email"]');
                var email = emailInput.value;

                if (!email) {
                    showMsg('Please enter your email', false);
                    return;
                }

                var joinBtn = emailForm.querySelector('.djebel-snl-join-btn');
                var origText = joinBtn.textContent;
                setButtonState(joinBtn, true, 'Please wait...');

                var formData = new FormData(emailForm);
                formData.append('simple_newsletter_action', 'join');

                var xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                xhr.onload = function() {
                    var resp = {};

                    try {
                        resp = JSON.parse(xhr.responseText);
                    } catch (ex) {
                        showMsg('An error occurred. Please try again.', false);
                        setButtonState(joinBtn, false, origText);
                        return;
                    }

                    if (resp.status) {
                        // Success: show code form
                        showMsg(resp.msg, true);
                        emailForm.style.display = 'none';
                        codeForm.style.display = '';

                        // Pass email to code form
                        var hiddenEmail = codeForm.querySelector('.djebel-snl-hidden-email');
                        hiddenEmail.value = email;

                        // Focus code input
                        var codeInput = codeForm.querySelector('.djebel-snl-code-input');
                        codeInput.focus();
                    } else {
                        showMsg(resp.msg, false);
                        setButtonState(joinBtn, false, origText);
                    }
                };

                xhr.onerror = function() {
                    showMsg('An error occurred. Please try again.', false);
                    setButtonState(joinBtn, false, origText);
                };

                xhr.send(formData);
            });

            // Step 2: Code verify
            codeForm.addEventListener('submit', function(e) {
                e.preventDefault();
                clearMsg();

                var codeInput = codeForm.querySelector('.djebel-snl-code-input');
                var code = codeInput.value;

                if (!code) {
                    showMsg('Please enter the verification code', false);
                    return;
                }

                var verifyBtn = codeForm.querySelector('.djebel-snl-verify-btn');
                var origText = verifyBtn.textContent;
                setButtonState(verifyBtn, true, 'Please wait...');

                var formData = new FormData(codeForm);
                formData.append('simple_newsletter_action', 'verify');

                var xhr = new XMLHttpRequest();
                xhr.open('POST', window.location.href, true);
                xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');

                xhr.onload = function() {
                    var resp = {};

                    try {
                        resp = JSON.parse(xhr.responseText);
                    } catch (ex) {
                        showMsg('An error occurred. Please try again.', false);
                        setButtonState(verifyBtn, false, origText);
                        return;
                    }

                    if (resp.status) {
                        // Success: hide form, show success
                        codeForm.style.display = 'none';
                        showMsg(resp.msg, true);
                    } else {
                        showMsg(resp.msg, false);
                        setButtonState(verifyBtn, false, origText);
                        codeInput.value = '';
                        codeInput.focus();
                    }
                };

                xhr.onerror = function() {
                    showMsg('An error occurred. Please try again.', false);
                    setButtonState(verifyBtn, false, origText);
                };

                xhr.send(formData);
            });
        })();
        </script>
        <?php
    }

    public function getFile(): string
    {
        $file = $this->file;
        $file = Dj_App_Hooks::applyFilter( 'app.plugin.simple_newsletter.file', $file );

        return $file;
    }

    public function setFile(string $file): void
    {
        $file = Dj_App_Hooks::applyFilter( 'app.plugin.simple_newsletter.set_file', $file );
        $this->file = $file;
    }

    /**
     * @param string $file
     * @param array $data
     * @return Dj_App_Result
     */
    public function writeCsv($file, $data = []) {
        $res_obj = new Dj_App_Result();
        $fp = null;

        try {
            Dj_App_Util::microtime( __METHOD__ );
            $dir = dirname($file);

            $res = Dj_App_File_Util::mkdir($dir);

            if (empty($res)) {
                throw new Dj_App_Exception('Failed to create directory ' . $dir);
            }

            $fp = fopen($file, 'ab');

            if (empty($fp)) {
                throw new Dj_App_File_Util_Exception("Couldn't create file", ['dir' => $dir]);
            }

            $fl_res = flock($fp, LOCK_EX);

            if (!$fl_res) {
                throw new Dj_App_File_Util_Exception("Couldn't lock file", ['file' => $file]);
            }

            $file_size = filesize($file);

            // new file so it needs a header
            if ($file_size < 100) {
                $header_cols = array_keys($data); // this is a row
                $header_cols = array_map('Dj_App_String_Util::formatStringId', $header_cols);
                $csv_res = fputcsv($fp, $header_cols, ",", '"', '\\');
            }

            // use csv; keep php 8.x happy and without warnings.
            $csv_res = fputcsv($fp, $data, ",", '"', '\\');

            if (empty($csv_res)) {
                throw new Dj_App_File_Util_Exception("Couldn't write to file", ['file' => $file]);
            }

            $res_obj->status(1);
        } catch (Exception $e) {
            $res_obj->msg = $e->getMessage();
        } finally {
            if (!empty($fp)) {
                flock($fp, LOCK_UN);
                fclose($fp);
            }

            $res_obj->exec_time = Dj_App_Util::microtime( __METHOD__ );
        }

        return $res_obj;
    }

    /**
     * @return static
     */
    public static function getInstance()
    {
        static $instance = null;

        if (is_null($instance)) {
            $instance = new static();
        }

        return $instance;
    }
}
