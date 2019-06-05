<?php

/**
* Plugin Name: Neuralab Next Web Page Cache
* Plugin URI: https://github.com/Neuralab/next-web-page-cache
* Description: Browser cache which utilizes data from Google Analytics to predict which page should prefetch.
* Version: 0.1
* Author: Neuralab
* Author URI: https://neuralab.net
* Developer: Matej
* Text Domain: nwpp
* Requires at least: 4.7
* Requires PHP: 7.2
*
* License: MIT
*/


if ( ! defined( 'ABSPATH' ) ) {
  exit;
}

if ( ! class_exists( 'NWPP_Main' ) ) {
  class NWPP_Main {

    /**
     * Instance of the current class, null before first usage.
     *
     * @var NWPP_Main
     */
    protected static $_instance = null;

    /**
     * Class constructor.
     *
     * @since 0.1
     */
    protected function __construct() {
      NWPP_Main::register_constants();

      require_once 'includes/class-nwpp-settings.php';
      require_once 'includes/class-nwpp-ajax-interface.php';
      require_once 'includes/class-nwpp-cron-manager.php';

      $this->settings = new NWPP_Settings();
      $this->settings->register();

      if ( $this->settings->is_enabled() ) {
        $this->ajax = new NWPP_Ajax_Interface();
        $this->ajax->register();

        $this->cm = new NWPP_Cron_Manager();
        $this->cm->register();
      }
    }

    /**
     * Register plugin's constants.
     */
    public static function register_constants() {
      if ( ! defined( 'NWPP_PLUGIN_ID' ) ) {
        define( 'NWPP_PLUGIN_ID', 'nwpp' );
      }

      if ( ! defined( 'NWPP_DIR_PATH' ) ) {
        define( 'NWPP_DIR_PATH', plugin_dir_path( __FILE__ ) );
      }

      if ( ! defined( 'NWPP_DIR_URL' ) ) {
        define( 'NWPP_DIR_URL', plugin_dir_url( __FILE__ ) );
      }
    }

    /**
     * Installation procedure.
     *
     * @static
     * @since 0.1
     */
    public static function install() {
      if ( ! current_user_can( 'activate_plugins' ) ) {
        wp_die( __( 'User is not able to active plugin.', 'nwpp' ) );
      }

      NWPP_Main::register_constants();
      require_once 'includes/class-nwpp-db.php';
      require_once 'includes/class-nwpp-cron-manager.php';

      $db = new NWPP_DB();
      if ( ! $db->install() ) {
        wp_die( __( 'Failed to create DB tables for Neuralab Next Web Page Cache plugin.', 'nwpp' ) );
      }
    }

    /**
     * Uninstallation procedure.
     *
     * @static
     * @since 0.1
     */
    public static function uninstall() {
      if ( ! current_user_can( 'activate_plugins' ) ) {
        wp_die( __( 'User is not able to active plugin.', 'nwpp' ) );
      }

      NWPP_Main::register_constants();
      require_once 'includes/class-nwpp-db.php';
      require_once 'includes/class-nwpp-cron-manager.php';

      $cm = new NWPP_Cron_Manager();
      $cm->uninstall();

      $db = new NWPP_DB();
      $db->uninstall();

      wp_cache_flush();
    }

    /**
     * Deactivation procedure.
     *
     * @static
     * @since 0.1
     */
    public static function deactivate() {
      NWPP_Main::register_constants();
      require_once 'includes/class-nwpp-cron-manager.php';

      $cm = new NWPP_Cron_Manager();
      $cm->deactivate();

      wp_cache_flush();
    }

    /**
     * Return class instance.
     *
     * @static
     * @since 0.1
     * @return NWPP_Main
     */
    public static function get_instance() {
      if ( is_null( self::$_instance ) ) {
        self::$_instance = new self;
      }

      return self::$_instance;
    }

    /**
     * Cloning is forbidden.
     *
     * @since 0.1
     */
    public function __clone() {
      return wp_die( 'Cloning is forbidden!' );
    }

    /**
     * Unserializing instances of this class is forbidden.
     *
     * @since 0.1
     */
    public function __wakeup() {
      return wp_die( 'Unserializing instances is forbidden!' );
    }
  }
}

register_activation_hook( __FILE__, ['NWPP_Main', 'install'] );
register_deactivation_hook( __FILE__, ['NWPP_Main', 'deactivate'] );
register_uninstall_hook( __FILE__, ['NWPP_Main', 'uninstall'] );
add_action( 'plugins_loaded', ['NWPP_Main', 'get_instance'], 0 );
