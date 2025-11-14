# Djebel Simple Newsletter Plugin

A lightweight and modern newsletter subscription plugin for Djebel applications. Collect email addresses with optional GDPR compliance and store them in organized CSV files.

## Features

- ✅ **Simple Email Collection** - Clean, modern subscription form
- ✅ **GDPR Compliance** - Optional checkbox for consent
- ✅ **CSV Storage** - Organized data storage with date-based file structure
- ✅ **Responsive Design** - Works perfectly on all devices
- ✅ **Customizable** - Flexible shortcode parameters
- ✅ **Hook System** - Extensible with Djebel hooks and filters
- ✅ **Validation** - Email validation and error handling
- ✅ **Modern UI** - Beautiful, accessible design
- ✅ **Spam Protection** - Honeypot fields to prevent automated spam

## Installation

1. Place the plugin folder in your `dj-app/dj-content/plugins/` directory
2. The plugin will automatically load when Djebel starts
3. CSS styles are automatically included when the shortcode is used
4. No additional configuration required

## Usage

### Basic Shortcode

```
[djebel-simple-newsletter]
```

### Advanced Shortcode with Parameters

```
[djebel-simple-newsletter title="Join Our Newsletter" cta_text="Get exclusive updates and early access to new features!" render_agree="1" auto_focus="1" agree_text="I agree to receive updates"]
```

## Shortcode Parameters

| Parameter | Type | Default | Description |
|-----------|------|---------|-------------|
| `title` | string | `""` | Optional title/heading displayed above form |
| `cta_text` | string | `""` | Call-to-action text displayed before form |
| `render_agree` | boolean | `0` | Show GDPR consent checkbox |
| `auto_focus` | boolean | `0` | Auto-focus the email input field |
| `agree_text` | string | `"I agree to be notified"` | Custom text for consent checkbox |

### Parameter Examples

**Simple form without consent:**
```
[djebel-simple-newsletter]
```

**Form with GDPR consent:**
```
[djebel-simple-newsletter render_agree="1"]
```

**Form with custom consent text:**
```
[djebel-simple-newsletter render_agree="1" agree_text="I agree to receive marketing emails"]
```

**Form with auto-focus:**
```
[djebel-simple-newsletter auto_focus="1"]
```

**Form with title and CTA text:**
```
[djebel-simple-newsletter title="Stay Updated" cta_text="Join our mailing list for the latest news and exclusive offers!"]
```

**Complete form with all options:**
```
[djebel-simple-newsletter title="Newsletter Signup" cta_text="Don't miss out on important updates!" render_agree="1" auto_focus="1" agree_text="I consent to receive email updates"]
```

## Data Storage

The plugin stores subscription data in CSV files with the following structure:

**File Location:** `dj-app/data/plugins/djebel-simple-newsletter/{YYYY}/{MM}/data_{YYYY}-{MM}-{DD}.csv`

**CSV Columns:**
- `email` - Subscriber's email address
- `creation_date` - Subscription timestamp
- `user_agent` - Browser/device information
- `ip` - Subscriber's IP address

### Example CSV Output:
```csv
email,creation_date,user_agent,ip
john@example.com,Mon, 15 Jan 2024 10:30:00 +0000,Mozilla/5.0...,192.168.1.1
jane@example.com,Mon, 15 Jan 2024 11:45:00 +0000,Mozilla/5.0...,192.168.1.2
```

## Customization

### CSS Classes

The plugin uses the following CSS classes for styling:

- `.djebel-simple-newsletter-title` - Newsletter title/heading
- `.djebel-simple-newsletter-cta` - Call-to-action text area
- `.djebel-simple-newsletter-form` - Main form container
- `.djebel-simple-newsletter-msg` - Message display area
- `.newsletter-input-group` - Email input and button container
- `.newsletter-email-input` - Email input field
- `.newsletter-submit-btn` - Subscribe button
- `.newsletter-agree-section` - Consent checkbox section
- `.newsletter-checkbox-label` - Checkbox label
- `.newsletter-checkbox` - Checkbox input
- `.newsletter-agree-text` - Consent text

### Hooks and Filters

#### Actions

**`app.plugin.simple_newsletter.validate_data`**
- Fired before saving subscription data
- Parameters: `$ctx['data']` - Array containing email and other data

**`app.plugin.simple_newsletter.form_start`**
- Fired at the beginning of the form
- Use for adding custom fields or content

**`app.plugin.simple_newsletter.form_end`**
- Fired at the end of the form
- Use for adding custom fields or content

#### Filters

**`app.plugin.simple_newsletter.data`**
- Modify subscription data before saving
- Parameters: `$data` - Array of subscription data
- Return: Modified data array

**`app.plugin.simple_newsletter.file`**
- Modify the CSV file path
- Parameters: `$file` - File path string
- Return: Modified file path

**`app.plugin.simple_newsletter.set_file`**
- Modify the file path when setting it
- Parameters: `$file` - File path string
- Return: Modified file path

### Example Customization

**Add custom validation:**
```php
function my_newsletter_validation($ctx) {
    $email = $ctx['data']['email'];
    
    // Custom validation logic
    if (strpos($email, '@company.com') !== false) {
        throw new Exception('Company emails are not allowed');
    }
}

Dj_App_Hooks::addAction('app.plugin.simple_newsletter.validate_data', 'my_newsletter_validation');
```

**Modify stored data:**
```php
function my_newsletter_data_modifier($data) {
    $data['source'] = 'website_form';
    $data['timestamp'] = time();
    return $data;
}

Dj_App_Hooks::addFilter('app.plugin.simple_newsletter.data', 'my_newsletter_data_modifier');
```

**Add custom form fields:**
```php
function my_newsletter_form_start() {
    echo '<div class="newsletter-custom-field">';
    echo '<input type="text" name="custom_field" placeholder="Additional info" />';
    echo '</div>';
}

Dj_App_Hooks::addAction('app.plugin.simple_newsletter.form_start', 'my_newsletter_form_start');
```

## Error Handling

The plugin includes comprehensive error handling:

- **Empty email** - "Please enter your email"
- **Invalid email** - "Invalid email"
- **Missing consent** - "Please agree to be notified" (when required)
- **File write errors** - "Failed to subscribe. Please try again later"

## Security Features

- **Email validation** - Proper email format verification
- **CSV injection protection** - Safe CSV writing with proper escaping
- **File locking** - Prevents concurrent write conflicts
- **Input sanitization** - All user inputs are properly sanitized
- **Honeypot spam protection** - Hidden fields to detect and block automated spam bots

## Browser Compatibility

- ✅ Chrome 60+
- ✅ Firefox 55+
- ✅ Safari 12+
- ✅ Edge 79+
- ✅ Mobile browsers

## Requirements

- **PHP:** 5.6 or higher
- **Djebel App:** 1.0.0 or higher
- **File permissions:** Write access to data directory

## Changelog

### Version 1.0.1
- ✨ Added `title` parameter for optional form heading
- ✨ Added `cta_text` parameter for call-to-action text before form
- 📝 Updated documentation with new parameter examples
- 🔧 Fixed plugin description and tags

### Version 1.0.0
- Initial release
- Basic email collection functionality
- GDPR compliance support
- CSV data storage
- Responsive design
- Hook system integration

## Support

For support, feature requests, or bug reports, please contact the development team.

## License

This plugin is licensed under GPL v2 or later.

---

**Author:** Svetoslav Marinov (Slavi)  
**Company:** Orbisius  
**Website:** https://orbisius.com
