<?php
/**
 * Plugin Name: TrustPulse API
 * Plugin URI:  https://trustpulse.com
 * Description: Easily Add the TrustPulse API Script to your Site
 * Author URI:  https://trustpulse.com
 * Version:     1.0.4
 * Text Domain: trustpulse-api
 *
 * TrustPulse API Plugin is is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 2 of the License, or
 * any later version.
 *
 * TrustPulse API Plugin is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with TrustPulse API Plugin. If not, see <https://www.gnu.org/licenses/>.
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Autoload the class files.
spl_autoload_register( 'TPAPI::autoload' );

// Store base file location
define( 'TPAPI_FILE', __FILE__ );
if ( ! defined( 'TRUSTPULSE_APIJS_URL' ) ) {
	define( 'TRUSTPULSE_APIJS_URL', 'https://a.trstplse.com/' );
}
if ( ! defined( 'TRUSTPULSE_APIJS_SCRIPT_URL' ) ) {
	define( 'TRUSTPULSE_APIJS_SCRIPT_URL', TRUSTPULSE_APIJS_URL . 'app/js/api.min.js' );
}
if ( ! defined( 'TRUSTPULSE_APP_URL' ) ) {
	define( 'TRUSTPULSE_APP_URL', 'https://app.trustpulse.com/' );
}
if ( ! defined( 'TRUSTPULSE_URL' ) ) {
	define( 'TRUSTPULSE_URL', 'https://trustpulse.com/' );
}
define( 'TRUSTPULSE_ADMIN_PAGE_NAME', 'trustpulse' );
define( 'TRUSTPULSE_PLUGIN_VERSION', '1.0.3' );

/**
 * Get the directory URI for this plugin
 *
 * @return string the URI
 */
function trustpulse_dir_uri() {
	return plugin_dir_url( __FILE__ );
}

register_activation_hook( __FILE__, 'trustpulse_api_activation_hook' );

/**
 * Fired when the plugin is activated.
 *
 * @since 1.0.0
 *
 * @global int $wp_version      The version of WordPress for this install.
 * @global object $wpdb         The WordPress database object.
 * @param boolean $network_wide True if WPMU superadmin uses "Network Activate" action, false otherwise.
 */
function trustpulse_api_activation_hook( $network_wide ) {
	global $wp_version;
	if ( version_compare( $wp_version, '3.5.1', '<' ) && ! defined( 'TRUSTPULSE_FORCE_ACTIVATION' ) ) {
		deactivate_plugins( plugin_basename( __FILE__ ) );
		wp_die( sprintf( esc_html__( 'Sorry, but your version of WordPress does not meet %1$s\'s required version of <strong>3.5.1</strong> to run properly. The plugin has been deactivated. <a href="%2$s">Click here to return to the Dashboard</a>.', 'trustpulse-api' ), 'TrustPulse', get_admin_url() ) );
	}

	$trustpulse_script_id = get_option( 'trustpulse_script_id', false );
	if ( ! $trustpulse_script_id ) {
		add_option( 'trustpulse_api_plugin_do_activation_redirect', true );
	}
}

/**
 * Main plugin class.
 *
 * @since 1.0.0
 *
 * @package TPAPI
 * @author  Erik Jonasson
 */
class TPAPI {

	/**
	 * Holds the class object.
	 *
	 * @since 1.0.0
	 *
	 * @var object
	 */
	public static $instance;

	/**
	 * Plugin version, used for cache-busting of style and script file references.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $version = '1.0.4';

	/**
	 * The name of the plugin.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $plugin_name = 'TrustPulse API';

	/**
	 * Unique plugin slug identifier.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $plugin_slug = 'trustpulse';

	/**
	 * Plugin file.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $file = __FILE__;

	/**
	 * Plugin basename.
	 *
	 * @since 1.0.0
	 *
	 * @var string
	 */
	public $basename;


	/**
	 * AM_Notification object (loaded only in the admin)
	 *
	 * @var AM_Notification
	 */
	public $notifications;

	/**
	 * TP Actions Object
	 *
	 * @var TPAPI_Actions
	 */
	public $actions;

	/**
	 * TP WooCommerce Object
	 *
	 * @var TPAPI_WooCommerce
	 */
	public $woocommerce;

	/**
	 * Primary class constructor.
	 *
	 * @since 1.0.0
	 */
	public function __construct() {
		$this->actions  = new TPAPI_Actions();
		$this->basename = plugin_basename( __FILE__ );
		// Load the plugin.
		add_action( 'init', array( $this, 'init' ) );
	}

	/**
	 * Loads the plugin into WordPress.
	 *
	 * @since 1.0.0
	 */
	public function init() {
		// Load admin only components.
		if ( is_admin() ) {
			$this->load_admin();
		}
		add_action( 'wp_enqueue_scripts', array( $this, 'add_footer_script_if_enabled' ) );

		// If Give plugin is available add additional embed code output
		add_action('give_post_form', array( $this, 'outputRawEmbedScript') );

		// Support Extra WooCommerce product data in webhook payload
		add_filter( 'woocommerce_rest_prepare_shop_order_object', 'TPAPI_WooCommerce::add_tp_product_data_to_wc_api',  10, 3 );
	}

	/**
	 * Loads all admin related classes into scope.
	 *
	 * @since 1.0.0
	 */
	public function load_admin() {

		// Manually load notification api.
		require_once plugin_dir_path( __FILE__ ) . 'includes/class-am-notification.php';
		$this->notifications = new AM_Notification( 'tp', $this->version );
		$this->admin_page    = new TPAPI_AdminPage();
		add_filter( "plugin_action_links_{$this->basename}", array( $this, 'add_settings_link' ) );
		add_action( 'admin_init', array( $this, 'activation_redirect' ) );
	}

	/**
	 * Redirect to the settings page if the activation redirect setting is enabled
	 * This is turned on on plugin activation if we don't have a TrustPulse Script ID
	 *
	 * @return void
	 */
	public function activation_redirect() {
		$option = get_option( 'trustpulse_api_plugin_do_activation_redirect' );
		if ( $option ) {
			delete_option( 'trustpulse_api_plugin_do_activation_redirect' );
			wp_redirect( 'admin.php?page=' . TRUSTPULSE_ADMIN_PAGE_NAME );
			exit;
		}
	}

	/**
	 * Adds our Settings link to the plugin page
	 *
	 * @since 1.0.0
	 */
	public function add_settings_link( $links ) {

		$settings_link = '<a href="admin.php?page="' . TRUSTPULSE_ADMIN_PAGE_NAME . '>' . esc_html__( 'Settings', 'trustpulse-api' ) . '</a>';
		array_unshift( $links, $settings_link );

		return $links;
	}


	/**
	 * Add Plugin Script to the Footer if it's both enabled and we have a script ID
	 *
	 * @since 1.0.0
	 */
	public function add_footer_script_if_enabled() {
		$enabled    = get_option( 'trustpulse_script_enabled', false );
		$account_id = get_option( 'trustpulse_script_id', false );

		if ( $enabled && $account_id ) {
			wp_enqueue_script( 'trustpulse-api-js', TRUSTPULSE_APIJS_SCRIPT_URL, array(), '', true );

			if ( version_compare( get_bloginfo( 'version' ), '4.1.0', '>=' ) ) {
				add_filter( 'script_loader_tag', array( $this, 'filter_api_script' ), 10, 2 );
			} else {
				add_filter( 'clean_url', array( $this, 'filter_api_url' ) );
			}
		}
	}

	/**
	 * Filters the API script tag to add a custom ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $tag    The HTML script output.
	 * @param string $handle The script handle to target.
	 * @return string $tag   Amended HTML script with our ID attribute appended.
	 */
	public function filter_api_script( $tag, $handle ) {

		// If the handle is not ours, do nothing.
		if ( 'trustpulse-api-js' !== $handle ) {
			return $tag;
		}
		$account_id = esc_html( get_option( 'trustpulse_script_id', false ) );

		// Adjust the output to add our custom script ID.
		return str_replace( ' src', " data-account='$account_id'  async src", $tag );
	}

	/**
	 * Filters the API script tag to add a custom ID.
	 *
	 * @since 1.0.0
	 *
	 * @param string $url  The URL to filter.
	 * @return string $url Amended URL with our ID attribute appended.
	 */
	public function filter_api_url( $url ) {
		// If the handle is not ours, do nothing.
		if ( false === strpos( $url, str_replace( 'https://', '', TRUSTPULSE_APIJS_URL ) ) ) {
			return $url;
		}

		$account_id = esc_html( get_option( 'trustpulse_script_id', false ) );

		// Adjust the URL to add our custom script ID.
		return "$url' async='async' data-account='{$account_id}'";
	}

	/**
	 * Outputs full embed script code.
	 *
	 * @since 1.0.3
	 */
	public function outputRawEmbedScript() {
		echo $this->getRawEmbedScript();
	}

	/**
	 * Helper method to get a full embed script without wp helpers
	 *
	 * @since 1.0.3
	 */
	public function getRawEmbedScript() {
		$enabled    = get_option( 'trustpulse_script_enabled', false );
		if (!$enabled) {
			return;
		}

		$account_id = esc_html( get_option( 'trustpulse_script_id', false ) );
		return '<script type="text/javascript" src="' . TRUSTPULSE_APIJS_URL . 'app/js/api.min.js' . '" data-account="' . $account_id . '" async></script>';
	}

	/**
	 * Check if the  main WooCommerce class is active.
	 *
	 * @since 1.0.4
	 *
	 * @return bool
	 */
	public static function is_woocommerce_active() {
		return class_exists( 'WooCommerce', true );
	}

	/**
	 * PRS-0 compliant autoloader.
	 *
	 * @since 1.0.0
	 *
	 * @param string $classname The classname to check with the autoloader.
	 */
	public static function autoload( $classname ) {

		// Return early if not the proper classname.
		if ( 'TPAPI' !== mb_substr( $classname, 0, 5 ) ) {
			return;
		}

		// Check if the file exists. If so, load the file.
		$filename = dirname( __FILE__ ) . DIRECTORY_SEPARATOR . str_replace( '_', DIRECTORY_SEPARATOR, $classname ) . '.php';
		if ( file_exists( $filename ) ) {
			require $filename;
		}

	}

	/**
	 * Returns the singleton instance of the class.
	 *
	 * @since 1.0.0
	 *
	 * @return TPAPI
	 */
	public static function get_instance() {

		if ( ! isset( self::$instance ) && ! ( self::$instance instanceof TPAPI ) ) {
			self::$instance = new TPAPI();
		}

		return self::$instance;

	}

}

// Load the plugin.
$trustpulse_api = TPAPI::get_instance();
