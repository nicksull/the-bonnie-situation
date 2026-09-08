<?php

/**
 * Shared service registry handed to add-ons.
 *
 * A thin, read-only facade over the core services an add-on (e.g. Bonnie Pro)
 * needs to register its own behaviour: the data-access layer, the logger, the
 * hook loader, and the running version. Passed to the `bonnie_loaded` action so
 * add-ons never have to reach into core internals directly.
 *
 * @link       https://beforebonnie.com
 * @since      1.1.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/includes
 */

/**
 * Service registry.
 *
 * @since      1.1.0
 * @package    Bonnie
 * @subpackage Bonnie/includes
 * @author     Red Pocket <studio@redpocket.hk>
 */
class Bonnie_Services {

	/**
	 * Data-access layer.
	 *
	 * @var Bonnie_Store
	 */
	protected $store;

	/**
	 * Logger.
	 *
	 * @var Bonnie_Logger
	 */
	protected $logger;

	/**
	 * Hook loader.
	 *
	 * @var Bonnie_Loader
	 */
	protected $loader;

	/**
	 * Running core version.
	 *
	 * @var string
	 */
	protected $version;

	/**
	 * Constructor.
	 *
	 * @since 1.1.0
	 * @param Bonnie_Store  $store   Data-access layer.
	 * @param Bonnie_Logger $logger  Logger.
	 * @param Bonnie_Loader $loader  Hook loader.
	 * @param string        $version Running core version.
	 */
	public function __construct( Bonnie_Store $store, Bonnie_Logger $logger, Bonnie_Loader $loader, $version ) {
		$this->store   = $store;
		$this->logger  = $logger;
		$this->loader  = $loader;
		$this->version = (string) $version;
	}

	/**
	 * The data-access layer.
	 *
	 * @since  1.1.0
	 * @return Bonnie_Store
	 */
	public function store() {
		return $this->store;
	}

	/**
	 * The logger.
	 *
	 * @since  1.1.0
	 * @return Bonnie_Logger
	 */
	public function logger() {
		return $this->logger;
	}

	/**
	 * The hook loader.
	 *
	 * @since  1.1.0
	 * @return Bonnie_Loader
	 */
	public function loader() {
		return $this->loader;
	}

	/**
	 * The running core version — add-ons gate their requires-core check on this.
	 *
	 * @since  1.1.0
	 * @return string
	 */
	public function version() {
		return $this->version;
	}
}
