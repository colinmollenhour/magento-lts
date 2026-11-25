<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Sales
 */

/**
 * Sales Billing Agreement Payment Method Abstract model
 *
 * @package    Mage_Sales
 */
abstract class Mage_Sales_Model_Payment_Method_Billing_AgreementAbstract extends Mage_Payment_Model_Method_Abstract
{
    /**
     * Transport billing agreement id
     */
    public const TRANSPORT_BILLING_AGREEMENT_ID = 'ba_agreement_id';

    public const PAYMENT_INFO_REFERENCE_ID      = 'ba_reference_id';

    protected $_infoBlockType = 'sales/payment_info_billing_agreement';

    protected $_formBlockType = 'sales/payment_form_billing_agreement';

    /**
     * Is method instance available
     *
     * @var null|bool
     */
    protected $_isAvailable = null;

    /**
     * Check whether method is available
     *
     * @param  Mage_Sales_Model_Quote $quote
     * @return bool
     */
    #[Override]
    public function isAvailable($quote = null)
    {
        if (is_null($this->_isAvailable)) {
            if (is_object($quote) && $quote->getCustomer()) {
                $availableBA = Mage::getModel('sales/billing_agreement')->getAvailableCustomerBillingAgreements(
                    $quote->getCustomer()->getId(),
                );
                $isAvailableBA = count($availableBA) > 0;
                $this->_canUseForMultishipping = $isAvailableBA;
                $this->_canUseCheckout = $isAvailableBA;
                $this->_canUseInternal = $isAvailableBA;
            }

            $this->_isAvailable = parent::isAvailable($quote) && $this->_isAvailable($quote);
            $this->_canUseCheckout = ($this->_isAvailable && $this->_canUseCheckout);
            $this->_canUseForMultishipping = ($this->_isAvailable && $this->_canUseForMultishipping);
            $this->_canUseInternal = ($this->_isAvailable && $this->_canUseInternal);
        }

        return $this->_isAvailable;
    }

    /**
     * Assign data to info model instance
     *
     * The billing agreement fields of the additional information are set only from the transport field, and only
     * when the agreement belongs to the quote customer and is active. Values submitted any other way are cleared.
     *
     * @param  mixed                              $data
     * @return Mage_Payment_Model_Method_Abstract
     * @throws Mage_Core_Exception
     */
    #[Override]
    public function assignData($data)
    {
        $result = parent::assignData($data);

        $key = self::TRANSPORT_BILLING_AGREEMENT_ID;
        $id = null;
        if (is_array($data)) {
            $id = $data[$key] ?? null;
        } elseif ($data instanceof Varien_Object) {
            $id = $data->getData($key);
        }

        $info = $this->getInfoInstance();
        $additionalInformation = $info->getAdditionalInformation();
        if (!is_array($additionalInformation)) {
            $additionalInformation = [];
        }

        unset($additionalInformation[$key], $additionalInformation[self::PAYMENT_INFO_REFERENCE_ID]);
        $info->setAdditionalInformation($additionalInformation);

        if ($id === null || $id === '') {
            return $result;
        }

        $agreement = $this->_loadBillingAgreement($id);
        if (!$agreement instanceof Mage_Sales_Model_Billing_Agreement
            || !$agreement->canBeUsedByCustomer($info->getQuote()->getCustomer()->getId())
        ) {
            Mage::throwException(Mage::helper('sales')->__('Wrong billing agreement ID specified.'));
        }

        $info->setAdditionalInformation($key, $agreement->getId())
            ->setAdditionalInformation(self::PAYMENT_INFO_REFERENCE_ID, $agreement->getReferenceId());

        return $result;
    }

    /**
     * Load a billing agreement by id
     *
     * Does not check the owner or status: use Mage_Sales_Model_Billing_Agreement::canBeUsedByCustomer() for that.
     *
     * @param  mixed                                   $agreementId
     * @return null|Mage_Sales_Model_Billing_Agreement null when the id is empty, not an int or string, or unknown
     */
    protected function _loadBillingAgreement($agreementId)
    {
        if ((!is_int($agreementId) && !is_string($agreementId)) || in_array($agreementId, [0, '', '0'], true)) {
            return null;
        }

        /** @var Mage_Sales_Model_Billing_Agreement $agreement */
        $agreement = Mage::getModel('sales/billing_agreement')->load($agreementId);
        return $agreement->getId() === null ? null : $agreement;
    }

    /**
     * @param Mage_Sales_Model_Quote $quote
     */
    abstract protected function _isAvailable($quote);
}
