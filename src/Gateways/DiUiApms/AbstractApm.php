<?php

namespace GlobalPayments\WooCommercePaymentGatewayProvider\Gateways\DiUiApms;

use Automattic\WooCommerce\Internal\DependencyManagement\ContainerException;
use Exception;
use Automattic\WooCommerce\Caching\CacheException;
use GlobalPayments\Api\Utils\GenerationUtils;
use GlobalPayments\WooCommercePaymentGatewayProvider\Gateways\GpApiGateway;
use GlobalPayments\WooCommercePaymentGatewayProvider\Utils\Utils;
use WC_Order;
use WC_Order_Refund;
use WP_Exception;

defined( 'ABSPATH' ) || exit;

/**
 *
 * @package GlobalPayments\WooCommercePaymentGatewayProvider\Gateways\DiUiApms
 */
class AbstractApm {

	/**
	 * Handle payment status callback from payment gateway
	 *
	 * @return void
	 * @throws WP_Exception
	 * @throws ContainerException
	 * @throws Exception
	 * @throws CacheException
	 */
	public static function handle_gpapi_apm_status_notification(): void {
		// validate_request_contents() returns the signature-verified data
		// set, or false when the request cannot be trusted.
		$request_data = self::validate_request_contents( new GpApiGateway() );
		if ( false === $request_data ) {
			return;
		}

		$transaction_id = self::extract_transaction_id( $request_data );
		$payment_status = self::extract_payment_status( $request_data );

		if ( ! empty( $request_data['reference'] ) ) {
			$order_id = str_replace( 'WooCommerce_Order_', '', $request_data['reference'] );

			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				return;
			}

			// Only mutate the order when the notification carries a non-empty transaction
			// id that matches the one currently stored on the order. A callback whose
			// transaction id does not match the order's active attempt (for example, a
			// delayed callback from a previous, retried attempt) must not change its state.
			if ( ! empty( $transaction_id ) && $order->get_transaction_id() === $transaction_id ) {
				self::update_order_status_from_notification( $order, $payment_status, $transaction_id, $request_data );
			}
		}
	}

	/**
	 * Extract transaction ID from callback data
	 *
	 * @param array $data
	 * @return string|null
	 * @throws WP_Exception
	 */
	protected static function extract_transaction_id( array $data ): ?string {
		// Common field names for transaction ID
		$possible_fields = array( 'id', 'reference', 'notification' );

		foreach ( $possible_fields as $field ) {
			if ( isset( $data[ $field ] ) && is_scalar( $data[ $field ] ) && '' !== (string) $data[ $field ] ) {
				return sanitize_text_field( (string) $data[ $field ] );
			}
		}

		return null;
	}

	/**
	 * Extract payment status from callback data
	 *
	 * @param array $data
	 * @return string
	 * @throws WP_Exception
	 */
	protected static function extract_payment_status( array $data ): string {
		// Common field names for status
		$possible_fields = array( 'status' );

		foreach ( $possible_fields as $field ) {
			if ( isset( $data[ $field ] ) && is_scalar( $data[ $field ] ) && '' !== (string) $data[ $field ] ) {
				return sanitize_text_field( (string) $data[ $field ] );
			}
		}

		return 'UNKNOWN';
	}

	/**
	 * Update order status based on payment notification
	 *
	 * @param bool|WC_Order|WC_Order_Refund $order
	 * @param string                        $payment_status
	 * @param string                        $transaction_id
	 * @param array                         $callback_data
	 * @return void
	 */
	protected static function update_order_status_from_notification(
		bool|WC_Order|WC_Order_Refund $order,
		string $payment_status,
		string $transaction_id,
		array $callback_data
	): void {
		$status_upper = strtoupper( $payment_status );

		// Create order note with callback details
		$callback_summary = "Status: $payment_status, Transaction ID: $transaction_id";
		if ( isset( $callback_data['amount'] ) ) {
			$callback_summary .= ', Amount: ' . $callback_data['amount'];
		}

		switch ( $status_upper ) {
			case 'CAPTURED':
				if ( ! in_array( $order->get_status(), array( 'processing', 'completed' ) ) ) {
					$note_text = sprintf(
						'Payment completed. %s',
						$callback_summary
					);
					$order->add_order_note( $note_text );
					$order->payment_complete( $transaction_id );

					// Add metadata to track callback processing
					$order->update_meta_data( '_gpapi_apm_callback_processed', date( 'Y-m-d H:i:s' ) );
					$order->update_meta_data( '_gpapi_apm_payment_status', $payment_status );
					$order->save();
				}
				break;
			case 'DECLINED':
				if ( in_array( $order->get_status(), array( 'on-hold', 'pending', 'cancelled', 'declined' ) ) ) {
					$note_text = sprintf(
						'Payment failed/declined via status notification. %s', $callback_summary
					);

					$order->update_status( 'failed', $note_text );

					// Add metadata to track callback processing
					$order->update_meta_data( '_gpapi_apm_callback_processed', date( 'Y-m-d H:i:s' ) );
					$order->update_meta_data( '_gpapi_apm_payment_status', $payment_status );

					$order->save();
				}
				break;
			case 'CANCELLED':
				if ( in_array( $order->get_status(), array( 'on-hold', 'pending', 'cancelled', 'declined' ) ) ) {
					$note_text = sprintf(
						'Payment cancelled via status notification. %s',
						$callback_summary
					);
					$order->update_status( 'cancelled', $note_text );

					// Add metadata to track callback processing
					$order->update_meta_data( '_gpapi_apm_callback_processed', date( 'Y-m-d H:i:s' ) );
					$order->update_meta_data( '_gpapi_apm_payment_status', $payment_status );
					$order->save();
				}
				break;
		}
	}

	/**
	 * Handle payment redirect back to shop
	 *
	 * @return void
	 */
	public static function handle_gpapi_apm_success_redirect(): void {
		$gateway = new GpApiGateway();

		$request_data = self::validate_request_contents( $gateway );
		if ( false === $request_data ) {
			return;
		}

		$transaction_id = self::extract_transaction_id( $request_data );
		$status         = isset( $request_data['status'] ) && is_scalar( $request_data['status'] )
			? strtoupper( (string) $request_data['status'] )
			: '';
		$reference      = isset( $request_data['reference'] ) && is_scalar( $request_data['reference'] )
			? (string) $request_data['reference']
			: '';

		if ( '' !== $reference && in_array( $status, array( 'DECLINED', 'CANCELLED' ), true ) ) {
			$order = wc_get_order(
				str_replace( 'WooCommerce_Order_', '', $reference )
			);

			$new_status = 'DECLINED' === $status ? 'failed' : 'cancelled';
			$note_text  = 'DECLINED' === $status
				? 'Payment was declined.'
				: 'Payment was cancelled by customer.';

			// Only transition the order when the callback matches the currently stored
			// transaction. A delayed callback from a previous, retried attempt must not
			// fail or cancel the active attempt.
			$matches_active_attempt = $order
				&& ! empty( $transaction_id )
				&& $order->get_transaction_id() === $transaction_id;

			if ( $matches_active_attempt && in_array( $order->get_status(), array( 'on-hold', 'pending' ), true ) ) {
				$order->update_status( $new_status, $note_text );
			} elseif ( $matches_active_attempt ) {
				$order->add_order_note( $note_text );
			}

			wp_safe_redirect( wc_get_checkout_url() );
		} elseif ( '' !== $reference && 'CAPTURED' === $status ) {
			self::handle_gpapi_apm_status_notification();

			WC()->cart->empty_cart();

			wp_safe_redirect(
				$gateway->get_return_url(
					wc_get_order(
						str_replace( 'WooCommerce_Order_', '', $reference )
					)
				)
			);
		} else {
			WC()->cart->empty_cart();

			wp_safe_redirect( $gateway->get_return_url() );
		}
	}

	/**
	 *
	 * Validate that the request carries a genuine gateway signature and return the
	 * data set that callers are allowed to act upon.
	 *
	 * @param GpApiGateway $gateway The gateway instance used to resolve the merchant credentials.
	 * @return array|false The request data on success, or false on failure.
	 */
	private static function validate_request_contents( GpApiGateway $gateway ): false|array {
		$app_key = $gateway->get_backend_gateway_options()['appKey'] ?? '';
		$app_key = is_scalar( $app_key ) ? (string) $app_key : '';

		// Never validate against an empty app key
		if ( '' === $app_key ) {
			return false;
		}

		$signature = self::get_request_signature();
		if ( '' === $signature ) {
			return false;
		}

		$request_method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
		$raw_input      = (string) file_get_contents( 'php://input' );

		// Build the data set the handlers are allowed to act upon. For POST status
		// notifications this is the JSON (or form-encoded) body; for GET redirects it is
		// the query parameters. On POST the query parameters are merged in as well so the
		// WooCommerce API route (wc-api) remains resolvable.
		if ( 'POST' === $request_method ) {
			$data = json_decode( $raw_input, true );
			if ( ! is_array( $data ) ) {
				$data = array();
				parse_str( $raw_input, $data );
			}
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Authenticity is verified via the X-GP-Signature, not a WordPress nonce.
			$query_params = wp_unslash( $_GET );
			unset( $query_params['X-GP-Signature'] );
			$data = array_merge( $query_params, $data );
		} else {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Authenticity is verified via the X-GP-Signature, not a WordPress nonce.
			$data = wp_unslash( $_GET );
			unset( $data['X-GP-Signature'] );
		}

		// The value feeds the HMAC only; it is never output, stored, or used in a query.
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized, WordPress.Security.ValidatedSanitizedInput.MissingUnslash -- Raw signed bytes, HMAC input only; sanitizing/unslashing would corrupt the signature check.
		$query_string = isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '';
		if ( '' !== trim( $query_string ) ) {
			$to_hash = ltrim( (string) preg_replace( '/(^|&)X-GP-Signature=[^&]*/', '', $query_string ), '&' );
		} else {
			$to_hash = $raw_input;
		}

		$expected_signature = GenerationUtils::generateXGPSignature( $to_hash, $app_key );

		if ( ! hash_equals( $expected_signature, $signature ) ) {
			return false;
		}

		return $data;
	}

	/**
	 * Retrieve the X-GP-Signature from the request (query parameter or header).
	 *
	 * @return string
	 */
	private static function get_request_signature(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Authenticity is verified via the X-GP-Signature, not a WordPress nonce.
		$query_signature = isset( $_GET['X-GP-Signature'] ) ? sanitize_text_field( wp_unslash( $_GET['X-GP-Signature'] ) ) : '';
		if ( '' !== $query_signature ) {
			return $query_signature;
		}

		$headers   = array_change_key_case( Utils::get_all_headers() );
		$signature = ( ! empty( $headers['x-gp-signature'] ) ) ? (string) $headers['x-gp-signature'] : '';

		// Final attempt to get the signature.
		if ( '' === $signature && ! empty( $_SERVER['HTTP_X_GP_SIGNATURE'] ) ) {
			$signature = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_GP_SIGNATURE'] ) );
		}

		return $signature;
	}
}
