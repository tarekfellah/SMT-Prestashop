<?php


/*
 * (c) 2026 Oxygène Technologies <tarek.fellah@oxygene-tech.com>
 *
 * Based on the ClicToPay module (c) 2017 ExtrumWeb International <info@extrumweb.com>,
 * distributed under the BSD license.
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Handles both returnUrl and failUrl. The decision comes from
 * getOrderStatusExtended.do only, never from which URL was hit.
 */
class ClictopayReturnModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /**
     * Checks the cart key, asks ClicToPay for the real status and creates the order
     * only when orderStatus = 2.
     *
     * @return void
     */
    public function postProcess()
    {
        $cart = new Cart((int)Tools::getValue('id_cart'));
        $key = (string)Tools::getValue('key');

        if (!Validate::isLoadedObject($cart) || !hash_equals((string)$cart->secure_key, $key)) {
            $this->module->log('[return] Rejected callback: invalid cart key.', (int)$cart->id);
            Tools::redirect($this->context->link->getPageLink('order', true));
        }

        // Already validated (page refreshed, or both URLs hit)
        if ($id_order = (int)Order::getIdByCartId((int)$cart->id)) {
            $this->redirectToConfirmation($cart, $id_order);
        }

        $ctp_order_id = $this->module->getLastCtpOrderId((int)$cart->id);
        if ($ctp_order_id === '') {
            $this->fail($this->module->l('No ClicToPay orderId stored for this cart.', 'return'));
        }

        $result = $this->module->getOrderStatusExtended($ctp_order_id, (int)$cart->id);
        if ($result['error']) {
            $this->fail($this->module->l('Unable to reach ClicToPay to confirm the payment.', 'return'));
        }

        $body = $result['data'];
        $error_code = isset($body['errorCode']) ? (int)$body['errorCode'] : 0;
        $status = isset($body['orderStatus']) ? (int)$body['orderStatus'] : -1;

        // orderStatus = 2 is the only value that means the money was taken
        if ($error_code !== 0 || $status !== 2) {
            if (!empty($body['actionCodeDescription'])) {
                $reason = $body['actionCodeDescription'];
            } elseif (!empty($body['errorMessage'])) {
                $reason = $body['errorMessage'];
            } else {
                $reason = $this->module->l('Payment not completed.', 'return');
            }
            $this->fail($reason);
        }

        $transaction_id = $ctp_order_id;
        if (!empty($body['cardAuthInfo']['approvalCode'])) {
            $transaction_id = $body['cardAuthInfo']['approvalCode'];
        } elseif (!empty($body['authRefNum'])) {
            $transaction_id = $body['authRefNum'];
        }

        // Use the amount actually captured: if the cart changed after register.do,
        // validateOrder() detects the difference and sets the order to "Payment error"
        $currency = new Currency($cart->id_currency);
        $amount_paid = $cart->getOrderTotal(true, Cart::BOTH);
        if (isset($body['amount'], Clictopay::SUPPORTED_CURRENCIES[$currency->iso_code])) {
            $amount_paid = (int)$body['amount'] / Clictopay::SUPPORTED_CURRENCIES[$currency->iso_code]['multiplier'];
        }

        $this->module->validateOrder(
            (int)$cart->id,
            (int)Configuration::get('SMT_OS_ACCEPTED'),
            $amount_paid,
            'Carte bancaire ClicToPay',
            null,
            ['transaction_id' => $transaction_id],
            (int)$cart->id_currency,
            false,
            $cart->secure_key
        );

        $this->redirectToConfirmation($cart, (int)$this->module->currentOrder);
    }

    /**
     * Redirects the customer to the order confirmation page. Does not return.
     *
     * @param Cart $cart
     * @param int $id_order
     *
     * @return void
     */
    private function redirectToConfirmation(Cart $cart, $id_order)
    {
        $customer = new Customer((int)$cart->id_customer);
        Tools::redirect($this->context->link->getPageLink('order-confirmation', true, null, [
            'id_cart' => (int)$cart->id,
            'id_module' => (int)$this->module->id,
            'id_order' => (int)$id_order,
            'key' => $customer->secure_key,
        ]));
    }

    /**
     * No order is created: the cart stays available so the customer can retry. Does not return.
     *
     * @param string $message Reason shown to the customer
     *
     * @return void
     */
    private function fail($message)
    {
        $this->module->log('[return] Payment not confirmed: ' . $message, (int)Tools::getValue('id_cart'));
        $this->errors[] = sprintf($this->module->l('Payment failed: %s', 'return'), $message);
        $this->redirectWithNotifications($this->context->link->getPageLink('order', true));
    }
}
