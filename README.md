# ifthenpay | Payments for WPForms

**English** | [Português](README.pt.md) | [Español](README.es.md) | [Français](README.fr.md)

Adds ifthenpay payment methods to WPForms: cards, wallets, and local payment options; supports secure one-time payments via pay-by-link.

---

## Table of Contents

- [Description](#description)
- [Key Features](#key-features)
- [Requirements](#requirements)
- [Installation](#installation)
- [Form Setup](#form-setup)
- [Using ifthenpay Alongside Another Payment Gateway](#using-ifthenpay-alongside-another-payment-gateway)
- [Frequently Asked Questions](#frequently-asked-questions)
- [External Services](#external-services)
- [Screenshots](#screenshots)
- [Support](#support)

## Description

This plugin integrates the ifthenpay payment gateway with WPForms to enable seamless payment collection directly from your forms. Payments are processed through a secure pay-by-link system, ensuring that no sensitive card or banking data is stored on your website. Customers can complete payments using their preferred method via a secure payment page. After submitting a form, users are redirected to ifthenpay's secure hosted payment page to complete the transaction; ifthenpay then sends a server-side callback to update the payment status automatically.

### In plain terms you get:

- One-time payments directly from WPForms
- Support for coupons and automatic total calculations
- Merchant backoffice (basic sales) on web + mobile
- Secure automatic payment confirmations (no card numbers stored)

All settings are made in WPForms and in your ifthenpay Backoffice. The plugin is built so site owners can manage payments without needing deep technical knowledge.

## Key Features

1. Full integration with WPForms Lite and Pro payment flow
2. Secure transactions
3. Automatic payment confirmation
4. Support for multiple payment methods (cards, wallets, transfers)
5. Coupon and discount support via WPForms
6. Secure full-page redirect to ifthenpay's hosted payment page
7. Real-time payment status in WPForms entries
8. Multi-language support (EN, ES, FR, PT)
9. Security first (no card data stored)

## Requirements

- An active ifthenpay merchant account — [subscribe here](https://ifthenpay.com/aderir/) to obtain your credentials.
- A WPForms Gateway Key (request this from ifthenpay support/helpdesk).
- The payment methods you want enabled on that Gateway Key (our helpdesk team will guide you).
- WordPress 6.5+ and PHP 8.2+, and WPForms installed and activated.
- HTTPS (SSL) enabled on your site.

## Installation

1. **Install:** Upload the plugin zip via `Plugins → Add New → Upload`, or install from WordPress.org and Activate.
2. **Credentials:** Ensure your ifthenpay account has an active WPForms Gateway Key with the desired payment methods enabled.
3. **Setup:** Go to `WPForms → Settings → Payments` and enter your Backoffice Key.
4. **Form config:** `Create/Edit a form → Payments tab → Add the Ifthenpay field on your form → enable "ifthenpay | Payment Gateway"` and select a Gateway Key. Next, choose which payment methods to activate from those available in your gateway, and set your default payment method. Finally, add a payment description, which will be displayed on the ifthenpay payment page for all transactions.

## Using ifthenpay Alongside Another Payment Gateway

WPForms only allows one active payment method per submission. If a form has both the ifthenpay field and another payment gateway's field (PayPal, Stripe, Square, or Authorize.Net) visible at the same time, the ifthenpay field automatically hides itself — logo, payment methods, and "Pay now" button — so customers only ever see the one payment option that will actually work, instead of a button that would fail or double-charge them.

- The ifthenpay field reappears automatically if the other gateway's field becomes hidden again (for example, through WPForms conditional logic), with no page reload required.
- This only applies to gateway fields that are actually visible on the form. A gateway field that's present but hidden by conditional logic does not trigger this behavior.
- Built-in WPForms fields like Total, Coupon, and single/multiple/checkbox/select payment items are never treated as competing gateways — only PayPal, Stripe, Square, and Authorize.Net fields are.

## Frequently Asked Questions

<details>
<summary><strong>Does this plugin require WPForms?</strong></summary>
Yes. WPForms must be installed and active to use this plugin.
</details>

<details>
<summary><strong>Does it support recurring payments?</strong></summary>
No. This version supports only one-time payments via pay-by-link.
</details>

<details>
<summary><strong>Are payment details stored?</strong></summary>
No. The plugin does not store card numbers or full bank details. Only minimal references required for payment matching are kept.
</details>

<details>
<summary><strong>Does it support WPForms coupons?</strong></summary>
Yes. WPForms coupon fields are fully supported and discounts are automatically calculated.
</details>

<details>
<summary><strong>Which payment methods are supported?</strong></summary>
Any ifthenpay method attached to your Gateway Key (e.g. Multibanco, MB WAY, Payshop, Credit Card, Google Pay, Apple Pay, Pix).
</details>

<details>
<summary><strong>How does the payment process work?</strong></summary>
After form submission, users are redirected to a secure ifthenpay-hosted payment page. Once payment is completed, the status is updated automatically via callback.
</details>

<details>
<summary><strong>What happens if a payment fails?</strong></summary>
The entry is marked as Failed. Users can retry the payment depending on your configuration.
</details>

<details>
<summary><strong>Can I customize the payment experience?</strong></summary>
Yes. You can configure button label, description, and styling within WPForms.
</details>

<details>
<summary><strong>What happens if my form also has another payment gateway field (PayPal, Stripe, Square, Authorize.Net)?</strong></summary>
The ifthenpay field hides itself automatically while that other gateway's field is visible, so customers are never shown two active payment options at once. See <a href="#using-ifthenpay-alongside-another-payment-gateway">Using ifthenpay Alongside Another Payment Gateway</a>.
</details>

<details>
<summary><strong>Is there a sandbox?</strong></summary>
ifthenpay may provide test entities; if unavailable, use a low-value live test.
</details>

<details>
<summary><strong>How secure is the integration?</strong></summary>
Requests are encrypted over HTTPS; no sensitive payment data is stored.
</details>

<details>
<summary><strong>Does ifthenpay's addon for WPForms accept WEBHOOKS(Callbacks)?</strong></summary>
Yes! ifthenpay's addon for WPForms from version 2.0.0 and future ones accepts webhook(callbacks).
</details>

## External Services

This plugin integrates with the ifthenpay payment platform to process payments for WPForms submissions. ifthenpay is a third-party service that provides secure payment processing for cards, wallets, and local bank transfers.

- **WPForms**
  - **What it is and what it is used for**: A form builder plugin used to create payment forms. This plugin extends its payment capabilities.

- **ifthenpay Backoffice & Integrations**
  - **What it is and what it is used for**: The ifthenpay Backoffice is the merchant dashboard used to manage integrations and payment configurations. The plugin uses the ifthenpay API to generate payment links and validate transactions.
  - **What data is sent and when**:
    - During setup: Backoffice Key and Gateway Key for authentication and configuration retrieval.
    - During payment processing: Order reference ID, amount, description, enabled payment method accounts, success/error/cancel return URLs, language, and optionally the selected payment method, customer email, customer name, and form field data.
    - During webhook registration: the Gateway Key and this site's callback URL, so ifthenpay can notify the site directly when a payment resolves.
    - During payment method activation requests: when an admin requests activation of a new payment method from `WPForms → Settings → Payments`, an email is sent to ifthenpay support (suporte@ifthenpay.com) containing the Backoffice Key, Gateway Key, the requested payment method, the admin's email address, site URL, site name, WordPress version, WPForms version, and plugin version.
    - During callbacks: Payment status and payment method.
  - **End-User License Agreement (EULA)**: [EULA](https://ifthenpay.com/eula/)
  - **Privacy Policy**: [Privacy Policy](https://ifthenpay.com/politica-de-privacidade/)

All network requests are performed server-side over HTTPS. Sensitive credentials are stored securely and are not publicly exposed. No raw card or bank details are stored.

## Screenshots

Below are screenshots demonstrating key features and interfaces of the plugin:

1. **(Admin Only) Backoffice Synchronization under WPForms Settings Payments**
   ![Backoffice Settings](.wordpress-org/screenshot-1.png)
2. **(Admin Only) WPForms's admin page (Creation/Editing Form -> Payments)**
   ![Gateway Settings](.wordpress-org/screenshot-2.png)
3. **(Admin Only) Adding ifthenpay's Payment field to the selected form**
   ![Adding Field to Form](.wordpress-org/screenshot-3.png)
4. **(Customers Experience) Payment Gateway field display varies by WPForms settings**
   ![Display of Field](.wordpress-org/screenshot-4.png)
5. **(Customers Experience) ifthenpay's Secure Payment Page**
   ![Payment Page](.wordpress-org/screenshot-5.png)
6. **(Customers Experience) Payment Message (either paid, pending, cancelled or failed)**
   ![Display of Payment Message](.wordpress-org/screenshot-6.png)
7. **(Admin Only) Payment Details**
   ![Payment Details](.wordpress-org/screenshot-7.png)
8. **(Admin Only) Payment Entries**
   ![Payment Entries](.wordpress-org/screenshot-8.png)

## Support

For assistance use the [WordPress.org support forum](https://wordpress.org/support):

Pre-checks:

- Payment method enabled on Gateway Key AND mapped to Integration
- Running current recommended versions of WordPress, PHP, & WPForms

Commercial helpdesk available (no direct email required): [helpdesk.ifthenpay.com](https://helpdesk.ifthenpay.com/)

- **ifthenpay support**: [suporte@ifthenpay.com](mailto:suporte@ifthenpay.com)
- **WPForms docs**: [WPForms docs](https://wpforms.com/docs/)
