<?php
/**
 *
 *          ..::..
 *     ..::::::::::::..
 *   ::'''''':''::'''''::
 *   ::..  ..:  :  ....::
 *   ::::  :::  :  :   ::
 *   ::::  :::  :  ''' ::
 *   ::::..:::..::.....::
 *     ''::::::::::::''
 *          ''::''
 *
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
 * @copyright   Copyright (c) Total Internet Group B.V. https://tig.nl/copyright
 * @license     http://creativecommons.org/licenses/by-nc-nd/3.0/nl/deed.en_US
 */
namespace TIG\Buckaroo\Model\Method;

class PaymentGuarantee extends AbstractMethod
{
    /**
     * Payment Code
     */
    const PAYMENT_METHOD_CODE = 'tig_buckaroo_paymentguarantee';

    /**
     * @var string
     */
    public $buckarooPaymentMethodCode = 'paymentguarantee';

    // @codingStandardsIgnoreStart
    /**
     * Payment method code
     *
     * @var string
     */
    protected $_code                    = self::PAYMENT_METHOD_CODE;

    /**
     * @var bool
     */
    protected $_isGateway               = true;

    /**
     * @var bool
     */
    protected $_canOrder                = true;

    /**
     * @var bool
     */
    protected $_canAuthorize            = true;

    /**
     * @var bool
     */
    protected $_canCapture              = true;

    /**
     * @var bool
     */
    protected $_canCapturePartial       = true;

    /**
     * @var bool
     */
    protected $_canRefund               = true;

    /**
     * @var bool
     */
    protected $_canVoid                 = false;

    /**
     * @var bool
     */
    protected $_canUseInternal          = true;

    /**
     * @var bool
     */
    protected $_canUseCheckout          = true;

    /**
     * @var bool
     */
    protected $_canRefundInvoicePartial = true;

    /**
     * @var bool
     */
    public $closeAuthorizeTransaction   = false;

    /**
     * @var bool
     */
    protected $_isPartialCapture        = false;
    // @codingStandardsIgnoreEnd

    /**
     * {@inheritdoc}
     */
    public function assignData(\Magento\Framework\DataObject $data)
    {
        parent::assignData($data);
        $data = $this->assignDataConvertAllVersionsArray($data);

        if (!isset($data['additional_data']['termsCondition'])) {
            return $this;
        }

        $additionalData = $data['additional_data'];
        $this->getInfoInstance()->setAdditionalInformation('termsCondition', $additionalData['termsCondition']);
        $this->getInfoInstance()->setAdditionalInformation('customer_gender', $additionalData['customer_gender']);
        $this->getInfoInstance()->setAdditionalInformation(
            'customer_billingName',
            $additionalData['customer_billingName']
        );
        $this->getInfoInstance()->setAdditionalInformation('customer_DoB', $additionalData['customer_DoB']);
        $this->getInfoInstance()->setAdditionalInformation('customer_iban', $additionalData['customer_iban']);

        return $this;
    }

    /**
     * Check capture availability
     *
     * @return bool
     * @api
     */
    public function canCapture()
    {
        if ($this->getConfigData('payment_action') == 'order') {
            return false;
        }
        return $this->_canCapture;
    }

    /**
     * {@inheritdoc}
     */
    public function getOrderTransactionBuilder($payment)
    {
        $transactionBuilder = $this->transactionBuilderFactory->get('order');

        $services = [
            'Name'             => 'paymentguarantee',
            'Action'           => 'PaymentInvitation',
            'Version'          => 1,
            'RequestParameter' => $this->getPaymentGuaranteeRequestParameters($payment),
        ];

        /** @noinspection PhpUndefinedMethodInspection */
        $transactionBuilder->setOrder($payment->getOrder())
            ->setServices($services)
            ->setMethod('TransactionRequest');

        return $transactionBuilder;
    }

    /**
     * {@inheritdoc}
     */
    public function getCaptureTransactionBuilder($payment)
    {
        $transactionBuilder = $this->transactionBuilderFactory->get('order');

        $services = [
            'Name'             => 'paymentguarantee',
            'Action'           => 'PartialInvoice',
            'Version'          => 1,
            'RequestParameter' => $this->keepKeysFromParameters(
                [
                    'AmountVat',
                    'InvoiceDate',
                    'DateDue',
                    'PaymentMethodsAllowed',
                    'SendMail',
                    'CustomerCode'
                ],
                $this->getPaymentGuaranteeRequestParameters($payment)
            )
        ];

        /** @var \Magento\Sales\Model\Order $order */
        $order       = $payment->getOrder();
        $totalAmount = $this->calculateInvoiceAmount($order);

        $transactionBuilder->setOrder($order)
            ->setServices($services)
            ->setAmount($totalAmount)
            ->setMethod('TransactionRequest')
            ->setOriginalTransactionKey(
                $payment->getAdditionalInformation(
                    self::BUCKAROO_ORIGINAL_TRANSACTION_KEY_KEY
                )
            );

        if ($this->_isPartialCapture) {
            /** @noinspection PhpUndefinedMethodInspection */
            $transactionBuilder->setInvoiceId($this->getPartialInvoiceId($order))
                ->setOriginalTransactionKey(
                    $payment->getParentTransactionId()
                );
        }

        return $transactionBuilder;
    }

    /**
     * {@inheritdoc}
     */
    public function getAuthorizeTransactionBuilder($payment)
    {
        $transactionBuilder = $this->transactionBuilderFactory->get('order');

        $services = [
            'Name'             => 'paymentguarantee',
            'Action'           => 'Order',
            'Version'          => 1,
            'RequestParameter' => $this->stripKeysFromParameters(
                [
                    'InvoiceDate',
                    'DateDue',
                    'PaymentMethodsAllowed'
                ],
                $this->getPaymentGuaranteeRequestParameters($payment)
            )
        ];

        /** @noinspection PhpUndefinedMethodInspection */
        $transactionBuilder->setOrder($payment->getOrder())
            ->setServices($services)
            ->setMethod('TransactionRequest');

        return $transactionBuilder;
    }

    /**
     * {@inheritdoc}
     */
    public function getRefundTransactionBuilder($payment)
    {
        $transactionBuilder = $this->transactionBuilderFactory->get('refund');

        $services = [
            'Name'    => 'paymentguarantee',
            'Action'  => 'CreditNote',
            'Version' => 1,
        ];

        $requestParams = $this->addExtraFields($this->_code);
        $services = array_merge($services, $requestParams);

        /**
         * @noinspection PhpUndefinedMethodInspection
         */
        $transactionBuilder->setOrder($payment->getOrder())
            ->setServices($services)
            ->setMethod('TransactionRequest')
            ->setOriginalTransactionKey(
                $payment->getAdditionalInformation(self::BUCKAROO_ORIGINAL_TRANSACTION_KEY_KEY)
            )
            ->setChannel('CallCenter');

        return $transactionBuilder;
    }

    /**
     * {@inheritdoc}
     */
    public function getVoidTransactionBuilder($payment)
    {
        return false;
    }

    /**
     * @param array $parameters
     * @param array $keys
     *
     * @return array
     */
    private function stripKeysFromParameters($keys, $parameters)
    {
        $stripped = array_filter($parameters, function ($value) use ($keys) {
             return !in_array($value['Name'], $keys);
        });

        return array_values($stripped);
    }

    /**
     * @param $keys
     * @param $parameters
     *
     * @return array
     */
    private function keepKeysFromParameters($keys, $parameters)
    {
        $stripped = array_filter($parameters, function ($value) use ($keys) {
            return in_array($value['Name'], $keys);
        });

        return array_values($stripped);
    }

    /**
     * @param \Magento\Sales\Api\Data\OrderPaymentInterface|\Magento\Payment\Model\InfoInterface $payment
     *
     * @return array
     */
    private function getPaymentGuaranteeRequestParameters($payment)
    {
        /** @var \TIG\Buckaroo\Model\ConfigProvider\Method\PaymentGuarantee $config */
        $config = $this->configProviderMethodFactory->get('paymentguarantee');

        /** @var \Magento\Sales\Api\Data\OrderAddressInterface $billingAddress */
        $billingAddress = $payment->getOrder()->getBillingAddress();
        /** @var \Magento\Sales\Api\Data\OrderAddressInterface $shippingAddress */
        $shippingAddress = $payment->getOrder()->getShippingAddress();

        $customerId = $billingAddress->getCustomerId()
            ? $billingAddress->getCustomerId()
            : $payment->getOrder()->getIncrementId();

        $defaultValues = [
            [
                '_'    => $config->getPaymentFee(),
                'Name' => 'AmountVat'
            ],
            [
                '_'    => date('Y-m-d'),
                'Name' => 'InvoiceDate'
            ],
            [
                '_'    => date('Y-m-d',strtotime('+14 day', time())),
                'Name' => 'DateDue'
            ],
            [
                '_'    => $customerId,
                'Name' => 'CustomerCode'
            ],
            [
                '_'    => strtoupper(substr($billingAddress->getFirstname(), 0, 1)),
                'Name' => 'CustomerInitials'
            ],
            [
                '_'    => $billingAddress->getFirstname(),
                'Name' => 'CustomerFirstName'
            ],
            [
                '_'    => $billingAddress->getLastname(),
                'Name' => 'CustomerLastName'
            ],
            [
                '_'    => $payment->getAdditionalInformation('customer_gender'),
                'Name' => 'CustomerGender'
            ],
            [
                '_'    => $payment->getAdditionalInformation('customer_DoB'),
                'Name' => 'CustomerBirthDate'
            ],
            [
                '_'    => $billingAddress->getEmail(),
                'Name' => 'CustomerEmail'
            ],
            [
                '_'    => $billingAddress->getTelephone(),
                'Name' => 'PhoneNumber'
            ],
            [
                '_'    => $config->getPaymentMethodToUse(),
                'Name' => 'PaymentMethodsAllowed'
            ],
            [
                '_'    => $config->getSendMail(),
                'Name' => 'SendMail'
            ]
        ];

        if ($payment->getAdditionalInformation('customer_iban')) {
            $defaultValues = array_merge($defaultValues, [
                [
                    '_'    => $payment->getAdditionalInformation('customer_iban'),
                    'Name' => 'CustomerIBAN'
                ]
            ]);
        }

        if ($this->isAddressDataDifferent($billingAddress->getData(), $shippingAddress->getData())) {
            $returnValues = array_merge($defaultValues, $this->singleAddress($billingAddress, 'INVOICE'));
            return array_merge($returnValues, $this->singleAddress($shippingAddress, 'SHIPPING', 2));
        }

        return array_merge($defaultValues, $this->singleAddress($billingAddress, 'INVOICE,SHIPPING'));

    }

    /**
     * @param \Magento\Sales\Api\Data\OrderAddressInterface $address
     * @param string $addressType
     * @param int $id
     *
     * @return array
     */
    private function singleAddress($address, $addressType, $id = 1)
    {
        $street = $this->formatStreet($address->getStreet());

        return [
            [
                '_'       => $addressType,
                'Name'    => 'AddressType',
                'Group'   => 'address',
                'GroupID' => 'address_'.$id
            ],
            [
                '_'       => $street['street'],
                'Name'    => 'Street',
                'Group'   => 'address',
                'GroupID' => 'address_'.$id
            ],
            [
                '_'       => $street['house_number'],
                'Name'    => 'HouseNumber',
                'Group'   => 'address',
                'GroupID' => 'address_'.$id
            ],
            [
                '_'       => $address->getPostcode(),
                'Name'    => 'ZipCode',
                'Group'   => 'address',
                'GroupID' => 'address_'.$id
            ],
            [
                '_'       => $address->getCity(),
                'Name'    => 'City',
                'Group'   => 'address',
                'GroupID' => 'address_'.$id
            ],
            [
                '_'       => $address->getCountryId(),
                'Name'    => 'Country',
                'Group'   => 'address',
                'GroupID' => 'address_'.$id
            ]
        ];
    }

    /**
     * @param $addressOne
     * @param $addressTwo
     *
     * @return bool
     */
    private function isAddressDataDifferent($addressOne, $addressTwo)
    {
        $keysToExclude = array_flip([
            'prefix',
            'telephone',
            'fax',
            'created_at',
            'email',
            'customer_address_id',
            'vat_request_success',
            'vat_request_date',
            'vat_request_id',
            'vat_is_valid',
            'vat_id',
            'address_type'
        ]);

        $arrayDifferences = array_diff(
            array_diff_key($addressOne, $keysToExclude),
            array_diff_key($addressTwo, $keysToExclude)
        );

        return !empty($arrayDifferences);
    }

    /**
     * @param $street
     *
     * @return array
     */
    private function formatStreet($street)
    {
        // Street is always an array since it is parsed with two field objects.
        // Nondeless it could be that only the first field is parsed to the array
        if (isset($street[1])) {
            $street = $street[0] . ' ' . $street[1];
        } else {
            $street = $street[0];
        }

        $format = [
            'house_number'    => '',
            'number_addition' => '',
            'street'          => $street
        ];

        if (preg_match('#^(.*?)([0-9]+)(.*)#s', $street, $matches)) {
            // Check if the number is at the beginning of streetname
            if ('' == $matches[1]) {
                $format['house_number'] = trim($matches[2]);
                $format['street']       = trim($matches[3]);
            } else {
                $format['street']          = trim($matches[1]);
                $format['house_number']    = trim($matches[2]);
                $format['number_addition'] = trim($matches[3]);
            }
        }

        return $format;
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @return int|float
     */
    private function calculateInvoiceAmount($order)
    {
        $invoiceAmount = 0;

        if (!$order->hasInvoices()) {
            return $invoiceAmount;
        }

        $i = 0;
        /** @var \Magento\Sales\Model\Order\Invoice $invoice */
        foreach ($order->getInvoiceCollection() as $invoice) {
            if (++$i !== $order->hasInvoices()) {
                continue;
            }
            $invoiceAmount = $invoice->getBaseGrandTotal();
        }

        $this->setCaptureType($order, $invoiceAmount);
        return $invoiceAmount;
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     *
     * @return string
     */
    private function getPartialInvoiceId($order)
    {
        return $order->getIncrementId() . '-'
            . $order->hasInvoices() . '-'
            . substr(md5(date("YMDHis")), 0, 6);
    }

    /**
     * @param \Magento\Sales\Model\Order $order
     * @param $invoiceAmount
     */
    private function setCaptureType($order, $invoiceAmount)
    {
        $this->_isPartialCapture = !($order->getBaseGrandTotal() == $invoiceAmount && $order->hasInvoices() == 1);
    }
}
