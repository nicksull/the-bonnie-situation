<?php

/**
 * The file that defines the core plugin class
 *
 * A class definition that includes attributes and functions used across both the
 * public-facing side of the site and the admin area.
 *
 * @link       https://nicksull.dev
 * @since      1.0.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/includes
 */

/**
 * The core plugin class.
 *
 * This is used to define internationalization, admin-specific hooks, and
 * public-facing site hooks.
 *
 * Also maintains the unique identifier of this plugin as well as the current
 * version of the plugin.
 *
 * @since      1.0.0
 * @package    Bonnie
 * @subpackage Bonnie/includes
 * @author     Nick Sullivan <studio@redpocket.hk>
 */
class Bonnie {

	/**
	 * The loader that's responsible for maintaining and registering all hooks that power
	 * the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      Bonnie_Loader    $loader    Maintains and registers all hooks for the plugin.
	 */
	protected $loader;

	/**
	 * Service registry shared with add-ons through the `bonnie_loaded` action.
	 *
	 * @since    1.1.0
	 * @access   protected
	 * @var      Bonnie_Services    $services    Read-only facade over core services.
	 */
	protected $services;

	/**
	 * The unique identifier of this plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $plugin_name    The string used to uniquely identify this plugin.
	 */
	protected $plugin_name;

	/**
	 * The current version of the plugin.
	 *
	 * @since    1.0.0
	 * @access   protected
	 * @var      string    $version    The current version of the plugin.
	 */
	protected $version;

	/**
	 * Define the core functionality of the plugin.
	 *
	 * Set the plugin name and the plugin version that can be used throughout the plugin.
	 * Load the dependencies, define the locale, and set the hooks for the admin area and
	 * the public-facing side of the site.
	 *
	 * @since    1.0.0
	 */
	public function __construct() {
		if ( defined( 'BONNIE_VERSION' ) ) {
			$this->version = BONNIE_VERSION;
		} else {
			$this->version = '1.0.0';
		}
		$this->plugin_name = 'bonnie';

		$this->load_dependencies();
		$this->set_locale();
		$this->define_admin_hooks();
		$this->define_public_hooks();
		$this->define_capture_hooks();
		$this->define_cron_hooks();
		$this->define_compliance_hooks();
		$this->define_rest_hooks();

	}

	/**
	 * Load the required dependencies for this plugin.
	 *
	 * Include the following files that make up the plugin:
	 *
	 * - Bonnie_Loader. Orchestrates the hooks of the plugin.
	 * - Bonnie_i18n. Defines internationalization functionality.
	 * - Bonnie_Admin. Defines all hooks for the admin area.
	 * - Bonnie_Public. Defines all hooks for the public side of the site.
	 *
	 * Create an instance of the loader which will be used to register the hooks
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function load_dependencies() {

		/**
		 * The class responsible for orchestrating the actions and filters of the
		 * core plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-bonnie-loader.php';

		/**
		 * Service registry handed to add-ons via the `bonnie_loaded` action.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-bonnie-services.php';

		/**
		 * The class responsible for defining internationalization functionality
		 * of the plugin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-bonnie-i18n.php';

		/**
		 * The class responsible for defining all actions that occur in the admin area.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-bonnie-admin.php';

		/**
		 * The class responsible for defining all actions that occur in the public-facing
		 * side of the site.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'public/class-bonnie-public.php';

		/**
		 * Core capture pipeline: logger, data-access layer and the CF7 handler.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-bonnie-logger.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-bonnie-store.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-bonnie-capture.php';

		/**
		 * Retention engine and its cron handler.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-bonnie-retention.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-bonnie-cron.php';

		/**
		 * Core Personal Data export / erase integration.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-bonnie-privacy.php';

		/**
		 * REST API for the DataViews admin.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'includes/class-bonnie-rest.php';

		/**
		 * Admin: submissions viewer and settings.
		 */
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-bonnie-submissions.php';
		require_once plugin_dir_path( dirname( __FILE__ ) ) . 'admin/class-bonnie-settings.php';

		$this->loader = new Bonnie_Loader();

		$this->services = new Bonnie_Services(
			new Bonnie_Store(),
			new Bonnie_Logger(),
			$this->loader,
			$this->version
		);

	}

	/**
	 * Define the locale for this plugin for internationalization.
	 *
	 * Uses the Bonnie_i18n class in order to set the domain and to register the hook
	 * with WordPress.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function set_locale() {

		$plugin_i18n = new Bonnie_i18n();

		$this->loader->add_action( 'plugins_loaded', $plugin_i18n, 'load_plugin_textdomain' );

	}

	/**
	 * Register all of the hooks related to the admin area functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_admin_hooks() {

		$plugin_admin = new Bonnie_Admin( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_styles' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_admin, 'enqueue_scripts' );

		// Submissions viewer owns the top-level menu; register it first so the
		// Settings subpage can attach to it.
		$plugin_submissions = new Bonnie_Submissions_Page( new Bonnie_Store(), new Bonnie_Logger() );

		$this->loader->add_action( 'admin_menu', $plugin_submissions, 'add_menu' );
		$this->loader->add_action( 'admin_init', $plugin_submissions, 'handle_actions' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_submissions, 'enqueue_assets' );

		$plugin_settings = new Bonnie_Settings();

		$this->loader->add_action( 'admin_menu', $plugin_settings, 'add_menu' );
		$this->loader->add_action( 'admin_enqueue_scripts', $plugin_settings, 'enqueue_assets' );

	}

	/**
	 * Register all of the hooks related to the public-facing functionality
	 * of the plugin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_public_hooks() {

		$plugin_public = new Bonnie_Public( $this->get_plugin_name(), $this->get_version() );

		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_styles' );
		$this->loader->add_action( 'wp_enqueue_scripts', $plugin_public, 'enqueue_scripts' );

	}

	/**
	 * Register the Contact Form 7 capture hooks.
	 *
	 * The wpcf7_submit action only fires when CF7 is active, so it is always
	 * safe to register. A separate admin notice (evaluated at render time, after
	 * all plugins have loaded) covers the case where CF7 is missing.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_capture_hooks() {

		$store   = new Bonnie_Store();
		$logger  = new Bonnie_Logger();
		$capture = new Bonnie_Capture( $store, $logger );

		$this->loader->add_action( 'wpcf7_submit', $capture, 'capture', 20, 2 );
		$this->loader->add_action( 'admin_notices', $this, 'maybe_cf7_missing_notice' );

	}

	/**
	 * Warn admins when Contact Form 7 is not active, so capture is a no-op.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function maybe_cf7_missing_notice() {

		if ( class_exists( 'WPCF7' ) || ! current_user_can( 'activate_plugins' ) ) {
			return;
		}

		echo '<div class="notice notice-warning"><p>';
		echo esc_html__( 'Bonnie needs Contact Form 7 to capture submissions. Please install and activate Contact Form 7.', 'the-bonnie-situation' );
		echo '</p></div>';

	}

	/**
	 * Register the retention cron handler and the WP-CLI command.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_cron_hooks() {

		$store     = new Bonnie_Store();
		$logger    = new Bonnie_Logger();
		$retention = new Bonnie_Retention( $store, $logger );
		$cron      = new Bonnie_Cron( $retention );

		$this->loader->add_action( BONNIE_CRON_HOOK, $cron, 'run' );

	}

	/**
	 * Register the core Personal Data exporter and eraser.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_compliance_hooks() {

		$privacy = new Bonnie_Privacy( new Bonnie_Store(), new Bonnie_Logger() );

		$this->loader->add_filter( 'wp_privacy_personal_data_exporters', $privacy, 'register_exporter' );
		$this->loader->add_filter( 'wp_privacy_personal_data_erasers', $privacy, 'register_eraser' );

	}

	/**
	 * Register the REST routes used by the DataViews admin.
	 *
	 * @since    1.0.0
	 * @access   private
	 */
	private function define_rest_hooks() {

		$rest = new Bonnie_REST( new Bonnie_Store(), new Bonnie_Logger() );

		$this->loader->add_action( 'rest_api_init', $rest, 'register_routes' );

	}

	/**
	 * Run the loader to execute all of the hooks with WordPress.
	 *
	 * @since    1.0.0
	 */
	public function run() {
		$this->loader->run();

		/**
		 * Fires once core has registered all of its hooks, handing add-ons the
		 * shared service registry. Add-ons (e.g. Bonnie Pro) should register their
		 * own behaviour here rather than reaching into core internals.
		 *
		 * @since 1.1.0
		 * @param Bonnie_Services $services Read-only facade over core services.
		 */
		do_action( 'bonnie_loaded', $this->services );
	}

	/**
	 * The service registry shared with add-ons.
	 *
	 * @since     1.1.0
	 * @return    Bonnie_Services
	 */
	public function get_services() {
		return $this->services;
	}

	/**
	 * The name of the plugin used to uniquely identify it within the context of
	 * WordPress and to define internationalization functionality.
	 *
	 * @since     1.0.0
	 * @return    string    The name of the plugin.
	 */
	public function get_plugin_name() {
		return $this->plugin_name;
	}

	/**
	 * The reference to the class that orchestrates the hooks with the plugin.
	 *
	 * @since     1.0.0
	 * @return    Bonnie_Loader    Orchestrates the hooks of the plugin.
	 */
	public function get_loader() {
		return $this->loader;
	}

	/**
	 * Retrieve the version number of the plugin.
	 *
	 * @since     1.0.0
	 * @return    string    The version number of the plugin.
	 */
	public function get_version() {
		return $this->version;
	}

}
