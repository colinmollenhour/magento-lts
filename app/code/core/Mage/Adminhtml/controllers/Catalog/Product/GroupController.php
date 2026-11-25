<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Adminhtml
 */

/**
 * @package    Mage_Adminhtml
 * @deprecated after 1.4.0.0-alpha2
 */
class Mage_Adminhtml_Catalog_Product_GroupController extends Mage_Adminhtml_Controller_Action
{
    /**
     * ACL resource
     * @see Mage_Adminhtml_Controller_Action::_isAllowed()
     */
    public const ADMIN_RESOURCE = 'catalog/products';

    /**
     * @return void
     */
    public function saveAction()
    {
        // This action belongs to the product attribute set editor: other entity types' sets are not its to change
        $attributeSetId = (int) $this->getRequest()->getParam('attribute_set_id');
        if ($this->_getAttributeSetEntityTypeId($attributeSetId) !== $this->_getProductEntityTypeId()) {
            $this->_getSession()->addError(Mage::helper('catalog')->__('An error occurred while saving this group.'));
            return;
        }

        $model = $this->_createGroupModel();
        $model->setAttributeGroupName($this->getRequest()->getParam('attribute_group_name'))
              ->setAttributeSetId($attributeSetId);

        if ($model->itemExists()) {
            $this->_getSession()->addError(Mage::helper('catalog')->__('A group with the same name already exists.'));
        } else {
            try {
                $model->save();
            } catch (Exception) {
                $this->_getSession()->addError(Mage::helper('catalog')->__('An error occurred while saving this group.'));
            }
        }
    }

    /**
     * Entity type of an attribute set, null when the set does not exist
     */
    protected function _getAttributeSetEntityTypeId(int $attributeSetId): ?int
    {
        if ($attributeSetId <= 0) {
            return null;
        }

        $attributeSet = Mage::getModel('eav/entity_attribute_set')->load($attributeSetId);
        return (int) $attributeSet->getId() === 0 ? null : (int) $attributeSet->getEntityTypeId();
    }

    protected function _getProductEntityTypeId(): int
    {
        return (int) Mage::getModel('catalog/product')->getResource()->getTypeId();
    }

    /**
     * @return Mage_Eav_Model_Entity_Attribute_Group
     */
    protected function _createGroupModel()
    {
        return Mage::getModel('eav/entity_attribute_group');
    }
}
