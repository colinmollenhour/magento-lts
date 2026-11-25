<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Adminhtml
 */

/**
 * Adminhtml entity sets controller
 *
 * @package    Mage_Adminhtml
 */
class Mage_Adminhtml_Catalog_Product_SetController extends Mage_Adminhtml_Controller_Action
{
    /**
     * ACL resource
     * @see Mage_Adminhtml_Controller_Action::_isAllowed()
     */
    public const ADMIN_RESOURCE = 'catalog/attributes/sets';

    /**
     * @return void
     */
    public function indexAction()
    {
        $this->_title($this->__('Catalog'))
             ->_title($this->__('Attributes'))
             ->_title($this->__('Manage Attribute Sets'));

        $this->_setTypeId();

        $this->loadLayout();
        $this->_setActiveMenu('catalog/attributes/sets');

        $this->_addBreadcrumb(Mage::helper('catalog')->__('Catalog'), Mage::helper('catalog')->__('Catalog'));
        $this->_addBreadcrumb(
            Mage::helper('catalog')->__('Manage Attribute Sets'),
            Mage::helper('catalog')->__('Manage Attribute Sets'),
        );

        $this->_addContent($this->getLayout()->createBlock('adminhtml/catalog_product_attribute_set_toolbar_main'));
        $this->_addContent($this->getLayout()->createBlock('adminhtml/catalog_product_attribute_set_grid'));

        $this->renderLayout();
    }

    /**
     * @return void
     */
    public function editAction()
    {
        $this->_title($this->__('Catalog'))
             ->_title($this->__('Attributes'))
             ->_title($this->__('Manage Attribute Sets'));

        $this->_setTypeId();
        $attributeSet = $this->_initAttributeSet($this->getRequest()->getParam('id'));

        if (!$attributeSet instanceof Mage_Eav_Model_Entity_Attribute_Set) {
            $this->_redirect('*/*/index');
            return;
        }

        $this->_title($attributeSet->getAttributeSetName());

        Mage::register('current_attribute_set', $attributeSet);

        $this->loadLayout();
        $this->_setActiveMenu('catalog/attributes/sets');
        $this->getLayout()->getBlock('head')->setCanLoadExtJs(true);

        $this->_addBreadcrumb(Mage::helper('catalog')->__('Catalog'), Mage::helper('catalog')->__('Catalog'));
        $this->_addBreadcrumb(
            Mage::helper('catalog')->__('Manage Product Sets'),
            Mage::helper('catalog')->__('Manage Product Sets'),
        );

        $this->_addContent($this->getLayout()->createBlock('adminhtml/catalog_product_attribute_set_main'));

        $this->renderLayout();
    }

    /**
     * @return void
     */
    public function setGridAction()
    {
        $this->_setTypeId();
        $this->getResponse()->setBody(
            $this->getLayout()
                ->createBlock('adminhtml/catalog_product_attribute_set_grid')
            ->toHtml(),
        );
    }

    /**
     * Save attribute set action
     *
     * [POST] Create attribute set from another set and redirect to edit page
     * [AJAX] Save attribute set data
     * @return void
     */
    public function saveAction()
    {
        $entityTypeId   = $this->_getEntityTypeId();
        $hasError       = false;
        $attributeSetId = $this->getRequest()->getParam('id', false);
        $isNewSet       = $this->getRequest()->getParam('gotoEdit', false) == '1';
        $skeletonSetId  = 0;

        /** @var Mage_Eav_Model_Entity_Attribute_Set $model */
        $model  = Mage::getModel('eav/entity_attribute_set')
            ->setEntityTypeId($entityTypeId);

        /** @var Mage_Adminhtml_Helper_Data $helper */
        $helper = Mage::helper('adminhtml');

        try {
            if ($isNewSet) {
                //filter html tags
                $name = $helper->stripTags($this->getRequest()->getParam('attribute_set_name'));
                $model->setAttributeSetName(trim($name));

                // The new set copies the groups and attributes of a set of the same entity type
                $skeletonSet = $this->_initAttributeSet($this->getRequest()->getParam('skeleton_set'));
                if (!$skeletonSet instanceof Mage_Eav_Model_Entity_Attribute_Set) {
                    Mage::throwException(Mage::helper('catalog')->__('This attribute set no longer exists.'));
                }

                $skeletonSetId = (int) $skeletonSet->getId();
            } else {
                $attributeSet = $this->_initAttributeSet($attributeSetId);
                if (!$attributeSet instanceof Mage_Eav_Model_Entity_Attribute_Set) {
                    Mage::throwException(Mage::helper('catalog')->__('This attribute set no longer exists.'));
                }

                $model = $attributeSet;
                $data = Mage::helper('core')->jsonDecode($this->getRequest()->getPost('data'));
                if (!is_array($data)) {
                    Mage::throwException(Mage::helper('catalog')->__('An error occurred while saving the attribute set.'));
                }

                //filter html tags
                $data['attribute_set_name'] = $helper->stripTags($data['attribute_set_name'] ?? '');

                $model->organizeData($this->_filterAttributeSetData($model, $data));
            }

            $model->validate();
            if ($isNewSet) {
                $model->save();
                $model->initFromSkeleton($skeletonSetId);
            }

            $model->save();
            $this->_getSession()->addSuccess(Mage::helper('catalog')->__('The attribute set has been saved.'));
        } catch (Mage_Core_Exception $mageCoreException) {
            $this->_getSession()->addError($mageCoreException->getMessage());
            $hasError = true;
        } catch (Exception $exception) {
            $this->_getSession()->addException(
                $exception,
                Mage::helper('catalog')->__('An error occurred while saving the attribute set.'),
            );
            $hasError = true;
        }

        if ($isNewSet) {
            if ($hasError) {
                $this->_redirect('*/*/add');
            } else {
                $this->_redirect('*/*/edit', ['id' => $model->getId()]);
            }
        } else {
            $response = [];
            if ($hasError) {
                $this->_initLayoutMessages('adminhtml/session');
                $response['error']   = 1;
                $response['message'] = $this->getLayout()->getMessagesBlock()->getGroupedHtml();
            } else {
                $response['error']   = 0;
                $response['url']     = $this->getUrl('*/*/');
            }

            $this->getResponse()->setBody(Mage::helper('core')->jsonEncode($response));
        }
    }

    /**
     * @return void
     */
    public function addAction()
    {
        $this->_title($this->__('Catalog'))
             ->_title($this->__('Attributes'))
             ->_title($this->__('Manage Attribute Sets'))
             ->_title($this->__('New Set'));

        $this->_setTypeId();

        $this->loadLayout();
        $this->_setActiveMenu('catalog/sets');

        $this->_addContent($this->getLayout()->createBlock('adminhtml/catalog_product_attribute_set_toolbar_add'));

        $this->renderLayout();
    }

    /**
     * @return void
     */
    public function deleteAction()
    {
        try {
            $attributeSet = $this->_initAttributeSet($this->getRequest()->getParam('id'));
            // The editor offers no Delete button for the default set, which every entity type needs
            if (!$attributeSet instanceof Mage_Eav_Model_Entity_Attribute_Set || (int) $attributeSet->getId() === $this->_getDefaultAttributeSetId()) {
                Mage::throwException($this->__('An error occurred while deleting this set.'));
            }

            $attributeSet->delete();

            $this->_getSession()->addSuccess($this->__('The attribute set has been removed.'));
            $this->getResponse()->setRedirect($this->getUrl('*/*/'));
        } catch (Exception) {
            $this->_getSession()->addError($this->__('An error occurred while deleting this set.'));
            $this->_redirectReferer();
        }
    }

    /**
     * Controller pre-dispatch method
     *
     * @return Mage_Adminhtml_Controller_Action
     */
    #[Override]
    public function preDispatch()
    {
        $this->_setForcedFormKeyActions('delete');
        return parent::preDispatch();
    }

    /**
     * Define in register catalog_product entity type code as entityType
     */
    protected function _setTypeId()
    {
        Mage::register(
            'entityType',
            Mage::getModel('catalog/product')->getResource()->getTypeId(),
        );
    }

    /**
     * Retrieve catalog product entity type id
     *
     * @return int
     */
    protected function _getEntityTypeId()
    {
        if (is_null(Mage::registry('entityType'))) {
            $this->_setTypeId();
        }

        return Mage::registry('entityType');
    }

    /**
     * Load an attribute set of the entity type this controller edits
     *
     * The editor and its save and delete actions handle one entity type (product, unless
     * _setTypeId() is overridden). Sets of other entity types are treated as missing.
     */
    protected function _initAttributeSet(mixed $setId): ?Mage_Eav_Model_Entity_Attribute_Set
    {
        if (!is_scalar($setId) || (int) $setId <= 0) {
            return null;
        }

        $attributeSet = $this->_loadAttributeSet((int) $setId);
        if (!$attributeSet->getId() || (int) $attributeSet->getEntityTypeId() !== (int) $this->_getEntityTypeId()) {
            return null;
        }

        return $attributeSet;
    }

    /**
     * Check the posted attribute set structure against the set being saved
     *
     * Mage_Eav_Model_Entity_Attribute_Set::organizeData() saves every posted group, removed
     * group and removed attribute by id. Each id must belong to this set, and each assigned
     * attribute to its entity type. Group names are stripped of tags, as set names are.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     * @throws Mage_Core_Exception
     */
    protected function _filterAttributeSetData(Mage_Eav_Model_Entity_Attribute_Set $attributeSet, array $data): array
    {
        $setId = (int) $attributeSet->getId();
        $groupIds = $this->_getAttributeGroupIds($setId);
        $error = Mage::helper('catalog')->__('An error occurred while saving the attribute set.');

        // organizeData() skips a list that is empty or false
        $lists = [];
        foreach (['groups', 'attributes', 'not_attributes', 'removeGroups'] as $key) {
            $list = $data[$key] ?? false;
            if ($list !== false && !is_array($list)) {
                Mage::throwException($error);
            }

            $data[$key] = $list;
            $lists[$key] = $list === false ? [] : $list;
        }

        // The editor posts sparse arrays, so null entries are kept as they are
        foreach ($lists['groups'] as $index => $group) {
            if ($group === null) {
                continue;
            }

            if (!is_array($group) || !isset($group[0]) || !is_scalar($group[0]) || !is_scalar($group[1] ?? null)) {
                Mage::throwException($error);
            }

            // New groups have a generated id that is not a number
            if (is_numeric($group[0]) && $group[0] > 0) {
                if (!in_array((int) $group[0], $groupIds, true)) {
                    Mage::throwException($error);
                }

                $group[0] = (int) $group[0];
            }

            $group[1] = trim(Mage::helper('adminhtml')->stripTags((string) $group[1]));
            $lists['groups'][$index] = $group;
        }

        if ($data['groups'] !== false) {
            $data['groups'] = $lists['groups'];
        }

        $attributeIds = null;
        foreach ($lists['attributes'] as $attribute) {
            if ($attribute === null) {
                continue;
            }

            $attributeIds ??= $this->_getEntityTypeAttributeIds((int) $attributeSet->getEntityTypeId());
            if (!is_array($attribute) || !isset($attribute[0]) || !is_scalar($attribute[0])
                || !in_array((int) $attribute[0], $attributeIds, true)
            ) {
                Mage::throwException($error);
            }
        }

        $entityAttributeIds = null;
        foreach ($lists['not_attributes'] as $entityAttributeId) {
            // Attributes that were never in the set have no entity attribute id
            if ($entityAttributeId === null || $entityAttributeId === '') {
                continue;
            }

            $entityAttributeIds ??= $this->_getEntityAttributeIds($setId);
            if (!is_scalar($entityAttributeId) || !in_array((int) $entityAttributeId, $entityAttributeIds, true)) {
                Mage::throwException($error);
            }
        }

        foreach ($lists['removeGroups'] as $groupId) {
            if ($groupId === null) {
                continue;
            }

            if (!is_scalar($groupId) || !in_array((int) $groupId, $groupIds, true)) {
                Mage::throwException($error);
            }
        }

        return $data;
    }

    /**
     * @return Mage_Eav_Model_Entity_Attribute_Set
     */
    protected function _loadAttributeSet(int $setId)
    {
        return Mage::getModel('eav/entity_attribute_set')->load($setId);
    }

    /**
     * Default attribute set of the entity type this controller edits
     */
    protected function _getDefaultAttributeSetId(): int
    {
        return (int) Mage::getModel('eav/entity_type')->load($this->_getEntityTypeId())->getDefaultAttributeSetId();
    }

    /**
     * Ids of the groups of an attribute set
     *
     * @return list<int>
     */
    protected function _getAttributeGroupIds(int $setId): array
    {
        $ids = Mage::getResourceModel('eav/entity_attribute_group_collection')
            ->setAttributeSetFilter($setId)
            ->getAllIds();

        return array_values(array_map(intval(...), $ids));
    }

    /**
     * Ids of the attributes of an entity type
     *
     * @return list<int>
     */
    protected function _getEntityTypeAttributeIds(int $entityTypeId): array
    {
        $ids = Mage::getResourceModel('eav/entity_attribute_collection')
            ->setEntityTypeFilter($entityTypeId)
            ->getAllIds();

        return array_values(array_map(intval(...), $ids));
    }

    /**
     * Ids of the attribute assignments (eav_entity_attribute rows) of an attribute set
     *
     * @return list<int>
     */
    protected function _getEntityAttributeIds(int $setId): array
    {
        // The set filter joins eav_entity_attribute, which holds the assignment ids
        $ids = Mage::getResourceModel('eav/entity_attribute_collection')
            ->setAttributeSetFilter($setId)
            ->getColumnValues('entity_attribute_id');

        return array_values(array_map(intval(...), $ids));
    }
}
