<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Customer
 */

/**
 * Abstract API2 class for customer
 *
 * @package    Mage_Customer
 */
abstract class Mage_Customer_Model_Api2_Customer_Rest extends Mage_Customer_Model_Api2_Customer
{
    /**
     * Attribute codes never returned, whatever attributes the API role allows
     *
     * @var string[]
     */
    protected $_secretAttributeCodes = ['password_hash', 'rp_token', 'rp_token_created_at', 'rp_customer_id'];

    /**
     * Create customer
     *
     * @return string
     */
    protected function _create(array $data)
    {
        /** @var Mage_Api2_Model_Resource_Validator_Eav $validator */
        $validator = Mage::getResourceModel('api2/validator_eav', ['resource' => $this]);

        $data = $validator->filter($data);
        if (!$validator->isValidData($data)) {
            foreach ($validator->getErrors() as $error) {
                $this->_error($error, Mage_Api2_Model_Server::HTTP_BAD_REQUEST);
            }

            $this->_critical(self::RESOURCE_DATA_PRE_VALIDATION_ERROR);
        }

        /** @var Mage_Customer_Model_Customer $customer */
        $customer = Mage::getModel('customer/customer');
        $customer->setData($data);

        try {
            $customer->save();
        } catch (Mage_Core_Exception $mageCoreException) {
            $this->_error($mageCoreException->getMessage(), Mage_Api2_Model_Server::HTTP_INTERNAL_ERROR);
        } catch (Exception $exception) {
            Mage::logException($exception);
            $this->_critical(self::RESOURCE_INTERNAL_ERROR);
        }

        return $this->_getLocation($customer);
    }

    /**
     * Retrieve information about customer
     *
     * @return array
     * @throws Mage_Api2_Exception
     */
    protected function _retrieve()
    {
        $customer = $this->_loadCustomerById($this->getRequest()->getParam('id'));
        return $this->_prepareCustomerDataForResponse($customer->getData());
    }

    /**
     * Get customers list
     *
     * @return array
     */
    protected function _retrieveCollection()
    {
        $data = $this->_getCollectionForRetrieve()->load()->toArray();
        $items = $data['items'] ?? $data;

        foreach ($items as $key => $item) {
            if (is_array($item)) {
                $items[$key] = $this->_prepareCustomerDataForResponse($item);
            }
        }

        return $items;
    }

    /**
     * Reject collection filters on the confirmation key other than null/notnull
     *
     * Any other condition (eq, like, ...) would let the caller recover the key one request at a time.
     *
     * @throws Exception
     * @throws Mage_Api2_Exception
     */
    protected function _validateConfirmationFilter(): void
    {
        $filter = $this->getRequest()->getFilter();
        if (!is_array($filter)) {
            return;
        }

        foreach ($filter as $filterEntry) {
            if (!is_array($filterEntry) || ($filterEntry['attribute'] ?? null) !== 'confirmation') {
                continue;
            }

            unset($filterEntry['attribute']);
            if (count($filterEntry) !== 1 || !in_array(key($filterEntry), ['null', 'notnull'], true)) {
                $this->_critical(self::RESOURCE_COLLECTION_FILTERING_ERROR);
            }
        }
    }

    /**
     * Remove credentials from customer data before it is returned
     *
     * The confirmation key lets anyone who holds it confirm the account and log in as the customer, so
     * only whether confirmation is pending is returned.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    protected function _prepareCustomerDataForResponse(array $data)
    {
        foreach ($this->_secretAttributeCodes as $attributeCode) {
            unset($data[$attributeCode]);
        }

        if (array_key_exists('confirmation', $data)) {
            $data['confirmation'] = (bool) $data['confirmation'];
        }

        return $data;
    }

    /**
     * Update customer
     *
     * @throws Mage_Api2_Exception
     */
    protected function _update(array $data)
    {
        $customer = $this->_loadCustomerById($this->getRequest()->getParam('id'));
        /** @var Mage_Api2_Model_Resource_Validator_Eav $validator */
        $validator = Mage::getResourceModel('api2/validator_eav', ['resource' => $this]);

        $data = $validator->filter($data);

        unset($data['website_id']); // website is not allowed to change

        if (!$validator->isValidData($data, true)) {
            foreach ($validator->getErrors() as $error) {
                $this->_error($error, Mage_Api2_Model_Server::HTTP_BAD_REQUEST);
            }

            $this->_critical(self::RESOURCE_DATA_PRE_VALIDATION_ERROR);
        }

        $customer->addData($data);

        try {
            $customer->save();
        } catch (Mage_Core_Exception $mageCoreException) {
            $this->_error($mageCoreException->getMessage(), Mage_Api2_Model_Server::HTTP_INTERNAL_ERROR);
        } catch (Exception $exception) {
            Mage::logException($exception);
            $this->_critical(self::RESOURCE_INTERNAL_ERROR);
        }
    }

    /**
     * Load customer by id
     *
     * @param  int                          $id
     * @return Mage_Customer_Model_Customer
     * @throws Mage_Api2_Exception
     */
    protected function _loadCustomerById($id)
    {
        /** @var Mage_Customer_Model_Customer $customer */
        $customer = Mage::getModel('customer/customer')->load($id);
        if (!$customer->getId()) {
            $this->_critical(self::RESOURCE_NOT_FOUND);
        }

        return $customer;
    }

    /**
     * Retrieve collection instances
     *
     * @return Mage_Customer_Model_Resource_Customer_Collection
     */
    protected function _getCollectionForRetrieve()
    {
        /** @var Mage_Customer_Model_Resource_Customer_Collection $collection */
        $collection = Mage::getResourceModel('customer/customer_collection');
        $collection->addAttributeToSelect(array_keys(
            $this->getAvailableAttributes($this->getUserType(), Mage_Api2_Model_Resource::OPERATION_ATTRIBUTE_READ),
        ));

        $this->_validateConfirmationFilter();
        $this->_applyCollectionModifiers($collection);
        return $collection;
    }
}
