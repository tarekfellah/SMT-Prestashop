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

use PrestaShop\PrestaShop\Core\Payment\PaymentOption;

if (!defined('_PS_VERSION_')) {
    exit;
}

class Clictopay extends PaymentModule
{
    /**
     * ISO 4217 codes and minor-unit multipliers for the currencies ClicToPay supports.
     */
    const SUPPORTED_CURRENCIES = [
        'TND' => ['code' => 788, 'multiplier' => 1000],
        'EUR' => ['code' => 978, 'multiplier' => 100],
        'USD' => ['code' => 840, 'multiplier' => 100],
    ];

    const TEST_URL = 'https://test.clictopay.com/payment/rest/';
    const LIVE_URL = 'https://ipay.clictopay.com/payment/rest/';

    protected $_html = '';
    protected $_postErrors = array();
    public $details;
    public $owner;
    public $address;
    public $extra_mail_vars;

    /**
     * Sets the module metadata and warns when no currency is enabled for it.
     */
    public function __construct()
    {
        $this->name = 'clictopay';
        $this->tab = 'payments_gateways';
        $this->version = '2.0.0';
        $this->ps_versions_compliancy = ['min' => '1.7.7', 'max' => '9.99.99'];
        $this->author = 'Oxygène Technologies';
        $this->currencies = true;
        $this->currencies_mode = 'checkbox';
        $this->bootstrap = true;
        $this->controllers = ['redirect', 'return'];

        parent::__construct();
        $this->displayName = $this->l('ClicToPay SMT');
        $this->description = $this->l('This module allows you to accept online payments based on the SPS Clictopay SMT.');

        if (!count(Currency::checkPaymentCurrencies($this->id))) {
            $this->warning = $this->l('No currency has been set for this module.');
        }
    }

    /**
     * Installs the module: hook, transaction table, "accepted" order state and default configuration.
     *
     * @return bool
     */
    public function install()
    {
        if (!parent::install()) {
            return false;
        }

        if (!$this->registrationHook()) {
            return false;
        }

        if (!$this->installDb()) {
            return false;
        }

        if (!$this->installOrderState('SMT :: Autorisation acceptée', 'SMT ::  Authorized acceptance', 'accepted', 'LimeGreen', false, false, true, true, true)) {
            return false;
        }

        if (!Configuration::updateValue('SMT_SANDBOX', 1)
            || !Configuration::updateValue('CLICTOPAY_USERNAME', '')
            || !Configuration::updateValue('CLICTOPAY_PASSWORD', '')
            || !Configuration::updateValue('CLICTOPAY_LANGUAGE', 'fr')
            || !Configuration::updateValue('CLICTOPAY_DEBUG', 1)
        ) {
            return false;
        }

        return true;
    }

    /**
     * Uninstalls the module and deletes its configuration values.
     *
     * The transaction table and order states are kept so existing orders stay consistent.
     *
     * @return bool
     */
    public function uninstall()
    {
        if (!parent::uninstall()) {
            return false;
        }

        foreach (['SMT_SANDBOX', 'CLICTOPAY_USERNAME', 'CLICTOPAY_PASSWORD', 'CLICTOPAY_LANGUAGE', 'CLICTOPAY_DEBUG'] as $key) {
            Configuration::deleteByName($key);
        }

        return true;
    }

    /**
     * Creates the transaction table. One row per register.do call: links a cart to the ClicToPay orderId.
     *
     * @return bool
     */
    public function installDb()
    {
        return Db::getInstance()->execute('
            CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'clictopay_transaction` (
                `id_clictopay_transaction` INT UNSIGNED NOT NULL AUTO_INCREMENT,
                `id_cart` INT UNSIGNED NOT NULL,
                `order_number` VARCHAR(64) NOT NULL,
                `ctp_order_id` VARCHAR(64) NOT NULL,
                `date_add` DATETIME NOT NULL,
                PRIMARY KEY (`id_clictopay_transaction`),
                KEY `id_cart` (`id_cart`)
            ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4');
    }

    /**
     * Module configuration page: saves the settings, runs the integration tests and renders both panels.
     *
     * @return string HTML of the configuration page
     */
    public function getContent()
    {
        $output = null;

        if (Tools::isSubmit('submit' . $this->name)) {
            $username = trim((string)Tools::getValue('CLICTOPAY_USERNAME'));
            $password = (string)Tools::getValue('CLICTOPAY_PASSWORD');
            $language = (string)Tools::getValue('CLICTOPAY_LANGUAGE');

            if (empty($username) || !Validate::isGenericName($username)) {
                $output .= $this->displayError($this->l('Invalid Configuration value'));
            } else {
                Configuration::updateValue('CLICTOPAY_USERNAME', $username);
                // An empty password field keeps the stored password
                if ($password !== '') {
                    Configuration::updateValue('CLICTOPAY_PASSWORD', $password);
                }
                Configuration::updateValue('CLICTOPAY_LANGUAGE', in_array($language, ['fr', 'en', 'ar'], true) ? $language : 'fr');
                Configuration::updateValue('SMT_SANDBOX', (int)Tools::getValue('SMT_SANDBOX'));
                Configuration::updateValue('CLICTOPAY_DEBUG', (int)Tools::getValue('CLICTOPAY_DEBUG'));
                $output .= $this->displayConfirmation($this->l('Settings updated'));
            }
        }

        $calls = [];
        if (Tools::isSubmit('submitClictopayTest')) {
            if (!Configuration::get('CLICTOPAY_USERNAME') || !Configuration::get('CLICTOPAY_PASSWORD')) {
                $output .= $this->displayError($this->l('Set the ClicToPay API user and password first, then save.'));
            } else {
                $calls = $this->runDiagnostic((string)Tools::getValue('clictopay_test'), trim((string)Tools::getValue('clictopay_order_id')));
            }
        }

        return $output . $this->displayForm() . $this->displayDiagnostics($calls);
    }

    /**
     * Builds the configuration form (API credentials, payment page language, sandbox, debug log).
     *
     * @return string HTML of the form
     */
    public function displayForm()
    {
        // Get default language
        $default_lang = (int)Configuration::get('PS_LANG_DEFAULT');

        // Init Fields form array
        $fields_form[0]['form'] = [
            'legend' => [
                'title' => $this->l('API configuration provided by ClicToPay'),
            ],
            'input' => [
                [
                    'type' => 'text',
                    'label' => $this->l('Api-User Login'),
                    'name' => 'CLICTOPAY_USERNAME',
                    'size' => 20,
                    'required' => true
                ],
                [
                    'type' => 'password',
                    'label' => $this->l('Api-User Password'),
                    'name' => 'CLICTOPAY_PASSWORD',
                    'desc' => $this->l('Leave empty to keep the current password'),
                ],
                [
                    'type' => 'select',
                    'label' => $this->l('Payment page language'),
                    'name' => 'CLICTOPAY_LANGUAGE',
                    'options' => [
                        'query' => [
                            ['id' => 'fr', 'name' => 'Français'],
                            ['id' => 'en', 'name' => 'English'],
                            ['id' => 'ar', 'name' => 'العربية'],
                        ],
                        'id' => 'id',
                        'name' => 'name',
                    ],
                ],
                [
                    'type' => 'switch',
                    'label' => $this->l('Enable Sandbox'),
                    'name' => 'SMT_SANDBOX',
                    'is_bool' => true,
                    'desc' => $this->l('Use test.clictopay.com instead of ipay.clictopay.com'),
                    'values' => [
                        [
                            'id' => 'active_on',
                            'value' => 1,
                            'label' => $this->l('Yes')
                        ],
                        [
                            'id' => 'active_off',
                            'value' => 0,
                            'label' => $this->l('No')
                        ]
                    ],
                ],
                [
                    'type' => 'switch',
                    'label' => $this->l('Debug log'),
                    'name' => 'CLICTOPAY_DEBUG',
                    'is_bool' => true,
                    'desc' => $this->l('Log every ClicToPay API request and response in Advanced Parameters > Logs'),
                    'values' => [
                        [
                            'id' => 'debug_on',
                            'value' => 1,
                            'label' => $this->l('Yes')
                        ],
                        [
                            'id' => 'debug_off',
                            'value' => 0,
                            'label' => $this->l('No')
                        ]
                    ],
                ],
            ],
            'submit' => [
                'title' => $this->l('Save'),
                'class' => 'btn btn-default pull-right'
            ]
        ];

        $helper = new HelperForm();

        // Module, token and currentIndex
        $helper->module = $this;
        $helper->name_controller = $this->name;
        $helper->token = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex = AdminController::$currentIndex . '&configure=' . $this->name;

        // Language
        $helper->default_form_language = $default_lang;
        $helper->allow_employee_form_lang = $default_lang;

        // Title and toolbar
        $helper->title = $this->displayName;
        $helper->show_toolbar = false;
        $helper->submit_action = 'submit' . $this->name;

        // Load current value
        $helper->fields_value['CLICTOPAY_USERNAME'] = Configuration::get('CLICTOPAY_USERNAME');
        $helper->fields_value['CLICTOPAY_PASSWORD'] = '';
        $helper->fields_value['CLICTOPAY_LANGUAGE'] = Configuration::get('CLICTOPAY_LANGUAGE') ?: 'fr';
        $helper->fields_value['SMT_SANDBOX'] = (int)Configuration::get('SMT_SANDBOX');
        $helper->fields_value['CLICTOPAY_DEBUG'] = (int)Configuration::get('CLICTOPAY_DEBUG');

        return $helper->generateForm($fields_form);
    }

    /**
     * Integration test screen: ClicToPay requires the validation grid results
     * to come from the merchant site itself, not from Postman.
     *
     * @param array $calls Results of runDiagnostic() to display
     *
     * @return string HTML of the test panel
     */
    public function displayDiagnostics($calls)
    {
        $this->context->smarty->assign([
            'clictopay_action' => AdminController::$currentIndex . '&configure=' . $this->name . '&token=' . Tools::getAdminTokenLite('AdminModules'),
            'clictopay_sandbox' => (int)Configuration::get('SMT_SANDBOX'),
            'clictopay_calls' => $calls,
        ]);

        return $this->display(__FILE__, 'views/templates/admin/diagnostics.tpl');
    }

    /**
     * Runs the requested test case of the ClicToPay validation grid.
     *
     * @param string $test One of register_valid, register_missing_amount, register_duplicate, status_unknown_order, status
     * @param string $ctp_order_id ClicToPay orderId, used by the "status" test only
     *
     * @return array List of ['label' => string, 'request' => array, 'raw' => string]
     */
    public function runDiagnostic($test, $ctp_order_id = '')
    {
        $calls = [];
        $base_url = $this->context->link->getBaseLink();
        $register = [
            'orderNumber' => 'TEST-REGISTER-' . time(),
            'amount' => 10000,
            'currency' => 788,
            'returnUrl' => $base_url . '?clictopay_test=success',
            'failUrl' => $base_url . '?clictopay_test=fail',
            'language' => 'fr',
        ];

        switch ($test) {
            case 'register_valid':
                $result = $this->apiRequest('register.do', $register, 'TEST register');
                $calls[] = ['label' => $this->l('Valid registration: register.do with every mandatory parameter'), 'request' => $register, 'raw' => $result['raw']];
                break;

            case 'register_missing_amount':
                // Deliberately omits "amount": expected errorCode 4
                $params = $register;
                $params['orderNumber'] = 'TEST-NOAMOUNT-' . time();
                unset($params['amount'], $params['failUrl']);
                $result = $this->apiRequest('register.do', $params, 'TEST register without amount');
                $calls[] = ['label' => $this->l('Missing parameter: register.do without the amount parameter'), 'request' => $params, 'raw' => $result['raw']];
                break;

            case 'register_duplicate':
                $params = $register;
                $params['orderNumber'] = 'TEST-DUPLICATE-ORDER';
                $first = $this->apiRequest('register.do', $params, 'TEST duplicate order number (1)');
                $second = $this->apiRequest('register.do', $params, 'TEST duplicate order number (2)');
                $calls[] = ['label' => $this->l('Duplicate order number: first call'), 'request' => $params, 'raw' => $first['raw']];
                $calls[] = ['label' => $this->l('Duplicate order number: second call with the same orderNumber'), 'request' => $params, 'raw' => $second['raw']];
                break;

            case 'status_unknown_order':
                $ctp_order_id = '00000000-0000-0000-0000-000000000000';
                $result = $this->getOrderStatusExtended($ctp_order_id);
                $calls[] = ['label' => $this->l('Unknown order: getOrderStatusExtended.do with an unknown orderId'), 'request' => ['orderId' => $ctp_order_id], 'raw' => $result['raw']];
                break;

            case 'status':
                if ($ctp_order_id === '') {
                    break;
                }
                $result = $this->getOrderStatusExtended($ctp_order_id);
                $calls[] = ['label' => sprintf($this->l('getOrderStatusExtended.do for orderId %s'), $ctp_order_id), 'request' => ['orderId' => $ctp_order_id], 'raw' => $result['raw']];
                break;
        }

        return $calls;
    }

    /**
     * Registers the hooks used by the module.
     *
     * @return bool
     */
    private function registrationHook()
    {
        if (!$this->registerHook('paymentOptions')
        ) {
            return false;
        }
        return true;
    }

    /**
     * Creates a module order state, copies its icon and stores its id in SMT_OS_<TYPE>.
     *
     * @param string $nameFR Name for French languages
     * @param string $nameEN Name for every other language
     * @param string $type State suffix, also used for the icon views/img/clictopay_<type>.png
     * @param string $color Color shown in the back office
     * @param bool $send_email Send an email to the customer when the order gets this state
     * @param bool $hidden Hide the state from the customer
     * @param bool $delivery Consider the order as shipped
     * @param bool $logable Consider the order as validated (also used for "paid")
     * @param bool $invoice Allow the invoice to be generated
     *
     * @return bool
     */
    private function installOrderState($nameFR, $nameEN, $type, $color = 'LimeGreen', $send_email = false, $hidden = false, $delivery = false, $logable = false, $invoice = false)
    {
        $order_state = new OrderState();
        $order_state->name = array();
        $order_state->module_name = $this->name;
        foreach (Language::getLanguages() as $language) {
            if (Tools::strtolower($language['iso_code']) == 'fr') {
                $order_state->name[$language['id_lang']] = $nameFR;
            } else {
                $order_state->name[$language['id_lang']] = $nameEN;
            }
        }
        $order_state->send_email = $send_email;
        $order_state->color = $color;
        $order_state->hidden = $hidden;
        $order_state->delivery = $delivery;
        $order_state->logable = $logable;
        $order_state->invoice = $invoice;
        $order_state->paid = $logable;
        $order_state->unremovable = true;

        if ($order_state->add()) {
            $source = _PS_MODULE_DIR_ . $this->name . '/views/img/clictopay_' . $type . '.png';
            $destination = _PS_ROOT_DIR_ . '/img/os/' . (int)$order_state->id . '.gif';
            copy($source, $destination);
            Configuration::updateValue('SMT_OS_' . strtoupper($type), (int)$order_state->id);
        }
        return true;
    }

    /**
     * Adds ClicToPay to the payment options of the checkout.
     *
     * @param array $params Hook parameters, contains the current 'cart'
     *
     * @return PaymentOption[] Empty when the module is inactive, not configured or the currency is not supported
     */
    public function hookPaymentOptions($params)
    {
        if (!$this->active
            || !$this->checkCurrency($params['cart'])
            || !Configuration::get('CLICTOPAY_USERNAME')
        ) {
            return [];
        }

        $payment_options = new PaymentOption();

        $action_text = $this->l("Pay with SMT");
        $payment_options->setLogo(Media::getMediaPath(_PS_MODULE_DIR_ . $this->name . '/views/img/payments.png'));

        $action_text .= ' | ' . $this->l("It's easy, simple and secure");

        $payment_options->setModuleName($this->name)
            ->setCallToActionText($action_text)
            ->setAction($this->context->link->getModuleLink($this->name, 'redirect', [], true))
            ->setAdditionalInformation('<p>' . $this->l('You will be redirected to the ClicToPay website to make a payment securely') . '</p>');

        return [$payment_options];
    }

    /**
     * Checks the cart currency is both supported by ClicToPay and enabled for this module.
     *
     * @param Cart $cart
     *
     * @return bool
     */
    public function checkCurrency($cart)
    {
        $currency_order = new Currency($cart->id_currency);
        if (!isset(self::SUPPORTED_CURRENCIES[$currency_order->iso_code])) {
            return false;
        }

        $currencies_module = $this->getCurrency($cart->id_currency);
        if (is_array($currencies_module)) {
            foreach ($currencies_module as $currency_module) {
                if ($currency_order->id == $currency_module['id_currency']) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Base URL of the ClicToPay REST API for the current mode (sandbox or production).
     *
     * @return string
     */
    public function getUrl()
    {
        return Configuration::get('SMT_SANDBOX') == 1 ? self::TEST_URL : self::LIVE_URL;
    }

    /**
     * Writes a message to the PrestaShop logs when the debug log is enabled.
     *
     * @param string $message
     * @param int|null $id_cart Cart the message relates to
     *
     * @return void
     */
    public function log($message, $id_cart = null)
    {
        if (!Configuration::get('CLICTOPAY_DEBUG')) {
            return;
        }
        PrestaShopLogger::addLog('[ClicToPay] ' . $message, 1, null, $id_cart ? 'Cart' : null, $id_cart ? (int)$id_cart : null, true);
    }

    /**
     * POST to a ClicToPay endpoint, credentials are added here.
     *
     * The raw body is what has to be pasted into the integration validation grid.
     *
     * @param string $endpoint e.g. register.do, getOrderStatusExtended.do
     * @param array $params Request parameters, without userName/password
     * @param string $label Name used in the logs, defaults to the endpoint
     * @param int|null $id_cart Cart the call relates to, for the logs
     *
     * @return array ['raw' => string, 'data' => array|null, 'error' => string|null]
     */
    public function apiRequest($endpoint, $params, $label = '', $id_cart = null)
    {
        $url = $this->getUrl() . $endpoint;
        $label = $label ?: $endpoint;
        $params = [
            'userName' => Configuration::get('CLICTOPAY_USERNAME'),
            'password' => Configuration::get('CLICTOPAY_PASSWORD'),
        ] + $params;

        $loggable = $params;
        $loggable['password'] = '***';
        $this->log(sprintf('[%s] POST %s %s', $label, $url, json_encode($loggable)), $id_cart);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 45,
        ]);
        $raw = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $transport_error = curl_error($ch);
        curl_close($ch);

        if ($raw === false) {
            $this->log(sprintf('[%s] TRANSPORT ERROR: %s', $label, $transport_error), $id_cart);
            return ['raw' => '', 'data' => null, 'error' => $transport_error];
        }

        $this->log(sprintf('[%s] HTTP %d %s', $label, $code, $raw), $id_cart);

        $data = json_decode($raw, true);
        if (!is_array($data)) {
            return ['raw' => $raw, 'data' => null, 'error' => $this->l('Invalid response from ClicToPay.')];
        }

        return ['raw' => $raw, 'data' => $data, 'error' => null];
    }

    /**
     * Registers the cart on ClicToPay (register.do) and stores the returned orderId.
     *
     * @param Cart $cart
     *
     * @return array ['formUrl' => string] on success, ['error' => string] otherwise
     */
    public function registerCart(Cart $cart)
    {
        $currency = new Currency($cart->id_currency);
        if (!isset(self::SUPPORTED_CURRENCIES[$currency->iso_code])) {
            return ['error' => sprintf($this->l('Currency %s is not supported by ClicToPay.'), $currency->iso_code)];
        }
        $iso = self::SUPPORTED_CURRENCIES[$currency->iso_code];

        // A per-attempt order number, so retrying a payment never hits errorCode 1 (duplicate order number)
        $attempt = 1 + (int)Db::getInstance()->getValue('SELECT COUNT(*) FROM `' . _DB_PREFIX_ . 'clictopay_transaction` WHERE `id_cart` = ' . (int)$cart->id);
        $order_number = $attempt > 1 ? $cart->id . '-' . $attempt : (string)$cart->id;

        $return_url = $this->context->link->getModuleLink($this->name, 'return', [
            'id_cart' => (int)$cart->id,
            'key' => $cart->secure_key,
        ], true);

        $result = $this->apiRequest('register.do', [
            'orderNumber' => $order_number,
            'amount' => (int)round($cart->getOrderTotal(true, Cart::BOTH) * $iso['multiplier']),
            'currency' => $iso['code'],
            'returnUrl' => $return_url,
            'failUrl' => $return_url,
            'description' => sprintf($this->l('Cart %s'), $order_number),
            'language' => Configuration::get('CLICTOPAY_LANGUAGE') ?: 'fr',
            // Always DESKTOP: that template is responsive, while the MOBILE one answers 404
            'pageView' => 'DESKTOP',
        ], 'register', $cart->id);

        if ($result['error']) {
            return ['error' => $this->l('Connection error.')];
        }

        $body = $result['data'];
        if (isset($body['errorCode']) && (int)$body['errorCode'] !== 0) {
            return ['error' => isset($body['errorMessage']) ? $body['errorMessage'] : $this->l('Payment registration failed.')];
        }

        if (empty($body['orderId']) || empty($body['formUrl'])) {
            return ['error' => $this->l('Invalid response from ClicToPay.')];
        }

        // orderId is the key accepted by getOrderStatusExtended.do, so it must be stored
        Db::getInstance()->insert('clictopay_transaction', [
            'id_cart' => (int)$cart->id,
            'order_number' => pSQL($order_number),
            'ctp_order_id' => pSQL($body['orderId']),
            'date_add' => date('Y-m-d H:i:s'),
        ]);

        return ['formUrl' => $body['formUrl']];
    }

    /**
     * ClicToPay orderId of the latest register.do call for this cart.
     *
     * @param int $id_cart
     *
     * @return string Empty string when the cart was never registered
     */
    public function getLastCtpOrderId($id_cart)
    {
        return (string)Db::getInstance()->getValue('
            SELECT `ctp_order_id` FROM `' . _DB_PREFIX_ . 'clictopay_transaction`
            WHERE `id_cart` = ' . (int)$id_cart . '
            ORDER BY `id_clictopay_transaction` DESC');
    }

    /**
     * Query the real status of an order (getOrderStatusExtended.do). Never trust the redirection alone.
     *
     * @param string $ctp_order_id ClicToPay orderId returned by register.do
     * @param int|null $id_cart Cart the call relates to, for the logs
     *
     * @return array Same format as apiRequest()
     */
    public function getOrderStatusExtended($ctp_order_id, $id_cart = null)
    {
        return $this->apiRequest('getOrderStatusExtended.do', [
            'orderId' => $ctp_order_id,
            'language' => Configuration::get('CLICTOPAY_LANGUAGE') ?: 'fr',
        ], 'getOrderStatusExtended', $id_cart);
    }
}
