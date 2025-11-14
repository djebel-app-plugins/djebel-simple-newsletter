<?php
/*
plugin_name: Djebel Simple Newsletter
plugin_uri: https://djebel.com/plugins/djebel-simple-newsletter
description: Simple newsletter subscription form with honeypot spam protection. Supports title, CTA content, and GDPR compliance.
version: 1.0.0
load_priority:20
tags: newsletter, email, marketing, subscription
stable_version: 1.0.0
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

$obj = new Djebel_Plugin_Simple_Newsletter();

class Djebel_Plugin_Simple_Newsletter
{
    private $file = '';
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

        $shortcode_obj = Dj_App_Shortcode::getInstance();
        $shortcode_obj->addShortcode('djebel-simple-newsletter', [ $this, 'renderNewsletterForm' ] );

//        Dj_App_Hooks::addAction( 'app.page.body.start', [ $obj, 'renderNewsletterForm' ] );
    }

    /**
     * @todo send a code that once entered will confirm the subscription
     * @return void
     * @throws Dj_App_Exception|Djebel_Plugin_Simple_Newsletter_Honeypot_Exception
     */
    public function renderNewsletterForm($params = [])
    {
        $msg = '';
        $req_obj = Dj_App_Request::getInstance();
        $email = $req_obj->get('simple_newsletter_email');
        $email_enc = $req_obj->encode($email);

        $title = empty($params['title']) ? '' : trim($params['title']);
        $cta_text = empty($params['cta_text']) ? '' : trim($params['cta_text']);
        $render_agree = empty($params['render_agree']) ? 0 : 1;
        $auto_focus = !empty($params['auto_focus']) ? 1 : 0;

        $agree_text = '';

        if ($render_agree) {
            $agree_text = empty($params['agree_text']) ? "I agree to be notified" : $params['agree_text'];
            $agree_text = Djebel_App_HTML::encodeEntities($agree_text);
        }

        if ($req_obj->isPost('simple_newsletter_email')) {
            try {
                // Honeypot spam detection
                $honeypot_website = $req_obj->get('djebel_simple_newsletter_website');
                $honeypot_phone = $req_obj->get('djebel_simple_newsletter_phone');
                
                // If either honeypot field is filled, it's spam - throw ignorable exception
                if (!empty($honeypot_website) || !empty($honeypot_phone)) {
                    throw new Djebel_Plugin_Simple_Newsletter_Honeypot_Exception();
                }

                // Normal validation for legitimate submissions
                if (empty($email)) {
                    throw new Dj_App_Exception('Please enter your email');
                } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new Dj_App_Exception('Invalid email');
                }

                $simple_newsletter_gdpr_accept = $req_obj->simple_newsletter_gdpr_accept;

                if ($render_agree && empty($simple_newsletter_gdpr_accept)) {
                    throw new Dj_App_Exception('Please agree to be notified');
                }

                $data = [
                    'email' => $email,
                ];

                $ctx = [];
                $ctx['data'] = $data;
                Dj_App_Hooks::doAction( 'app.plugin.simple_newsletter.validate_data', $ctx );

                $data = [];
                $data['email'] = $email;
                $data['creation_date'] = date('r');
                $data['user_agent'] = $req_obj->getUserAgent();
                $data['ip'] = empty($_SERVER['REMOTE_ADDR']) ? '' : $_SERVER['REMOTE_ADDR'];

                $data = Dj_App_Hooks::applyFilter( 'app.plugin.simple_newsletter.data', $data );

                // save this in csv using php csv
                $file = $this->getFile();
                $res = $this->writeCsv($file, $data);

                if ($res->isError()) {
                    throw new Dj_App_Exception('Failed to subscribe. Please try again later');
                }

                $email_enc = ''; // no need to show it again
                $msg = 'Done';
                $msg = Dj_App_Util::msg($msg, Dj_App_Util::MSG_SUCCESS);
            } catch (Djebel_Plugin_Simple_Newsletter_Honeypot_Exception $e) {
                // Honeypot detected - return fake success without saving data
                $email_enc = '';
                $msg = 'Done';
                $msg = Dj_App_Util::msg($msg, Dj_App_Util::MSG_SUCCESS);
            } catch (Exception $e) {
                $msg = $e->getMessage();
                $msg = Dj_App_Util::msg($msg);
            }
        }
        ?>
        <style>
        /* Newsletter Plugin Optional Field Styles */
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
        </style>
        
        <?php if (!empty($title)) { ?>
            <h3 class="djebel-simple-newsletter-title"><?php echo Djebel_App_HTML::encodeEntities($title); ?></h3>
        <?php } ?>
        
        <div class="djebel-simple-newsletter-msg"><?php echo $msg; ?></div>

        <form id="djebel-simple-newsletter-form" class="djebel-simple-newsletter-form" method="post" action="">
            <?php if (!empty($cta_text)) { ?>
                <div class="djebel-simple-newsletter-cta"><?php echo Djebel_App_HTML::encodeEntities($cta_text); ?></div>
            <?php } ?>
            
            <?php Dj_App_Hooks::doAction( 'app.plugin.simple_newsletter.form_start' ); ?>
            
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
                       id="email" 
                       name="simple_newsletter_email" 
                       value="<?php echo $email_enc; ?>"
                       <?php echo $auto_focus ? 'autofocus="autofocus"' : ''; ?>
                       placeholder="Enter your email address" 
                       required="required" 
                       class="newsletter-email-input" />
                
                <button type="submit" 
                        name="simple_newsletter_submit" 
                        class="newsletter-submit-btn">
                    Subscribe
                </button>
            </div>

            <?php if ($render_agree) { ?>
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
            
            <?php Dj_App_Hooks::doAction( 'app.plugin.simple_newsletter.form_end' ); ?>
        </form>
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

}