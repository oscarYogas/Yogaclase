<?php
namespace AffWP\Utils\Batch_Process;

use AffWP\Utils;
use AffWP\Utils\Batch_Process as Batch;

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Implements a batch processor for generating coupons for affiliates.
 *
 * @since 2.6
 *
 * @see \AffWP\Utils\Batch_Process
 * @see \AffWP\Utils\Batch_Process\With_PreFetch
 */
class Create_Dynamic_Coupons extends Utils\Batch_Process implements Batch\With_PreFetch {

	/**
	 * Batch process ID.
	 *
	 * @access public
	 * @since  2.6
	 * @var    string
	 */
	public $batch_id = 'create-dynamic-coupons';

	/**
	 * Capability needed to perform the current batch process.
	 *
	 * @access public
	 * @since  2.6
	 * @var    string
	 */
	public $capability = 'manage_affiliates';

	/**
	 * Number of affiliate coupons to generate per step.
	 *
	 * @access public
	 * @since  2.6
	 * @var    int
	 */
	public $per_step = 40;

	/**
	 * Override existing coupon for the affiliate.
	 *
	 * @access public
	 * @since  2.6
	 * @var    int
	 */
	public $override_coupon = 0;

	/**
	 * Initializes values needed following instantiation.
	 *
	 * @access public
	 * @since  2.6
	 *
	 * @param null|array $data Optional. Form data. Default null.
	 */
	public function init( $data = null ) {

		if ( null !== $data && ! empty( $data['override_coupon'] ) ) {
			$this->override_coupon = $data['override_coupon'];
		}
	}

	/**
	 * Handles pre-fetching user IDs for accounts in migration.
	 *
	 * @access public
	 * @since  2.6
	 */
	public function pre_fetch() {

		$total_to_export = $this->get_total_count();

		if ( false === $total_to_export ) {
			$total_to_process = affiliate_wp()->affiliates->count( array(
				'status' => 'active',
				'number' => -1,
			) );

			$this->set_total_count( absint( $total_to_process ) );
		}
	}

	/**
	 * Executes a single step in the batch process.
	 *
	 * @access public
	 * @since  2.6
	 *
	 * @return int|string|\WP_Error Next step number, 'done', or a WP_Error object.
	 */
	public function process_step() {

		$current_count = $this->get_current_count();

		$args = array(
			'number'  => $this->per_step,
			'offset'  => $this->get_offset(),
			'orderby' => 'affiliate_id',
			'status'  => 'active',
			'order'   => 'ASC',
			'fields'  => array( 'affiliate_id' ),
		);

		$affiliates = affiliate_wp()->affiliates->get_affiliates( $args );

		if ( empty( $affiliates ) ) {
			return 'done';
		}

		$inserted = 0;

		foreach ( $affiliates as $affiliate_id ) {

			$coupons = affwp_get_dynamic_affiliate_coupons( $affiliate_id, false );

			if ( ! ( ! $coupons || $this->override_coupon ) ) {
				continue;
			}

			if ( $this->override_coupon && $coupons ) {

				foreach( $coupons as $coupon ) {
					$added = affiliate_wp()->affiliates->coupons->update( $coupon->ID, array(
						'affiliate_id' => $affiliate_id,
						'coupon_code'  => affiliate_wp()->affiliates->coupons->generate_code( array(
							'affiliate_id' => $affiliate_id,
						) ),
					) );
				}

			} else {

				$added = affiliate_wp()->affiliates->coupons->add( array(
					'affiliate_id' => $affiliate_id,
				) );

			}

			if ( false !== $added ) {
				$inserted++;
			}
		}

		$this->set_current_count( absint( $current_count ) + $inserted );

		return ++$this->step;
	}

	/**
	 * Retrieves a message for the given code.
	 *
	 * @access public
	 * @since  2.6
	 *
	 * @param string $code Message code.
	 * @return string Message.
	 */
	public function get_message( $code ) {

		switch ( $code ) {

			case 'done':

				$final_count = $this->get_current_count();

				if ( empty( $final_count ) ) {

					$message = __( 'All affiliates have a coupon already assigned.', 'affiliate-wp' );

				} else {

					$message = sprintf(
						_n(
							'Coupon successfully generated for %s affiliate.',
							'Coupons successfully generated for %s affiliates.',
							$final_count,
							'affiliate-wp'
						), number_format_i18n( $final_count )
					);

				}

				break;

			default:
				$message = '';
				break;
		}

		return $message;
	}

}
