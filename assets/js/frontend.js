(function ($) {
	'use strict';

	const cfg = window.iftpPblFrontend || {};

	function apiPost(action, data) {
		return $.post(
			cfg.ajax_url,
			Object.assign({ action, nonce: cfg.ajax_nonce }, data || {}),
			null,
			'json'
		);
	}

	function getUrlSearchParam(href, key) {
		if (!href) {
			return '';
		}
		try {
			return (
				new URL(href, window.location.href).searchParams.get(key) || ''
			);
		} catch (e) {
			return '';
		}
	}

	function getReturnStatusFromUrl(href) {
		if (!href) {
			return '';
		}
		try {
			return String(
				new URL(href, window.location.href).searchParams.get(
					'wpforms_pay'
				) || ''
			).toLowerCase();
		} catch (e) {
			return '';
		}
	}

	// Query params ifthenpay's hosted payment page can leave on the return URL: our own
	// tracking params (see IfthenpayPayload::build_gateway_urls()) plus ifthenpay's own, which
	// it appends regardless of whether our tracking params survive the round trip (they often
	// don't). All of these must be scrubbed from the address bar once read — left in place,
	// they get resubmitted with the next "Pay now" click since WPForms forms post back to the
	// current URL, corrupting values like `amount` on that next attempt.
	const RETURN_PARAM_KEYS = [
		'wpforms_pay',
		'iftp_payment_id',
		'iftp_gateway',
		'id',
		'amount',
		'requestId',
		'sk',
		'brand',
		'pan',
		'lang',
	];

	// Field types allowed alongside an ifthenpay field (do not count as competing gateways).
	const ALLOWED_PAYMENT_FIELD_TYPES = [
		'iftp_pbl_field',
		'payment-single',
		'payment-checkbox',
		'payment-multiple',
		'payment-select',
		'payment-coupon',
		'coupon',
		'payment-total',
	];

	// CSS class fragments for the built-in WPForms payment fields (not considered competing).
	const NEUTRAL_PAYMENT_CLASSES = [
		'wpforms-field-payment-total',
		'wpforms-field-payment-coupon',
		'wpforms-field-payment-single',
		'wpforms-field-payment-checkbox',
		'wpforms-field-payment-multiple',
		'wpforms-field-payment-select',
	];

	// CSS class fragments for third-party payment gateways that conflict with ifthenpay.
	const COMPETING_GATEWAY_CLASSES = [
		'wpforms-field-paypal',
		'wpforms-field-square',
		'wpforms-field-stripe',
		'wpforms-field-authorize',
	];

	class IfthenpayPaymentsForWpformsFront {
		constructor() {
			this.$loadingOverlay = null;
			this.allowProgrammaticSubmit = false;
			this.activeButton = null;
			this.activeForm = null;
			this.paymentSessionActive = false;
			this.scrollLockState = null;
			this.visibilityUpdateTimer = null;
			this.activeRuntimeError = false;

			this.init();
		}

		getFormId($form) {
			const candidates = [
				$form.data('formid'),
				$form.data('form-id'),
				$form.attr('data-formid'),
				$form.attr('data-form-id'),
				$form.find('input[name="wpforms[id]"]').val(),
				$form.find('input[name="form_id"]').val(),
			];

			for (const candidate of candidates) {
				const id = parseInt(candidate, 10);
				if (id > 0) {
					return id;
				}
			}

			const idMatch = String($form.attr('id') || '').match(
				/wpforms-form-(\d+)/
			);
			return idMatch ? parseInt(idMatch[1], 10) || 0 : 0;
		}

		showRuntimeError($field, message) {
			const text = String(message || '').trim();
			if (!text || !$field || !$field.length) {
				return;
			}
			this.activeRuntimeError = true;
			this.renderWarning(
				$field.find('.iftp-pbl-runtime-warning').first(),
				this.getFrontendText(
					'warning_payment_error_title',
					'Unable to open payment'
				),
				[text],
				true
			);
		}

		/**
		 * Render the outcome of a payment attempt as a popup — used on return from ifthenpay's
		 * hosted payment page (see handleGatewayReturn()). This is a modal appended to <body>
		 * rather than inline markup on the field, because WPForms conditional logic can set the
		 * field to display:none, which would hide inline content right when it's needed most (the
		 * field the customer just paid on is often the one a later answer conditionally hides).
		 * Every outcome is final as shown: "completed"/"cancelled"/"failed" just state what
		 * happened (the pay button stays disabled) and "pending" explains a webhook will confirm
		 * it later. There is no in-place retry — re-attempting a payment is a brand new
		 * submission, so it goes through the normal form flow (a page reload) rather than
		 * silently reusing this field's state.
		 */
		showOutcomeNotice(status) {
			const copy = {
				completed: {
					title: this.getFrontendText('paid_title', 'Payment received'),
					message: this.getFrontendText(
						'paid_message',
						'Your payment was successful. Thank you!'
					),
				},
				pending: {
					title: this.getFrontendText(
						'pending_title',
						'Payment processing'
					),
					message: this.getFrontendText(
						'pending_message',
						"We're waiting for your payment to be confirmed. You don't need to do anything else — this will update automatically once it's complete."
					),
				},
				cancelled: {
					title: this.getFrontendText('cancelled_title', 'Payment cancelled'),
					message: this.getFrontendText(
						'cancelled_message',
						'You cancelled the payment.'
					),
				},
				failed: {
					title: this.getFrontendText('failed_title', 'Payment failed'),
					message: this.getFrontendText(
						'failed_message',
						'Your payment could not be completed.'
					),
				},
			};

			const entry = copy[status] || copy.pending;

			this.activeRuntimeError = true;

			this.renderOutcomeModal(status, entry.title, entry.message);
		}

		/**
		 * Lazily build the outcome popup's DOM (overlay + modal box) and wire its dismiss
		 * handlers. Built once and reused across calls so repeated poll updates
		 * (pollPaymentOutcome()) just swap its content rather than re-creating it.
		 */
		getOutcomeModalElements() {
			if (this.$outcomeModalOverlay?.length) {
				return this.$outcomeModalOverlay;
			}

			const $overlay = $(
				'<div class="iftp-pbl-outcome-modal-overlay" role="presentation">'
			);
			const $modal = $(
				'<div class="iftp-pbl-outcome-modal" role="dialog" aria-modal="true">'
			);
			const $close = $(
				'<button type="button" class="iftp-pbl-outcome-modal-close" aria-label="Close">'
			).html('&times;');
			const $title = $('<p class="iftp-pbl-outcome-modal-title">');
			const $message = $('<p class="iftp-pbl-outcome-modal-message">');

			$modal.append($close, $title, $message);
			$overlay.append($modal);
			$('body').append($overlay);

			$close.on('click', () => this.dismissOutcomeModal());
			$overlay.on('click', (e) => {
				if (e.target === $overlay.get(0)) {
					this.dismissOutcomeModal();
				}
			});
			$(document).on('keydown.iftpOutcomeModal', (e) => {
				if (e.key === 'Escape' && $overlay.hasClass('iftp-pbl-is-open')) {
					this.dismissOutcomeModal();
				}
			});

			this.$outcomeModalOverlay = $overlay;
			this.$outcomeModalBox = $modal;
			this.$outcomeModalTitle = $title;
			this.$outcomeModalMessage = $message;

			return $overlay;
		}

		/**
		 * Fill and (re)open the outcome popup. Called every time the outcome is known or
		 * re-checked (initial "pending" on return, then again on each poll tick) — it always
		 * shows, even if the customer already closed it once, since a status the customer
		 * dismissed while it was "pending" must still be able to reappear once it resolves.
		 */
		renderOutcomeModal(status, title, message) {
			this.getOutcomeModalElements();

			this.$outcomeModalTitle.text(title);
			this.$outcomeModalMessage.text(message);

			if (this.appliedOutcomeStatusClass) {
				this.$outcomeModalBox.removeClass(
					this.appliedOutcomeStatusClass
				);
			}
			this.appliedOutcomeStatusClass = 'iftp-pbl-outcome-' + status;
			this.$outcomeModalBox.addClass(this.appliedOutcomeStatusClass);

			this.$outcomeModalOverlay.addClass('iftp-pbl-is-open');
			this.lockScroll();
		}

		dismissOutcomeModal() {
			if (!this.$outcomeModalOverlay?.length) {
				return;
			}
			this.$outcomeModalOverlay.removeClass('iftp-pbl-is-open');
			this.unlockScroll();
		}

		/**
		 * Detect a return visit from ifthenpay's hosted payment page (success/error/cancel_url,
		 * see IfthenpayPayload::build_gateway_urls()) and resolve + display the real outcome.
		 * There is no modal/iframe anymore — the customer's browser genuinely left the site and
		 * came back, so this runs on a fresh page load, not inside a same-page interception.
		 */
		handleGatewayReturn() {
			const status = getReturnStatusFromUrl(window.location.href);
			if (!status) {
				// No recognized wpforms_pay status, but ifthenpay may still have appended its own
				// params (id, amount, requestId, sk, brand, pan, ...) on top of — or instead of —
				// ours. Scrub those too so a stale amount/requestId can't bleed into the next
				// "Pay now" attempt on this page.
				if (
					RETURN_PARAM_KEYS.some(
						(key) => getUrlSearchParam(window.location.href, key) !== ''
					)
				) {
					this.stripReturnParamsFromUrl();
				}
				return;
			}

			const paymentId = getUrlSearchParam(
				window.location.href,
				'iftp_payment_id'
			);

			this.stripReturnParamsFromUrl();

			const $field = $('.iftp-pbl-live-field').first();
			if (!$field.length || !paymentId) {
				return;
			}

			const $button = $field.find('.iftp-pbl-pay-now-button').first();
			$button.prop('disabled', true);

			this.showOutcomeNotice('pending');
			this.pollPaymentOutcome($field, $button, status, paymentId, 0);
		}

		/**
		 * Poll ajax_verify_payment a handful of times while a payment stays "pending" after
		 * return, so the pending message's "this will update automatically" is actually true for
		 * the common case of a few extra seconds of processing, not just a manual-refresh promise.
		 * Bounded on purpose — a reference that genuinely takes longer (Multibanco/Payshop, paid
		 * hours or days later) is exactly what the webhook, independent of this page, resolves.
		 *
		 * A 'success' status here is only ever what the browser reported on return — it is never
		 * itself proof of payment (see IfthenpayReturn::is_successful_pay_now_return()'s
		 * docblock). ajax_verify_payment() only ever reports the payment's real, current status;
		 * it never marks it "completed" from this call. Only ifthenpay's server-to-server
		 * webhook (handle_webhook_success()) can ever move a payment to "completed".
		 */
		pollPaymentOutcome($field, $button, status, paymentId, attempt) {
			const maxAttempts = 5;
			const intervalMs = 8000;

			this.verifyPaymentReturn(status, paymentId, (ok, data) => {
				const finalStatus = String(
					data.status || (ok ? 'completed' : 'pending')
				);

				this.showOutcomeNotice(finalStatus);
				$button.prop('disabled', finalStatus === 'completed');

				if (finalStatus === 'pending' && attempt < maxAttempts) {
					window.setTimeout(
						() =>
							this.pollPaymentOutcome(
								$field,
								$button,
								status,
								paymentId,
								attempt + 1
							),
						intervalMs
					);
				}
			});
		}

		stripReturnParamsFromUrl() {
			try {
				const url = new URL(window.location.href);
				RETURN_PARAM_KEYS.forEach((key) => url.searchParams.delete(key));
				window.history.replaceState({}, document.title, url.toString());
			} catch (e) {
				// Cosmetic only — leaving the params in place is harmless.
			}
		}

		init() {
			this.handleGatewayReturn();

			$(document).on('click', '.iftp-pbl-pay-now-button', (e) => {
				e.preventDefault();
				this.handlePayNowClick($(e.currentTarget));
			});

			$(document).on('submit', 'form.wpforms-form', (e) => {
				if (this.allowProgrammaticSubmit) {
					return true;
				}
				if (this.isFormBlockedByExternalPayment($(e.currentTarget))) {
					e.preventDefault();
					e.stopImmediatePropagation();
					return false;
				}
				return true;
			});

			$(document).on(
				'click',
				'form.wpforms-form .wpforms-submit, form.wpforms-form button[type="submit"], form.wpforms-form input[type="submit"]',
				(e) => {
					if (this.allowProgrammaticSubmit) {
						return true;
					}
					if (
						this.isFormBlockedByExternalPayment(
							$(e.currentTarget).closest('form')
						)
					) {
						e.preventDefault();
						e.stopImmediatePropagation();
						return false;
					}
					return true;
				}
			);

			window.setTimeout(() => this.syncSubmitButtonsVisibility(), 200);
			$(document).on('wpformsReady wpformsJSReady change', () =>
				this.scheduleVisibilitySync()
			);
			this.observeConditionalVisibilityChanges();
		}

		scheduleVisibilitySync() {
			window.clearTimeout(this.visibilityUpdateTimer);
			this.visibilityUpdateTimer = window.setTimeout(
				() => this.syncSubmitButtonsVisibility(),
				50
			);
		}

		observeConditionalVisibilityChanges() {
			if (!window.MutationObserver) {
				return;
			}
			$('form.wpforms-form')
				.has('.iftp-pbl-live-field')
				.each((_, form) => {
					new MutationObserver(() =>
						this.scheduleVisibilitySync()
					).observe(form, {
						attributes: true,
						attributeFilter: ['class', 'style', 'hidden'],
						subtree: true,
					});
				});
		}

		getFrontendText(key, fallback) {
			return cfg?.[key] ? String(cfg[key]) : fallback;
		}

		getVisibilityState($element) {
			const className = String($element.attr('class') || '');
			const inlineStyle = String($element.attr('style') || '');
			const computedStyle =
				$element.length && window.getComputedStyle
					? window.getComputedStyle($element.get(0))
					: null;
			const isConditionallyHidden =
				className.indexOf('wpforms-conditional-hide') !== -1;
			const isConditionallyShown =
				className.indexOf('wpforms-conditional-show') !== -1;
			const hasDisplayNone =
				/display\s*:\s*none/i.test(inlineStyle) ||
				(computedStyle && computedStyle.display === 'none');
			const isVisible = $element.is(':visible') && !hasDisplayNone;

			return {
				isConditionallyHidden,
				isConditionallyShown,
				hasDisplayNone,
				isVisible,
				isHiddenByConditions: hasDisplayNone && isConditionallyHidden,
				isActive:
					isVisible && !(hasDisplayNone && isConditionallyHidden),
			};
		}

		isCompetingGatewayField($element, $currentField) {
			if (
				!$element.length ||
				($currentField && $element.is($currentField))
			) {
				return false;
			}
			if ($element.closest('.iftp-pbl-live-field').length) {
				return false;
			}

			const className = String(
				$element.attr('class') || ''
			).toLowerCase();
			const provider = String(
				$element.data('provider') || ''
			).toLowerCase();
			const fieldType = String(
				$element.data('field-type') || $element.data('type') || ''
			).toLowerCase();

			if (ALLOWED_PAYMENT_FIELD_TYPES.indexOf(fieldType) !== -1) {
				return false;
			}
			if (
				NEUTRAL_PAYMENT_CLASSES.some((c) => className.indexOf(c) !== -1)
			) {
				return false;
			}
			if (provider && provider !== 'iftp_pbl') {
				return true;
			}
			return COMPETING_GATEWAY_CLASSES.some(
				(c) => className.indexOf(c) !== -1
			);
		}

		getActiveCompetingGatewayFields($form, $currentField) {
			return $form.find('.wpforms-field').filter((_, el) => {
				const $element = $(el);
				const visibility = this.getVisibilityState($element);
				return (
					this.isCompetingGatewayField($element, $currentField) &&
					(visibility.isActive || visibility.isConditionallyShown)
				);
			});
		}

		getIfthenpayFieldState($form, $field) {
			const $fieldContainer = $field.closest('.wpforms-field');
			const visibility = this.getVisibilityState(
				$fieldContainer.length ? $fieldContainer : $field
			);
			const isConfigReady =
				String($field.data('iftp-config-ready') || '1') === '1';
			const disabledReason = String(
				$field.data('iftp-disabled-reason') || ''
			).trim();
			const hasActiveCompetingGateway =
				this.getActiveCompetingGatewayFields($form, $field).length > 0;
			const canUseIfthenpay =
				isConfigReady &&
				visibility.isActive &&
				!hasActiveCompetingGateway;

			return $.extend({}, visibility, {
				isConfigReady,
				disabledReason,
				hasActiveCompetingGateway,
				canUseIfthenpay,
				isBlocked: !canUseIfthenpay,
			});
		}

		renderWarning($target, title, messages, show) {
			if (!$target.length) {
				return;
			}
			if (!show) {
				$target.empty().hide();
				return;
			}

			const $body = $('<div class="wpforms-iftp-pbl-warning-body">');
			(messages || [])
				.filter((m) => String(m || '').trim() !== '')
				.forEach((m) =>
					$body.append(
						$('<p class="wpforms-iftp-pbl-warning-message">').text(
							m
						)
					)
				);

			$target
				.empty()
				.append(
					$('<div class="wpforms-iftp-pbl-warning-div">').append(
						$('<p class="wpforms-iftp-pbl-warning-title">').text(
							title
						),
						$body
					)
				)
				.show();
		}

		formHasChosenPaymentAmount($form) {
			const $paymentFields = $form.find(
				'.wpforms-field-payment-single, .wpforms-field-payment-multiple, ' +
					'.wpforms-field-payment-checkbox, .wpforms-field-payment-select'
			);

			if (!$paymentFields.length) {
				return true;
			}

			let hasAmount = false;

			$paymentFields.each(function () {
				$(this)
					.find('input, select')
					.each(function () {
						const $input = $(this);
						const value = $input.val();

						if ($input.is(':disabled')) {
							return;
						}
						if (
							($input.is(':checkbox') || $input.is(':radio')) &&
							!$input.is(':checked')
						) {
							return;
						}

						if (Array.isArray(value)) {
							hasAmount = value.some(
								(entry) => String(entry || '').trim() !== ''
							);
							return !hasAmount;
						}

						if (String(value || '').trim() !== '') {
							hasAmount = true;
							return false;
						}
					});

				return !hasAmount;
			});

			return hasAmount;
		}

		applyIfthenpayFieldState($field, fieldState) {
			const $button = $field.find('.iftp-pbl-pay-now-button').first();

			$field
				.toggleClass('iftp-pbl-is-active', fieldState.canUseIfthenpay)
				.toggleClass('iftp-pbl-is-blocked', fieldState.isBlocked)
				// A competing gateway field being active on the form isn't a
				// misconfiguration to explain to the visitor — just hide the whole
				// ifthenpay field (box + button) rather than showing a warning.
				.toggleClass(
					'iftp-pbl-gateway-conflict',
					fieldState.hasActiveCompetingGateway
				);

			this.renderWarning(
				$field.find('.iftp-pbl-config-warning').first(),
				this.getFrontendText(
					'warning_config_title',
					'Configuration Required'
				),
				[fieldState.disabledReason || 'This field is disabled.'],
				!fieldState.isConfigReady
			);

			if ($button.length) {
				$button.prop('disabled', fieldState.isBlocked);
				if (fieldState.isBlocked) {
					$button.attr('tabindex', '-1').attr('aria-hidden', 'true');
				} else {
					$button.removeAttr('tabindex').removeAttr('aria-hidden');
				}
			}
		}

		syncSubmitButtonsVisibility() {
			$('form.wpforms-form').each((_, form) => {
				const $form = $(form);
				let shouldHideWpformsSubmit = false;

				$form.find('.iftp-pbl-live-field').each((_i, field) => {
					const $field = $(field);
					const fieldState = this.getIfthenpayFieldState(
						$form,
						$field
					);
					this.applyIfthenpayFieldState($field, fieldState);

					if (fieldState.canUseIfthenpay) {
						shouldHideWpformsSubmit = true;
					}
				});

				$form.toggleClass(
					'iftp-pbl-hide-wpforms-button',
					shouldHideWpformsSubmit
				);
				$form
					.find(
						'.wpforms-submit, button[type="submit"], input[type="submit"]'
					)
					.toggleClass('wpforms-hidden', shouldHideWpformsSubmit)
					.prop('disabled', false);
				$form
					.find('.wpforms-submit-container')
					.toggle(!shouldHideWpformsSubmit);
			});
		}

		isFormBlockedByExternalPayment($form) {
			if (!$form || !$form.length) {
				return false;
			}
			return (
				$form.find('.iftp-pbl-live-field.iftp-pbl-is-active').length > 0
			);
		}

		_prepareHiddenInputs($form) {
			$form
				.find('.iftp-pbl-payment-id-input, .iftp-pbl-paid-now-return-input')
				.val('');
		}

		_disableButton($button) {
			if (!$button.data('iftp-original-label')) {
				$button.data(
					'iftp-original-label',
					String($button.text() || '').trim() || 'Pay now'
				);
			}
			$button
				.prop('disabled', true)
				.css({
					opacity: '0.6',
					'background-color': '#f3f4f6',
					color: '#d1d5db',
					cursor: 'not-allowed',
				})
				.text(
					this.getFrontendText('opening_text', 'Opening payment...')
				);
		}

		_injectSpinnerStyle() {
			if (!$('#iftp-spin-animation').length) {
				$('head').append(
					'<style id="iftp-spin-animation">@keyframes iftp-spin { to { transform: rotate(360deg); } }</style>'
				);
			}
		}

		_showLoadingOverlay() {
			const html =
				'<div class="iftp-loading-overlay" style="position:fixed;top:0;left:0;right:0;bottom:0;background:rgba(255,255,255,0.7);z-index:999999;display:flex;align-items:center;justify-content:center;">' +
				'<div style="text-align:center;">' +
				'<div style="width:40px;height:40px;border:3px solid rgba(0,0,0,0.1);border-top-color:#0077a1;border-radius:50%;animation:iftp-spin 0.8s linear infinite;margin:0 auto 16px;"></div>' +
				'<p style="margin:0;color:#374151;font-weight:500;">Processing payment...</p>' +
				'</div></div>';
			this.$loadingOverlay = $(html).appendTo('body');
			this.lockScroll();
		}

		handlePayNowClick($button) {
			if (this.paymentSessionActive) {
				return;
			}

			const $form = $button.closest('form');
			if (!$form.length) {
				return;
			}

			if (typeof $.fn.validate !== 'undefined' && !$form.valid()) {
				const $firstError = $form.find('.wpforms-error').first();
				if ($firstError.length) {
					$('html, body').animate(
						{ scrollTop: $firstError.offset().top - 100 },
						500
					);
				}
				return;
			}

			const $field = $button.closest('.iftp-pbl-live-field');
			$field.find('.iftp-pbl-runtime-warning').first().empty().hide();
			this.dismissOutcomeModal();

			if (!this.formHasChosenPaymentAmount($form)) {
				this.showRuntimeError(
					$field,
					this.getFrontendText(
						'warning_missing_amount',
						'The payment total is not ready yet. Please review the form and try again.'
					)
				);
				return;
			}

			const gatewayKey = String($button.data('gateway-key') || '');
			const formId = this.getFormId($form);
			const fieldState = this.getIfthenpayFieldState($form, $field);

			if (!fieldState.canUseIfthenpay) {
				this.syncSubmitButtonsVisibility();
				return;
			}

			if (!gatewayKey || !formId) {
				this.showRuntimeError(
					$field,
					!gatewayKey
						? 'The ifthenpay gateway key is missing.'
						: 'The WPForms form ID could not be detected.'
				);
				return;
			}

			this.activeButton = $button;
			this.activeForm = $form;
			this.paymentSessionActive = true;

			this._disableButton($button);
			this._injectSpinnerStyle();
			this._showLoadingOverlay();
			this._prepareHiddenInputs($form);

			apiPost('iftp_pbl_create_pay_button_payment', {
				form_id: formId,
				gateway_key: gatewayKey,
				form_payload: $form.serialize(),
			})
				.done((response) => {
					const data = response?.data ?? {};

					if (!response?.success) {
						this.showRuntimeError(
							$field,
							data.message ||
								'Unable to create the payment link. Please review the form and try again.'
						);
						this.resetButton();
						this.closeOverlay();
						return;
					}

					if (data.skip_payment) {
						this.resetButton();
						this.closeOverlay();
						this.submitPaidForm($form);
						return;
					}

					const redirectUrl = String(
						data.iframe_url || data.redirect_url || ''
					);
					if (!redirectUrl) {
						this.showRuntimeError(
							$field,
							'Unable to open payment. Please try again.'
						);
						this.resetButton();
						this.closeOverlay();
						return;
					}

					// Full-page redirect to ifthenpay's hosted payment page — no modal/iframe.
					// The customer's browser leaves this page entirely; handleGatewayReturn()
					// picks up when (and if) it comes back.
					window.location.href = redirectUrl;
				})
				.fail((jqXHR) => {
					this.showRuntimeError(
						$field,
						String(
							jqXHR?.responseJSON?.data?.message ||
								'Unable to reach the payment service. Please try again.'
						).trim()
					);
					this.resetButton();
					this.closeOverlay();
				});
		}

		verifyPaymentReturn(status, paymentId, callback = () => {}) {
			apiPost('iftp_pbl_verify_payment', {
				payment_id: paymentId,
				return_action: status,
			})
				.done((response) => {
					const data = response?.data ?? {};
					const ok =
						!!response?.success &&
						String(data.status || '') === 'completed';
					callback(ok, data);
				})
				.fail(() => callback(false, {}));
		}

		submitPaidForm($form) {
			if (!$form || !$form.length) {
				this.resetButton();
				this.closeOverlay();
				return;
			}

			this.allowProgrammaticSubmit = true;

			window.setTimeout(() => {
				try {
					const formEl = $form.get(0);
					if (formEl && typeof formEl.requestSubmit === 'function') {
						formEl.requestSubmit();
					} else if (formEl) {
						formEl.submit();
					}
				} finally {
					window.setTimeout(() => {
						this.allowProgrammaticSubmit = false;
					}, 50);
				}
			}, 50);
		}

		closeOverlay() {
			if (this.$loadingOverlay?.length) {
				this.$loadingOverlay.remove();
				this.$loadingOverlay = null;
			}
			this.unlockScroll();
		}

		lockScroll() {
			if (this.scrollLockState) {
				return;
			}

			const $html = $('html');
			const $body = $('body');

			this.scrollLockState = {
				htmlOverflow: $html.css('overflow'),
				bodyOverflow: $body.css('overflow'),
				bodyPaddingRight: $body.css('padding-right'),
			};

			const scrollbarWidth =
				window.innerWidth - document.documentElement.clientWidth;
			const currentPadding = parseFloat($body.css('padding-right')) || 0;

			$('html, body').css('overflow', 'hidden');

			if (scrollbarWidth > 0) {
				$body.css(
					'padding-right',
					currentPadding + scrollbarWidth + 'px'
				);
			}
		}

		unlockScroll() {
			if (!this.scrollLockState) {
				return;
			}

			$('html').css('overflow', this.scrollLockState.htmlOverflow || '');
			$('body')
				.css('overflow', this.scrollLockState.bodyOverflow || '')
				.css(
					'padding-right',
					this.scrollLockState.bodyPaddingRight || ''
				);

			this.scrollLockState = null;
		}

		resetButton() {
			this.paymentSessionActive = false;

			if (this.activeButton?.length) {
				const originalLabel =
					String(
						this.activeButton.data('iftp-original-label') || ''
					).trim() || 'Pay now';
				this.activeButton
					.text(originalLabel)
					.prop('disabled', false)
					.css({
						opacity: '1',
						'background-color': '',
						color: '',
						cursor: '',
					});
			}
			this.activeButton = null;
		}
	}

	new IfthenpayPaymentsForWpformsFront();
})(jQuery);

/* -----------------------------------------------------------------------
 * Theme-aware logo switcher
 * Detects whether the form's text colour is light (dark-background theme)
 * and swaps payment-method logos to their white variants when it is.
 * ----------------------------------------------------------------------- */
(function () {
	'use strict';

	/**
	 * Compute WCAG relative luminance (0 = black, 1 = white) from a CSS colour string.
	 *
	 * @param {string} cssColor  Computed colour value, e.g. "rgb(30, 30, 30)".
	 * @returns {number}
	 */
	function colorLuminance(cssColor) {
		var m = /rgba?\(\s*(\d+)[,\s]+(\d+)[,\s]+(\d+)/i.exec(cssColor);
		if (!m) { return 0; }
		var toLinear = function (c) {
			var s = parseInt(c, 10) / 255;
			return s <= 0.03928 ? s / 12.92 : Math.pow((s + 0.055) / 1.055, 2.4);
		};
		return 0.2126 * toLinear(m[1]) + 0.7152 * toLinear(m[2]) + 0.0722 * toLinear(m[3]);
	}

	/**
	 * For a single ifthenpay field block, check the payment box's own background
	 * colour — the actual surface the logos are drawn against — and replace logo
	 * src with the dark (white) version when that background is dark.
	 *
	 * Previously this sampled the *text* colour of the nearest label/heading
	 * anywhere in the form as a proxy for the theme; that probe could easily
	 * find nothing (a form with no other visible labels) or an element whose
	 * color doesn't represent this field's own background, silently skipping
	 * the swap — e.g. a solid-black logo (Apple Pay) staying black on a dark
	 * background and effectively disappearing. Reading the box's own
	 * background is direct and can't miss.
	 *
	 * @param {Element} fieldEl
	 */
	function applyThemeAwareLogos(fieldEl) {
		var box           = fieldEl.querySelector('.iftp-pbl-public-box') || fieldEl;
		var computedColor = window.getComputedStyle(box).backgroundColor;
		var luminance     = colorLuminance(computedColor);

		if (luminance < 0.5) {
			// Dark background → use white/dark-mode logos.
			var imgs = fieldEl.querySelectorAll('img[data-logo-dark]');
			for (var i = 0; i < imgs.length; i++) {
				var darkSrc = imgs[i].getAttribute('data-logo-dark');
				if (darkSrc) { imgs[i].src = darkSrc; }
			}
		}
	}

	function initThemeLogos() {
		var fields = document.querySelectorAll('.iftp-pbl-live-field');
		for (var i = 0; i < fields.length; i++) {
			applyThemeAwareLogos(fields[i]);
		}
	}

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initThemeLogos);
	} else {
		initThemeLogos();
	}

	// Re-run when WPForms reinitialises (e.g. multi-step form navigation).
	document.addEventListener('wpformsReady', initThemeLogos);

	// Re-run when a dark-mode plugin toggles a class/style on <body> after DOMContentLoaded.
	// This fixes the race where the theme plugin runs its JS after the logo switcher already
	// sampled the (still-light) computed color.
	if (window.MutationObserver) {
		new MutationObserver(function () {
			initThemeLogos();
		}).observe(document.body, {
			attributes: true,
			attributeFilter: ['class', 'style'],
		});
	}
})();
