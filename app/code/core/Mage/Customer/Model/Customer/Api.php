<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Customer
 */

/**
 * Customer api
 *
 * @package    Mage_Customer
 */
class Mage_Customer_Model_Customer_Api extends Mage_Customer_Model_Api_Resource
{
    protected $_mapAttributes = [
        'customer_id' => 'entity_id',
    ];

    /**
     * Input keys create() accepts besides the allowed customer attributes
     *
     * @var string[]
     */
    protected $_createOnlyFields = ['password'];

    /**
     * Prepare data to insert/update.
     * Creating array for stdClass Object
     *
     * @param  array $data
     * @return array
     */
    protected function _prepareData($data)
    {
        foreach ($this->_mapAttributes as $attributeAlias => $attributeCode) {
            if (isset($data[$attributeAlias])) {
                $data[$attributeCode] = $data[$attributeAlias];
                unset($data[$attributeAlias]);
            }
        }

        return $data;
    }

    /**
     * Create new customer
     *
     * Always creates a new customer. A customer id in the input is refused, see _prepareDataForCreate().
     *
     * @param  array|stdClass      $customerData
     * @return int
     * @throws Mage_Api_Exception
     * @throws Mage_Core_Exception
     */
    public function create($customerData)
    {
        /** @var Mage_Customer_Model_Customer $customer */
        $customer = Mage::getModel('customer/customer');
        $customer->setData($this->_prepareDataForCreate($customer, $customerData));

        // defence in depth: whatever a subclass copies, create() never saves over an existing customer
        if ($customer->getId() !== null) {
            $this->_fault('data_invalid', $this->_getCustomerIdOnCreateMessage());
        }

        try {
            $customer->save();
        } catch (Mage_Core_Exception $mageCoreException) {
            $this->_fault('data_invalid', $mageCoreException->getMessage());
        }

        return $customer->getId();
    }

    /**
     * Build the data create() sets on the new customer
     *
     * A customer id (customer_id, or entity_id) is refused rather than dropped, so a caller that relied
     * on create() updating an existing customer gets a fault instead of a duplicate account. Like
     * update(), only the attributes getAllowedAttributes() returns are copied, plus $_createOnlyFields.
     *
     * The id is checked on the raw input, because _prepareData() lets an empty customer_id replace a
     * non-empty entity_id.
     *
     * @param  array<string, mixed>|stdClass $customerData raw API input
     * @return array<string, mixed>
     * @throws Mage_Api_Exception
     * @throws Mage_Core_Exception
     */
    protected function _prepareDataForCreate(Mage_Customer_Model_Customer $customer, $customerData)
    {
        $input = is_object($customerData) ? get_object_vars($customerData) : $customerData;
        if (!is_array($input)) {
            $this->_fault('data_invalid');
        }

        foreach (['entity_id', ...array_keys($this->_mapAttributes)] as $idKey) {
            if (!in_array($input[$idKey] ?? null, [null, '', 0, '0'], true)) {
                $this->_fault('data_invalid', $this->_getCustomerIdOnCreateMessage());
            }
        }

        // the V2 override of _prepareData() takes the stdClass
        $customerData = $this->_prepareData($customerData); /** @phpstan-ignore argument.type */
        $fields = [...array_keys($this->getAllowedAttributes($customer)), ...$this->_createOnlyFields];
        $fields = array_diff($fields, ['entity_id', ...array_keys($this->_mapAttributes)]);

        $data = [];
        foreach ($fields as $field) {
            if (array_key_exists($field, $customerData)) {
                $data[$field] = $customerData[$field];
            }
        }

        if (array_key_exists('confirmation', $data)) {
            $data['confirmation'] = $this->_prepareConfirmationForWrite($customer, $data['confirmation']);
        }

        return $data;
    }

    /**
     * @return string
     */
    protected function _getCustomerIdOnCreateMessage()
    {
        return Mage::helper('customer')->__('A customer ID cannot be set when creating a customer. Update the existing customer instead.');
    }

    /**
     * Retrieve customer data
     *
     * @param  int                 $customerId
     * @param  array               $attributes
     * @return array
     * @throws Mage_Core_Exception
     */
    public function info($customerId, $attributes = null)
    {
        $customer = Mage::getModel('customer/customer')->load($customerId);

        if (!$customer->getId()) {
            $this->_fault('not_exists');
        }

        if (!is_null($attributes) && !is_array($attributes)) {
            $attributes = [$attributes];
        }

        $result = [];

        foreach ($this->_mapAttributes as $attributeAlias => $attributeCode) {
            $result[$attributeAlias] = $customer->getData($attributeCode);
        }

        foreach ($this->_getReadableAttributeCodes($customer, $attributes) as $attributeCode) {
            $result[$attributeCode] = $this->_prepareAttributeValueForRead($attributeCode, $customer->getData($attributeCode));
        }

        return $result;
    }

    /**
     * Retrieve customers data
     *
     * @param  array|object        $filters
     * @return array
     * @throws Mage_Core_Exception
     */
    public function items($filters)
    {
        $collection = Mage::getModel('customer/customer')->getCollection()->addAttributeToSelect('*');
        /** @var Mage_Api_Helper_Data $apiHelper */
        $apiHelper = Mage::helper('api');
        $filters = $apiHelper->parseFilters($filters, $this->_mapAttributes);
        try {
            foreach ($filters as $field => $value) {
                if (!$this->_isAllowedFilter($collection->getEntity(), $field, $value)) {
                    Mage::throwException(Mage::helper('customer')->__('Filtering by "%s" is not allowed.', $field));
                }

                $collection->addFieldToFilter($field, $value);
            }
        } catch (Mage_Core_Exception $mageCoreException) {
            $this->_fault('filters_invalid', $mageCoreException->getMessage());
        }

        $result = [];
        /** @var Mage_Customer_Model_Customer $customer */
        foreach ($collection as $customer) {
            $data = $customer->toArray();
            $row  = [];
            foreach ($this->_mapAttributes as $attributeAlias => $attributeCode) {
                $row[$attributeAlias] = $data[$attributeCode] ?? null;
            }

            foreach ($this->_getReadableAttributeCodes($customer) as $attributeCode) {
                if (isset($data[$attributeCode])) {
                    $row[$attributeCode] = $this->_prepareAttributeValueForRead($attributeCode, $data[$attributeCode]);
                }
            }

            $result[] = $row;
        }

        return $result;
    }

    /**
     * Return codes of the attributes info() and items() may return
     *
     * Credentials (see Mage_Customer_Helper_Data::getApiSecretAttributeCodes()) are excluded after the
     * optional filter, so naming one in the filter cannot re-admit it. They are kept out of
     * $_ignoredAttributeCodes because update() uses that list.
     *
     * @param  null|array<int|string> $filter
     * @return string[]
     * @throws Mage_Core_Exception
     */
    protected function _getReadableAttributeCodes(Mage_Customer_Model_Customer $customer, ?array $filter = null)
    {
        /** @var Mage_Customer_Helper_Data $helper */
        $helper = Mage::helper('customer');
        return array_values(array_diff(
            array_keys($this->getAllowedAttributes($customer, $filter)),
            $helper->getApiSecretAttributeCodes(),
        ));
    }

    /**
     * Prepare an attribute value for API output
     *
     * Only whether confirmation is pending is returned for the confirmation key. This matches the
     * xsd:boolean type of the "confirmation" element in the SOAP v2 and WS-I customerCustomerEntity type.
     *
     * @param  string $attributeCode
     * @param  mixed  $value
     * @return mixed
     */
    protected function _prepareAttributeValueForRead($attributeCode, $value)
    {
        if ($attributeCode === 'confirmation') {
            /** @var Mage_Customer_Helper_Data $helper */
            $helper = Mage::helper('customer');
            return $helper->prepareApiConfirmationForRead($value);
        }

        return $value;
    }

    /**
     * Prepare a confirmation value written by create() or update()
     *
     * Reads return a boolean, so a caller that writes back what it read must not store that boolean as
     * the key, and a caller can never choose the key.
     *
     * @param  mixed       $value
     * @return null|string
     */
    protected function _prepareConfirmationForWrite(Mage_Customer_Model_Customer $customer, $value)
    {
        /** @var Mage_Customer_Helper_Data $helper */
        $helper = Mage::helper('customer');
        return $helper->prepareApiConfirmationForWrite($customer, $value);
    }

    /**
     * Check whether a filter may be applied to the customer collection
     *
     * See Mage_Customer_Helper_Data::isApiFilterAllowed(). The field is resolved through the entity like
     * the collection filter does, so attribute ids and codes that only match in the database collation
     * are caught too.
     *
     * @param  int|string          $field
     * @param  mixed               $condition
     * @return bool
     * @throws Mage_Core_Exception
     */
    protected function _isAllowedFilter(Mage_Eav_Model_Entity_Abstract $entity, $field, $condition)
    {
        $codes = [strtolower(trim((string) $field))];
        $attribute = $entity->getAttribute($field);
        if ($attribute !== false) {
            $codes[] = strtolower((string) $attribute->getAttributeCode());
        }

        /** @var Mage_Customer_Helper_Data $helper */
        $helper = Mage::helper('customer');
        return $helper->isApiFilterAllowed($codes, $condition);
    }

    /**
     * Update customer data
     *
     * @param  int                 $customerId
     * @param  array               $customerData
     * @return bool
     * @throws Mage_Core_Exception
     * @throws Throwable
     */
    public function update($customerId, $customerData)
    {
        $customerData = $this->_prepareData($customerData);

        $customer = Mage::getModel('customer/customer')->load($customerId);

        if (!$customer->getId()) {
            $this->_fault('not_exists');
        }

        foreach (array_keys($this->getAllowedAttributes($customer)) as $attributeCode) {
            if (isset($customerData[$attributeCode])) {
                $value = $customerData[$attributeCode];
                if ($attributeCode === 'confirmation') {
                    $value = $this->_prepareConfirmationForWrite($customer, $value);
                }

                $customer->setData($attributeCode, $value);
            }
        }

        $customer->save();
        return true;
    }

    /**
     * Delete customer
     *
     * @param  int                 $customerId
     * @return bool
     * @throws Mage_Core_Exception
     */
    public function delete($customerId)
    {
        $customer = Mage::getModel('customer/customer')->load($customerId);

        if (!$customer->getId()) {
            $this->_fault('not_exists');
        }

        try {
            $customer->delete();
        } catch (Mage_Core_Exception $mageCoreException) {
            $this->_fault('not_deleted', $mageCoreException->getMessage());
        }

        return true;
    }
}
