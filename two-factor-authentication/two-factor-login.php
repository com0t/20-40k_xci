<?php
/*
Plugin Name: Two Factor Authentication
Plugin URI: https://www.simbahosting.co.uk/s3/product/two-factor-authentication/
Description: Secure your WordPress login forms with two factor authentication - including WooCommerce login forms
Author: David Anderson, original plugin by Oskar Hane and enhanced by Dee Nutbourne
Author URI: https://www.simbahosting.co.uk
Version: 1.13.0
Text Domain: two-factor-authentication
Domain Path: /languages
License: GPLv2 or later
*/

if (defined('SIMBA_TFA_PLUGIN_DIR') && file_exists(dirname(__FILE__).'/premium/loader.php')) {
	throw new Exception('To activate Two Factor Authentication Premium, first de-activate the free version (only one can be active at once).');
}

define('SIMBA_TFA_PLUGIN_DIR', dirname(__FILE__));
define('SIMBA_TFA_PLUGIN_URL', plugins_url('', __FILE__));

if (!class_exists('Simba_Two_Factor_Authentication')) require SIMBA_TFA_PLUGIN_DIR.'/simba-tfa.php';

/**
 * This parent-child relationship enables the two to be split without affecting backwards compatibility for developers making direct calls
 * 
 * This class is for the plugin encapsulation.
 */
class Simba_Two_Factor_Authentication_Plugin extends Simba_Two_Factor_Authentication {
	
	public $version = '1.13.0';
	
	const PHP_REQUIRED = '5.6';
	
	/**
	 * Constructor, run upon plugin initiation
	 *
	 * @uses __FILE__
	 */
	public function __construct() {
		
		if (version_compare(PHP_VERSION, self::PHP_REQUIRED, '<' )) {
			add_action('all_admin_notices', array($this, 'admin_notice_insufficient_php'));
			$abort = true;
		}
		
		if (!function_exists('mcrypt_get_iv_size') && !function_exists('openssl_cipher_iv_length')) {
			add_action('all_admin_notices', array($this, 'admin_notice_missing_mcrypt_and_openssl'));
			$abort = true;
		}
		
		if (!empty($abort)) return;
		
		if (file_exists(__DIR__.'/premium/loader.php')) include_once(__DIR__.'/premium/loader.php');
		
		// Add TFA column on users list
		add_action('manage_users_columns', array($this, 'manage_users_columns_tfa'));
		add_action('wpmu_users_columns', array($this, 'manage_users_columns_tfa'));
		add_action('manage_users_custom_column', array($this, 'manage_users_custom_column_tfa'), 10, 3);
		
		// Needed users.php CSS.
		add_action('admin_print_styles-users.php', array($this, 'load_users_css'), 10, 0);
		
		add_action('plugins_loaded', array($this, 'plugins_loaded'));
		
		// Menu entries
		add_action('admin_menu', array($this, 'menu_entry_for_admin'));
		add_action('admin_menu', array($this, 'menu_entry_for_user'));
		add_action('network_admin_menu', array($this, 'menu_entry_for_user'));
		
		// Add settings link in plugin list
		$plugin = plugin_basename(__FILE__); 
		add_filter("plugin_action_links_$plugin", array($this, 'add_plugin_settings_link'));
		add_filter("network_admin_plugin_action_links_$plugin", array($this, 'add_plugin_settings_link'));
		
		parent::__construct();
		
	}
	
	/**
	 * Runs upon the WP filters plugin_action_links_(plugin) and network_plugin_action_links_(plugin)
	 *
	 * @param Array $links
	 *
	 * @return Array
	 */
	public function add_plugin_settings_link($links) {
		if (!is_network_admin()) {
			$link = '<a href="options-general.php?page=two-factor-auth">'.__('Plugin settings', 'two-factor-authentication').'</a>';
			array_unshift($links, $link);
		} else {
			switch_to_blog(1);
			$link = '<a href="'.admin_url('options-general.php').'?page=two-factor-auth">'.__('Plugin settings', 'two-factor-authentication').'</a>';
			restore_current_blog();
			array_unshift($links, $link);
		}
		
		$link2 = '<a href="admin.php?page=two-factor-auth-user">'.__('User settings', 'two-factor-authentication').'</a>';
		array_unshift($links, $link2);
		
		return $links;
	}
	
	/**
	 * Runs upon the WP actions admin_menu and network_admin_menu
	 */
	public function menu_entry_for_user() {
		
		$this->get_totp_controller()->potentially_port_private_keys();
		
		global $current_user;
		if ($this->is_activated_for_user($current_user->ID)) {
			add_menu_page(__('Two Factor Authentication', 'two-factor-authentication'), __('Two Factor Auth', 'two-factor-authentication'), 'read', 'two-factor-auth-user', array($this, 'show_dashboard_user_settings_page'), SIMBA_TFA_PLUGIN_URL.'/img/tfa_admin_icon_16x16.png', 72);
		}
	}
	
	/**
	 * Runs upon the WP action admin_menu
	 */
	public function menu_entry_for_admin() {
		
		$this->get_totp_controller()->potentially_port_private_keys();
		
		if (is_multisite() && (!is_super_admin() || !is_main_site())) return;
		
		add_options_page(
			__('Two Factor Authentication', 'two-factor-authentication'),
			__('Two Factor Authentication', 'two-factor-authentication'),
			$this->get_management_capability(),
			'two-factor-auth',
			array($this, 'show_admin_settings_page')
		);
	}
	
	/**
	 * Include the admin settings page code
	 */
	public function show_admin_settings_page() {
		$totp_controller = $this->get_totp_controller();
		$totp_controller->setUserHMACTypes();
		if (!is_admin() || !current_user_can($this->get_management_capability())) return;
		$this->include_template('admin-settings.php', array('totp_controller' => $totp_controller));
	}
	
	/**
	 * Enqueue CSS styling on the users page
	 */
	public function load_users_css() {
		wp_enqueue_style(
			'tfa-users-css',
			SIMBA_TFA_PLUGIN_URL.'/css/users.css',
			array(),
			$this->version,
			'screen'
		);
	}

	/**
	 * Add the 2FA label to the users list table header.
	 *
	 * @param Array $columns Table columns.
	 *
	 * @return Array
	 */
	public function manage_users_columns_tfa($columns = array()) {
		$columns['tfa-status'] = __('2FA', 'two-factor-authentication');
		return $columns;
	}
	
	/**
	 * Add status into TFA column.
	 *
	 * @param  String  $value       String.
	 * @param  String  $column_name Column name.
	 * @param  Integer $user_id     User ID.
	 *
	 * @return String
	 */
	public function manage_users_custom_column_tfa($value = '', $column_name = '', $user_id = 0) {
		
		// Only for this column name.
		if ('tfa-status' === $column_name) {
			
			if (!$this->is_activated_for_user($user_id)) {
				$value = '&#8212;';
			} elseif ($this->is_activated_by_user($user_id)) {
				// Use value.
				$value = '<span title="' . __( 'Enabled', 'two-factor-authentication' ) . '" class="dashicons dashicons-yes"></span>';
			} else {
				// No group.
				$value = '<span title="' . __( 'Disabled', 'two-factor-authentication' ) . '" class="dashicons dashicons-no"></span>';
			}
		}
		
		return $value;
	}
	
	/**
	 * Runs conditionally on the WP action all_admin_notices
	 */
	public function admin_notice_insufficient_php() {
		$this->show_admin_warning('<strong>'.__('Higher PHP version required', 'two-factor-authentication').'</strong><br> '.sprintf(__('The Two Factor Authentication plugin requires PHP version %s or higher - your current version is only %s.', 'two-factor-authentication'), self::PHP_REQUIRED, PHP_VERSION), 'error');
	}
	
	/**
	 * Runs conditionally on the WP action all_admin_notices
	 */
	public function admin_notice_missing_mcrypt_and_openssl() {
		$this->show_admin_warning('<strong>'.__('PHP OpenSSL or mcrypt module required', 'two-factor-authentication').'</strong><br> '.__('The Two Factor Authentication plugin requires either the PHP openssl (preferred) or mcrypt module to be installed. Please ask your web hosting company to install one of them.', 'two-factor-authentication'), 'error');
	}
	
	/**
	 * Paint out an admin notice
	 *
	 * @param String $message - the caller should already have taken care of any escaping
	 * @param String $class
	 */
	public function show_admin_warning($message, $class = 'updated') {
		echo '<div class="tfamessage '.$class.'">'."<p>$message</p></div>";
	}
	
	/**
	 * Run upon the WP plugins_loaded action
	 */
	public function plugins_loaded() {
		load_plugin_textdomain(
			'two-factor-authentication',
			false,
			dirname(plugin_basename(__FILE__)).'/languages/'
		);
	}
}

$GLOBALS['simba_two_factor_authentication'] = new Simba_Two_Factor_Authentication_Plugin();
