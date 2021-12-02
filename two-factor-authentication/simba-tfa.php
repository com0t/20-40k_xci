<?php

if (!defined('ABSPATH')) die('Access denied.');

class Simba_Two_Factor_Authentication {
	
	protected $frontend;
	
	protected $totp_controller;
	
	/**
	 * Constructor, run upon plugin initiation
	 */
	public function __construct() {
		
		require_once(__DIR__.'/providers/totp-hotp/loader.php');
		
		$this->totp_controller = new Simba_TFA_Provider_TOTP($this);
		
		// Process login form AJAX events
		add_action('wp_ajax_nopriv_simbatfa-init-otp', array($this, 'tfaInitLogin'));
		add_action('wp_ajax_simbatfa-init-otp', array($this, 'tfaInitLogin'));
		
		add_action('wp_ajax_simbatfa_shared_ajax', array($this, 'shared_ajax'));
		
		require_once(SIMBA_TFA_PLUGIN_DIR.'/includes/login-form-integrations.php');
		new Simba_TFA_Login_Form_Integrations($this);
		
		add_action('admin_init', array($this, 'register_two_factor_auth_settings'));
		add_action('init', array($this, 'init'));
		
		if (!defined('TWO_FACTOR_DISABLE') || !TWO_FACTOR_DISABLE) {
			add_filter('authenticate', array($this, 'tfaVerifyCodeAndUser'), 99999999999, 3);
		}
		
		if (defined('DOING_AJAX') && DOING_AJAX && defined('WP_ADMIN') && WP_ADMIN && !empty($_REQUEST['action']) && 'simbatfa-init-otp' == $_REQUEST['action']) {
			// Try to prevent PHP notices breaking the AJAX conversation
			$this->output_buffering = true;
			$this->logged = array();
			set_error_handler(array($this, 'get_php_errors'), E_ALL & ~E_STRICT);
			ob_start();
		}
	}
	
	/**
	 * Include the user settings page code
	 */
	public function show_dashboard_user_settings_page() {
		$this->include_template('user-settings.php');
	}
	
	/**
	 * Runs upon the WP action admin_init
	 */
	public function register_two_factor_auth_settings() {
		global $wp_roles;
		if (!isset($wp_roles)) $wp_roles = new WP_Roles();
		
		foreach ($wp_roles->role_names as $id => $name) {
			register_setting('tfa_user_roles_group', 'tfa_'.$id);
			register_setting('tfa_user_roles_trusted_group', 'tfa_trusted_'.$id);
			register_setting('tfa_user_roles_required_group', 'tfa_required_'.$id);
		}
		
		if (is_multisite()) {
			register_setting('tfa_user_roles_group', 'tfa__super_admin');
			register_setting('tfa_user_roles_trusted_group', 'tfa_trusted__super_admin');
			register_setting('tfa_user_roles_required_group', 'tfa_required__super_admin');
		}
		
		register_setting('tfa_user_roles_required_group', 'tfa_requireafter');
		register_setting('tfa_user_roles_required_group', 'tfa_if_required_redirect_to');
		register_setting('tfa_user_roles_required_group', 'tfa_hide_turn_off');
		register_setting('tfa_user_roles_trusted_group', 'tfa_trusted_for');
		register_setting('simba_tfa_woocommerce_group', 'tfa_wc_add_section');
		register_setting('simba_tfa_woocommerce_group', 'tfa_bot_protection');
		register_setting('simba_tfa_default_hmac_group', 'tfa_default_hmac');
		register_setting('tfa_xmlrpc_status_group', 'tfa_xmlrpc_on');
	}
	
	/**
	 * See whether TFA is available or not for a particular user - i.e. whether the administrator has permitted it for their user level
	 *
	 * @param Integer $user_id - WordPress user ID
	 *
	 * @return Boolean
	 */
	public function is_activated_for_user($user_id) {
		
		if (empty($user_id)) return false;
		
		// Super admin is not a role (they are admins with an extra attribute); needs separate handling
		if (is_multisite() && is_super_admin($user_id)) {
			// This is always a final decision - we don't want it to drop through to the 'admin' role's setting
			$role = '_super_admin';
			$db_val = $this->get_option('tfa_'.$role);
			// Defaults to true if no setting has been saved
			return (false === $db_val || $db_val) ? true : false;
		}
		
		$roles = $this->get_user_roles($user_id);
		
		// N.B. This populates with roles on the current site within a multisite
		foreach ($roles as $role) {
			$db_val = $this->get_option('tfa_'.$role);
			if (false === $db_val || $db_val) return true;
		}
		
		return false;
		
	}
	
	/**
	 * Get all user roles for a given user (if on multisite, amalgamates all roles from all sites)
	 *
	 * @param Integer $user_id - WordPress user ID
	 *
	 * @return Array
	 */
	protected function get_user_roles($user_id) {
		
		// Get roles on the main site
		$user = new WP_User($user_id);
		$roles = (array) $user->roles;
		
		// On multisite, also check roles on non-main sites
		if (is_multisite()) {
			global $wpdb, $table_prefix;
			$roles_db = $wpdb->get_results($wpdb->prepare("SELECT meta_key, meta_value FROM {$wpdb->usermeta} WHERE user_id=%d AND meta_key LIKE '".esc_sql($table_prefix)."%_capabilities'", $user_id));
			if (is_array($roles_db)) {
				foreach ($roles_db as $role_info) {
					if (empty($role_info->meta_key) || !preg_match('/^'.$table_prefix.'\d+_capabilities$/', $role_info->meta_key) || empty($role_info->meta_value) || !preg_match('/^a:/', $role_info->meta_value)) continue;
					$site_roles = unserialize($role_info->meta_value);
					if (!is_array($site_roles)) continue;
					foreach ($site_roles as $role => $active) {
						if ($active && !in_array($role, $roles)) $roles[] = $role;
					}
				}
			}
		}
		
		return $roles;
	}
	
	/**
	 * Check if TFA is required for a specified user
	 *
	 * N.B. - This doesn't check is_activated_for_user() - the caller would normally want to do that first
	 *
	 * @param $user_id Integer - the WP user ID
	 *
	 * @return Boolean
	 */
	public function is_required_for_user($user_id) {
		return apply_filters('simba_tfa_required_for_user', $this->user_property_active($user_id, 'required_'), $user_id);
	}
	
	/**
	 * See if a particular user property is active
	 *
	 * @param Integer $user_id
	 * @param String  $prefix - e.g. "required_", "trusted_"
	 *
	 * @return Boolean
	 */
	public function user_property_active($user_id, $prefix = 'required_') {
		
		if (empty($user_id)) return false;
		
		// Super admin is not a role (they are admins with an extra attribute); needs separate handling
		if (is_multisite() && is_super_admin($user_id)) {
			// This is always a final decision - we don't want it to drop through to the 'admin' role's setting
			$role = '_super_admin';
			$db_val = $this->get_option('tfa_'.$prefix.$role);
			return $db_val ? true : false;
		}
		
		$roles = $this->get_user_roles($user_id);
		
		foreach ($roles as $role) {
			$db_val = $this->get_option('tfa_'.$prefix.$role);
			if ($db_val) return true;
		}
		
		return false;
		
	}
	
	/**
	 * Whether TFA is activated by a specific user. Note that this doesn't check if TFA is enabled for the user's role; the caller should check that first.
	 *
	 * @param Integer $user_id
	 *
	 * @return Boolean
	 */
	public function is_activated_by_user($user_id) {
		$enabled = get_user_meta($user_id, 'tfa_enable_tfa', true);
		return !empty($enabled);
	}
	
	/**
	 * Get a list of trusted devices for the user
	 *
	 * @param Integer|Boolean $user_id - WordPress user ID, or false for the current user
	 *
	 * @return Array
	 */
	public function user_get_trusted_devices($user_id = false) {
		
		if (false === $user_id) {
			global $current_user;
			$user_id = $current_user->ID;
		}
		
		$trusted_devices = get_user_meta($user_id, 'tfa_trusted_devices', true);
		
		if (!is_array($trusted_devices)) $trusted_devices = array();
		
		return $trusted_devices;
	}
	
	/**
	 * Trust the current device
	 *
	 * @param Integer $user_id - WordPress user ID
	 * @param Integer $trusted_for - time to trust for, in days
	 */
	public function trust_device($user_id, $trusted_for) {
		
		$trusted_devices = $this->user_get_trusted_devices($user_id);
		
		$time_now = time();
		
		foreach ($trusted_devices as $k => $device) {
			if (empty($device['until']) || $device['until'] <= $time_now) unset($trusted_devices[$k]);
		}
		
		$until = $time_now + $trusted_for * 86400;
		
		$token = bin2hex($this->random_bytes(40));
		
		$trusted_devices[] = array(
			'ip' => $_SERVER['REMOTE_ADDR'],
			'until' => $until,
			'user_agent' => empty($_SERVER['HTTP_USER_AGENT']) ? '' : (string) $_SERVER['HTTP_USER_AGENT'],
								   'token' => $token
		);
		
		$this->user_set_trusted_devices($user_id, $trusted_devices);
		
		$this->set_cookie('simbatfa_trust_token', $token, $until);
	}
	
	/**
	 * Returns true if running on a PHP version on which mcrypt has been deprecated
	 *
	 * @return Boolean
	 */
	public function is_mcrypt_deprecated() {
		return (7 == PHP_MAJOR_VERSION && PHP_MINOR_VERSION >= 1);
	}
	
	/**
	 * Return the specified number of bytes
	 *
	 * @param Integer $bytes
	 *
	 * @throws Exception
	 *
	 * @return String
	 */
	public function random_bytes($bytes) {
		if (function_exists('random_bytes')) {
			return random_bytes($bytes);
		} elseif (function_exists('mcrypt_create_iv')) {
			return $this->is_mcrypt_deprecated() ? @mcrypt_create_iv($bytes, MCRYPT_RAND) : mcrypt_create_iv($bytes, MCRYPT_RAND);
		} elseif (function_exists('openssl_random_pseudo_bytes')) {
			return openssl_random_pseudo_bytes($bytes);
		}
		throw new Exception('One of the mcrypt or openssl PHP modules needs to be installed');
	}
	
	/**
	 * Set a cookie so that, however we logged in, it can be found
	 *
	 * @param String  $name	   - the cookie name
	 * @param String  $value   - the cookie value
	 * @param Integer $expires - when the cookie expires, in epoch time. Defaults to 24 hours' time. Values in the past cause cookie deletion.
	 */
	protected function set_cookie($name, $value, $expires = null) {
		if (null === $expires) $expires = time() + 86400;
		$secure = is_ssl();
		$secure_logged_in_cookie = ($secure && 'https' === parse_url(get_option('home'), PHP_URL_SCHEME));
		$secure = apply_filters('secure_auth_cookie', $secure, get_current_user_id());
		$secure_logged_in_cookie = apply_filters('secure_logged_in_cookie', $secure_logged_in_cookie, get_current_user_id(), $secure);
		
		setcookie($name, $value, $expires, ADMIN_COOKIE_PATH, COOKIE_DOMAIN, $secure, true);
		setcookie($name, $value, $expires, COOKIEPATH, COOKIE_DOMAIN, $secure_logged_in_cookie, true);
		if (COOKIEPATH != SITECOOKIEPATH) {
			setcookie($name, $value, $expires, SITECOOKIEPATH, COOKIE_DOMAIN, $secure_logged_in_cookie, true);
		}
	}
	
	/**
	 * Get a list of trusted devices for the user
	 *
	 * @param Integer $user_id - WordPress user ID
	 * @param Array	  $trusted_devices - the list of devices
	 */
	public function user_set_trusted_devices($user_id, $trusted_devices) {
		update_user_meta($user_id, 'tfa_trusted_devices', $trusted_devices);
	}
	
	/**
	 * Get the user capability needed for managing TFA users.
	 * You'll want to think carefully about changing this to a non-admin, as it can give the ability to lock admins out (though, if you have FTP/files access, you can always disable TFA or any plugin)
	 *
	 * @return String
	 */
	public function get_management_capability() {
		return apply_filters('simba_tfa_management_capability', 'manage_options');
	}
	
	/**
	 * Used with set_error_handler()
	 *
	 * @param Integer $errno
	 * @param String  $errstr
	 * @param String  $errfile
	 * @param Integer $errline
	 *
	 * @return Boolean
	 */
	public function get_php_errors($errno, $errstr, $errfile, $errline) {
		if (0 == error_reporting()) return true;
		$logline = $this->php_error_to_logline($errno, $errstr, $errfile, $errline);
		$this->logged[] = $logline;
		# Don't pass it up the chain (since it's going to be output to the user always)
		return true;
	}
	
	public function php_error_to_logline($errno, $errstr, $errfile, $errline) {
		switch ($errno) {
			case 1:		$e_type = 'E_ERROR'; break;
			case 2:		$e_type = 'E_WARNING'; break;
			case 4:		$e_type = 'E_PARSE'; break;
			case 8:		$e_type = 'E_NOTICE'; break;
			case 16:	$e_type = 'E_CORE_ERROR'; break;
			case 32:	$e_type = 'E_CORE_WARNING'; break;
			case 64:	$e_type = 'E_COMPILE_ERROR'; break;
			case 128:	$e_type = 'E_COMPILE_WARNING'; break;
			case 256:	$e_type = 'E_USER_ERROR'; break;
			case 512:	$e_type = 'E_USER_WARNING'; break;
			case 1024:	$e_type = 'E_USER_NOTICE'; break;
			case 2048:	$e_type = 'E_STRICT'; break;
			case 4096:	$e_type = 'E_RECOVERABLE_ERROR'; break;
			case 8192:	$e_type = 'E_DEPRECATED'; break;
			case 16384:	$e_type = 'E_USER_DEPRECATED'; break;
			case 30719:	$e_type = 'E_ALL'; break;
			default:	$e_type = "E_UNKNOWN ($errno)"; break;
		}
		
		if (!is_string($errstr)) $errstr = serialize($errstr);
		
		if (0 === strpos($errfile, ABSPATH)) $errfile = substr($errfile, strlen(ABSPATH));
		
		return "PHP event: code $e_type: $errstr (line $errline, $errfile)";
		
	}
	
	/**
	 * Runs upon the WordPress 'init' action
	 */
	public function init() {
		if ((!is_admin() || (defined('DOING_AJAX') && DOING_AJAX)) && is_user_logged_in() && file_exists(SIMBA_TFA_PLUGIN_DIR.'/includes/tfa_frontend.php')) {
			$this->load_frontend();
		} else {
			add_shortcode('twofactor_user_settings', array($this, 'shortcode_when_not_logged_in'));
		}
	}
	
	/**
	 * Return the Simba_TFA_Provider_TOTP object.
	 *
	 * @returns Simba_TFA_Provider_TOTP
	 */
	public function get_totp_controller() {
		return $this->totp_controller;
	}
	
	/** 
	 * "Shared" - i.e. could be called from either front-end or back-end
	 */
	public function shared_ajax() {
		
		if (empty($_POST['subaction']) || empty($_POST['nonce']) || !is_user_logged_in() || !wp_verify_nonce($_POST['nonce'], 'tfa_shared_nonce')) die('Security check (3).');
		
		global $current_user;
		
		$subaction = $_POST['subaction'];
		
		if ('refreshotp' == $subaction) {
			
			$code = $this->totp_controller->get_current_code($current_user->ID);
			
			if (false === $code) die(json_encode(array('code' => '')));
			
			die(json_encode(array('code' => $code)));
			
		} elseif ('untrust_device' == $subaction && isset($_POST['device_id'])) {
			$this->untrust_device(stripslashes($_POST['device_id']));
			ob_start();
			$this->include_template('trusted-devices-inner-box.php', array('trusted_devices' => $this->user_get_trusted_devices()));
			echo json_encode(array('trusted_list' => ob_get_clean()));
		}
		
		exit;
		
	}
	
	/**
	 * Mark a device as untrusted for the current user
	 *
	 * @param String $device_id
	 */
	protected function untrust_device($device_id) {
		
		$trusted_devices = $this->user_get_trusted_devices();
		
		unset($trusted_devices[$device_id]);
		
		global $current_user;
		$current_user_id = $current_user->ID;
		
		$this->user_set_trusted_devices($current_user_id, $trusted_devices);
		
	}
	
	/**
	 * Called upon the AJAX action simbatfa-init-otp . Will die.
	 *
	 * Uses these keys from $_POST: user
	 */
	public function tfaInitLogin() {
		
		if (empty($_POST['user'])) die('Security check (2).');
		
		if (defined('TWO_FACTOR_DISABLE') && TWO_FACTOR_DISABLE) {
			$res = array('result' => false, 'user_can_trust' => false);
		} else {
			
			if (!function_exists('sanitize_user')) require_once ABSPATH.WPINC.'/formatting.php';
			
			// WP's password-checking sanitizes the supplied user, so we must do the same to check if TFA is enabled for them
			$auth_info = array('log' => sanitize_user(stripslashes((string)$_POST['user'])));
			
			if (!empty($_COOKIE['simbatfa_trust_token'])) $auth_info['trust_token'] = (string) $_COOKIE['simbatfa_trust_token'];
			
			$res = $this->pre_auth($auth_info, 'array');
		}
		
		$results = array(
			'jsonstarter' => 'justhere',
			'status' => $res['result'],
		);
		
		if (!empty($res['user_can_trust'])) {
			$results['user_can_trust'] = 1;
			if (!empty($res['user_already_trusted'])) $results['user_already_trusted'] = 1;
		}
		
		
		if (!empty($this->output_buffering)) {
			if (!empty($this->logged)) {
				$results['php_output'] = $this->logged;
			}
			restore_error_handler();
			$buffered = ob_get_clean();
			if ($buffered) $results['extra_output'] = $buffered;
		}
		
		$results = apply_filters('simbatfa_check_tfa_requirements_ajax_response', $results);
		
		echo json_encode($results);
		
		exit;
	}
	
	/**
	 * Enable or disable TFA for a user
	 *
	 * @param Integer $user_id - the WordPress user ID
	 * @param String  $setting - either "true" (to turn on) or "false" (to turn off)
	 */
	public function change_tfa_enabled_status($user_id, $setting) {
		$previously_enabled = $this->is_activated_by_user($user_id) ? 1 : 0;
		$setting = ('true' === $setting) ? 1 : 0;
		update_user_meta($user_id, 'tfa_enable_tfa', $setting);
		do_action('simba_tfa_activation_status_saved', $user_id, $setting, $previously_enabled, $this);
	}
	
	/**
	 * Here's where the login action happens. Called on the WP 'authenticate' action.
	 *
	 * @param WP_Error|WP_User $user
	 * @param String		   $username - this is not necessarily the WP username; it is whatever was typed in the form, so can be an email address
	 * @param String		   $password
	 *
	 * @return WP_Error|WP_User
	 */
	public function tfaVerifyCodeAndUser($user, $username, $password) {
		
		$original_user = $user;
		$params = stripslashes_deep($_POST);
		
		// If (only) the error was a wrong password, but it looks like the user appended a TFA code to their password, then have another go
		if (is_wp_error($user) && array('incorrect_password') == $user->get_error_codes() && !isset($params['two_factor_code']) && false !== ($from_password = apply_filters('simba_tfa_tfa_from_password', false, $password))) {
			// This forces a new password authentication below
			$user = false;
		}
		
		if (is_wp_error($user)) {
			$ret = $user;
		} else {
			
			if (is_object($user) && isset($user->ID) && isset($user->user_login)) {
				$params['log'] = $user->user_login;
				// Confirm that this is definitely a username regardless of its format
				$may_be_email = false;
			} else {
				$params['log'] = $username;
				$may_be_email = true;
			}
			
			$params['caller'] = $_SERVER['PHP_SELF'] ? $_SERVER['PHP_SELF'] : $_SERVER['REQUEST_URI'];
			if (!empty($_COOKIE['simbatfa_trust_token'])) $params['trust_token'] = (string) $_COOKIE['simbatfa_trust_token'];
			
			if (isset($from_password) && false !== $from_password) {
				// Support login forms that can't be hooked via appending to the password
				$speculatively_try_appendage = true;
				$params['two_factor_code'] = $from_password['tfa_code'];
			}
			
			$code_ok = $this->authorise_user_from_login($params, $may_be_email);
			
			if (is_wp_error($code_ok)) {
				$ret = $code_ok;
			} elseif (!$code_ok) {
				$ret =  new WP_Error('authentication_failed', '<strong>'.__('Error:', 'two-factor-authentication').'</strong> '.__('The one-time password (TFA code) you entered was incorrect.', 'two-factor-authentication'));
			} elseif ($user) {
				$ret = $user;
			} else {
				
				if (!empty($speculatively_try_appendage) && true === $code_ok) {
					$password = $from_password['password'];
				}
				
				$username_is_email = false;
				
				if (function_exists('wp_authenticate_username_password') && $may_be_email && filter_var($username, FILTER_VALIDATE_EMAIL)) {
					global $wpdb;
					// This has to match self::authorise_user_from_login()
					$response = $wpdb->get_row($wpdb->prepare("SELECT ID, user_registered from ".$wpdb->users." WHERE user_email=%s", $username));
					if (is_object($response)) $username_is_email = true;
				}
				
				$ret = $username_is_email ? wp_authenticate_email_password(null, $username, $password) : wp_authenticate_username_password(null, $username, $password);
			}
			
		}
		
		$ret = apply_filters('simbatfa_verify_code_and_user_result', $ret, $original_user, $username, $password);
		
		// If the TFA code was actually validated (not just not required, for example), then $code_ok is (boolean)true
		if (isset($code_ok) && true === $code_ok && is_a($ret, 'WP_User')) {
			if (!empty($params['simba_tfa_mark_as_trusted']) && $this->user_can_trust($ret->ID) && (is_ssl() || (!empty($_SERVER['SERVER_NAME']) && ('localhost' == $_SERVER['SERVER_NAME'] ||'127.0.0.1' == $_SERVER['SERVER_NAME'])))) {
				
				$trusted_for = $this->get_option('tfa_trusted_for');
				$trusted_for = (false === $trusted_for) ? 30 : (string) absint($trusted_for);
				
				$this->trust_device($ret->ID, $trusted_for);
			}
		}
		
		return $ret;
	}
	
	// N.B. - This doesn't check is_activated_for_user() - the caller would normally want to do that first
	public function user_can_trust($user_id) {
		// Default is false because this is a new feature and we don't want to surprise existing users by granting broader access than they expected upon an upgrade
		return apply_filters('simba_tfa_user_can_trust', false, $user_id);
	}
	
	/**
	 * Should the user be asked for a TFA code? And optionally, is the user allowed to trust devices?
	 *
	 * @param Array	 $params - the key used is 'log', indicating the username or email address
	 * @param String $response_format - 'simple' (historic format) or 'array' (richer info)
	 *
	 * @return Boolean
	 */
	public function pre_auth($params, $response_format = 'simple') {
		global $wpdb;
		
		$query = filter_var($params['log'], FILTER_VALIDATE_EMAIL) ? $wpdb->prepare("SELECT ID, user_email from ".$wpdb->users." WHERE user_email=%s", $params['log']) : $wpdb->prepare("SELECT ID, user_email from ".$wpdb->users." WHERE user_login=%s", $params['log']);
		$user = $wpdb->get_row($query);
		
		if (!$user && filter_var($params['log'], FILTER_VALIDATE_EMAIL)) {
			// Corner-case: login looks like an email, but is a username rather than email address
			$user = $wpdb->get_row($wpdb->prepare("SELECT ID, user_email from ".$wpdb->users." WHERE user_login=%s", $params['log']));
		}
		
		$is_activated_for_user = true;
		$is_activated_by_user = false;
		
		$result = false;
		
		$totp_controller = $this->totp_controller;
		
		if ($user) {
			$tfa_priv_key = get_user_meta($user->ID, 'tfa_priv_key_64', true);
			$is_activated_for_user = $this->is_activated_for_user($user->ID);
			$is_activated_by_user = $this->is_activated_by_user($user->ID);
			
			if ($is_activated_for_user && $is_activated_by_user) {
				
				// No private key yet, generate one. This shouldn't really be possible.
				if (!$tfa_priv_key) $tfa_priv_key = $totp_controller->addPrivateKey($user->ID);
				
				$code = $totp_controller->generateOTP($user->ID, $tfa_priv_key);
				
				$result = true;
			}
		}
		
		if ('array' != $response_format) return $result;
		
		$ret = array('result' => $result);
		
		if ($result) {
			$ret['user_can_trust'] = $this->user_can_trust($user->ID);
			if (!empty($params['trust_token']) && $this->user_trust_token_valid($user->ID, $params['trust_token'])) {
				$ret['user_already_trusted'] = 1;
			}
		}
		
		return $ret;
	}
	
	/**
	 * Print the radio buttons for enabling/disabling TFA
	 *
	 * @param Integer $user_id	  - the WordPress user ID
	 * @param Boolean $long_label - whether to use a long label rather than a short one
	 * @param String  $style	  - valid values are "show_current" and "require_current"
	 */ 
	public function paint_enable_tfa_radios($user_id, $long_label = false, $style = 'show_current') {
		
		if (!$user_id) return;
		
		if ('require_current' != $style) $style = 'show_current';
		
		$is_required = $this->is_required_for_user($user_id);
		$is_activated = $this->is_activated_by_user($user_id);
		
		if ($is_required) {
			$require_after = absint($this->get_option('tfa_requireafter'));
			echo '<p class="tfa_required_warning" style="font-weight:bold; font-style:italic;">'.sprintf(__('N.B. This site is configured to forbid you to log in if you disable two-factor authentication after your account is %d days old', 'two-factor-authentication'), $require_after).'</p>';
		}
		
		$tfa_enabled_label = $long_label ? __('Enable two-factor authentication', 'two-factor-authentication') : __('Enabled', 'two-factor-authentication');
		
		if ('show_current' == $style) {
			$tfa_enabled_label .= ' '.sprintf(__('(Current code: %s)', 'two-factor-authentication'), $this->get_totp_controller()->current_otp_code($user_id));
		} elseif ('require_current' == $style) {
			$tfa_enabled_label .= ' '.sprintf(__('(you must enter the current code: %s)', 'two-factor-authentication'), '<input type="text" class="tfa_enable_current" name="tfa_enable_current" size="6" style="height">');
		}
		
		$show_disable = ((is_multisite() && is_super_admin()) || (!is_multisite() && current_user_can($this->get_management_capability())) || false == $is_activated || !$is_required || !$this->get_option('tfa_hide_turn_off')) ? true : false;
		
		$tfa_disabled_label = $long_label ? __('Disable two-factor authentication', 'two-factor-authentication') : __('Disabled', 'two-factor-authentication');
		
		if ('require_current' == $style) echo '<input type="hidden" name="require_current" value="1">'."\n";
		
		echo '<input type="radio" class="tfa_enable_radio" id="tfa_enable_tfa_true" name="tfa_enable_tfa" value="true" '.(true == $is_activated ? 'checked="checked"' : '').'> <label class="tfa_enable_radio_label" for="tfa_enable_tfa_true">'.apply_filters('simbatfa_radiolabel_enabled', $tfa_enabled_label, $long_label).'</label> <br>';
		
		// Show the 'disabled' option if the user is an admin, or if it is currently set, or if TFA is not compulsory, or if the site owner doesn't require it to be hidden
		// Note that this just hides the option in the UI. The user could POST to turn off TFA, but, since it's required, they won't be able to log in.
		if ($show_disable) {
			echo '<input type="radio" class="tfa_enable_radio" id="tfa_enable_tfa_false" name="tfa_enable_tfa" value="false" '.(false == $is_activated ? 'checked="checked"' :'').'> <label class="tfa_enable_radio_label" for="tfa_enable_tfa_false">'.apply_filters('simbatfa_radiolabel_disabled', $tfa_disabled_label, $long_label).'</label> <br>';
		}
	}
	
	/**
	 * Retrieve a saved option
	 *
	 * @param String $key - option key
	 *
	 * @return Mixed
	 */
	public function get_option($key) {
		if (!is_multisite()) return get_option($key);
		switch_to_blog(1);
		$v = get_option($key);
		restore_current_blog();
		return $v;
	}
	
	/**
	 * Paint a list of checkboxes, one for each role
	 *
	 * @param String  $prefix
	 * @param Integer $default - default value (0 or 1)
	 */
	public function list_user_roles_checkboxes($prefix = '', $default = 1) {
		
		if (is_multisite()) {
			// Not a real WP role; needs separate handling
			$id = '_super_admin';
			$name = __('Multisite Super Admin', 'two-factor-authentication');
			$setting = $this->get_option('tfa_'.$prefix.$id);
			$setting = ($setting === false) ? $default : ($setting ? 1 : 0);
			
			echo '<input type="checkbox" id="tfa_'.$prefix.$id.'" name="tfa_'.$prefix.$id.'" value="1" '.($setting ? 'checked="checked"' :'').'> <label for="tfa_'.$prefix.$id.'">'.htmlspecialchars($name)."</label><br>\n";
		}
		
		global $wp_roles;
		if (!isset($wp_roles)) $wp_roles = new WP_Roles();
		
		foreach ($wp_roles->role_names as $id => $name) {
			$setting = $this->get_option('tfa_'.$prefix.$id);
			$setting = ($setting === false) ? $default : ($setting ? 1 : 0);
			
			echo '<input type="checkbox" id="tfa_'.$prefix.$id.'" name="tfa_'.$prefix.$id.'" value="1" '.($setting ? 'checked="checked"' :'').'> <label for="tfa_'.$prefix.$id.'">'.htmlspecialchars($name)."</label><br>\n";
		}
		
	}
	
	public function tfa_list_xmlrpc_status_radios() {
		
		$setting = $this->get_option('tfa_xmlrpc_on');
		$setting = $setting ? 1 : 0;
		
		$types = array(
			'0' => __('Do not require 2FA over XMLRPC (best option if you must use XMLRPC and your client does not support 2FA)', 'two-factor-authentication'),
			'1' => __('Do require 2FA over XMLRPC (best option if you do not use XMLRPC or are unsure)', 'two-factor-authentication')
		);
		
		foreach($types as $id => $name) {
			print '<input type="radio" name="tfa_xmlrpc_on" id="tfa_xmlrpc_on_'.$id.'" value="'.$id.'" '.($setting == $id ? 'checked="checked"' : '').'> <label for="tfa_xmlrpc_on_'.$id.'">'.htmlspecialchars($name)."</label><br>\n";
		}
	}
	
	protected function is_caller_active() {
		
		if (!defined('XMLRPC_REQUEST') || !XMLRPC_REQUEST) return true;
		
		$saved_data = $this->get_option('tfa_xmlrpc_on');
		
		return $saved_data ? true : false;
		
	}
	
	/**
	 * @param Array	 		 $params
	 * @param Boolean		 $may_be_email
	 *
	 * @return WP_Error|Boolean|Integer - WP_Error or false means failure; true or 1 means success, but true means the TFA code was validated
	 */
	public function authorise_user_from_login($params, $may_be_email = false) {
		
		$params = apply_filters('simbatfa_auth_user_from_login_params', $params);
		
		global $wpdb;
		
		if (!$this->is_caller_active()) return 1;
		
		$query = ($may_be_email && filter_var($params['log'], FILTER_VALIDATE_EMAIL)) ? $wpdb->prepare("SELECT ID, user_registered from ".$wpdb->users." WHERE user_email=%s", $params['log']) : $wpdb->prepare("SELECT ID, user_registered from ".$wpdb->users." WHERE user_login=%s", $params['log']);
		$response = $wpdb->get_row($query);
		
		if (!$response && $may_be_email && filter_var($params['log'], FILTER_VALIDATE_EMAIL)) {
			// Corner-case: login looks like an email, but is a username rather than email address
			$response = $wpdb->get_row($wpdb->prepare("SELECT ID, user_registered from ".$wpdb->users." WHERE user_login=%s", $params['log']));
		}
		
		$user_ID = is_object($response) ? $response->ID : false;
		$user_registered = is_object($response) ? $response->user_registered : false;
		
		$user_code = isset($params['two_factor_code']) ? str_replace(' ', '', trim($params['two_factor_code'])) : '';
		
		// This condition in theory should not be possible
		if (!$user_ID) return new WP_Error('tfa_user_not_found', apply_filters('simbatfa_tfa_user_not_found', '<strong>'.__('Error:', 'two-factor-authentication').'</strong> '.__('The indicated user could not be found.', 'two-factor-authentication')));
		
		if (!$this->is_activated_for_user($user_ID)) return 1;
		
		if (!empty($params['trust_token']) && $this->user_trust_token_valid($user_ID, $params['trust_token'])) {
			return 1;
		}
		
		if (!$this->is_activated_by_user($user_ID)) {
			
			if (!$this->is_required_for_user($user_ID)) return 1;
			
			$require_after = absint($this->get_option('tfa_requireafter')) * 86400;
			
			$account_age = time() - strtotime($user_registered);
			
			if ($account_age > $require_after && apply_filters('simbatfa_enforce_require_after_check', true, $user_ID, $require_after, $account_age)) {
				return new WP_Error('tfa_required', apply_filters('simbatfa_notfa_forbidden_login', '<strong>'.__('Error:', 'two-factor-authentication').'</strong> '.__('The site owner has forbidden you to login without two-factor authentication. Please contact the site owner to re-gain access.', 'two-factor-authentication')));
			}
			
			return 1;
		}
		
		$tfa_creds_user_id = !empty($params['creds_user_id']) ? $params['creds_user_id'] : $user_ID;
		
		if ($tfa_creds_user_id != $user_ID) {
			
			// Authenticating using a different user's credentials (e.g. https://wordpress.org/plugins/use-administrator-password/)
			// In this case, we require that different user to have TFA active - so that this mechanism can't be used to avoid TFA
			
			if (!$this->is_activated_for_user($tfa_creds_user_id) || !$this->is_activated_by_user($tfa_creds_user_id)) {
				return new WP_Error('tfa_required', apply_filters('simbatfa_notfa_forbidden_login_altuser', '<strong>'.__('Error:', 'two-factor-authentication').'</strong> '.__('You are attempting to log in to an account that has two-factor authentication enabled; this requires you to also have two-factor authentication enabled on the account whose credentials you are using.', 'two-factor-authentication')));
			}
			
		}
		
		return $this->totp_controller->check_code_for_user($tfa_creds_user_id, $user_code);
		
	}
	
	/**
	 * Evaluate whether a trust token is valid for a user
	 *
	 * @param Integer $user_id	   - WP user ID
	 * @param String  $trust_token - trust token
	 *
	 * @return Boolean
	 */
	protected function user_trust_token_valid($user_id, $trust_token) {
		
		if (!is_string($trust_token) || strlen($trust_token) < 30) return false;
		
		$trusted_devices = $this->user_get_trusted_devices($user_id);
		
		$time_now = time();
		
		foreach ($trusted_devices as $device) {
			if (empty($device['until']) || $device['until'] <= $time_now) continue;
			if (!empty($device['token']) && $device['token'] === $trust_token) {
				return true;
			}
		}
		
		return false;
	}
	
	/**
	 * This deals with the issue that wp-login.php does not redirect to a canonical URL. As a result, if a website is available under more than one host, then admin_url('admin-ajax.php') might return a different one than the visitor is using, resulting in AJAX failing due to CORS errors.
	 *
	 * @return String
	 */
	protected function get_ajax_url() {
		$ajax_url = admin_url('admin-ajax.php');
		$parsed_url = parse_url($ajax_url);
		if (strtolower($parsed_url['host']) !== strtolower($_SERVER['HTTP_HOST']) && !empty($parsed_url['path'])) {
			// Mismatch - return the relative URL only
			$ajax_url = $parsed_url['path'];
		}
		return $ajax_url;
	}
	
	/**
	 * Called not only upon the WP action login_enqueue_scripts, but potentially upon the action 'init' and various others from other plugins too. It can handle being called multiple times.
	 */
	public function login_enqueue_scripts() {
		
		if (isset($_GET['action']) && 'logout ' != $_GET['action'] && 'login' != $_GET['action']) return;
		
		static $already_done = false;
		if ($already_done) return;
		$already_done = true;
		
		// Prevent cacheing when in debug mode
		$script_ver = (defined('WP_DEBUG') && WP_DEBUG) ? time() : filemtime(SIMBA_TFA_PLUGIN_DIR.'/includes/tfa.js');
		
		wp_enqueue_script('tfa-ajax-request', SIMBA_TFA_PLUGIN_URL.'/includes/tfa.js', array('jquery'), $script_ver);
		
		$trusted_for = $this->get_option('tfa_trusted_for');
		$trusted_for = (false === $trusted_for) ? 30 : (string) absint($trusted_for);
		
		$localize = array(
			'ajaxurl' => $this->get_ajax_url(),
			'click_to_enter_otp' => __("Click to enter One Time Password", 'two-factor-authentication'),
			'enter_username_first' => __('You have to enter a username first.', 'two-factor-authentication'),
			'otp' => __('One Time Password (i.e. 2FA)', 'two-factor-authentication'),
			'otp_login_help' => __('(check your OTP app to get this password)', 'two-factor-authentication'),
			'mark_as_trusted' => sprintf(_n('Trust this device (allow login without 2FA for %d day)', 'Trust this device (allow login without TFA for %d days)', $trusted_for, 'two-factor-authentication'), $trusted_for),
			'is_trusted' => __('(Trusted device)', 'two-factor-authentication'),
			'nonce' => wp_create_nonce('simba_tfa_loginform_nonce'),
			'login_form_selectors' => '',
			'login_form_off_selectors' => '',
		);
		
		// Spinner exists since WC 3.8. Use the proper functions to avoid SSL warnings.
		if (file_exists(ABSPATH.'wp-admin/images/spinner-2x.gif')) {
			$localize['spinnerimg'] = admin_url('images/spinner-2x.gif');
		} elseif (file_exists(ABSPATH.WPINC.'/images/spinner-2x.gif')) {
			$localize['spinnerimg'] = includes_url('images/spinner-2x.gif');
		}
		
		$localize = apply_filters('simba_tfa_login_enqueue_localize', $localize);
		
		wp_localize_script('tfa-ajax-request', 'simba_tfasettings', $localize);
		
	}
	
	/**
	 * Return or output view content
	 *
	 * @param String  $path                   - path to template, usually relative to templates/ within the plugin directory
	 * @param Array	  $extract_these		  - key/value pairs for substitution into the scope of the template
	 * @param Boolean $return_instead_of_echo - what to do with the results
	 *
	 * @return String|Void
	 */
	public function include_template($path, $extract_these = array(), $return_instead_of_echo = false) {
		
		if ($return_instead_of_echo) ob_start();
		
		$template_file = apply_filters('simatfa_template_file', SIMBA_TFA_PLUGIN_DIR.'/templates/'.$path, $path, $extract_these, $return_instead_of_echo);
		
		do_action('simbatfa_before_template', $path, $return_instead_of_echo, $extract_these, $template_file);
		
		if (!file_exists($template_file)) {
			error_log("TFA: template not found: $template_file (from $path)");
			echo __('Error:', 'two-factor-authentication').' '.__('two-factor-authentication', 'wp-optimize')." (".$path.")";
		} else {
			extract($extract_these);
			// The following are useful variables which can be used in the template.
			// They appear as unused, but may be used in the $template_file.
			$wpdb = $GLOBALS['wpdb'];// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $wpdb might be used in the included template
			$simba_tfa = $this;// phpcs:ignore VariableAnalysis.CodeAnalysis.VariableAnalysis.UnusedVariable -- $wp_optimize might be used in the included template
			include $template_file;
		}
		
		do_action('simbatfa_after_template', $path, $return_instead_of_echo, $extract_these, $template_file);
		
		if ($return_instead_of_echo) return ob_get_clean();
	}
	
	/**
	 * Make sure that self::$frontend is the instance of Simba_TFA_Frontend, and return it
	 *
	 * @return Simba_TFA_Frontend
	 */
	public function load_frontend() {
		if (!class_exists('Simba_TFA_Frontend')) require_once(SIMBA_TFA_PLUGIN_DIR.'/includes/tfa_frontend.php');
		if (empty($this->frontend)) $this->frontend = new Simba_TFA_Frontend($this);
		return $this->frontend;
	}
	
	// __return_empty_string() does not exist until WP 3.7
	public function shortcode_when_not_logged_in() {
		return '';
	}
	
}
