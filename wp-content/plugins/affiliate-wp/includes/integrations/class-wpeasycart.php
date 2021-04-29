<?php

class Affiliate_WP_EasyCart extends Affiliate_WP_Base {

	/**
	 * The context for referrals. This refers to the integration that is being used.
	 *
	 * @access  public
	 * @since   1.2
	 */
	public $context = 'wpeasycart';

	/**
	 * Setup actions and filters
	 *
	 * @access  public
	 * @since   1.6
	*/
	public function init() {

		add_action( 'wpeasycart_order_inserted', array( $this, 'add_pending_referral' ), 10, 5 );

		// There should be an option to choose which of these is used
		add_action( 'wpeasycart_order_paid', array( $this, 'mark_referral_complete' ), 10 );
		add_action( 'wpeasycart_full_order_refund', array( $this, 'revoke_referral_on_refund' ), 10 );

	}

	/**
	 * Store a pending referral when a new order is created
	 *
	 * @access  public
	 * @since   1.6
	 * @since   2.3   Added support for per-order rates
	 */
	public function add_pending_referral( $order_id, $cart, $order_totals, $user, $payment_type ){

		if( $this->was_referred() ) {

      	// get affiliate ID
      	$affiliate_id = $this->get_affiliate_id( $order_id );

			$this->email = $user->email;

			if ( $this->email === affwp_get_affiliate_email( $affiliate_id ) ) {

				$this->log( 'Referral not created because affiliate\'s own account was used.' );

				return false; // Customers cannot refer themselves
			}

			if( affiliate_wp()->referrals->get_by( 'reference', $order_id, $this->context ) ) {
				return false; // Referral already created for this reference
			}

			$cart_shipping = $order_totals->shipping_total;
			$cart_tax      = $order_totals->tax_total;
			$items         = $cart->cart;

			// Calculate the referral amount based on product prices
			if ( affwp_is_per_order_rate( $affiliate_id ) ) {
				$amount = $this->calculate_referral_amount();
			} else {
				$amount = 0.00;
				foreach ( $items as $cart_item ) {

					if ( $cart_item->has_affiliate_rule ) {
						continue; // Referrals are disabled on this product
					}

					// The order discount has to be divided across the items

					$product_total = $cart_item->total_price;
					$shipping      = 0;

					if ( $cart_shipping > 0 && ! affiliate_wp()->settings->get( 'exclude_shipping' ) ) {

						$shipping      = $cart_shipping / count( $items );
						$product_total += $shipping;

					}

					if ( $cart_tax > 0 && ! affiliate_wp()->settings->get( 'exclude_tax' ) ) {

						$tax           = $cart_tax / count( $items );
						$product_total += $tax;

					}

					if ( $product_total <= 0 ) {
						continue;
					}

					$amount += $this->calculate_referral_amount( $product_total, $order_id, $cart_item->product_id );

				}
			}
			if( 0 == $amount && affiliate_wp()->settings->get( 'ignore_zero_referrals' ) ) {
			
				$this->log( 'Referral not created due to 0.00 amount.' );

				return false; // Ignore a zero amount referral
			}

			$description = $this->get_referral_description( $cart->cart );

			$this->insert_pending_referral(
					$amount,
					$order_id,
					$description,
					$items,
					array(
							'affiliate_id' => $affiliate_id,
					)
			);
		}
	}

	/**
	 * Mark referral as complete when payment is completed
	 *
	 * @access  public
	 * @since   1.6
	*/
	public function mark_referral_complete( $order_id = 0 ) {

		$this->complete_referral( $order_id );

	}

	/**
	 * Revoke the referral when the order is refunded
	 *
	 * @access  public
	 * @since   1.6
	*/
	public function revoke_referral_on_refund( $order_id = 0 ) {

		if( ! affiliate_wp()->settings->get( 'revoke_on_refund' ) ) {
			return;
		}

		$this->reject_referral( $order_id );

	}

	/**
	 * Retrieves the referral description
	 *
	 * @access  public
	 * @since   1.1
	*/
	public function get_referral_description( $items ) {

		$description = array();

		foreach ( $items as $key => $item ) {

			$description[] = $item->title;

		}

		$description = implode( ', ', $description );

		return $description;

	}

	/**
	 * Runs the check necessary to confirm this plugin is active.
	 *
	 * @since 2.5
	 *
	 * @return bool True if the plugin is active, false otherwise.
	 */
	function plugin_is_active() {
		return function_exists( 'wpeasycart_load_startup' );
	}
}

	new Affiliate_WP_EasyCart;
