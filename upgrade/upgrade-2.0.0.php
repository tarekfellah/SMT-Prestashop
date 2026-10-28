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

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * 2.0.0: legacy SMT affiliate protocol replaced by the ClicToPay REST API (register.do / getOrderStatusExtended.do).
 *
 * @param Clictopay $module
 *
 * @return bool
 */
function upgrade_module_2_0_0($module)
{
    Configuration::deleteByName('SMT_ID');
    Configuration::updateValue('CLICTOPAY_LANGUAGE', 'fr');
    Configuration::updateValue('CLICTOPAY_DEBUG', 1);

    return $module->installDb() && $module->registerHook('paymentOptions');
}
