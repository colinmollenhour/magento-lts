<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Adminhtml
 */

/**
 * Adminhtml config data model
 *
 * @package    Mage_Adminhtml
 *
 * @method array  getGroups()
 * @method string getScope()
 * @method string getScopeCode()
 * @method int    getScopeId()
 * @method string getSection()
 * @method string getStore()
 * @method string getWebsite()
 * @method $this  setGroups(array $value)
 * @method $this  setScope(string $value)
 * @method $this  setScopeCode(string $value)
 * @method $this  setScopeId(int $value)
 * @method $this  setSection(string $value)
 * @method $this  setStore(string $value)
 * @method $this  setWebsite(string $value)
 */
class Mage_Adminhtml_Model_Config_Data extends Varien_Object
{
    public const SCOPE_DEFAULT  = 'default';

    public const SCOPE_WEBSITES = 'websites';

    public const SCOPE_STORES   = 'stores';

    /**
     * Config data for sections
     *
     * @var null|array
     */
    protected $_configData;

    /**
     * Root config node
     *
     * @var null|Mage_Core_Model_Config_Element
     */
    protected $_configRoot;

    /**
     * Save config section
     * Require set: section, website, store and groups
     *
     * @return $this
     */
    public function save()
    {
        $this->_validate();
        $this->_getScope();

        Mage::dispatchEvent('model_config_data_save_before', ['object' => $this]);

        $section = $this->getSection();
        $website = $this->getWebsite();
        $store   = $this->getStore();
        $groups  = $this->getGroups();
        $scope   = $this->getScope();
        $scopeId = $this->getScopeId();

        if (empty($groups)) {
            return $this;
        }

        $sections = Mage::getModel('adminhtml/config')->getSections();
        /** @var Mage_Core_Model_Config_Element $sections */

        $oldConfig = $this->_getConfig(true);

        $deleteTransaction = Mage::getModel('core/resource_transaction');
        /** @var Mage_Core_Model_Resource_Transaction $deleteTransaction */
        $saveTransaction = Mage::getModel('core/resource_transaction');
        /** @var Mage_Core_Model_Resource_Transaction $saveTransaction */

        // Extends for old config data
        $oldConfigAdditionalGroups = [];

        foreach ($groups as $group => $groupData) {
            /**
             * Map field names if they were cloned
             */
            $groupConfig = $sections->descend($section . '/groups/' . $group);

            if ($clonedFields = !empty($groupConfig->clone_fields)) {
                if ($groupConfig->clone_model) {
                    $cloneModel = Mage::getModel((string) $groupConfig->clone_model);
                } else {
                    Mage::throwException('Config form fieldset clone model required to be able to clone fields');
                }

                $mappedFields = [];
                $fieldsConfig = $sections->descend($section . '/groups/' . $group . '/fields');

                if ($fieldsConfig->hasChildren()) {
                    foreach ($fieldsConfig->children() as $field => $node) {
                        foreach ($cloneModel->getPrefixes() as $prefix) {
                            $mappedFields[$prefix['field'] . $field] = (string) $field;
                        }
                    }
                }
            }

            // set value for group field entry by fieldname
            // use extra memory
            $fieldsetData = [];
            foreach ($groupData['fields'] as $field => $fieldData) {
                $fieldsetData[$field] = (is_array($fieldData) && isset($fieldData['value']))
                    ? $fieldData['value'] : null;
            }

            $helper = Mage::helper('adminhtml/config');
            foreach ($groupData['fields'] as $field => $fieldData) {
                $field = ltrim($field, '/');
                $fieldConfig = $sections->descend($section . '/groups/' . $group . '/fields/' . $field);
                if (!$fieldConfig && $clonedFields && isset($mappedFields[$field])) {
                    $fieldConfig = $sections->descend($section . '/groups/' . $group . '/fields/'
                        . $mappedFields[$field]);
                }

                if (!$fieldConfig && $this->_isPlainIdentifier($group) && $this->_isPlainIdentifier($field)) {
                    $node = $sections->xpath($section . '//' . $group . '[@type="group"]/fields/' . $field);
                    if ($node) {
                        $fieldConfig = $node[0];
                    }
                }

                $backendClass = $fieldConfig ? $helper->getBackendModelByFieldConfig($fieldConfig) : false;
                if (!$backendClass) {
                    $backendClass = 'core/config_data';
                }

                /** @var Mage_Core_Model_Config_Data $dataObject */
                $dataObject = Mage::getModel($backendClass);
                if (!$dataObject instanceof Mage_Core_Model_Config_Data) {
                    Mage::throwException('Invalid config field backend model: ' . $backendClass);
                }

                $dataObject
                    ->setField($field)
                    ->setGroups($groups)
                    ->setGroupId($group)
                    ->setStoreCode($store)
                    ->setWebsiteCode($website)
                    ->setScope($scope)
                    ->setScopeId($scopeId)
                    ->setFieldConfig($fieldConfig)
                    ->setFieldsetData($fieldsetData)
                ;

                $fieldData['value'] ??= null;

                $path = $section . '/' . $group . '/' . $field;

                /**
                 * Look for custom defined field path
                 */
                if (is_object($fieldConfig)) {
                    $configPath = (string) $fieldConfig->config_path;
                    if (!empty($configPath) && strrpos($configPath, '/') > 0) {
                        $parts = explode('/', $configPath);
                        if (!$this->_isSectionAllowed($parts[0])) {
                            Mage::throwException('Access denied.');
                        }

                        // Extend old data with specified section group
                        $groupPath = substr($configPath, 0, strrpos($configPath, '/'));
                        if (!isset($oldConfigAdditionalGroups[$groupPath])) {
                            $oldConfig = $this->extendConfig($groupPath, true, $oldConfig);
                            $oldConfigAdditionalGroups[$groupPath] = true;
                        }

                        $path = $configPath;
                    }
                }

                $inherit = !empty($fieldData['inherit']);
                if (!$inherit && is_object($fieldConfig)) {
                    $this->_validateFieldValue($fieldConfig, $fieldData['value']);
                }

                $dataObject->setPath($path)
                    ->setValue($fieldData['value']);

                if (isset($oldConfig[$path])) {
                    $dataObject->setConfigId($oldConfig[$path]['config_id']);

                    /**
                     * Delete config data if inherit
                     */
                    if (!$inherit) {
                        $saveTransaction->addObject($dataObject);
                    } else {
                        $deleteTransaction->addObject($dataObject);
                    }
                } elseif (!$inherit) {
                    $dataObject->unsConfigId();
                    $saveTransaction->addObject($dataObject);
                }
            }
        }

        $deleteTransaction->delete();
        $saveTransaction->save();

        return $this;
    }

    /**
     * Load config data for section
     *
     * @return array
     */
    public function load()
    {
        if (is_null($this->_configData)) {
            $this->_validate();
            $this->_getScope();
            $this->_configData = $this->_getConfig(false);
        }

        return $this->_configData;
    }

    /**
     * Extend config data with additional config data by specified path
     *
     * @param  string $path      Config path prefix
     * @param  bool   $full      Simple config structure or not
     * @param  array  $oldConfig Config data to extend
     * @return array
     */
    public function extendConfig($path, $full = true, $oldConfig = [])
    {
        $extended = $this->_getPathConfig($path, $full);
        if (is_array($oldConfig) && $oldConfig !== []) {
            return $oldConfig + $extended;
        }

        return $extended;
    }

    /**
     * Check if specified section allowed in ACL
     *
     * Taken from Mage_Adminhtml_System_ConfigController::_isSectionAllowed
     *
     * @param  string $section
     * @return bool
     */
    protected function _isSectionAllowed($section)
    {
        try {
            $session = Mage::getSingleton('admin/session');
            $resourceLookup = "admin/system/config/{$section}";
            if ($session->getDataByKey('acl') instanceof Mage_Admin_Model_Acl) {
                return $session->isAllowed(
                    $session->getDataByKey('acl')->get($resourceLookup)->getResourceId(),
                );
            }
        } catch (Exception) {
            return false;
        }

        return false;
    }

    /**
     * Validate isset required parametrs
     */
    protected function _validate()
    {
        if (is_null($this->getSection())) {
            $this->setSection('');
        }

        if (is_null($this->getWebsite())) {
            $this->setWebsite('');
        }

        if (is_null($this->getStore())) {
            $this->setStore('');
        }
    }

    /**
     * Get scope name and scopeId
     */
    protected function _getScope()
    {
        if ($this->getStore()) {
            $scope   = self::SCOPE_STORES;
            $scopeId = (int) Mage::getConfig()->getNode('stores/' . $this->getStore() . '/system/store/id');
            $scopeCode = $this->getStore();
        } elseif ($this->getWebsite()) {
            $scope   = self::SCOPE_WEBSITES;
            $scopeId = (int) Mage::getConfig()->getNode('websites/' . $this->getWebsite() . '/system/website/id');
            $scopeCode = $this->getWebsite();
        } else {
            $scope   = self::SCOPE_DEFAULT;
            $scopeId = 0;
            $scopeCode = '';
        }

        $this->setScope($scope);
        $this->setScopeId($scopeId);
        $this->setScopeCode($scopeCode);
    }

    /**
     * Return formatted config data for current section
     *
     * @param  bool  $full Simple config structure or not
     * @return array
     */
    protected function _getConfig($full = true)
    {
        return $this->_getPathConfig($this->getSection(), $full);
    }

    /**
     * Return formatted config data for specified path prefix
     *
     * @param  string $path Config path prefix
     * @param  bool   $full Simple config structure or not
     * @return array
     */
    protected function _getPathConfig($path, $full = true)
    {
        $configDataCollection = Mage::getModel('core/config_data')
            ->getCollection()
            ->addScopeFilter($this->getScope(), $this->getScopeId(), $path);

        $config = [];
        foreach ($configDataCollection as $data) {
            if ($full) {
                $config[$data->getPath()] = [
                    'path'      => $data->getPath(),
                    'value'     => $data->getValue(),
                    'config_id' => $data->getConfigId(),
                ];
            } else {
                $config[$data->getPath()] = $data->getValue();
            }
        }

        if (!$full) {
            /** @var Mage_Core_Helper_EnvironmentConfigLoader $environmentConfigLoaderHelper */
            $environmentConfigLoaderHelper = Mage::helper('core/environmentConfigLoader');
            $store = $this->getStore();
            $envConfig = $environmentConfigLoaderHelper->getAsArray($store);
            $config = array_merge($config, $envConfig);
        }

        return $config;
    }

    /**
     * Get config data value
     *
     * @param string     $path
     * @param null|bool  $inherit
     * @param null|array $configData
     * @param-out bool $inherit
     * @return Varien_Simplexml_Element
     */
    public function getConfigDataValue($path, &$inherit = null, $configData = null)
    {
        $this->load();
        $configData ??= $this->_configData;

        if (array_key_exists($path, $configData)) {
            $data = $configData[$path];
            $inherit = false;
        } else {
            $data = $this->getConfigRoot()->descend($path);
            $inherit = true;
        }

        return $data;
    }

    /**
     * Get config root node for current scope
     *
     * @return Mage_Core_Model_Config_Element
     */
    public function getConfigRoot()
    {
        if (is_null($this->_configRoot)) {
            $this->load();
            $this->_configRoot = Mage::getConfig()->getNode(null, $this->getScope(), $this->getScopeCode());
        }

        return $this->_configRoot;
    }

    /**
     * Secure set groups
     *
     * @param  array               $groups
     * @return $this
     * @throws Mage_Core_Exception
     */
    public function setGroupsSecure($groups)
    {
        $this->_validate();
        $this->_getScope();

        $groupsSecure = [];
        $section = $this->getSection();
        $sections = Mage::getModel('adminhtml/config')->getSections();

        foreach ($groups as $group => $groupData) {
            $groupConfig = $sections->descend($section . '/groups/' . $group);
            foreach ($groupData['fields'] as $field => $fieldData) {
                $fieldName = $field;
                if ($groupConfig && $groupConfig->clone_fields) {
                    if ($groupConfig->clone_model) {
                        $cloneModel = Mage::getModel((string) $groupConfig->clone_model);
                    } else {
                        Mage::throwException(
                            $this->__('Config form fieldset clone model required to be able to clone fields'),
                        );
                    }

                    foreach ($cloneModel->getPrefixes() as $prefix) {
                        if (str_starts_with($field, $prefix['field'])) {
                            $field = substr($field, strlen($prefix['field']));
                        }
                    }
                }

                $fieldConfig = $sections->descend($section . '/groups/' . $group . '/fields/' . $field);
                if (!$fieldConfig && $this->_isPlainIdentifier($group) && $this->_isPlainIdentifier($field)) {
                    $node = $sections->xpath($section . '//' . $group . '[@type="group"]/fields/' . $field);
                    if ($node) {
                        $fieldConfig = $node[0];
                    }
                }

                if (($groupConfig ? !$groupConfig->dynamic_group : true) && !$this->_isValidField($fieldConfig)) {
                    $message = Mage::helper('adminhtml')->__('Wrong field specified.') . ' ' . Mage::helper('adminhtml')->__('(%s/%s/%s)', $section, $group, $field);
                    Mage::throwException($message);
                }

                $groupsSecure[$group]['fields'][$fieldName] = $fieldData;
            }
        }

        $this->setGroups($groupsSecure);

        return $this;
    }

    /**
     * Check field visibility by scope
     *
     * @param  Mage_Core_Model_Config_Element $field
     * @return bool
     */
    protected function _isValidField($field)
    {
        if (!$field) {
            return false;
        }

        return match ($this->getScope()) {
            self::SCOPE_DEFAULT => (bool) (int) $field->show_in_default,
            self::SCOPE_WEBSITES => (bool) (int) $field->show_in_website,
            self::SCOPE_STORES => (bool) (int) $field->show_in_store,
            default => true,
        };
    }

    /**
     * Whether a posted group or field key is a plain config node name
     *
     * Group and field keys are concatenated into an XPath expression for the
     * dynamic_group fallback lookup. Anything other than a plain identifier
     * (e.g. an XPath union such as "x|//admin/...") must be rejected before
     * it reaches that expression, or it can select a node outside the
     * requested section.
     *
     * @param  string $value
     * @return bool
     */
    protected function _isPlainIdentifier($value)
    {
        return is_scalar($value) && preg_match('/^[a-zA-Z0-9_]+$/', (string) $value) === 1;
    }

    /**
     * Check a posted value against the numeric validation classes and the Yes/No source its field declares
     *
     * The admin form applies these checks only in the browser, so a request that skips them could store any
     * string. Each check follows its counterpart in js/prototype/validation.js, except that the classes that
     * only compare a number (greater than zero, zero or greater, not negative) also require the value to be a
     * number. As in the browser, an empty value passes the numeric checks.
     *
     * @param  Varien_Simplexml_Element $fieldConfig
     * @param  mixed                    $value
     * @throws Mage_Core_Exception      if the value does not pass a check
     */
    protected function _validateFieldValue($fieldConfig, $value): void
    {
        if ($value === null) {
            return;
        }

        $label = (string) $fieldConfig->label;
        $label = $label === '' ? $fieldConfig->getName() : Mage::helper('adminhtml')->__($label);

        if ((string) $fieldConfig->frontend_type === 'select' && $this->_isBooleanSourceModel((string) $fieldConfig->source_model)) {
            if (is_bool($value)) {
                $value = (int) $value;
            }

            if (!is_scalar($value) || !in_array((string) $value, ['0', '1'], true)) {
                Mage::throwException($label . ': ' . Mage::helper('core')->__('Please select an option.'));
            }

            return;
        }

        $classes = preg_split('/\s+/', trim($fieldConfig->validate . ' ' . $fieldConfig->frontend_class), -1, PREG_SPLIT_NO_EMPTY);
        $numericClasses = array_intersect($classes === false ? [] : $classes, [
            'validate-digits',
            'validate-number',
            'validate-greater-than-zero',
            'validate-zero-or-greater',
            'validate-not-negative-number',
        ]);
        if ($numericClasses === []) {
            return;
        }

        if (!is_scalar($value)) {
            Mage::throwException($label . ': ' . Mage::helper('core')->__('Please enter a valid number in this field.'));
        }

        $value = (string) $value;
        if (preg_match('/^\s*$/D', $value) === 1) {
            return;
        }

        $number = $this->_parseNumber($value);
        foreach ($numericClasses as $class) {
            switch ($class) {
                case 'validate-digits':
                    $isValid = preg_match('/^\d+$/D', $value) === 1;
                    $message = 'Please use numbers only in this field. Please avoid spaces or other characters such as dots or commas.';
                    break;
                case 'validate-number':
                    $isValid = preg_match('/^\s*-?(\d+(\.\d*)?|\.\d+)\s*$/D', $value) === 1;
                    $message = 'Please enter a valid number in this field.';
                    break;
                case 'validate-greater-than-zero':
                    $isValid = $number !== null && $number > 0;
                    $message = 'Please enter a number greater than 0 in this field.';
                    break;
                default:
                    $isValid = $number !== null && $number >= 0;
                    $message = 'Please enter a number 0 or greater in this field.';
            }

            if (!$isValid) {
                Mage::throwException($label . ': ' . Mage::helper('core')->__($message));
            }
        }
    }

    /**
     * Whether a source model is the core Yes/No or Enable/Disable source, whose only options are 0 and 1
     */
    protected function _isBooleanSourceModel(string $sourceModel): bool
    {
        $config = Mage::getConfig();
        if ($config === null || $sourceModel === '' || str_contains($sourceModel, '::')) {
            return false;
        }

        return in_array($config->getModelClassName($sourceModel), [
            Mage_Adminhtml_Model_System_Config_Source_Yesno::class,
            Mage_Adminhtml_Model_System_Config_Source_Enabledisable::class,
        ], true);
    }

    /**
     * Parse a number the way parseNumber() in js/prototype/validation.js reads separators, but strictly
     *
     * A comma is read as the decimal separator, or as a thousands separator when a dot follows it.
     *
     * @return null|float the number, or null if the value is not a number
     */
    protected function _parseNumber(string $value): ?float
    {
        $value = trim($value);
        $dot = strpos($value, '.');
        $comma = strpos($value, ',');
        if ($dot !== false && $comma !== false) {
            $value = $comma > $dot
                ? (string) preg_replace('/,/', '.', (string) preg_replace('/\./', '', $value, 1), 1)
                : (string) preg_replace('/,/', '', $value, 1);
        } elseif ($comma !== false) {
            $value = (string) preg_replace('/,/', '.', $value, 1);
        }

        if (preg_match('/^-?(\d+(\.\d*)?|\.\d+)$/D', $value) !== 1) {
            return null;
        }

        return (float) $value;
    }

    /**
     * Select group setter is secure or not based on the configuration
     *
     * @param  array                            $groups
     * @return Mage_Adminhtml_Model_Config_Data
     * @throws Mage_Core_Exception
     */
    public function setGroupsSelector($groups)
    {
        if (Mage::getStoreConfigFlag('admin/security/secure_system_configuration_save_disabled')) {
            return $this->setGroups($groups);
        }

        return $this->setGroupsSecure($groups);
    }
}
