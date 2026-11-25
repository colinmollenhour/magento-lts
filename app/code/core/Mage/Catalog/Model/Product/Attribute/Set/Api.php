<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Catalog
 */

/**
 * Catalog product attribute set api
 *
 * @package    Mage_Catalog
 */
class Mage_Catalog_Model_Product_Attribute_Set_Api extends Mage_Api_Model_Resource_Abstract
{
    /**
     * Retrieve attribute set list
     *
     * @return array
     */
    public function items()
    {
        $entityType = Mage::getModel('catalog/product')->getResource()->getEntityType();
        $collection = Mage::getResourceModel('eav/entity_attribute_set_collection')
            ->setEntityTypeFilter($entityType->getId());

        $result = [];
        foreach ($collection as $attributeSet) {
            $result[] = [
                'set_id' => $attributeSet->getId(),
                'name'   => $attributeSet->getAttributeSetName(),
            ];
        }

        return $result;
    }

    /**
     * Create new attribute set based on another set
     *
     * @param  string $attributeSetName
     * @param  string $skeletonSetId
     * @return int
     */
    public function create($attributeSetName, $skeletonSetId)
    {
        // check if product attribute set with requested $skeletonSetId exists
        $this->_initProductAttributeSet($skeletonSetId, 'invalid_skeleton_set_id');

        // get catalog product entity type id
        $entityTypeId = $this->_getProductEntityTypeId();
        /** @var Mage_Eav_Model_Entity_Attribute_Set $attributeSet */
        $attributeSet = Mage::getModel('eav/entity_attribute_set')
                ->setEntityTypeId($entityTypeId)
                ->setAttributeSetName($attributeSetName);
        try {
            // check if name is valid
            $attributeSet->validate();
            // copy parameters to new set from skeleton set
            $attributeSet->save();
            $attributeSet->initFromSkeleton($skeletonSetId)->save();
        } catch (Mage_Eav_Exception $mageEavException) {
            $this->_fault('invalid_data', $mageEavException->getMessage());
        } catch (Exception $exception) {
            $this->_fault('create_attribute_set_error', $exception->getMessage());
        }

        return (int) $attributeSet->getId();
    }

    /**
     * Remove attribute set
     *
     * @param  string $attributeSetId
     * @param  bool   $forceProductsRemove
     * @return bool
     */
    public function remove($attributeSetId, $forceProductsRemove = false)
    {
        // check if product attribute set with requested id exists
        $attributeSet = $this->_initProductAttributeSet($attributeSetId);

        // every product needs the default set
        if ((int) $attributeSet->getId() === $this->_getDefaultProductAttributeSetId()) {
            $this->_fault('remove_attribute_set_error');
        }

        // if attribute set has related goods and $forceProductsRemove is not set throw exception
        if (!$forceProductsRemove) {
            /** @var Mage_Catalog_Model_Resource_Product_Collection $catalogProductsCollection */
            $catalogProductsCollection = Mage::getModel('catalog/product')->getCollection()
                    ->addFieldToFilter('attribute_set_id', $attributeSetId);
            if (count($catalogProductsCollection)) {
                $this->_fault('attribute_set_has_related_products');
            }
        }

        try {
            $attributeSet->delete();
        } catch (Exception $exception) {
            $this->_fault('remove_attribute_set_error', $exception->getMessage());
        }

        return true;
    }

    /**
     * Add attribute to attribute set
     *
     * @param  string      $attributeId
     * @param  string      $attributeSetId
     * @param  null|string $attributeGroupId
     * @param  string      $sortOrder
     * @return bool
     */
    public function attributeAdd($attributeId, $attributeSetId, $attributeGroupId = null, $sortOrder = '0')
    {
        // check if product attribute with requested id exists
        $attribute = $this->_initProductAttribute($attributeId);

        // check if product attribute set with requested id exists
        $attributeSet = $this->_initProductAttributeSet($attributeSetId);

        if (!empty($attributeGroupId)) {
            // check if attribute group with requested id exists in the requested set
            $this->_initProductAttributeGroup($attributeGroupId, (int) $attributeSet->getId());
        } else {
            // define default attribute group id for current attribute set
            $attributeGroupId = $attributeSet->getDefaultGroupId();
        }

        $attribute->setAttributeSetId($attributeSet->getId())->loadEntityAttributeIdBySet();
        if ($attribute->getEntityAttributeId()) {
            $this->_fault('attribute_is_already_in_set');
        }

        try {
            $attribute->setEntityTypeId($attributeSet->getEntityTypeId())
                    ->setAttributeSetId($attributeSet->getId())
                    ->setAttributeGroupId($attributeGroupId)
                    ->setSortOrder($sortOrder)
                    ->save();
        } catch (Exception $exception) {
            $this->_fault('add_attribute_error', $exception->getMessage());
        }

        return true;
    }

    /**
     * Remove attribute from attribute set
     *
     * @param  string $attributeId
     * @param  string $attributeSetId
     * @return bool
     */
    public function attributeRemove($attributeId, $attributeSetId)
    {
        // check if product attribute with requested id exists
        $attribute = $this->_initProductAttribute($attributeId);

        // check if product attribute set with requested id exists
        $attributeSet = $this->_initProductAttributeSet($attributeSetId);

        // check if attribute is in set
        $attribute->setAttributeSetId($attributeSet->getId())->loadEntityAttributeIdBySet();
        if (!$attribute->getEntityAttributeId()) {
            $this->_fault('attribute_is_not_in_set');
        }

        try {
            // delete record from eav_entity_attribute
            // using entity_attribute_id loaded by loadEntityAttributeIdBySet()
            $attribute->deleteEntity();
        } catch (Exception $exception) {
            $this->_fault('remove_attribute_error', $exception->getMessage());
        }

        return true;
    }

    /**
     * Create group within existing attribute set
     *
     * @param  int|string $attributeSetId
     * @param  string     $groupName
     * @return int
     */
    public function groupAdd($attributeSetId, $groupName)
    {
        // check if product attribute set with requested id exists
        $attributeSet = $this->_initProductAttributeSet($attributeSetId);

        /** @var Mage_Eav_Model_Entity_Attribute_Group $group */
        $group = Mage::getModel('eav/entity_attribute_group');
        $group->setAttributeSetId($attributeSet->getId())
                ->setAttributeGroupName(
                    Mage::helper('catalog')->stripTags($groupName),
                );
        if ($group->itemExists()) {
            $this->_fault('group_already_exists');
        }

        try {
            $group->save();
        } catch (Exception $exception) {
            $this->_fault('group_add_error', $exception->getMessage());
        }

        return (int) $group->getId();
    }

    /**
     * Rename existing group
     *
     * @param  int|string $groupId
     * @param  string     $groupName
     * @return bool
     */
    public function groupRename($groupId, $groupName)
    {
        $model = Mage::getModel('eav/entity_attribute_group')->load($groupId);

        if (!$model->getAttributeGroupName()) {
            $this->_fault('invalid_attribute_group_id');
        }

        $model->setAttributeGroupName(
            Mage::helper('catalog')->stripTags($groupName),
        );
        try {
            $model->save();
        } catch (Exception $exception) {
            $this->_fault('group_rename_error', $exception->getMessage());
        }

        return true;
    }

    /**
     * Remove group from existing attribute set
     *
     * @param  int|string $attributeGroupId
     * @return bool
     */
    public function groupRemove($attributeGroupId)
    {
        // check if attribute group with requested id exists in a product attribute set
        $group = $this->_initProductAttributeGroup($attributeGroupId);

        if ($group->hasConfigurableAttributes()) {
            $this->_fault('group_has_configurable_attributes');
        }

        if ($group->hasSystemAttributes()) {
            $this->_fault('group_has_system_attributes');
        }

        try {
            $group->delete();
        } catch (Exception $exception) {
            $this->_fault('group_remove_error', $exception->getMessage());
        }

        return true;
    }

    /**
     * Load a product attribute set, or fault as if it did not exist
     *
     * This is the product attribute set API: sets of other entity types are not its to change.
     *
     * @param  int|string         $attributeSetId
     * @throws Mage_Api_Exception
     */
    protected function _initProductAttributeSet($attributeSetId, string $fault = 'invalid_attribute_set_id'): Mage_Eav_Model_Entity_Attribute_Set
    {
        $attributeSet = $this->_loadAttributeSet((int) $attributeSetId);
        if (!$attributeSet->getId() || (int) $attributeSet->getEntityTypeId() !== $this->_getProductEntityTypeId()) {
            $this->_fault($fault);
        }

        return $attributeSet;
    }

    /**
     * Load a group of a product attribute set, or fault as if it did not exist
     *
     * @param  int|string         $attributeGroupId
     * @param  null|int           $attributeSetId   the set the group must belong to
     * @throws Mage_Api_Exception
     */
    protected function _initProductAttributeGroup($attributeGroupId, ?int $attributeSetId = null): Mage_Catalog_Model_Product_Attribute_Group
    {
        $group = $this->_loadAttributeGroup((int) $attributeGroupId);
        if (!$group->getId()
            || ($attributeSetId !== null && (int) $group->getAttributeSetId() !== $attributeSetId)
        ) {
            $this->_fault('invalid_attribute_group_id');
        }

        $this->_initProductAttributeSet($group->getAttributeSetId(), 'invalid_attribute_group_id');

        return $group;
    }

    /**
     * Load a product attribute, or fault as if it did not exist
     *
     * @param  int|string         $attributeId
     * @throws Mage_Api_Exception
     */
    protected function _initProductAttribute($attributeId): Mage_Eav_Model_Entity_Attribute
    {
        $attribute = $this->_loadAttribute((int) $attributeId);
        if (!$attribute->getId() || (int) $attribute->getEntityTypeId() !== $this->_getProductEntityTypeId()) {
            $this->_fault('invalid_attribute_id');
        }

        return $attribute;
    }

    /**
     * @return Mage_Eav_Model_Entity_Attribute_Set
     */
    protected function _loadAttributeSet(int $attributeSetId)
    {
        return Mage::getModel('eav/entity_attribute_set')->load($attributeSetId);
    }

    /**
     * @return Mage_Catalog_Model_Product_Attribute_Group
     */
    protected function _loadAttributeGroup(int $attributeGroupId)
    {
        return Mage::getModel('catalog/product_attribute_group')->load($attributeGroupId);
    }

    /**
     * @return Mage_Eav_Model_Entity_Attribute
     */
    protected function _loadAttribute(int $attributeId)
    {
        return Mage::getModel('eav/entity_attribute')->load($attributeId);
    }

    protected function _getProductEntityTypeId(): int
    {
        return (int) Mage::getModel('catalog/product')->getResource()->getTypeId();
    }

    protected function _getDefaultProductAttributeSetId(): int
    {
        return (int) Mage::getModel('catalog/product')->getDefaultAttributeSetId();
    }
}
