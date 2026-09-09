<?php

declare(strict_types=1);

namespace Ifthenpay\WPForms\Builder;

use Ifthenpay\WPForms\Admin\Settings;
use Ifthenpay\WPForms\Api\IfthenpayClient;
use Ifthenpay\WPForms\Api\IfthenpayFormData;
use Ifthenpay\WPForms\Api\IfthenpayPayload;
use Ifthenpay\WPForms\Api\IfthenpayReturn;

if ( ! defined( 'ABSPATH' ) ) {
	die( 'Are you sure?' );
}

class Process
{
	/** @var array<string, mixed>|null */
	private ?array $resolved_context = null;

	/**
	 * Set for the duration of a create_entry_for_payment_unlocked() call made with
	 * $deferNotifications = true, so entry_saved_process() (fired synchronously from inside
	 * that call via WPForms' own process()) knows to stash the notification payload instead
	 * of letting it send immediately. See release_deferred_notifications().
	 */
	private ?int $suppressedNotificationsPaymentId = null;

    public function __construct(
        private string $slug,
    ) {
        add_filter('wpforms_forms_submission_prepare_payment_data', [$this, 'prepare_payment_data'], 10, 3);
        add_filter('wpforms_forms_submission_prepare_payment_meta', [$this, 'prepare_payment_meta'], 10, 3);
        add_filter('wpforms_process_initial_errors', [$this, 'payment_check_process'], 10, 2);
        add_action('wpforms_process_payment_saved', [$this, 'payment_saved_process'], 10, 3);
        add_action('wpforms_process_entry_saved', [$this, 'entry_saved_process'], 10, 5);
        add_action('wpforms_process_complete', [$this, 'payment_confirmation_process'], 10, 4);
        add_action('init', [$this, 'handle_ifthenpay_webhook']);
    }

    /**
     * @param array<string, mixed> $paymentData
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $formData
     * @return array<string, mixed>
     */
    public function prepare_payment_data($paymentData, $fields, $formData): array
    {
        if (empty(Settings::get_form_payment_settings($formData)['enabled'])) {
            return $paymentData;
        }

        $amount = IfthenpayFormData::resolve_amount($fields);

        if ($amount <= 0) {
            $paymentData['gateway'] = 'none';
            $this->stamp_payment_data($paymentData, $fields);
            return $paymentData;
        }

        // A real, pending WPForms payment row was already created up front when the Pay By
        // Link was generated (see handle_process()) — WPForms must not insert a second row for
        // the same attempt, so gateway always stays blank here regardless of outcome. That
        // existing row is confirmed below, and independently by the webhook.
        $paymentData['gateway'] = '';

        $context = $this->get_context($fields);
        $paymentMethod = (string) ($context['payment_method'] ?? '');
        $isSuccessful = !empty($context['successful']);

        $paymentId = $this->get_posted_payment_id();
        if ($paymentId > 0 && $isSuccessful && $paymentMethod !== '') {
            $this->sync_confirmed_payment_method($paymentId, $paymentMethod);
        }

        return $paymentData;
    }

    /**
     * @param array<string, mixed> $paymentData
     * @param array<string, mixed> $fields
     */
    private function stamp_payment_data(array &$paymentData, array $fields): void
    {
        $paymentData['type']        = 'one-time';
        $paymentData['mode']        = isset($paymentData['mode']) && $paymentData['mode'] !== '' ? sanitize_text_field($paymentData['mode']) : 'live';
        $paymentData['status']      = 'completed';
        $name                       = $this->extract_name($fields);
        $paymentData['title']       = $name !== '' ? $name : 'UserID: ' . IfthenpayPayload::generate_customer_id() . ' Purchase';
        $paymentData['customer_id'] = IfthenpayPayload::generate_customer_id();
    }

	 /**
     * @param array<string, mixed> $fields
     * @return string
     */
    private function extract_name(array $fields): string
    {
        foreach ($fields as $field) {
            if (!is_array($field) || ($field['type'] ?? '') !== 'name') {
                continue;
            }

            $value = $field['value'] ?? '';
            if (is_array($value)) {
                $first = trim((string) ($value['first'] ?? ''));
                $last = trim((string) ($value['last'] ?? ''));
                $name = trim($first . ' ' . $last);

                if ($name !== '') {
                    return $name;
                }
            }

            $name = trim(wp_strip_all_tags((string) $value));
            if ($name !== '') {
                return $name;
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $paymentMeta
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $formData
     * @return array<string, mixed>
     */
    public function prepare_payment_meta($paymentMeta, $fields, $formData): array
    {
        $paymentMeta = is_array($paymentMeta) ? $paymentMeta : [];
        $fields = is_array($fields) ? $fields : [];
        $formData = is_array($formData) ? $formData : [];

        if (empty(Settings::get_form_payment_settings($formData)['enabled'])) {
            return $paymentMeta;
        }

        $returnData = IfthenpayReturn::get_pay_now_return_data_from_request();
        $context = $this->get_context($fields);

        $paymentMethod = (string) $context['payment_method'];
        $customerName = $this->extract_name($fields);

        if ($paymentMethod !== '') {
            $paymentMeta['method_type'] = $paymentMethod;
		}

        if ($customerName !== '') {
            $paymentMeta['customer_name'] = $customerName;
        }

        // A client-reported "success" return is not proof of payment (see
        // IfthenpayReturn::is_successful_pay_now_return()'s docblock) — this log entry is
        // purely descriptive of what the browser reported, never a claim that the payment has
        // actually been confirmed. Only handle_webhook_success() may ever mark a payment
        // "completed".
        $paymentMeta['log'] = wp_json_encode([
            'value' => IfthenpayReturn::is_successful_pay_now_return($returnData)
                ? __('ifthenpay pay-by-link return received (pending confirmation).', 'ifthenpay-payments-for-wpforms')
                : __('ifthenpay pay-by-link initialized.', 'ifthenpay-payments-for-wpforms'),
            'date' => gmdate('Y-m-d H:i:s'),
        ]);

        return $paymentMeta;
    }

    public function payment_check_process(array $errors, array $formData): array
    {
        $formData = is_array($formData) ? $formData : [];

        if (false === wpforms_has_field_type(IFTP_PBL_FIELD_TYPE, $formData, false)) {
            return $errors;
        }

        $reason = Settings::get_unusable_reason($formData);
        $formId = isset($formData['id']) ? (int) $formData['id'] : 0;

        if ($reason === '') {
            $returnData = IfthenpayReturn::get_pay_now_return_data_from_request();
			$fields = isset($formData['fields']) && is_array($formData['fields']) ? $formData['fields'] : [];
            $context = $this->get_context($fields);
			$shouldBlock = !empty($returnData) && IfthenpayReturn::should_block_pay_now_return($returnData, $context);

            if ($shouldBlock) {
				$errors[$formId] = [
                    'header' => __('Payment Failed', 'ifthenpay-payments-for-wpforms'),
                ];
			}
			return $errors;
        }

        $errors[$formId] = [
            'header' => $reason,
        ];

        return $errors;
    }

    /**
     * Persist final payment details after WPForms creates the payment row.
     * @param int $paymentId
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $formData
     * @return void
     */
    public function payment_saved_process(int $paymentId, array $fields, array $formData): void
    {
        // With the payment row now always created up front (see handle_process()),
        // WPForms' own insert — and this action — is skipped entirely for real ifthenpay Pay By
        // Link attempts (prepare_payment_data() always blanks "gateway" for them). This remains
        // registered only as a safety net for any submission path that still reaches WPForms'
        // own payment insert with this gateway attached.
        if ($paymentId <= 0 || false === wpforms_has_field_type(IFTP_PBL_FIELD_TYPE, $formData, false)) {
            return;
        }

        $context = $this->get_context($fields);
        $paymentMethod = (string) $context['payment_method'];

        $this->sync_confirmed_payment_method($paymentId, $paymentMethod);
    }

    /**
     * Link WPForms Pro entries with the payment row created during submission.
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $formData
     * @param int $entryId
     * @param int $paymentId
     * @return void
     */
    public function entry_saved_process(array $fields, array $entry, array $formData, int $entryId, int $paymentId): void
    {
        if ($entryId <= 0 || false === wpforms_has_field_type(IFTP_PBL_FIELD_TYPE, $formData, false)) {
            return;
        }

        // WPForms only creates its own payment row (and passes a non-zero $paymentId here) when
        // our own row wasn't already in play. Every real ifthenpay Pay By Link attempt already
        // has its row created up front (see handle_process()), so resolve it the same way the
        // rest of this class does rather than trusting WPForms' own $paymentId.
        $resolvedFromPostedId = $paymentId <= 0;
        if ($resolvedFromPostedId) {
            $paymentId = $this->get_posted_payment_id();
        }

        if ($paymentId <= 0) {
            return;
        }

        // The posted payment_id is a plain client-editable hidden field — unlike a fresh id
        // WPForms itself just inserted moments ago in this same request, it can't be trusted on
        // its own to target a row for mutation without first confirming it's actually ours.
        if ($resolvedFromPostedId && ! $this->owns_wpforms_payment($paymentId)) {
            return;
        }

        $context = $this->get_context($fields);
        $paymentMethod = (string) $context['payment_method'];

        $this->update_payment_row($paymentId, ['entry_id' => $entryId]);

        if ($this->suppressedNotificationsPaymentId === $paymentId) {
            $this->store_deferred_notification_payload($paymentId, $fields, $entry, $formData, $entryId);
        }

        $this->sync_confirmed_payment_method($paymentId, $paymentMethod);

        $this->update_stored_payment_summary(
            $paymentId,
            [
                'entry_id' => $entryId,
                'form_id' => isset($formData['id']) ? (int) $formData['id'] : 0,
            ]
        );

        delete_option( $this->payment_summary_option_key( $paymentId ) );
    }

    /**
     * Sync the payment method label onto a WPForms payment row / entry meta from a
     * client-observed return signal. This never marks a payment "completed" — only
     * handle_webhook_success() may do that, since it's the only source that independently
     * verifies the payment with ifthenpay (anti-phishing key + amount match). A client-supplied
     * "successful" signal proves nothing on its own; see
     * IfthenpayReturn::is_successful_pay_now_return()'s docblock.
     */
    private function sync_confirmed_payment_method(int $paymentId, string $paymentMethod): void
    {
        if ($paymentMethod !== '') {
            $this->update_payment_method($paymentId, $paymentMethod);
        }
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $entry
     * @param array<string, mixed> $formData
     * @param int $entryId
     * @return void
     */
    public function payment_confirmation_process(array $fields, array $entry, array $formData, int $entryId): void
    {
        unset($entry);

        if (false === wpforms_has_field_type(IFTP_PBL_FIELD_TYPE, $formData, false)) {
            return;
        }

        $paymentId = $this->get_posted_payment_id();
        $context = $this->get_context($fields);

        // Same reasoning as entry_saved_process(): a posted payment_id is client-editable, so
        // confirm it actually names a row this gateway owns before writing to it.
        if ($paymentId > 0 && ! $this->owns_wpforms_payment($paymentId)) {
            return;
        }

        if ($paymentId > 0 && function_exists('wpforms')) {
            $paymentMeta = wpforms()->get('payment_meta');
            if ($paymentMeta) {
                $paymentMethod = (string) $context['payment_method'];
                if ($paymentMethod !== '') {
                    $paymentMeta->update_or_add($paymentId, 'method_type', $paymentMethod);
                } else {
					return;
				}
            }

            if ($entryId > 0) {
                $this->update_payment_row($paymentId, ['entry_id' => $entryId]);
            }
        }
    }

    /**
	 * Handle the payment process from form submission and create a payment session.
	 * @param array<string, mixed> $fields The submitted form fields
	 * @param array<string, mixed> $entry The entry data
	 * @param array<string, mixed> $form_data The form configuration data
	 * @return array<string, mixed> Returns success state, message, and payment data if created
	 */
	public function handle_process( array $fields, array $entry, array $form_data, bool $is_ajax_request = false ): array {
		try {
			if ( ! $this->should_handle( $form_data, $is_ajax_request ) ) {
				return array(
					'success' => true,
					'handled' => false,
				);
			}

			$payment_id = absint( filter_input( INPUT_POST, 'iftp_pbl_payment_id', FILTER_SANITIZE_NUMBER_INT ) );

			if ( $payment_id > 0 ) {
				return array(
					'success' => true,
					'handled' => false,
				);
			}

			$backoffice_key = Settings::get_backoffice_key();
			if ( $backoffice_key === '' ) {
				return array(
					'success' => false,
					'message' => 'Gateway not configured',
				);
			}

			$config = isset( $form_data['payments'][ $this->slug ] ) && is_array( $form_data['payments'][ $this->slug ] )
				? $form_data['payments'][ $this->slug ]
				: [];

			$gateway_key = isset( $config['gateway_key'] ) ? trim( (string) $config['gateway_key'] ) : '';
			if ( $gateway_key === '' ) {
				return array(
					'success' => false,
					'message' => 'Gateway key not configured',
				);
			}

			$amount = IfthenpayFormData::resolve_amount( $fields );
			if ( ! ( $amount >= 0 && $amount ) ) {
				return array(
					'success' => false,
					'message' => 'Amount cannot be lower than 0',
				);
			}

			if ( $amount <= 0 ) {
				return array(
					'success'    => true,
					'skip_payment' => true,
				);
			}

			$methods_config         = IfthenpayPayload::get_gateway_methods_config( $config, $gateway_key );
			$selected_method_entity = IfthenpayPayload::get_selected_method_entity( $config, $methods_config );
			$selected_method_code   = IfthenpayPayload::get_selected_method_code( $config, $methods_config );
			$description            = 'Payment Gateway';
			$expire_days            = isset( $config['expire_days'] ) ? max( 1, absint( $config['expire_days'] ) ) : 1;
			$accounts               = IfthenpayPayload::build_accounts_string( $methods_config );

			if ( $accounts === '' ) {
				return array(
					'success' => false,
					'message' => 'No payment methods configured',
				);
			}

			$summary = IfthenpayFormData::extract_payment_summary( $fields, $entry, $form_data, $this->slug, $selected_method_entity );
			$name    = $this->extract_name( $fields );
			$title   = $name !== '' ? $name : 'UserID: ' . IfthenpayPayload::generate_customer_id() . ' Purchase';

			// Reserves a WPForms payment row up front so the pending attempt is tracked (and its
			// id can be used as the pbl_ref/order number shown to the customer) before ifthenpay
			// has confirmed anything. The WPForms *entry* itself is created right after, back in
			// ajax_create_pay_button_payment() — see create_entry_for_payment() — visible in the
			// entries list immediately as "pending", but with its notification emails deferred
			// until the payment actually resolves.
			$form_id    = isset( $form_data['id'] ) ? (int) $form_data['id'] : 0;
			$payment_id = $this->create_wpforms_payment( $form_id, $summary, $title );
			if ( $payment_id <= 0 ) {
				return array(
					'success' => false,
					'message' => 'Unable to reserve payment id',
				);
			}

			$summary['status']     = 'pending';
			$summary['payment_id'] = $payment_id;
			$summary['title']      = $title;

			$this->update_stored_payment_summary( $payment_id, $summary );

			$pbl_ref = $this->generate_pbl_ref( $payment_id );

			$base_url = wp_get_referer() ?: home_url( '/' );
			$gateway_urls = IfthenpayPayload::build_gateway_urls( $payment_id, $base_url );

			$payload = IfthenpayPayload::build_pay_by_link_payload(
				array(
					'id'              => $pbl_ref,
					'amount'          => $amount,
					'description'     => $description,
					'accounts'        => $accounts,
					'expire_days'     => $expire_days,
					'success_url'     => $gateway_urls['success_url'],
					'error_url'       => $gateway_urls['error_url'],
					'cancel_url'      => $gateway_urls['cancel_url'],
					'locale'          => get_locale(),
					'callback_url'    => $gateway_urls['callback_url'],
					'selected_method' => $selected_method_code,
				)
			);

			$data = IfthenpayClient::create_payment_link( $gateway_key, $payload );

			if ( ! is_array( $data ) || empty( $data['RedirectUrl'] ) ) {
				$this->mark_wpforms_payments_status( (string) $payment_id, 'failed' );

				return array(
					'success' => false,
					'message' => 'Failed to create payment link',
				);
			}

			$remote_redirect_url = esc_url_raw( (string) $data['RedirectUrl'] );

			if ( $remote_redirect_url === '' ) {
				$this->mark_wpforms_payments_status( (string) $payment_id, 'failed' );

				return array(
					'success' => false,
					'message' => 'Failed to generate payment session',
				);
			}

			$this->store_webhook_context( $pbl_ref, $gateway_key, $amount, $payment_id, $remote_redirect_url );
			$this->mark_wpforms_payments_status( (string) $payment_id, 'pending' );

			$payload_data = IfthenpayPayload::build_pay_by_link_session(
				$payment_id,
				$remote_redirect_url,
				$base_url
			);

			return array(
				'success' => true,
				'data'    => $payload_data,
			);
		} catch ( \Throwable $e ) {
			return array(
				'success' => false,
				'message' => 'An error occurred',
			);
		}
	}

	/**
	 * Handle AJAX request to create a Pay By Link payment session.
	 * Validates form, gateway configuration, and returns a payment redirect URL.
	 * @return void
	 */
	public function ajax_create_pay_button_payment(): void {
		try {
			check_ajax_referer( 'iftp_pbl_frontend', 'nonce' );

			$form_id      = isset( $_POST['form_id'] )      ? absint( $_POST['form_id'] )                                                  : 0;
			$gateway_key  = isset( $_POST['gateway_key'] )  ? sanitize_text_field( wp_unslash( (string) $_POST['gateway_key'] ) )          : '';
			$form_payload = '';
			if ( isset( $_POST['form_payload'] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Serialized query-string payload is intentionally preserved for wp_parse_str(); values are sanitized after parsing in build_fields_from_request().
				$form_payload = wp_unslash( (string) $_POST['form_payload'] );
			}

			if ( $form_id <= 0 || $gateway_key === '' || $form_payload === '' ) {
				$error_msg = 'Invalid form data';
				if ( $form_id <= 0 ) {
					$error_msg = 'Invalid form ID: ' . $form_id;
				} elseif ( $gateway_key === '' ) {
					$error_msg = 'Gateway key is missing';
				} elseif ( $form_payload === '' ) {
					$error_msg = 'Form payload is missing';
				}
				wp_send_json_error( array( 'message' => $error_msg ) );
			}

			$loaded_form = IfthenpayFormData::load_form_data( $form_id );
			$form_data   = $loaded_form['form_data'];

			if ( empty( $form_data ) ) {
				wp_send_json_error( array( 'message' => 'Form not found or not accessible' ) );
			}

			$submitted_fields = IfthenpayFormData::build_fields_from_request( $form_data, $form_payload );

			$entry            = array(
				'fields' => $submitted_fields,
			);

			$result = $this->handle_process( $submitted_fields, $entry, $form_data, true );

			if ( empty( $result['success'] ) ) {
				wp_send_json_error(
					array(
						'message' => isset( $result['message'] ) ? (string) $result['message'] : 'An error occurred',
					)
				);
			}

			if ( isset( $result['handled'] ) && ! $result['handled'] ) {
				wp_send_json_error( array( 'message' => 'Form not configured for payments' ) );
			}

			if ( ! empty( $result['skip_payment'] ) ) {
				wp_send_json_success(
					array(
						'skip_payment' => true,
					)
				);
			}

			if ( ! isset( $result['data'] ) || ! is_array( $result['data'] ) || empty( $result['data'] ) ) {
				wp_send_json_error(
					array(
						'message' => 'Failed to create payment session',
					)
				);
			}

			$reserved_payment_id = isset( $result['data']['payment_id'] ) ? (int) $result['data']['payment_id'] : 0;
			if ( $reserved_payment_id > 0 ) {
				// The raw, complete submission — needed later to create the real WPForms entry
				// once payment is confirmed, whether that happens on a return visit or, for a
				// Multibanco/Payshop reference paid after the customer has left, only via webhook.
				$this->store_pending_entry_payload( $reserved_payment_id, $form_id, $form_payload );

				// Create the entry now, visible in the entries list right away as "pending",
				// rather than waiting for payment confirmation — notifications are deferred
				// until the payment actually resolves (see release_deferred_notifications()),
				// so the merchant/customer aren't emailed about an unpaid attempt.
				$this->create_entry_for_payment( $reserved_payment_id, true );
			}

			wp_send_json_success( $result['data'] );
		} catch ( \Throwable $e ) {
			wp_send_json_error(
				array(
					'message' => 'Unable to create the payment link. Please review the ifthenpay configuration and try again.',
				)
			);
		}
	}

	/**
	 * Report a WPForms payment's status after the browser returns from ifthenpay's hosted
	 * payment page.
	 *
	 * IMPORTANT: a client-POSTed "success" outcome is never, by itself, proof of payment.
	 * IfthenpayPayload::build_gateway_urls() builds success/error/cancel_url as a plain,
	 * unsigned query string — nothing here is signed or verified by ifthenpay, so anyone can
	 * forge an identical POST straight to admin-ajax.php (this endpoint is necessarily
	 * `nopriv`, and its nonce is only tied to viewing the form, not to a specific payment).
	 * This method therefore never mutates a payment to "completed"; on a "success" return it
	 * only reports the payment's real, current status. handle_webhook_success() — which
	 * independently verifies the payment server-to-server with ifthenpay's anti-phishing key
	 * and amount — is the only place that may ever mark a payment "completed". Until that
	 * webhook fires, the status here stays "pending" and the frontend keeps polling (see
	 * pollPaymentOutcome() in frontend.js).
	 */
	public function ajax_verify_payment(): void {

		check_ajax_referer('iftp_pbl_frontend', 'nonce');

		$payment_id    = isset( $_POST['payment_id'] )    ? absint( $_POST['payment_id'] )                                              : 0;
		$return_action = isset( $_POST['return_action'] ) ? sanitize_text_field( wp_unslash( (string) $_POST['return_action'] ) )       : '';

		if ($payment_id <= 0) {
			wp_send_json_error([
				'message' => __('Missing required parameters.', 'ifthenpay-payments-for-wpforms'),
			]);
		}

		if ($return_action === 'cancel') {
			$this->respond_with_status($payment_id, 'cancelled');
		}

		if ($return_action === 'error') {
			$this->respond_with_status($payment_id, 'failed');
		}

		// 'success' (and any other/unknown action) only ever reports the payment's real,
		// current status — never mutates it to "completed" from client-POSTed data. See this
		// method's docblock.
		$this->respond_with_current_status($payment_id);
	}

	/**
	 * Receive ifthenpay's asynchronous payment-notification webhook.
	 *
	 * Registered against ifthenpay via IfthenpayClient::activate_callback() (see
	 * Payments::activate_ifthenpay_callback()). Confirms payments server-to-server,
	 * independent of the customer's browser — this is what actually resolves
	 * Multibanco/Payshop references that get paid after the customer has left the site.
	 */
	public function handle_ifthenpay_webhook(): void {
		if ( ! $this->is_ifthenpay_callback_request() ) {
			return;
		}

		if ( ( $_SERVER['REQUEST_METHOD'] ?? '' ) !== 'GET' || file_get_contents( 'php://input' ) !== '' ) {
			status_header( 400 );
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		if ( isset( $_GET['status'], $_GET['ref'] ) ) {
			$this->handle_webhook_failure();
			exit;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		if ( isset( $_GET['ref'], $_GET['apk'], $_GET['val'], $_GET['mtd'], $_GET['req'] ) ) {
			$this->handle_webhook_success();
			exit;
		}

		status_header( 400 );
		exit;
	}

	/**
	 * Matches requests against the query-var marker built by
	 * IfthenpayPayload::build_gateway_urls() (?iftp_wpforms_cb=wpforms on the site
	 * root). A query string on the home URL is used rather than a bare invented path so
	 * this is reachable regardless of the site's permalink structure — see the docblock
	 * on IfthenpayPayload::CALLBACK_QUERY_VAR for why a bare path isn't safe to rely on.
	 */
	private function is_ifthenpay_callback_request(): bool {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		$marker = isset( $_GET[ IfthenpayPayload::CALLBACK_QUERY_VAR ] )
			? sanitize_text_field( wp_unslash( (string) $_GET[ IfthenpayPayload::CALLBACK_QUERY_VAR ] ) )
			: '';

		return $marker !== '' && $marker === IfthenpayPayload::callback_path_segment();
	}

	private function handle_webhook_failure(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		$pbl_ref = sanitize_text_field( wp_unslash( (string) ( $_GET['ref'] ?? '' ) ) );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		$status = strtolower( sanitize_text_field( wp_unslash( (string) ( $_GET['status'] ?? '' ) ) ) );

		if ( $pbl_ref === '' || ! in_array( $status, [ 'cancelled', 'error' ], true ) ) {
			status_header( 404 );
			return;
		}

		// A stored context proves this ref was actually issued by this plugin for an ifthenpay
		// Pay By Link — without it, an unauthenticated ref guess could flip the status of any
		// WPForms payment on the site.
		$context = $this->get_webhook_context( $pbl_ref );
		if ( empty( $context['gateway_key'] ) ) {
			status_header( 404 );
			return;
		}

		$new_status = $status === 'cancelled' ? 'cancelled' : 'failed';
		$payment_id = isset( $context['payment_id'] ) ? (int) $context['payment_id'] : 0;

		if ( $this->can_update_wpforms_payment( $payment_id ) ) {
			$this->mark_wpforms_payments_status( (string) $payment_id, $new_status );
		}

		$this->update_webhook_context_status( $pbl_ref, $context, $new_status );

		status_header( 200 );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal acknowledgement, not user input.
		echo 'OK';
	}

	private function handle_webhook_success(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		$pbl_ref = sanitize_text_field( wp_unslash( (string) ( $_GET['ref'] ?? '' ) ) );
		$context = $this->get_webhook_context( $pbl_ref );

		if ( $pbl_ref === '' || empty( $context['gateway_key'] ) ) {
			status_header( 404 );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		// A raw, un-percent-encoded "+" in a query string is decoded to a space by PHP before
		// $_GET is even populated — restoring it here protects a legitimate base64 apk (whose
		// alphabet includes "+") from being silently corrupted if it wasn't percent-encoded.
		$raw_apk             = strtr( sanitize_text_field( wp_unslash( (string) $_GET['apk'] ) ), ' ', '+' );
		$decoded_gateway_key = trim( (string) base64_decode( $raw_apk, true ) );
		if ( ! hash_equals( (string) $context['gateway_key'], $decoded_gateway_key ) ) {
			status_header( 403 );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		$amount          = (float) sanitize_text_field( wp_unslash( (string) $_GET['val'] ) );
		$expected_amount = isset( $context['amount'] ) ? (float) $context['amount'] : 0.0;
		if ( $expected_amount > 0 && abs( $amount - $expected_amount ) > 0.01 ) {
			status_header( 409 );
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		$payment_method = sanitize_text_field( wp_unslash( (string) $_GET['mtd'] ) );

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- external gateway webhook; ifthenpay cannot carry a WP nonce.
		$request_id = sanitize_text_field( wp_unslash( (string) $_GET['req'] ) );

		$payment_id = isset( $context['payment_id'] ) ? (int) $context['payment_id'] : 0;

		if ( $this->can_update_wpforms_payment( $payment_id, [ 'pending', 'cancelled', 'failed' ] ) ) {
			$this->update_payment_method( $payment_id, $payment_method );
			$this->mark_wpforms_payments_status( (string) $payment_id, 'completed' );

			$this->update_stored_payment_summary(
				$payment_id,
				[
					'verified_payment_method' => $payment_method,
					'verified_successful'     => true,
					'verified_at'             => time(),
				]
			);

			// The entry itself already exists (created up front as "pending" — see
			// ajax_create_pay_button_payment()) with its notifications held back; now that the
			// payment has actually resolved, send them.
			$this->release_deferred_notifications( $payment_id );

			// The Pay by Link note was already added when the entry was created; add ifthenpay's
			// own request id now, since it only exists once the payment has actually resolved.
			$this->add_ifthenpay_request_id_note( $payment_id, $request_id, $amount );
		}

		// The payment row was created up front (see handle_process()), so it always exists by the
		// time this webhook can arrive. create_entry_for_payment() is idempotent, so it's safe to
		// attempt regardless of whether the browser already confirmed this payment itself — this
		// is what lets a Multibanco/Payshop reference paid days after the customer left still
		// produce a normal WPForms entry. In the ordinary case the entry (and its deferred
		// notifications, released above) already exist by the time this runs; this call only
		// still does real work for a submission that somehow never got its up-front entry (e.g.
		// an older payment created before this plugin version), where notifications fire inline.
		if ( $payment_id > 0 ) {
			$this->create_entry_for_payment( $payment_id );
		}

		$context['payment_method'] = $payment_method;
		$this->update_webhook_context_status( $pbl_ref, $context, 'completed' );

		status_header( 200 );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static literal acknowledgement, not user input.
		echo 'OK';
	}

	/**
	 * Only ever mutate a WPForms payment row from an unauthenticated webhook when it can be
	 * confirmed to (a) still exist, (b) belong to this gateway, and (c) currently sit in one of
	 * $allowedStatuses — this is the safety net behind the pbl_ref scheme: even in the unlikely
	 * event a stored payment_id turns out to be stale (e.g. WPForms' own row-id counter reused
	 * it for something else), a row that already resolved through another gateway/attempt is
	 * left untouched. Defaults to "pending" only; handle_webhook_success() widens this to also
	 * accept "cancelled"/"failed", since ifthenpay can still report a reference as paid after an
	 * earlier failure/cancellation notification (e.g. a Multibanco reference paid after expiry
	 * was first reported as cancelled) — completed is never in the allowed set, so a stray
	 * webhook can't downgrade a payment that's already resolved.
	 *
	 * @param array<int, string> $allowedStatuses
	 */
	private function can_update_wpforms_payment( int $payment_id, array $allowedStatuses = [ 'pending' ] ): bool {
		if ( $payment_id <= 0 || ! function_exists( 'wpforms' ) ) {
			return false;
		}

		$payment = wpforms()->obj( 'payment' )->get( $payment_id, array( 'cap' => false ) );
		if ( ! $payment ) {
			return false;
		}

		$gateway = isset( $payment->gateway ) ? (string) $payment->gateway : '';
		$status  = isset( $payment->status ) ? (string) $payment->status : '';

		return $gateway === $this->slug && in_array( $status, $allowedStatuses, true );
	}

	/**
	 * Looser ownership check for paths (entry_saved_process()) that legitimately need to update
	 * a payment row regardless of its current status — unlike can_update_wpforms_payment(), a row
	 * already marked "completed" earlier in the same request (see prepare_payment_data(), which
	 * runs first) is still fair game here. Still confirms the row exists and actually belongs to
	 * this gateway before trusting a posted payment_id to target it.
	 */
	private function owns_wpforms_payment( int $payment_id ): bool {
		if ( $payment_id <= 0 || ! function_exists( 'wpforms' ) ) {
			return false;
		}

		$payment = wpforms()->obj( 'payment' )->get( $payment_id, array( 'cap' => false ) );
		if ( ! $payment ) {
			return false;
		}

		return ( isset( $payment->gateway ) ? (string) $payment->gateway : '' ) === $this->slug;
	}

	/**
	 * The reference sent to ifthenpay as the Pay By Link "id" and echoed back as the webhook's
	 * "ref". ifthenpay's own hosted payment page displays this value back to the customer as
	 * "ID #..." — since that's the only "id" ifthenpay exposes at all, it has to be the same
	 * value used for webhook lookups; there's no way to show a customer-facing id without it also
	 * being the tracking reference. Using the WPForms payment id here (rather than an opaque
	 * random string) is what makes that displayed ID match the numeric order the customer/site
	 * owner already sees everywhere else (the entry, the payment record, "Order #18").
	 *
	 * Note: this makes `ref` on the *unauthenticated* handle_webhook_failure() endpoint a small,
	 * guessable sequential number rather than a 15-character random string. That endpoint has no
	 * signature check (ifthenpay's cancel/error notifications don't carry one, unlike its success
	 * callback's `apk`), so this trades a little security-through-obscurity for a correct-looking
	 * id; the actual damage a guessed ref allows is limited to flipping a still-pending payment to
	 * "cancelled" — see can_update_wpforms_payment() — which a genuine later success webhook can
	 * still correct.
	 */
	private function generate_pbl_ref( int $payment_id ): string {
		return (string) $payment_id;
	}

	/**
	 * Persist everything needed to validate and, later, act on a future webhook call for this
	 * Pay By Link attempt. Stored as a plain (non-autoloaded) option rather than a transient:
	 * Multibanco/Payshop references can be paid days after creation, well past a transient's
	 * typical lifetime. Left in place (status updated, not deleted) once resolved, so a payment
	 * that came in with no linked WPForms row stays visible rather than disappearing.
	 */
	private function store_webhook_context( string $pbl_ref, string $gateway_key, float $amount, int $payment_id, string $redirect_url = '' ): void {
		if ( $pbl_ref === '' || $gateway_key === '' ) {
			return;
		}

		update_option(
			$this->get_webhook_context_option_key( $pbl_ref ),
			[
				'gateway_key'  => $gateway_key,
				'amount'       => $amount,
				'payment_id'   => $payment_id,
				'redirect_url' => $redirect_url,
				'status'       => 'pending',
				'created_at'   => time(),
			],
			false
		);
	}

	/**
	 * @param array<string, mixed> $context
	 */
	private function update_webhook_context_status( string $pbl_ref, array $context, string $status ): void {
		if ( $pbl_ref === '' ) {
			return;
		}

		$context['status']      = $status;
		$context['resolved_at'] = time();

		update_option( $this->get_webhook_context_option_key( $pbl_ref ), $context, false );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_webhook_context( string $pbl_ref ): array {
		if ( $pbl_ref === '' ) {
			return [];
		}

		$context = get_option( $this->get_webhook_context_option_key( $pbl_ref ), [] );
		return is_array( $context ) ? $context : [];
	}

	private function get_webhook_context_option_key( string $pbl_ref ): string {
		return 'iftp_pbl_webhook_ctx_' . $pbl_ref;
	}

	/**
	 * Persist the complete raw submission (every field, not just the payment-relevant ones
	 * IfthenpayFormData::build_fields_from_request() extracts) for a Pay By Link attempt, so
	 * create_entry_for_payment() can later replay it through WPForms' real submission pipeline
	 * — this is what lets the real entry (name, email, every answer) get created even when the
	 * confirmation that triggers it is an asynchronous webhook with no browser present at all.
	 */
	private function store_pending_entry_payload( int $payment_id, int $form_id, string $form_payload ): void {
		if ( $payment_id <= 0 || $form_payload === '' ) {
			return;
		}

		update_option(
			$this->pending_entry_payload_option_key( $payment_id ),
			[
				'form_id'      => $form_id,
				'form_payload' => $form_payload,
			],
			false
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private function get_pending_entry_payload( int $payment_id ): array {
		if ( $payment_id <= 0 ) {
			return [];
		}

		$payload = get_option( $this->pending_entry_payload_option_key( $payment_id ), [] );
		return is_array( $payload ) ? $payload : [];
	}

	private function clear_pending_entry_payload( int $payment_id ): void {
		if ( $payment_id <= 0 ) {
			return;
		}

		delete_option( $this->pending_entry_payload_option_key( $payment_id ) );
	}

	private function pending_entry_payload_option_key( int $payment_id ): string {
		return 'iftp_pbl_entry_payload_' . $payment_id;
	}

	/**
	 * Create the real WPForms entry for a Pay By Link payment, using the raw submission captured
	 * when the link was generated (see store_pending_entry_payload()). Idempotent — a no-op if
	 * this payment already has an entry, which is the common case for every call except the very
	 * first: ajax_create_pay_button_payment() now calls this immediately (with
	 * $deferNotifications = true) so the entry exists and shows up in the entries list right away
	 * as "pending", rather than waiting for payment confirmation. The later calls from
	 * ajax_verify_payment() / handle_webhook_success() therefore just confirm the entry is there
	 * (or create it, for an older payment made before this behavior existed) — the notification
	 * emails deferred by that first call are what release_deferred_notifications() sends once the
	 * payment actually resolves.
	 *
	 * This drives WPForms' own wpforms()->obj('process')->process() — the exact same pipeline a
	 * live submission goes through (field validation/formatting for every field type, honeypot,
	 * spam filtering, notifications, confirmations) — rather than hand-building an entry, so
	 * behavior for any field type (including third-party ones) matches a normal submission.
	 * WPForms' own nonce check inside that pipeline only applies to logged-in users, so this
	 * works for a guest checkout confirmed days later without a valid nonce — the overwhelmingly
	 * common case for a public payment form.
	 *
	 * Known limitation: a file upload field cannot survive this — $form.serialize() (used to
	 * capture the original submission) never includes <input type="file">, so a required file
	 * field will fail validation on replay (no entry gets created; the payload option is left in
	 * place rather than silently dropped) and an optional one is simply missing from the entry.
	 * There is no fix for this short of capturing and durably storing the uploaded file itself at
	 * link-creation time.
	 */
	private function create_entry_for_payment( int $payment_id, bool $deferNotifications = false ): int {
		if ( $payment_id <= 0 || ! function_exists( 'wpforms' ) ) {
			return 0;
		}

		// ajax_create_pay_button_payment() (up front, deferred notifications) and
		// handle_webhook_success() (async, for older payments made before entries were created
		// up front) can both reach this method for the same payment_id — claim the lock first so
		// a race can't have both pass the entry_id-empty check below and create a duplicate entry
		// with duplicate notifications.
		if ( ! $this->acquire_entry_creation_lock( $payment_id ) ) {
			return 0;
		}

		try {
			return $this->create_entry_for_payment_unlocked( $payment_id, $deferNotifications );
		} finally {
			delete_option( $this->entry_creation_lock_key( $payment_id ) );
		}
	}

	/**
	 * Acquire the lock guarding create_entry_for_payment_unlocked() as a single INSERT into a
	 * UNIQUE-keyed option, not a transient's separate get-then-set. A transient's check and write
	 * are two round trips — two requests (the browser's ajax_verify_payment() poll and ifthenpay's
	 * handle_webhook_success() webhook) can both observe "unlocked" before either one writes,
	 * letting both replay the same submission and each create their own entry. add_option()
	 * fails atomically when the row already exists — enforced by the database's unique index, not
	 * by this code's own timing — so only one caller can ever win it.
	 */
	private function acquire_entry_creation_lock( int $payment_id ): bool {
		$lock_key = $this->entry_creation_lock_key( $payment_id );

		if ( add_option( $lock_key, time(), '', 'no' ) ) {
			return true;
		}

		// A lock this old can only be left over from a request that crashed or timed out before
		// reaching the finally{} cleanup above — a real in-progress replay finishes in well under
		// a second — so it's safe to reclaim rather than leave this payment permanently stuck.
		$existing = get_option( $lock_key );
		if ( is_numeric( $existing ) && (int) $existing < time() - 5 * MINUTE_IN_SECONDS ) {
			delete_option( $lock_key );
			return add_option( $lock_key, time(), '', 'no' );
		}

		return false;
	}

	private function entry_creation_lock_key( int $payment_id ): string {
		return 'iftp_pbl_entry_lock_' . $payment_id;
	}

	private function create_entry_for_payment_unlocked( int $payment_id, bool $deferNotifications = false ): int {
		$payment = wpforms()->obj( 'payment' )->get( $payment_id, array( 'cap' => false ) );
		if ( ! $payment ) {
			return 0;
		}

		// Already created by an earlier call (e.g. the browser confirmed it first and the webhook
		// is only now arriving) — idempotent, so simply hand back the existing id.
		if ( ! empty( $payment->entry_id ) ) {
			return (int) $payment->entry_id;
		}

		$payload = $this->get_pending_entry_payload( $payment_id );
		if ( empty( $payload['form_payload'] ) ) {
			// This payment never got a raw submission captured to replay.
			return 0;
		}

		$submitted = [];
		wp_parse_str( (string) $payload['form_payload'], $submitted );

		$entry_post = isset( $submitted['wpforms'] ) && is_array( $submitted['wpforms'] ) ? $submitted['wpforms'] : [];
		if ( empty( $entry_post ) ) {
			return 0;
		}

		$process = wpforms()->obj( 'process' );
		if ( ! $process || ! method_exists( $process, 'process' ) ) {
			return 0;
		}

		// Two behaviors of process() only make sense for a live HTTP request and must be
		// suspended for the duration of this replay:
		// - it treats a real, out-of-band POST (which technically this is, from admin-ajax.php
		//   or the fully-server-side webhook) to an ajax_submit-enabled form as spam, when Modern
		//   Anti-Spam is also on — legitimate here, since the payment webhook already vouches for
		//   this submission.
		// - a "Redirect"/"Page" confirmation type ends the request with wp_redirect()+exit — fatal
		//   inside an AJAX response or the webhook's own 200 OK — so the redirect URL is forced
		//   empty, which routes process() through its normal no-redirect (message) branch instead.
		$bypass_direct_post_check = static fn () => true;
		$suppress_confirmation_redirect = static fn () => '';
		// Only used when $deferNotifications is true — see the property's docblock and
		// release_deferred_notifications() for why a payment created as "pending" doesn't get
		// its notification emails yet.
		$suppress_entry_email = static fn () => false;

		add_filter( 'wpforms_process_anti_spam_direct_post_bypass', $bypass_direct_post_check );
		add_filter( 'wpforms_process_redirect_url', $suppress_confirmation_redirect );

		if ( $deferNotifications ) {
			add_filter( 'wpforms_entry_email', $suppress_entry_email );
			$this->suppressedNotificationsPaymentId = $payment_id;
		}

		// Reinstate, for the duration of this call only, the POST context a live submission
		// would have had — WPForms' process() reads $_POST throughout, requires action=wpforms_submit
		// when the form has AJAX submission enabled, and this class's own hooks
		// (prepare_payment_data(), entry_saved_process()) resolve which payment row to confirm via
		// the same iftp_pbl_payment_id field a real browser submission would post.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- snapshot/restore of superglobal state, not read as input.
		$original_post                = $_POST;
		$_POST['action']              = 'wpforms_submit';
		$_POST['wpforms']             = $entry_post;
		$_POST['iftp_pbl_payment_id'] = (string) $payment_id;

		try {
			$process->process( $entry_post );
		} finally {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- restoring the snapshot taken above.
			$_POST = $original_post;
			remove_filter( 'wpforms_process_anti_spam_direct_post_bypass', $bypass_direct_post_check );
			remove_filter( 'wpforms_process_redirect_url', $suppress_confirmation_redirect );

			if ( $deferNotifications ) {
				remove_filter( 'wpforms_entry_email', $suppress_entry_email );
				$this->suppressedNotificationsPaymentId = null;
			}
		}

		// entry_saved_process() (hooked on wpforms_process_entry_saved, fired inside process()
		// above) already wrote the freshly-created entry's id onto this payment row.
		$updated_payment = wpforms()->obj( 'payment' )->get( $payment_id, array( 'cap' => false ) );
		$entry_id        = $updated_payment && ! empty( $updated_payment->entry_id ) ? (int) $updated_payment->entry_id : 0;

		if ( $entry_id <= 0 ) {
			return 0;
		}

		$this->clear_pending_entry_payload( $payment_id );

		// Added as soon as the entry exists, regardless of whether its notification emails are
		// deferred — the Pay by Link URL is already known at this point, unlike ifthenpay's
		// request id (see add_ifthenpay_request_id_note()), which only exists once paid.
		$this->add_payment_reference_note( $payment_id, $entry_id );

		return $entry_id;
	}

	/**
	 * Stash the exact args WPForms' own entry_email() would have been called with (see
	 * entry_saved_process(), which captures these from the wpforms_process_entry_saved action —
	 * the same values process() itself passes straight into entry_email() right after), so
	 * release_deferred_notifications() can send them later once the payment actually resolves.
	 *
	 * @param array<string, mixed> $fields
	 * @param array<string, mixed> $entry
	 * @param array<string, mixed> $formData
	 */
	private function store_deferred_notification_payload( int $paymentId, array $fields, array $entry, array $formData, int $entryId ): void {
		update_option(
			$this->deferred_notification_option_key( $paymentId ),
			[
				'fields'    => $fields,
				'entry'     => $entry,
				'form_data' => $formData,
				'entry_id'  => $entryId,
			],
			false
		);
	}

	private function deferred_notification_option_key( int $paymentId ): string {
		return 'iftp_pbl_deferred_notify_' . $paymentId;
	}

	/**
	 * Send the notification emails that were held back when the entry was created up front at
	 * "pending" time (see create_entry_for_payment_unlocked()'s $deferNotifications). Called once
	 * the payment actually resolves to completed — only handle_webhook_success() ever calls this,
	 * since it's the only place a payment is ever marked "completed".
	 * A no-op if there's nothing stored (e.g. the entry wasn't created with notifications
	 * deferred in the first place, or this payment already had its notifications released).
	 */
	private function release_deferred_notifications( int $paymentId ): void {
		$optionKey = $this->deferred_notification_option_key( $paymentId );
		$payload   = get_option( $optionKey, null );

		if ( ! is_array( $payload ) || empty( $payload['entry_id'] ) || ! function_exists( 'wpforms' ) ) {
			return;
		}

		// Clear first — a duplicate/replay webhook landing while this is mid-flight must not be
		// able to fire the same notifications twice.
		delete_option( $optionKey );

		$process = wpforms()->obj( 'process' );
		if ( $process && method_exists( $process, 'entry_email' ) ) {
			$process->entry_email(
				(array) ( $payload['fields'] ?? [] ),
				(array) ( $payload['entry'] ?? [] ),
				(array) ( $payload['form_data'] ?? [] ),
				(int) $payload['entry_id'],
				'entry'
			);
		}
	}

	/**
	 * Leave a note on the entry with the Pay By Link URL that was generated for it
	 * (IfthenpayClient::create_payment_link()'s RedirectUrl, captured in
	 * store_webhook_context()), so it can be looked up in ifthenpay's backoffice. Called as soon
	 * as the entry exists (see create_entry_for_payment_unlocked()) — the URL is already known at
	 * that point, unlike ifthenpay's request id (see add_ifthenpay_request_id_note()), which only
	 * exists once the payment actually resolves.
	 */
	private function add_payment_reference_note( int $payment_id, int $entry_id ): void {
		if ( $entry_id <= 0 || ! function_exists( 'wpforms' ) ) {
			return;
		}

		$entry_meta = wpforms()->obj( 'entry_meta' );
		if ( ! $entry_meta || ! method_exists( $entry_meta, 'add' ) ) {
			return;
		}

		$context      = $this->get_webhook_context( $this->generate_pbl_ref( $payment_id ) );
		$redirect_url = is_array( $context ) ? (string) ( $context['redirect_url'] ?? '' ) : '';

		if ( $redirect_url === '' ) {
			return;
		}

		$note = sprintf(
			/* translators: %s: Pay By Link URL. */
			__( 'Pay by Link: %s', 'ifthenpay-payments-for-wpforms' ),
			esc_url_raw( $redirect_url )
		);

		$payment = wpforms()->obj( 'payment' )->get( $payment_id, array( 'cap' => false ) );

		$entry_meta->add(
			[
				'entry_id' => $entry_id,
				'form_id'  => $payment && isset( $payment->form_id ) ? (int) $payment->form_id : 0,
				'user_id'  => 0,
				'type'     => 'note',
				'data'     => wpautop( esc_html( $note ) ),
			],
			'entry_meta'
		);
	}

	/**
	 * Leave a note on the entry with ifthenpay's own request id for this payment, once the
	 * webhook has confirmed it (see handle_webhook_success()) — a reference for looking the
	 * payment up in ifthenpay's backoffice, distinct from the Pay by Link note added at
	 * entry-creation time. Also leaves a second note stating the paid amount, so the entry
	 * notes make it obvious at a glance that ifthenpay confirmed payment, and for how much.
	 */
	private function add_ifthenpay_request_id_note( int $payment_id, string $request_id, float $amount ): void {
		if ( $request_id === '' || ! function_exists( 'wpforms' ) ) {
			return;
		}

		$entry_meta = wpforms()->obj( 'entry_meta' );
		if ( ! $entry_meta || ! method_exists( $entry_meta, 'add' ) ) {
			return;
		}

		$payment  = wpforms()->obj( 'payment' )->get( $payment_id, array( 'cap' => false ) );
		$entry_id = $payment && ! empty( $payment->entry_id ) ? (int) $payment->entry_id : 0;

		if ( $entry_id <= 0 ) {
			return;
		}

		$form_id = isset( $payment->form_id ) ? (int) $payment->form_id : 0;

		$request_id_note = sprintf(
			/* translators: %s: ifthenpay request id. */
			__( 'ifthenpay request ID: %s', 'ifthenpay-payments-for-wpforms' ),
			$request_id
		);

		$entry_meta->add(
			[
				'entry_id' => $entry_id,
				'form_id'  => $form_id,
				'user_id'  => 0,
				'type'     => 'note',
				'data'     => wpautop( esc_html( $request_id_note ) ),
			],
			'entry_meta'
		);

		$formatted_amount = function_exists( 'wpforms_format_amount' ) ? wpforms_format_amount( $amount, true ) : number_format( $amount, 2 );

		$paid_note = sprintf(
			/* translators: 1: ifthenpay gateway label, 2: formatted paid amount. */
			__( '%1$s - %2$s - Paid', 'ifthenpay-payments-for-wpforms' ),
			IFTP_PBL_GATEWAY_LABEL,
			$formatted_amount
		);

		$entry_meta->add(
			[
				'entry_id' => $entry_id,
				'form_id'  => $form_id,
				'user_id'  => 0,
				'type'     => 'note',
				'data'     => wpautop( esc_html( $paid_note ) ),
			],
			'entry_meta'
		);
	}

	/**
	 * Insert a normal, independently-numbered WPForms payment row via WPForms' own insert API.
	 *
	 * @param array<string, mixed> $summary Output of IfthenpayFormData::extract_payment_summary().
	 */
	private function create_wpforms_payment( int $form_id, array $summary, string $title, int $entry_id = 0 ): int {
		$payment = wpforms()->obj( 'payment' );
		if ( ! $payment || ! method_exists( $payment, 'add' ) ) {
			return 0;
		}

		$payment_id = $payment->add(
			[
				'form_id'         => $form_id,
				'status'          => 'pending',
				'total_amount'    => isset( $summary['total_amount'] ) ? (float) $summary['total_amount'] : 0.0,
				'subtotal_amount' => isset( $summary['subtotal_amount'] ) ? (float) $summary['subtotal_amount'] : 0.0,
				'discount_amount' => isset( $summary['discount_amount'] ) ? (float) $summary['discount_amount'] : 0.0,
				'currency'        => function_exists( 'wpforms_get_currency' ) ? wpforms_get_currency() : 'EUR',
				'gateway'         => $this->slug,
				'type'            => 'one-time',
				'mode'            => 'live',
				'transaction_id'  => '',
				'customer_id'     => IfthenpayPayload::generate_customer_id(),
				'title'           => $title,
				'entry_id'        => $entry_id,
			]
		);

		return (int) $payment_id;
	}

	private function get_context(array $fields): array
	{
		if ($this->resolved_context !== null) {
			return $this->resolved_context;
		}

		$payment_id = $this->get_posted_payment_id();

		// 'verified_successful' is only ever written by handle_webhook_success() — the only
		// place that independently confirms a payment with ifthenpay (anti-phishing key +
		// amount match) — so this fast path is trustworthy. See
		// get_pay_now_return_data_from_request() below for the untrusted, client-reported
		// fallback used before the webhook has fired.
		if ($payment_id > 0) {
			$summary = get_option( $this->payment_summary_option_key( $payment_id ), [] );
			if (is_array($summary) && !empty($summary['verified_successful'])) {
				return $this->resolved_context = [
					'payment_method' => (string) ($summary['verified_payment_method'] ?? ''),
					'successful'     => true,
				];
			}
		}

		// Last resort: legacy return-URL path. This context is derived entirely from
		// client-supplied $_GET/$_POST return params — see
		// IfthenpayReturn::is_successful_pay_now_return()'s docblock — and must never be
		// treated as proof of payment by any caller of get_context().
		$returnData = IfthenpayReturn::get_pay_now_return_data_from_request();
		return $this->resolved_context = IfthenpayReturn::resolve_return_context($returnData);
	}

	/**
	 * Persist and respond with payment status.
	 *
	 * Only ever called with 'cancelled' or 'failed' — both non-terminal (see
	 * mark_wpforms_payments_status()), so a spoofed call here can, at worst, temporarily
	 * mis-flag a still-pending payment; the genuine handle_webhook_success() can always
	 * still resolve it to "completed" later. Never call this with 'completed' — that status
	 * may only ever be set by handle_webhook_success() itself.
	 */
	private function respond_with_status( int $payment_id, string $status ): void {

		$this->mark_wpforms_payments_status(
			(string) $payment_id,
			$status
		);

		// mark_wpforms_payments_status() refuses to move a payment away from "completed" —
		// reflect the row's real, final status back to the frontend rather than blindly echoing
		// what was requested, so a stale cancel/error check-in is never reported as failed once
		// the payment has actually been confirmed paid.
		$actual_status = $this->get_wpforms_payment_status( $payment_id );
		if ( $actual_status !== '' ) {
			$status = $actual_status;
		}

		wp_send_json_success(
			IfthenpayPayload::build_payment_status_response( $status )
		);
	}

	/**
	 * Report a payment's real, current status without mutating it — used for the 'success'
	 * outcome of a gateway return, which is never itself proof of payment (see
	 * ajax_verify_payment()'s docblock). Only handle_webhook_success() may ever set
	 * "completed"; until it does, this simply reflects "pending" back to the frontend, which
	 * keeps polling.
	 */
	private function respond_with_current_status( int $payment_id ): void {
		$status = $this->get_wpforms_payment_status( $payment_id );
		if ( $status === '' ) {
			$status = 'pending';
		}

		wp_send_json_success(
			IfthenpayPayload::build_payment_status_response( $status )
		);
	}

	private function get_wpforms_payment_status( int $payment_id ): string {
		if ( $payment_id <= 0 || ! function_exists( 'wpforms' ) ) {
			return '';
		}

		$payment = wpforms()->obj( 'payment' )->get( $payment_id, array( 'cap' => false ) );

		return $payment && isset( $payment->status ) ? (string) $payment->status : '';
	}

	/**
	 * Read the posted payment ID.
	 *
	 * Deliberately reads $_POST directly rather than filter_input(INPUT_POST, ...):
	 * filter_input() reads PHP's original raw request buffer, captured once at the start of the
	 * request — it never reflects a runtime write to the $_POST superglobal. create_entry_for_payment_unlocked()
	 * relies on exactly that kind of write (setting $_POST['iftp_pbl_payment_id'] before replaying
	 * a submission through WPForms' process()) to tell this class's own hooks
	 * (prepare_payment_data(), entry_saved_process(), payment_confirmation_process(), get_context())
	 * which payment they're confirming. With filter_input(), every one of those hooks would
	 * silently see 0 during a replay, and entry_saved_process() would bail out without ever
	 * linking the newly-created entry back to its payment row. A real browser submission posts
	 * this same field normally, so reading $_POST still works correctly for that case too.
	 */
	private function get_posted_payment_id(): int {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing, WordPress.Security.NonceVerification.Recommended -- WPForms verifies its own submission nonce; this field is read, not trusted as an auth token (see owns_wpforms_payment()).
		return isset( $_POST['iftp_pbl_payment_id'] ) ? absint( wp_unslash( $_POST['iftp_pbl_payment_id'] ) ) : 0;
	}

	/**
	 * Determine if this gateway should handle the form submission.
	 * Checks if payment is enabled and form has required fields.
	 * @param array<string, mixed> $form_data The form configuration data.
	 * @return bool True if gateway should handle form.
	 */
	public function should_handle( array $form_data, bool $is_ajax_request = false ): bool {
		if ( ! isset( $form_data['payments'][ $this->slug ] ) ) {
			return false;
		}

		$config  = $form_data['payments'][ $this->slug ];
		$enabled = isset( $config['enable'] ) && (string) $config['enable'] === '1';

		if ( ! $enabled ) {
			return false;
		}

		if ( $is_ajax_request ) {
			return true;
		}

		if ( empty( $form_data['fields'] ) || ! is_array( $form_data['fields'] ) ) {
			return false;
		}

		if (
			! IfthenpayFormData::find_field_by_type( $form_data['fields'], 'iftp_pbl_field' )
		) {
			return false;
		}

		return true;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	private function update_payment_row( int $payment_id, array $data ): bool {
		if ( $payment_id <= 0 || empty( $data ) || ! function_exists( 'wpforms' ) ) {
			return false;
		}

		$data['date_updated_gmt'] = current_time( 'mysql', true );
		$payment                  = wpforms()->obj( 'payment' ) ?: wpforms()->get( 'payment' );

		if ( ! $payment || ! method_exists( $payment, 'update' ) ) {
			return false;
		}

		// WPForms' Payment::update() gates on manage_options by default (no init_allowed()
		// escape hatch, unlike ->get()) — without cap => false this silently no-ops for every
		// guest checkout and for the fully-unauthenticated ifthenpay webhook, leaving payment
		// rows permanently stuck at whatever status they were created with.
		return (bool) $payment->update( $payment_id, $data, '', '', array( 'cap' => false ) );
	}

	private function update_payment_method( int $payment_id, string $payment_method ): void {
		$payment_method = trim( $payment_method );
		if ( $payment_id <= 0 || $payment_method === '' ) {
			return;
		}

		$this->update_stored_payment_summary(
			$payment_id,
			array(
				'payment_method' => $payment_method,
			)
		);

		if ( function_exists( 'wpforms' ) ) {
			$payment_meta = wpforms()->get( 'payment_meta' );
			if ( $payment_meta && method_exists( $payment_meta, 'update_or_add' ) ) {
				$payment_meta->update_or_add( $payment_id, 'method_type', $payment_method );
			}
		}
	}

	/**
	 * Updates the payment status for a given payment ID in WPForms.
	 * @param string $payment_id The ID of the payment to update.
	 * @param string $status     The new status to set (e.g., 'completed', 'cancelled', 'failed').
	 */
	public function mark_wpforms_payments_status( string $payment_id, string $status ): void {
		if ( $payment_id === '' ) {
			return;
		}

		$payment_id = (int) $payment_id;
		$updated    = false;

		if ( function_exists( 'wpforms' ) ) {
			$payment_handler = wpforms()->obj( 'payment' );

			// "Completed" is terminal — once a payment is marked paid, nothing may ever move it
			// away from that status again. A cancelled or failed attempt is deliberately NOT
			// terminal the same way: a Multibanco/Payshop reference (or any method the customer
			// abandoned in the browser without fully reversing) can still genuinely get paid
			// later, and the authoritative signal for that — handle_webhook_success(), which
			// verifies ifthenpay's own anti-phishing key and the paid amount — must still be able
			// to mark it completed even though it was previously cancelled/failed.
			if ( $status !== 'completed' && $payment_handler && method_exists( $payment_handler, 'get' ) ) {
				$current = $payment_handler->get( $payment_id, array( 'cap' => false ) );
				if ( $current && isset( $current->status ) && (string) $current->status === 'completed' ) {
					return;
				}
			}

			if ( $payment_handler && method_exists( $payment_handler, 'update' ) ) {
				// See update_payment_row() — Payment::update() requires cap => false to work
				// outside a logged-in admin request (guest checkouts, the ifthenpay webhook).
				$updated = (bool) $payment_handler->update(
					$payment_id,
					array(
						'status'           => $status,
						'date_updated_gmt' => current_time( 'mysql', true ),
					),
					'',
					'',
					array( 'cap' => false )
				);
			}
		}

		if ( ! $updated ) {
			return;
		}

		do_action( 'iftp_pbl_payment_status_changed', $payment_id, $status );
	}

	/**
	 * Update stored payment summary data for a payment record. A plain (non-autoloaded) option
	 * rather than a transient: Multibanco/Payshop references can be paid days after creation,
	 * well past a transient's typical lifetime, and this data is what lets a webhook-only
	 * confirmation (no browser ever returns) still recreate the real WPForms entry correctly.
	 * @param int $payment_id Payment ID.
	 * @param array<string, mixed> $changes Key-value pairs of summary data to update.
	 */
	private function update_stored_payment_summary( int $payment_id, array $changes ): void {
		if ( $payment_id <= 0 ) {
			return;
		}

		$key     = $this->payment_summary_option_key( $payment_id );
		$summary = get_option( $key, [] );
		$summary = is_array( $summary ) ? $summary : [];
		$summary = array_merge( $summary, $changes );

		update_option( $key, $summary, false );
	}

	private function payment_summary_option_key( int $payment_id ): string {
		return 'iftp_pbl_payment_summary_' . $payment_id;
	}
}
