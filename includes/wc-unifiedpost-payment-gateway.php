<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WC_UnifiedPayment_Gateway extends WC_Payment_Gateway {
	public function __construct() {
		$this->id                 = WC_UnifiedPayment::$gateway_id;
		$this->icon               = WC_UnifiedPayment::$plugin_icon;
		$this->method_title       = esc_html__( 'Unifiedpost payment', 'payment-gateway-through-unifiedpost' );
		$this->method_description = esc_html__( 'Unifiedpost payment gateway', 'payment-gateway-through-unifiedpost' );
		$this->supports           = [ 'products' ];

		// Load the form fields
		$this->init_form_fields();

		// Load the settings
		$this->init_settings();
		$this->title          = $this->get_option( 'title' );
		$this->description    = $this->get_option( 'description' );
		$this->enabled        = $this->get_option( 'enabled' );
		$this->language       = get_bloginfo("language");
		$this->currency       = get_woocommerce_currency();
		$this->logging        = 'yes' === $this->get_option( 'logging' );
		$this->api_key       = $this->get_option( 'api_key' );
		$this->api_url            = 'https://dolphin.pay-nxt.com/api/v1/items';
		
		$this->redirect_url     = home_url( '/checkout' );
		$this->return_url       = home_url( '/wc-api/unifiedpost-return-url' );
		$this->fail_url         = home_url( '/wc-api/unifiedpost-fail-url' );

		// Hooks
		add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, [ $this, 'process_admin_options' ] );
		add_action( 'woocommerce_api_unifiedpost-return-url', [ $this, 'order_confirm' ] );
		add_action( 'woocommerce_api_unifiedpost-fail-url', [ $this, 'order_failed' ] );
	}

	public function init_form_fields() {
		$this->form_fields = [
			'enabled'               => [
				'title'       => __( 'Enabled/Disabled', 'payment-gateway-through-unifiedpost' ),
				'label'       => __( 'Unifiedpost payment', 'payment-gateway-through-unifiedpost' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no'
			],
			'title'                 => [
				'title'       => __( 'Title', 'payment-gateway-through-unifiedpost' ),
				'type'        => 'text',
				'description' => __( 'This controls the title which the user sees during checkout.', 'payment-gateway-through-unifiedpost' ),
				'default'     => 'Unifiedpost payment',
				'desc_tip'    => true,
			],
			'description'           => [
				'title'       => __( 'Describe', 'payment-gateway-through-unifiedpost' ),
				'type'        => 'textarea',
				'description' => __( 'This controls the description which the user sees during checkout.', 'payment-gateway-through-unifiedpost' ),
				'default'     => __( 'Pay with unifiedpost payment', 'payment-gateway-through-unifiedpost' ),
			],
			'api_key'          => [
				'title' => __( 'Api key', 'payment-gateway-through-unifiedpost' ),
				'type'  => 'text'
			],
			'logging'               => [
				'title'       => __( 'Enabled logging', 'payment-gateway-through-unifiedpost' ),
				'label'       => __( 'Enabled/Disabled', 'payment-gateway-through-unifiedpost' ),
				'type'        => 'checkbox',
				'description' => '',
				'default'     => 'no'
			]
		];
	}

	public function order_confirm() {
		$order_id = intval( sanitize_text_field($_GET['order_id']) );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			$this->log( __( 'Order not found (order_confirm)', 'payment-gateway-through-unifiedpost' ) );
		}

		$order->payment_complete( $order_id );
		WC()->cart->empty_cart();
		
		wp_redirect( $this->get_return_url( $order ) );
		die;
	}

	public function order_failed() {
		$order_id = intval( sanitize_text_field($_GET['order_id']) );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			$this->log( __( 'Order not found (order_failed)', 'payment-gateway-through-unifiedpost' ) );
		}

		$error_code = 'Order failed: ' . sanitize_text_field( $_GET['ErrorCode'] );
		$message    = 'Order failed: ' . sanitize_text_field( $_GET['Message'] );
		$details    = 'Order failed: ' . sanitize_text_field( $_GET['Details'] );

		$this->log( 'Order failed: order_id - ' . $order_id . ', error_code - ' . $error_code . ', message - ' . $message . ', details - ' . $details );
		wc_add_notice( sprintf(__('The currency is not supported by %s. Please contact the administrator', 'payment-gateway-through-unifiedpost'), sanitize_text_field( $_GET['Message'] )), 'error' );

		$order->update_status( 'failed' );

		wp_redirect( $order->get_cancel_order_url() );
		die;
	}

	public function process_payment( $order_id ) {
		// Get order data
		$order         = wc_get_order( $order_id );
		$order_data    = $order->get_data();
		$order_total   = $order->get_total();
		$order_key = $order->get_order_key();
		$order_items   = $order->get_items();
		$order_comment = !empty( $order->get_customer_note() ) && strlen($order->get_customer_note()) <= 50 ? $order->get_customer_note() : 'No comment';

		$supported_currency = ["HRK","RON","EUR","PLN","GBP","CZK","DKK","SEK","HUF","NOK"];

		if ( !in_array($this->currency, $supported_currency) ) {
			wc_add_notice(__('Payment currrency not supported with unifiedpost payment system. Please contact with administrator', 'payment-gateway-through-unifiedpost'), 'error' );
			return;
		}
		
		// Request data
		$request_data = [
			'items' => [
				1 => [
					'paymentAttributes' => [
						"amount" => $order_total,
						"currency" => $this->currency,
						"description" => $order_comment,
						"reconciliationReference" => $order_key,
						"paymentAppRedirectUrls" => [
							"redirectURL" => $this->redirect_url,
							"successURL" => $this->return_url,
							"failureURL" => $this->fail_url,
						]
					],
					'payerAttributes'  => [
						'language' => $order->get_billing_country(),
						'mobileNr' => $this->phoneValidate($order->get_billing_phone()),
						'email' => $order->get_billing_email(),
					]
				]
			]
		];

		$request_result = $this->sendRequest( $this->api_url, $request_data ); 

		if ( !empty($request_result[1]['paymentLinks']['checkout']) ) {
			$desctopUrlRedirect = '?successURL='.$this->return_url.'?order_id='.$order_id.'&failureURL='.$this->fail_url.'?order_id='.$order_id.'&redirectDelay=3';

			$result = [
				'result'   => 'success',
				'redirect' => $request_result[1]['paymentLinks']['checkout'].$desctopUrlRedirect,
			];
		} else {
			$result = [
				'result'        => 'error',
				'error_message' => __('Payment error. Please try get later or contact with administrator', 'payment-gateway-through-unifiedpost'),
			];
		}

		return $result;
	}

	/*
	 * Make curl request
	 *
	 * @api_url, $data  string, array Get request url and array params
	 * @return json
	*/
	private function sendRequest( $api_url, $data ) {
		if ( is_array( $data ) ) {
			$data = json_encode( $data );
		}
		$this->log( 'request ' . $data );
		$response = wp_remote_post( $api_url, [
			'headers' => [
				'Content-Type' => 'application/json',
				'Authorization' => 'OCS-API '.$this->api_key,
			],
			'body' => $data,
		] );

		$body = wp_remote_retrieve_body( $response );

		$this->log( 'Body '.$body );

		$json_out = json_decode( $body, true );

		if ( $response && isset( $response['response']['code'] ) && $response['response']['code'] !== 201 ) {
			$this->log( 'Error request. Error code - ' . $response['response']['code'] . ', error message - ' . $response['response']['message'] );
		}

		return $json_out;
	}

	public function phoneValidate($phone){
		$f_char = substr($phone, 0);
		
		return $f_char === '+' ? $phone : '+'.$phone;
	}

	public function log( $data, $prefix = '' ) {
		if ( $this->logging ) {
			wc_get_logger()->debug( "$prefix " . print_r( $data, 1 ), [ 'source' => $this->id ] );
		}
	}
}

new WC_UnifiedPayment_Gateway;
