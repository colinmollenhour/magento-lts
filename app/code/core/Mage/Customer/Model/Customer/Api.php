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
     * Attribute codes that info() and items() never return and items() does not accept as filters
     *
     * These hold credentials: the password hash and the password-reset token. They are kept apart from
     * $_ignoredAttributeCodes because update() uses that list. The confirmation key is not listed here:
     * it is returned as a boolean instead, see _prepareAttributeValueForRead().
     *
     * @var string[]
     */
    protected $_ignoredReadAttributeCodes = ['password_hash', 'rp_token', 'rp_token_created_at', 'rp_customer_id'];

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
     * @param  array               $customerData
     * @return int
     * @throws Mage_Core_Exception
     */
    public function create($customerData)
    {
        $customer = Mage::getModel('customer/customer');
        $customerData = $this->_prepareData($customerData);
        try {
            $customer
                ->setData($customerData)
                ->save();
        } catch (Mage_Core_Exception $mageCoreException) {
            $this->_fault('data_invalid', $mageCoreException->getMessage());
        }

        return $customer->getId();
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
                    Mage::throwException(sprintf('Filtering by "%s" is not allowed.', $field));
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
     * The read exclusion is applied after the optional filter, so naming an excluded attribute in
     * the filter cannot re-admit it.
     *
     * @param  null|array<int|string> $filter
     * @return string[]
     * @throws Mage_Core_Exception
     */
    protected function _getReadableAttributeCodes(Mage_Customer_Model_Customer $customer, ?array $filter = null)
    {
        return array_values(array_diff(
            array_keys($this->getAllowedAttributes($customer, $filter)),
            $this->_ignoredReadAttributeCodes,
        ));
    }

    /**
     * Prepare an attribute value for API output
     *
     * The confirmation key lets anyone who holds it confirm the account and log in as the customer, so
     * only whether confirmation is pending is returned. This matches the xsd:boolean type of the
     * "confirmation" element in the SOAP v2 and WS-I customerCustomerEntity type.
     *
     * @param  string $attributeCode
     * @param  mixed  $value
     * @return mixed
     */
    protected function _prepareAttributeValueForRead($attributeCode, $value)
    {
        if ($attributeCode === 'confirmation') {
            return (bool) $value;
        }

        return $value;
    }

    /**
     * Check whether a filter may be applied to the customer collection
     *
     * Filtering on a secret attribute would let the caller recover its value one condition at a time, so
     * excluded attributes cannot be filtered on and "confirmation" only accepts a null/notnull condition
     * (to list customers whose confirmation is pending or done). The field is resolved through the entity
     * like the collection filter does, so attribute ids and codes that only match in the database
     * collation are caught too.
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

        if (array_intersect($codes, $this->_ignoredReadAttributeCodes) !== []) {
            return false;
        }

        if (in_array('confirmation', $codes, true)) {
            return is_array($condition)
                && count($condition) === 1
                && in_array(key($condition), ['null', 'notnull'], true);
        }

        return true;
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
                $customer->setData($attributeCode, $customerData[$attributeCode]);
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
