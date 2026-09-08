<?php

/**
 * Cron handler.
 *
 * The recurring event is scheduled by the activator; this class is the bound
 * handler that delegates to the retention engine when the event fires.
 *
 * @link       https://beforebonnie.com
 * @since      1.0.0
 *
 * @package    Bonnie
 * @subpackage Bonnie/includes
 */

/**
 * Cron handler.
 *
 * @since      1.0.0
 * @package    Bonnie
 * @subpackage Bonnie/includes
 * @author     Red Pocket <studio@redpocket.hk>
 */
class Bonnie_Cron {

	/**
	 * Retention engine.
	 *
	 * @var Bonnie_Retention
	 */
	protected $retention;

	/**
	 * Constructor.
	 *
	 * @since 1.0.0
	 * @param Bonnie_Retention $retention Retention engine.
	 */
	public function __construct( Bonnie_Retention $retention ) {
		$this->retention = $retention;
	}

	/**
	 * Fired on BONNIE_CRON_HOOK; runs the retention purge.
	 *
	 * @since 1.0.0
	 * @return void
	 */
	public function run() {
		$this->retention->run();
	}

}
