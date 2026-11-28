<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Catalog
 */

/**
 * Catalog product attribute api
 *
 * @package    Mage_Catalog
 */
class Mage_Catalog_Model_Product_Attribute_Api extends Mage_Catalog_Model_Api_Resource
{
    /**
     * Attribute properties that create() and update() take from the caller, at the top level of the input or in
     * additional_fields. Extensions can add their own properties as child nodes of XML_PATH_ALLOWED_PROPERTIES.
     */
    public const ALLOWED_PROPERTIES = [
        'apply_to',
        'default_value',
        'frontend_class',
        'is_comparable',
        'is_configurable',
        'is_filterable',
        'is_filterable_in_search',
        'is_global',
        'is_html_allowed_on_front',
        'is_required',
        'is_searchable',
        'is_unique',
        'is_used_for_price_rules',
        'is_used_for_promo_rules',
        'is_visible',
        'is_visible_in_advanced_search',
        'is_visible_on_front',
        'is_wysiwyg_enabled',
        'note',
        'position',
        'used_for_sort_by',
        'used_in_product_listing',
    ];

    /**
     * Keys a caller can never set through create() or update(), even when the configuration allows them: they
     * identify the attribute, name classes that are instantiated whenever products load or save, or have their
     * own validated input (code, input type, labels, options)
     */
    public const PROTECTED_PROPERTIES = [
        'attribute_code',
        'attribute_id',
        'attribute_model',
        'backend_model',
        'backend_table',
        'backend_type',
        'default',
        'entity_type_id',
        'frontend_input',
        'frontend_input_renderer',
        'frontend_label',
        'frontend_model',
        'is_user_defined',
        'option',
        'source_model',
    ];

    /**
     * Config node whose children name additional attribute properties the API accepts
     */
    public const XML_PATH_ALLOWED_PROPERTIES = 'global/catalog/product/attribute/api/allowed_properties';

    /**
     * Product entity type id
     *
     * @var int
     */
    protected $_entityTypeId;

    public function __construct()
    {
        $this->_storeIdSessionField = 'product_store_id';
        $this->_ignoredAttributeCodes[] = 'type_id';
        $this->_ignoredAttributeTypes[] = 'gallery';
        $this->_ignoredAttributeTypes[] = 'media_image';
        $this->_entityTypeId = Mage::getModel('eav/entity')->setType('catalog_product')->getTypeId();
    }

    /**
     * Retrieve attributes from specified attribute set
     *
     * @param  int   $setId
     * @return array
     */
    public function items($setId)
    {
        $attributes = Mage::getModel('catalog/product')->getResource()
                ->loadAllAttributes()
                ->getSortedAttributes($setId);
        $result = [];

        foreach ($attributes as $attribute) {
            /** @var Mage_Catalog_Model_Resource_Eav_Attribute $attribute */
            if ((!$attribute->getId() || $attribute->isInSet($setId))
                    && $this->_isAllowedAttribute($attribute)
            ) {
                if (!$attribute->getId() || $attribute->isScopeGlobal()) {
                    $scope = 'global';
                } elseif ($attribute->isScopeWebsite()) {
                    $scope = 'website';
                } else {
                    $scope = 'store';
                }

                $result[] = [
                    'attribute_id' => $attribute->getId(),
                    'code' => $attribute->getAttributeCode(),
                    'type' => $attribute->getFrontendInput(),
                    'required' => $attribute->getIsRequired(),
                    'scope' => $scope,
                ];
            }
        }

        return $result;
    }

    /**
     * Retrieve attribute options
     *
     * @param  int        $attributeId
     * @param  int|string $store
     * @return array
     */
    public function options($attributeId, $store = null)
    {
        $storeId = $this->_getStoreId($store);
        $attribute = Mage::getModel('catalog/product')
                ->setStoreId($storeId)
                ->getResource()
                ->getAttribute($attributeId);

        /** @var Mage_Catalog_Model_Entity_Attribute $attribute */
        if (!$attribute) {
            $this->_fault('not_exists');
        }

        $options = [];
        if ($attribute->usesSource()) {
            $attribute->setStoreId($storeId);
            foreach ($attribute->getSource()->getAllOptions() as $optionId => $optionValue) {
                $options[] = is_array($optionValue) ? $optionValue : [
                    'value' => $optionId,
                    'label' => $optionValue,
                ];
            }
        }

        return $options;
    }

    /**
     * Retrieve list of possible attribute types
     *
     * @return array
     */
    public function types()
    {
        return Mage::getModel('catalog/product_attribute_source_inputtype')->toOptionArray();
    }

    /**
     * Create new product attribute
     *
     * @param  array $data input data
     * @return int
     */
    public function create($data)
    {
        /** @var Mage_Catalog_Model_Resource_Eav_Attribute $model */
        $model = Mage::getModel('catalog/resource_eav_attribute');
        /** @var Mage_Catalog_Helper_Product $helper */
        $helper = Mage::helper('catalog/product');

        if (empty($data['attribute_code']) || (isset($data['frontend_label']) && !is_array($data['frontend_label']))) {
            $this->_fault('invalid_parameters');
        }

        //validate attribute_code
        $attributeCode = $data['attribute_code'];
        if (!is_string($attributeCode) || !preg_match('/^[a-z][a-z_0-9]{0,254}$/D', $attributeCode)) {
            $this->_fault('invalid_code');
        }

        //validate frontend_input
        $allowedTypes = [];
        foreach ($this->types() as $type) {
            $allowedTypes[] = $type['value'];
        }

        $frontendInput = $data['frontend_input'] ?? null;
        if (!in_array($frontendInput, $allowedTypes, true)) {
            $this->_fault('invalid_frontend_input');
        }

        // keeps only the properties a caller may set, so the values below are set after it
        $this->_prepareDataForSave($data);

        $data['attribute_code'] = $attributeCode;
        $data['frontend_input'] = $frontendInput;
        $data['source_model'] = $helper->getAttributeSourceModelByInputType($frontendInput);
        $data['backend_model'] = $helper->getAttributeBackendModelByInputType($frontendInput);
        if (!$model->getBackendType() && (is_null($model->getIsUserDefined()) || $model->getIsUserDefined() != 0)) {
            $data['backend_type'] = $model->getBackendTypeByInput($frontendInput);
        }

        $model->addData($data);
        $model->setEntityTypeId($this->_entityTypeId);
        $model->setIsUserDefined(1);

        try {
            $model->save();
            // clear translation cache because attribute labels are stored in translation
            Mage::app()->cleanCache([Mage_Core_Model_Translate::CACHE_TAG]);
        } catch (Exception $exception) {
            $this->_fault('unable_to_save', $exception->getMessage());
        }

        return (int) $model->getId();
    }

    /**
     * Update product attribute
     *
     * @param  int|string $attribute attribute code or ID
     * @param  array      $data
     * @return bool
     */
    public function update($attribute, $data)
    {
        $model = $this->_getAttribute($attribute);

        if ($model->getEntityTypeId() != $this->_entityTypeId) {
            $this->_fault('can_not_edit');
        }

        // keeps only the properties a caller may set, so the code, input type, backend and models stay as stored
        $this->_prepareDataForSave($data);

        $model->addData($data);
        try {
            $model->save();
            // clear translation cache because attribute labels are stored in translation
            Mage::app()->cleanCache([Mage_Core_Model_Translate::CACHE_TAG]);
        } catch (Exception $exception) {
            $this->_fault('unable_to_save', $exception->getMessage());
        }

        return true;
    }

    /**
     * Remove attribute
     *
     * @param  int|string $attribute attribute ID or code
     * @return true|void
     */
    public function remove($attribute)
    {
        $model = $this->_getAttribute($attribute);

        if ($model->getEntityTypeId() != $this->_entityTypeId) {
            $this->_fault('can_not_delete');
        }

        if (!$model->getIsUserDefined()) {
            $this->_fault('can_not_delete');
        }

        try {
            $model->delete();
            return true;
        } catch (Exception $exception) {
            $this->_fault('can_not_delete', $exception->getMessage());
        }
    }

    /**
     * Get full information about attribute with list of options
     *
     * @param  int|string           $attribute attribute ID or code
     * @return array<string, mixed>
     */
    public function info($attribute)
    {
        $model = $this->_getAttribute($attribute);

        if ($model->isScopeGlobal()) {
            $scope = 'global';
        } elseif ($model->isScopeWebsite()) {
            $scope = 'website';
        } else {
            $scope = 'store';
        }

        $frontendLabels = [
            [
                'store_id' => 0,
                'label' => $model->getFrontendLabel(),
            ],
        ];
        foreach ($model->getStoreLabels() as $storeId => $label) {
            $frontendLabels[] = [
                'store_id' => $storeId,
                'label' => $label,
            ];
        }

        $result = [
            'attribute_id' => $model->getId(),
            'attribute_code' => $model->getAttributeCode(),
            'frontend_input' => $model->getFrontendInput(),
            'default_value' => $model->getDefaultValue(),
            'is_unique' => $model->getIsUnique(),
            'is_required' => $model->getIsRequired(),
            'apply_to' => $model->getApplyTo(),
            'is_configurable' => $model->getIsConfigurable(),
            'is_searchable' => $model->getIsSearchable(),
            'is_visible_in_advanced_search' => $model->getIsVisibleInAdvancedSearch(),
            'is_comparable' => $model->getIsComparable(),
            'is_used_for_promo_rules' => $model->getIsUsedForPromoRules(),
            'is_visible_on_front' => $model->getIsVisibleOnFront(),
            'used_in_product_listing' => $model->getUsedInProductListing(),
            'frontend_label' => $frontendLabels,
        ];
        if ($model->getFrontendInput() != 'price') {
            $result['scope'] = $scope;
        }

        // set additional fields to different types
        $result['additional_fields'] = match ($model->getFrontendInput()) {
            'text' => [
                'frontend_class' => $model->getFrontendClass(),
                'is_html_allowed_on_front' => $model->getIsHtmlAllowedOnFront(),
                'used_for_sort_by' => $model->getUsedForSortBy(),
            ],
            'textarea' => [
                'is_wysiwyg_enabled' => $model->getIsWysiwygEnabled(),
                'is_html_allowed_on_front' => $model->getIsHtmlAllowedOnFront(),
            ],
            'date', 'boolean' => [
                'used_for_sort_by' => $model->getUsedForSortBy(),
            ],
            'multiselect' => [
                'is_filterable' => $model->getIsFilterable(),
                'is_filterable_in_search' => $model->getIsFilterableInSearch(),
                'position' => $model->getPosition(),
            ],
            'select', 'price' => [
                'is_filterable' => $model->getIsFilterable(),
                'is_filterable_in_search' => $model->getIsFilterableInSearch(),
                'position' => $model->getPosition(),
                'used_for_sort_by' => $model->getUsedForSortBy(),
            ],
            default => [],
        };

        // set options
        $options = $this->options($model->getId());
        // remove empty first element
        if ($model->getFrontendInput() != 'boolean') {
            array_shift($options);
        }

        if (count($options) > 0) {
            $result['options'] = $options;
        }

        return $result;
    }

    /**
     * Add option to select or multiselect attribute
     *
     * @param  int|string $attribute attribute ID or code
     * @param  array      $data
     * @return bool
     */
    public function addOption($attribute, $data)
    {
        $model = $this->_getAttribute($attribute);

        if (!$model->usesSource()) {
            $this->_fault('invalid_frontend_input');
        }

        /** @var Mage_Catalog_Helper_Data $helperCatalog */
        $helperCatalog = Mage::helper('catalog');

        $optionLabels = [];
        foreach ($data['label'] as $label) {
            $storeId = $label['store_id'];
            $labelText = $helperCatalog->stripTags($label['value']);
            if (is_array($storeId)) {
                foreach ($storeId as $multiStoreId) {
                    $optionLabels[$multiStoreId] = $labelText;
                }
            } else {
                $optionLabels[$storeId] = $labelText;
            }
        }

        // data in the following format is accepted by the model
        // it simulates parameters of the request made to
        // Mage_Adminhtml_Catalog_Product_AttributeController::saveAction()
        $modelData = [
            'option' => [
                'value' => [
                    'option_1' => $optionLabels,
                ],
                'order' => [
                    'option_1' => (int) $data['order'],
                ],
            ],
        ];
        if ($data['is_default']) {
            $modelData['default'][] = 'option_1';
        }

        $model->addData($modelData);
        try {
            $model->save();
        } catch (Exception $exception) {
            $this->_fault('unable_to_add_option', $exception->getMessage());
        }

        return true;
    }

    /**
     * Remove option from select or multiselect attribute
     *
     * @param  int|string $attribute attribute ID or code
     * @param  int        $optionId  option to remove ID
     * @return bool
     */
    public function removeOption($attribute, $optionId)
    {
        $model = $this->_getAttribute($attribute);

        if (!$model->usesSource()) {
            $this->_fault('invalid_frontend_input');
        }

        // data in the following format is accepted by the model
        // it simulates parameters of the request made to
        // Mage_Adminhtml_Catalog_Product_AttributeController::saveAction()
        $modelData = [
            'option' => [
                'value' => [
                    $optionId => [],
                ],
                'delete' => [
                    $optionId => '1',
                ],
            ],
        ];
        $model->addData($modelData);
        try {
            $model->save();
        } catch (Exception $exception) {
            $this->_fault('unable_to_remove_option', $exception->getMessage());
        }

        return true;
    }

    /**
     * Retrieve the attribute properties a caller may set through create() and update()
     *
     * @return list<string>
     */
    protected function _getAllowedProperties()
    {
        $allowed = self::ALLOWED_PROPERTIES;
        $node = Mage::app()->getConfig()->getNode(self::XML_PATH_ALLOWED_PROPERTIES);
        if ($node instanceof Mage_Core_Model_Config_Element) {
            foreach ($node->children() as $property) {
                $allowed[] = $property->getName();
            }
        }

        return array_values(array_diff(array_unique($allowed), self::PROTECTED_PROPERTIES));
    }

    /**
     * Prepare request input data for saving
     *
     * Only the allowed attribute properties are kept, from the top level of the input and from additional_fields,
     * so a caller cannot replace the attribute code, id, entity type, backend type or models.
     *
     * @param  array              $data input data
     * @throws Mage_Api_Exception
     */
    protected function _prepareDataForSave(&$data)
    {
        /** @var Mage_Catalog_Helper_Data $helperCatalog */
        $helperCatalog = Mage::helper('catalog');

        $input = $data;
        $allowed = array_flip($this->_getAllowedProperties());
        $data = array_intersect_key($input, $allowed);

        $scope = $input['scope'] ?? null;
        if ($scope == 'global') {
            $data['is_global'] = Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_GLOBAL;
        } elseif ($scope == 'website') {
            $data['is_global'] = Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_WEBSITE;
        } else {
            $data['is_global'] = Mage_Catalog_Model_Resource_Eav_Attribute::SCOPE_STORE;
        }

        $data['is_configurable'] ??= 0;

        $data['is_filterable'] ??= 0;

        $data['is_filterable_in_search'] ??= 0;

        $data['apply_to'] ??= [];

        // set frontend labels array with store_id as keys
        if (isset($input['frontend_label']) && is_array($input['frontend_label'])) {
            $labels = [];
            foreach ($input['frontend_label'] as $label) {
                $storeId = $label['store_id'];
                $labelText = $helperCatalog->stripTags($label['label']);
                $labels[$storeId] = $labelText;
            }

            $data['frontend_label'] = $labels;
        } elseif (isset($input['frontend_label']) && is_scalar($input['frontend_label'])) {
            $data['frontend_label'] = $helperCatalog->stripTags((string) $input['frontend_label']);
        }

        // set additional fields
        if (isset($input['additional_fields']) && is_array($input['additional_fields'])) {
            $data = array_merge($data, array_intersect_key($input['additional_fields'], $allowed));
        }

        // the class is printed into form inputs: allow only CSS class names
        if (isset($data['frontend_class'])
            && (!is_string($data['frontend_class']) || preg_match('/^[a-zA-Z0-9_\- ]*$/D', $data['frontend_class']) !== 1)
        ) {
            $this->_fault('invalid_parameters');
        }

        //default value
        if (!empty($data['default_value'])) {
            $data['default_value'] = $helperCatalog->stripTags($data['default_value']);
        }
    }

    /**
     * Load model by attribute ID or code
     *
     * @param  int|string                                $attribute
     * @return Mage_Catalog_Model_Resource_Eav_Attribute
     */
    protected function _getAttribute($attribute)
    {
        $model = Mage::getResourceModel('catalog/eav_attribute')
            ->setEntityTypeId($this->_entityTypeId);

        if (is_numeric($attribute)) {
            $model->load((int) $attribute);
        } else {
            $model->load($attribute, 'attribute_code');
        }

        if (!$model->getId()) {
            $this->_fault('not_exists');
        }

        return $model;
    }
}
