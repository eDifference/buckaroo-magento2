<?php
/**
 *                  ___________       __            __
 *                  \__    ___/____ _/  |_ _____   |  |
 *                    |    |  /  _ \\   __\\__  \  |  |
 *                    |    | |  |_| ||  |   / __ \_|  |__
 *                    |____|  \____/ |__|  (____  /|____/
 *                                              \/
 *          ___          __                                   __
 *         |   |  ____ _/  |_   ____ _______   ____    ____ _/  |_
 *         |   | /    \\   __\_/ __ \\_  __ \ /    \ _/ __ \\   __\
 *         |   ||   |  \|  |  \  ___/ |  | \/|   |  \\  ___/ |  |
 *         |___||___|  /|__|   \_____>|__|   |___|  / \_____>|__|
 *                  \/                           \/
 *                  ________
 *                 /  _____/_______   ____   __ __ ______
 *                /   \  ___\_  __ \ /  _ \ |  |  \\____ \
 *                \    \_\  \|  | \/|  |_| ||  |  /|  |_| |
 *                 \______  /|__|    \____/ |____/ |   __/
 *                        \/                       |__|
 *
 * NOTICE OF LICENSE
 *
 * This source file is subject to the Creative Commons License.
 * It is available through the world-wide-web at this URL:
 * http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 * If you are unable to obtain it through the world-wide-web, please send an email
 * to servicedesk@tig.nl so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this module to newer
 * versions in the future. If you wish to customize this module for your
 * needs please contact servicedesk@tig.nl for more information.
 *
 * @copyright Copyright (c) Total Internet Group B.V. https://tig.nl/copyright
 * @license   http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 */

namespace TIG\Buckaroo\Model\Total\Invoice;

class BuckarooFee extends \Magento\Sales\Model\Order\Invoice\Total\AbstractTotal
{
    /**
     * Collect Buckaroo fee total for invoice
     *
     * @param  \Magento\Sales\Model\Order\Invoice $invoice
     * @return $this
     */
    public function collect(\Magento\Sales\Model\Order\Invoice $invoice)
    {
        $order = $invoice->getOrder();

        /**
         * @noinspection PhpUndefinedMethodInspection
         */
        $buckarooFeeLeft = $order->getBuckarooFee() - $order->getBuckarooFeeInvoiced();
        /**
         * @noinspection PhpUndefinedMethodInspection
         */
        $baseBuckarooFeeLeft = $order->getBaseBuckarooFee() - $order->getBaseBuckarooFeeInvoiced();
        /**
         * @noinspection PhpUndefinedMethodInspection
         */
        if ($order->getBaseBuckarooFee() && $baseBuckarooFeeLeft > 0) {
            if ($baseBuckarooFeeLeft < $invoice->getBaseGrandTotal()) {
                $invoice->setGrandTotal($invoice->getGrandTotal() + $buckarooFeeLeft);
                $invoice->setBaseGrandTotal($invoice->getBaseGrandTotal() + $baseBuckarooFeeLeft);
            } else {
                $buckarooFeeLeft = $invoice->getGrandTotal();
                $baseBuckarooFeeLeft = $invoice->getBaseGrandTotal();

                $invoice->setGrandTotal(0);
                $invoice->setBaseGrandTotal(0);
            }

            /**
             * @noinspection PhpUndefinedMethodInspection
             */
            $invoice->setBuckarooFee($buckarooFeeLeft);
            /**
             * @noinspection PhpUndefinedMethodInspection
             */
            $invoice->setBaseBuckarooFee($baseBuckarooFeeLeft);
        }

        return $this;
    }
}
