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
 * Registers the cart on ClicToPay (register.do) and sends the customer to the payment page.
 */
class ClictopayRedirectModuleFrontController extends ModuleFrontController
{
    public $ssl = true;

    /**
     * Checks the cart, registers it on ClicToPay and redirects the customer to the payment page.
     *
     * Sends the customer back to the checkout with an error when register.do fails.
     *
     * @return void
     */
    public function postProcess()
    {
        $cart = $this->context->cart;

        if (!$this->module->active
            || !Validate::isLoadedObject($cart)
            || !$cart->id_customer
            || !$cart->id_address_delivery
            || !$cart->id_address_invoice
            || !$this->module->checkCurrency($cart)
        ) {
            Tools::redirect($this->context->link->getPageLink('order', true));
        }

        $result = $this->module->registerCart($cart);

        if (isset($result['error'])) {
            $this->errors[] = sprintf($this->module->l('Payment failed: %s', 'redirect'), $result['error']);
            $this->redirectWithNotifications($this->context->link->getPageLink('order', true));
        }

        Tools::redirect($result['formUrl']);
    }
}
