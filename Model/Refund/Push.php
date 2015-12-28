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
 * to servicedesk@totalinternetgroup.nl so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade this module to newer
 * versions in the future. If you wish to customize this module for your
 * needs please contact servicedesk@totalinternetgroup.nl for more information.
 *
 * @copyright   Copyright (c) 2015 Total Internet Group B.V. (http://www.totalinternetgroup.nl)
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 */
namespace TIG\Buckaroo\Model\Refund;

use TIG\Buckaroo\Exception;

/**
 * Class Creditmemo
 *
 * @package TIG\Buckaroo\Model\Refund
 */
class Push
{
    public $postData;

    public $creditAmount;

    /** @var \Magento\Sales\Model\Order $order  */
    public $order;

    /** @var  \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory */
    public $creditmemoFactory;

    /** @var \Magento\Sales\Model\Order\Email\Sender\CreditmemoSender $creditEmailSender */
    public $creditEmailSender;

    /** @var \Magento\Sales\Controller\Adminhtml\Order\CreditmemoLoader $creditmemoLoader */
    public $creditmemoLoader;

    /** @var \TIG\Buckaroo\Debug\Debugger $debugger */
    public $debugger;

    /**
     * @param \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory
     * @param \Magento\Framework\ObjectManagerInterface $objectManager
     * @param \Magento\Sales\Model\Order\Email\Sender\CreditmemoSender $creditEmailSender
     * @param \Magento\Sales\Controller\Adminhtml\Order\CreditmemoLoader $creditmemoLoader
     */
    public function __construct(
        \Magento\Sales\Model\Order\CreditmemoFactory $creditmemoFactory,
        \Magento\Framework\ObjectManagerInterface $objectManager,
        \Magento\Sales\Model\Order\Email\Sender\CreditmemoSender $creditEmailSender,
        \Magento\Sales\Controller\Adminhtml\Order\CreditmemoLoader $creditmemoLoader,
        \TIG\Buckaroo\Debug\Debugger $debugger
    ) {
        $this->creditmemoFactory  = $creditmemoFactory;
        $this->objectManager      = $objectManager;
        $this->creditEmailSender  = $creditEmailSender;
        $this->creditmemoLoader   = $creditmemoLoader;
        $this->debugger           = $debugger;
    }

    /**
     * This is called when a refund is made in Buckaroo Payment Plaza.
     * This Function will result in a creditmemo being created for the order in question.
     *
     * @param Array $postData
     * @param Bool $signatureValidation
     * @param $order
     *
     * @return bool
     * @throws \TIG\Buckaroo\Exception
     */
    public function receiveRefundPush($postData, $signatureValidation, $order)
    {
        $this->postData = $postData;
        $this->order    = $order;

        $this->debugger->addToMessage('Trying to refund order ' . $this->order->getId(). ' out of paymentplaza');

        if (!$signatureValidation && !$this->order->canCreditmemo()) {
            $this->debugger->addToMessage('Validation incorrect :');
            $this->debugger->addToMessage([
               'signature'      => $signatureValidation,
               'canOrderCredit' => $this->order->canCreditmemo()
            ]);
            throw new Exception(
                __('Buckaroo refund push validation failed')
            );
        }

        $creditmemo = $this->createCreditmemo();

        $this->debugger->addToMessage('Order successfull refunded = '. $creditmemo);
        $this->debugger->log();

        return $creditmemo;
    }

    /**
     * Create the creditmemo
     */
    public function createCreditmemo()
    {
        $creditData = $this->getCreditmemoData();
        $creditmemo = $this->initCreditmemo($creditData);

        try {
            if ($creditmemo) {
                if (!$creditmemo->isValidGrandTotal()) {
                    $this->debugger->addToMessage('The credit memo\'s total must be positive.');
                    throw new \Magento\Framework\Exception\LocalizedException(
                        __('The credit memo\'s total must be positive.')
                    );
                }
                $creditmemo->setTransactionId($this->postData['brq_transactions']);
                $this->saveCreditmemo($creditmemo, (bool)$creditData['do_offline'], !empty($creditData['send_email']));
                if (!empty($data['send_email'])) {
                    $this->$creditEmailSender->send($creditmemo);
                }
                return true;
            } else {
                $this->debugger->addToMessage('Failed to create the creditmemo, method saveCreditmemo return value :');
                $this->debugger->addToMessage($creditmemo);
                throw new Exception(
                    __('Failed to create the creditmemo')
                );
            }
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->debugger->addToMessage('Buckaroo failed to create the credit memo\'s { '. $e->getLogMessage().' }');
        }
        return false;
    }

    /**
     * Save creditmemo and related order, invoice in one transaction
     *
     * @param \Magento\Sales\Model\Order\Creditmemo $creditmemo
     * @param bool $do_offline
     * @param bool $send_email
     */
    public function saveCreditmemo($creditmemo, $do_offline, $send_email)
    {
        $creditmemoManagement = $this->objectManager->create(
            'Magento\Sales\Api\CreditmemoManagementInterface'
        );

        $creditmemoManagement->refund($creditmemo, $do_offline, $send_email);
    }

    /**
     * @param $creditData
     *
     * @return \Magento\Sales\Model\Order\Creditmemo
     * @throws \Magento\Framework\Exception\LocalizedException
     */
    public function initCreditmemo($creditData)
    {
        try {
            /** @var \Magento\Sales\Model\Order\Creditmemo $creditmemo */
            $creditmemo = $this->creditmemoFactory->createByOrder($this->order, $creditData);

            foreach ($creditmemo->getAllItems() as $creditmemoItem) {
                $creditmemoItem->setBackToStock(false);
            }

            return $creditmemo;
        } catch (\Magento\Framework\Exception\LocalizedException $e) {
            $this->debugger->addToMessage(
                'Buckaroo can not initialize the credit memo\'s by order { '. $e->getLogMessage().' }'
            );
        }
        return false;
    }

    /**
     * Create array of data to use within the creditmemo.
     *
     * @return array
     */
    public function getCreditmemoData()
    {
        $data = [
            'do_offline'   => '0',
            'do_refund'    => '0',
            'comment_text' => ' '
        ];

        $totalAmountToRefund = $this->totalAmountToRefund();
        $this->creditAmount  = $totalAmountToRefund + $this->order->getBaseTotalRefunded();

        if ($this->creditAmount != $this->order->getBaseGrandTotal()) {
            $this->debugger->addToMessage('This is an adjustment refund of '. $totalAmountToRefund);
            $data['shipping_amount']     = '0';
            $data['adjustment_negative'] = '0';
            $data['adjustment_positive'] = $this->getAdjustmentRefundData();
            $data['items']               = '0';
            $data['qtys']                = '0';
        } else {
            $this->debugger->addToMessage(
                'With this refund of '. $this->creditAmount.' the grand total will be refunded.'
            );
            $data['shipping_amount']     = $this->caluclateShippingCostToRefund();
            $data['adjustment_negative'] = $this->getTotalCreditAdjustments();
            $data['adjustment_positive'] = $this->calculateRemainder();
            $data['items']               = $this->getCreditmemoDataItems();
            $data['qtys']                = $this->setCreditQtys($data['items']);
        }

        $this->debugger->addToMessage('Data used for credit nota : ');
        $this->debugger->addToMessage($data);

        return $data;
    }

    /**
     * Get total of adjustments made by previous credits.
     * @return int
     */
    public function getTotalCreditAdjustments()
    {
        $totalAdjustments = 0;

        foreach ($this->order->getCreditmemosCollection() as $creditmemo) {
            /** @var \Magento\Sales\Model\Order\Creditmemo $creditmemo */
            $adjustment = $creditmemo->getBaseAdjustmentPositive() - $creditmemo->getBaseAdjustmentNegative();
            $totalAdjustments += $adjustment;
        }

        return $totalAdjustments;
    }

    /**
     * Get adjustment refund data
     *
     * @return float
     */
    public function getAdjustmentRefundData()
    {
        $totalAmount = $this->totalAmountToRefund();

        if ($this->order->getBaseTotalRefunded() == null) {
            $totalAmount = $totalAmount
                - ($this->order->getBaseBuckarooFee() + $this->order->getBuckarooFeeBaseTaxAmountInvoiced());
        }

        return $totalAmount;
    }

    /**
     * Calculate the amount to be refunded.
     *
     * @return int $amount
     */
    public function totalAmountToRefund()
    {
        if ($this->postData['brq_currency'] == $this->order->getBaseCurrencyCode()) {
            $amount = $this->postData['brq_amount_credit'];
        } else {
            $amount = round($this->postData['brq_amount_credit'] * $this->order->getBaseToOrderRate(), 2);
        }

        return $amount;
    }

    /**
     * Cacluate the remainder of to be refunded
     *
     * @return float
     */
    public function calculateRemainder()
    {
        $baseTotalToBeRefunded = $this->caluclateShippingCostToRefund() +
            ($this->order->getBaseSubtotal() - $this->order->getBaseSubtotalRefunded()) +
            ($this->order->getBaseAdjustmentNegative() - $this->order->getBaseAdjustmentPositive()) +
            ($this->order->getBaseTaxAmount() - $this->order->getBaseTaxRefunded()) +
            ($this->order->getBaseDiscountAmount() - $this->order->getBaseDiscountRefunded());

        $remainderToRefund = $this->order->getBaseGrandTotal()
            - $baseTotalToBeRefunded
            - $this->order->getBaseTotalRefunded();

        if ($this->totalAmountToRefund() == $this->order->getBaseGrandTotal()) {
            $remainderToRefund = 0;
        }

        return $remainderToRefund;
    }

    /**
     * Calculate the total of shipping cost to be refunded.
     *
     * @return float
     */
    public function caluclateShippingCostToRefund()
    {
        return $this->order->getBaseShippingAmount()
        - $this->order->getBaseShippingRefunded();
    }

    /**
     * Check if there are items to correct on the creditmemo
     *
     * @return array $items
     */
    public function getCreditmemoDataItems()
    {
        $items = [];
        $qty   = 0;

        foreach ($this->order->getAllItems() as $orderItem) {
            /** @var \Magento\Sales\Model\Order\Item $orderItem */
            if (!array_key_exists($orderItem->getId(), $items)) {
                if ((float)$this->creditAmount == (float)$this->order->getBaseGrandTotal()) {
                    $qty = $orderItem->getQtyInvoiced() - $orderItem->getQtyRefunded();
                }

                $items[$orderItem->getId()] = ['qty' => (int)$qty];

            }
        }

        $this->debugger->addToMessage('Total items to be refunded : ');
        $this->debugger->addToMessage($items);

        return $items;
    }

    /**
     * Set quantity items
     *
     * @param array $items
     *
     * @return array $qtys
     */
    public function setCreditQtys($items)
    {
        $qtys = [];

        if (!empty($items)) {
            foreach ($items as $orderItemId => $itemData) {
                $qtys[$orderItemId] = $itemData['qty'];
            }
        }

        return $qtys;
    }
}
