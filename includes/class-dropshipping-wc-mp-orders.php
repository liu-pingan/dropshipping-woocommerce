<?php
/**
 * Class for handle Create/Update Orders on Knawat MP
 *
 * @link       http://knawat.com
 * @since      2.0.0
 *
 * @package    Knawat_Dropshipping_Woocommerce
 * @subpackage Knawat_Dropshipping_Woocommerce/includes
 */

// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Knawat_Dropshipping_WC_MP_Orders Class
 */
class Knawat_Dropshipping_WC_MP_Orders {

	/**
	 * MP API Wrapper object
	 *
	 * @var integer
	 */
	protected $mp_api;

	/**
	 * Initialize the class and set its properties.
	 *
	 * @since    2.0.0
	 */
	public function __construct() {
		// Create Order at Front-end.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'knawatds_order_created' ), 10 );

		// Create/Update Order.
		add_action( 'woocommerce_process_shop_order_meta', array( $this, 'knawatds_order_created_updated' ), 99 );
		/* add_action( 'woocommerce_update_order', array( $this, 'knawatds_order_created_updated' ), 99 ); */

		// Handle a custom meta query var to get orders with the custom meta field.
		add_filter( 'woocommerce_order_data_store_cpt_get_orders_query', array( $this, 'handle_knawatds_custom_query_var' ), 10, 2 );

		// Set Warning on edit order page if sync failed orders are there.
		add_action( 'current_screen', array( $this, 'knawatds_edit_shop_order_screen' ) );

		// Start Syncronization for sync failed orders.
		add_action( 'admin_post_knawatds_order_fail_sync', array( $this, 'knawat_start_order_fail_sync' ) );
	}

	/**
	 * Function knawatds_order_created_updated will run on order create/update from backend.
	 * if it is knawat order than create/update order on Knawat MP API.
	 *
	 * @param  int $order_id    The ID of the order that was just created.
	 *
	 * @return void
	 */
	public function knawatds_order_created_updated( $order_id ) {
		global $knawatdswc_errors;
		$post_type = get_post_type( $order_id );
		if ( 'shop_order' !== $post_type ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! empty( $order ) ) {
			$is_knawat = get_post_meta( $order_id, '_knawat_order', true );
			if ( 1 == $is_knawat ) {
				if ( knawat_dropshipwc_is_order_local_ds( $order_id ) ) {
					return;
				}
				$korder_id = get_post_meta( $order_id, '_knawat_order_id', true );
				if ( $korder_id != '' ) {

					$update_order_json = $this->knawat_format_order( $order_id, true );
					if ( $update_order_json ) {
						$this->mp_api = new Knawat_Dropshipping_Woocommerce_API();
						$result = $this->mp_api->put( 'orders/' . $korder_id, $update_order_json );
						$current_action = current_action();

						if ( ! is_wp_error( $result ) ) {
							if ( isset( $result->status ) && 'success' === $result->status ) {
								$korder_id = $result->data->id;
								delete_post_meta( $order_id, '_knawat_sync_failed' );
							} else {
								// WC log error.
								$order_sync_error = sprintf( esc_attr__( 'Order synchronize fail. order id: #%d', 'dropshipping-woocommerce' ), $order_id );
								if( isset( $result->message ) ){
									$order_sync_error .= ' REASON: ';
									$order_sync_error .= isset( $result->name ) ? $result->name . ':' . $result->message : $result->message;
									$order_sync_error .= isset( $result->code ) ? '('.$result->code . ')' : '';
								}
								$knawatdswc_errors['order_sync'] = $order_sync_error;
								knawat_dropshipwc_logger( $order_sync_error );
								update_post_meta( $order_id, '_knawat_sync_failed', true );
							}
						} else {
							// WC log error.
							$order_sync_error = sprintf( esc_attr__( 'Order synchronize fail. order id: #%d', 'dropshipping-woocommerce' ), $order_id );
							$knawatdswc_errors['order_sync'] = $order_sync_error;
							knawat_dropshipwc_logger( $order_sync_error );
							update_post_meta( $order_id, '_knawat_sync_failed', true );
						}
					}
				} else {
					$this->knawatds_order_created( $order_id );
				}
			}
		}
	}

	/**
	 * Function knawatds_order_created will run on order create.
	 * if it is knawat order than create order on Knawat MP API.
	 *
	 * @param  int $order_id    The ID of the order that was just created.
	 *
	 * @return void
	 */
	public function knawatds_order_created( $order_id ) {
		global $knawatdswc_errors;
		$post_type = get_post_type( $order_id );
		if ( 'shop_order' !== $post_type ) {
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! empty( $order ) ) {
			$is_knawat = get_post_meta( $order_id, '_knawat_order', true );
			if ( 1 == $is_knawat ) {
				if ( knawat_dropshipwc_is_order_local_ds( $order_id ) ) {
					return;
				}

				$push_statuses = knawat_dropshipwc_get_push_order_statuses();
				$payment_method = $order->get_payment_method();
				$push_status = isset( $push_statuses[$payment_method] ) ? str_replace('wc-', '', $push_statuses[$payment_method] ) : 'processing';
				$order_status = $order->get_status();

				if( $push_status != $order_status ){
					// Return as order status is not allowed to push order
					delete_post_meta( $order_id, '_knawat_sync_failed' );
					return;
				}

				$new_order_json = $this->knawat_format_order( $order_id );
				if ( $new_order_json ) {
					$this->mp_api = new Knawat_Dropshipping_Woocommerce_API();
					$result = $this->mp_api->post( 'orders', $new_order_json );

					if ( ! is_wp_error( $result ) ) {
						if ( isset( $result->status ) && 'success' === $result->status ) {
							$korder_id = $result->data->id;
							update_post_meta( $order_id, '_knawat_order_id', $korder_id );
							delete_post_meta( $order_id, '_knawat_sync_failed' );
						} else {
							// WC log error.
							$order_sync_error = sprintf( esc_attr__( 'Order synchronize fail. order id: #%d', 'dropshipping-woocommerce' ), $order_id );
							if( isset( $result->message ) ){
								$order_sync_error .= ' REASON: ';
								$order_sync_error .= isset( $result->name ) ? $result->name . ':' . $result->message : $result->message;
								$order_sync_error .= isset( $result->code ) ? '('.$result->code . ')' : '';
							}
							$knawatdswc_errors['order_sync'] = $order_sync_error;
							knawat_dropshipwc_logger( $order_sync_error );
							update_post_meta( $order_id, '_knawat_sync_failed', true );
						}
					} else {
						// WC log error.
						$order_sync_error = sprintf( esc_attr__( 'Order synchronize fail. order id: #%d', 'dropshipping-woocommerce' ), $order_id );
						$knawatdswc_errors['order_sync'] = $order_sync_error;
						knawat_dropshipwc_logger( $order_sync_error );
						update_post_meta( $order_id, '_knawat_sync_failed', true );
					}
				}
			}
		}
	}

	/**
	 * Get formated order Json for knawat mp API
	 *
	 * @param  int     $order_id    The ID of the order that was just created.
	 * @param  boolean $is_update   format order for update or not.
	 * @param  boolean $json        return data should json or array.
	 *
	 * @return string|Array  formatted order json.
	 */
	public function knawat_format_order( $order_id, $is_update = false, $json = true ) {

		if ( empty( $order_id ) ) {
			return false;
		}

		$request    = new WP_REST_Request( 'GET' );
		$controller = new WC_REST_Orders_Controller();

		// Set Order ID.
		$request->set_param( 'id', $order_id );
		$result  = $controller->get_item( $request );
		$order   = isset( $result->data ) ? $result->data : array();
		$order_whitelist_fields = array( 'id', 'status', 'line_items', 'billing', 'shipping', 'pdf_invoice_url' );
		$item_whitelist_fields = array( 'id', 'sku' );
		$new_order = array();

		$search_order  = array( 'line_items', 'pdf_invoice_url' );
		$replace_order = array( 'items', 'invoice_url' );
		foreach ( $order as $key => $value ) {
			if ( in_array( $key, $order_whitelist_fields ) ) {

				$key = str_replace( $search_order, $replace_order, $key );
				$new_order[ $key ] = $value;
			}
		}

		if ( isset( $new_order['items'] ) && ! empty( $new_order['items'] ) ) {
			foreach ( $new_order['items'] as $itemkey => $item ) {
				foreach ( $item as $ikey => $ivalue ) {
					if ( ! in_array( $ikey, $item_whitelist_fields ) ) {
						unset( $new_order['items'][ $itemkey ][ $ikey ] );
					}
				}
			}
		}

		// Set Payment Method.
		$payment_method = isset( $order['payment_method'] ) ? sanitize_text_field( $order['payment_method'] ) : '';
		$payment_method_title = isset( $order['payment_method_title'] ) ? sanitize_text_field( $order['payment_method_title'] ) : '';
		if( $payment_method != ''){
			if( $payment_method_title != ''){
				$payment_method .= ' ('.$payment_method_title.')';
			}
		} elseif( $payment_method_title != ''){
			$payment_method = $payment_method_title;
		}

		$new_order['payment_method'] = $payment_method;

		// Add Email and phone into Shipping.
		$new_order['shipping']['email'] = $new_order['billing']['email'];
		$new_order['shipping']['phone'] = $new_order['billing']['phone'];

		if ( $json ) {
			$new_order = wp_json_encode( $new_order );
		}
		return $new_order;
	}

	/**
	 * Handle a custom meta query var to get orders with the custom meta field.
	 *
	 * @param array $query - Args for WP_Query.
	 * @param array $query_vars - Query vars from WC_Order_Query.
	 * @return array modified $query
	 */
	public function handle_knawatds_custom_query_var( $query, $query_vars ) {
		if ( ! empty( $query_vars['knawat_sync_failed'] ) ) {
			$query['meta_query'][] = array(
				'key'   => '_knawat_sync_failed',
				'value' => esc_attr( $query_vars['knawat_sync_failed'] ),
			);
		}

		return $query;
	}

	/**
	 * Get Knawat Orders which are failed to sync with knawat MP API
	 *
	 * @return Array  Array for order IDs
	 */
	public function get_knawat_failed_orders() {
		// Setup arguments.
		$args = array(
			'type'               => 'shop_order',
			'limit'              => -1,
			'return'             => 'ids',
			'knawat_sync_failed' => 1,
		);

		// Get Order IDs.
		$orders = wc_get_orders( $args );

		return $orders;
	}

	/**
	 * Set Warning on edit order page if sync failed orders are there.
	 *
	 * @return void
	 */
	public function knawatds_edit_shop_order_screen() {
		$current_screen = get_current_screen();
		if ( ! empty( $current_screen ) && 'edit-shop_order' === $current_screen->id ) {
			global $knawatdswc_warnings;
			// Check if Access Token is valid or not.
			$is_valid = knawat_dropshipwc_is_access_token_valid();
			if ( ! $is_valid ) {
				return;
			}

			$sync_fail = $this->get_knawat_failed_orders();
			if ( ! empty( $sync_fail ) ) {
				$knawatdswc_warnings[] = sprintf( '%s <a href="' . wp_nonce_url( admin_url( 'admin-post.php?action=knawatds_order_fail_sync' ), 'knawatds_order_fail_sync_action', 'order_fail_nonce' ) . '">%s</a>',
					__( 'Some orders are not sycronized with knawat.com. Please', 'dropshipping-woocommerce' ),
					__( 'synchronize it now', 'dropshipping-woocommerce' )
				);
			}
		}
	}

	/**
	 * Manually Start sync failed order Sycronization.
	 *
	 * @since    2.0.0
	 */
	public function knawat_start_order_fail_sync() {
		if ( isset( $_GET['order_fail_nonce'] ) && wp_verify_nonce( sanitize_key( $_GET['order_fail_nonce'] ), 'knawatds_order_fail_sync_action' ) ) { // Input var okay.
			global $knawat_dropshipwc, $knawatdswc_errors;
			$is_valid = knawat_dropshipwc_is_access_token_valid();
			if ( $is_valid ) {
				$sync_fail = $this->get_knawat_failed_orders();
				if ( ! empty( $sync_fail ) ) {
					foreach ( $sync_fail as $order_id ) {
						$this->knawatds_order_created_updated( $order_id );
					}
				}
				if ( empty( $knawatdswc_errors ) ) {
					$redirect_url = esc_url_raw(
						add_query_arg(
							array(
								'order_sync' => '1',
							),
							admin_url( 'edit.php?post_type=shop_order' )
						)
					);
					wp_safe_redirect( $redirect_url );
					exit();
				}
			}
			$redirect_url = esc_url_raw(
				add_query_arg(
					array(
						'order_sync' => '0',
					),
					admin_url( 'edit.php?post_type=shop_order' )
				)
			);
			wp_safe_redirect( $redirect_url );
			exit();
		} else {
			wp_die( esc_attr__( 'Nonce failed. Please try again.', 'dropshipping-woocommerce' ) );
		}
	}

}
