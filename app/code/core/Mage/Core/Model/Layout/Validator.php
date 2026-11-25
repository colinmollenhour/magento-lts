<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Core
 */

use Monolog\Level;

/**
 * Validator for custom layout update
 *
 * Validator checked XML validation and protected expressions
 *
 * @package    Mage_Core
 */
class Mage_Core_Model_Layout_Validator extends Mage_Core_Model_Validate_Abstract
{
    public const XML_PATH_LAYOUT_DISALLOWED_BLOCKS       = 'validators/custom_layout/disallowed_block';

    public const XML_INVALID                             = 'invalidXml';

    public const INVALID_TEMPLATE_PATH                   = 'invalidTemplatePath';

    public const INVALID_BLOCK_NAME                      = 'invalidBlockName';

    public const PROTECTED_ATTR_HELPER_IN_TAG_ACTION_VAR = 'protectedAttrHelperInActionVar';

    public const PROTECTED_ATTR_JSON_IN_TAG_ACTION       = 'protectedAttrJsonInAction';

    public const INVALID_XML_OBJECT_EXCEPTION            = 'invalidXmlObject';

    /**
     * The Varien SimpleXml object
     *
     * @inheritDoc
     */
    protected $_value;

    /**
     * XPath expression for checking layout update
     *
     * @var array
     */
    protected $_disallowedXPathExpressions = [
        "*//*[local-name()='template']",
        '*//@template',
        // PHP resolves method names case-insensitively and accepts "Class::method" callables, so the
        // method attribute is compared after dropping any "Class::" part and lower-casing it
        "//*[translate(concat(substring-after(@method, '::'), substring(@method, 1 div not(contains(@method, '::')))),
        'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='settemplate']",
        "//*[translate(concat(substring-after(@method, '::'), substring(@method, 1 div not(contains(@method, '::')))),
        'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')='setdatausingmethod']//*[contains(translate(text(),
        'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz'), 'template')]/../*",
    ];

    /**
     * @var string
     */
    protected $_xpathBlockValidationExpression = '';

    /**
     * Disallowed template name
     *
     * @var array
     */
    protected $_disallowedBlock = [];

    /**
     * Protected expressions
     *
     * @var array
     */
    protected $_protectedExpressions = [
        self::PROTECTED_ATTR_HELPER_IN_TAG_ACTION_VAR => "//*[local-name()='action']/*[@helper]",
        // The layout generator JSON-decodes the action arguments named in a "json" attribute. That is the only
        // way layout XML can pass a list, and so a callable such as ["Class", "method"], to a block method.
        self::PROTECTED_ATTR_JSON_IN_TAG_ACTION       => '//*[@json]',
    ];

    /**
     * Admin block classes that may not be placed on the storefront by a custom layout update
     *
     * Admin blocks expect an authorised back-office user. On the storefront they publish admin data (grids
     * load whole collections) and accept configuration that runs code, such as grid column callbacks.
     *
     * @var list<class-string>
     */
    protected $_adminhtmlBlockClasses = [
        Mage_Adminhtml_Block_Abstract::class,
        Mage_Adminhtml_Block_Template::class,
    ];

    /**
     * Whether a class name is loaded or can be autoloaded, by class name
     *
     * @var array<string, bool>
     */
    protected $_loadableClasses = [];

    public function __construct()
    {
        $this->_initMessageTemplates();
        $this->getDisallowedBlocks();
    }

    /**
     * Initialize messages templates with translating
     *
     * @return $this
     */
    protected function _initMessageTemplates()
    {
        if (!$this->_messageTemplates) {
            $this->_messageTemplates = [
                self::PROTECTED_ATTR_HELPER_IN_TAG_ACTION_VAR
                    => Mage::helper('core')->__('Helper attributes should not be used in custom layout updates.'),
                self::PROTECTED_ATTR_JSON_IN_TAG_ACTION
                    => Mage::helper('core')->__('JSON arguments should not be used in custom layout updates.'),
                self::XML_INVALID => Mage::helper('core')->__('XML data is invalid.'),
                self::INVALID_TEMPLATE_PATH => Mage::helper('core')->__(
                    'Invalid template path used in layout update.',
                ),
                self::INVALID_BLOCK_NAME => Mage::helper('core')->__('Disallowed block name for frontend.'),
                self::INVALID_XML_OBJECT_EXCEPTION
                    => Mage::helper('core')->__('XML object is not instance of "Varien_Simplexml_Element".'),
            ];
        }

        return $this;
    }

    /**
     * @return array
     */
    public function getDisallowedBlocks()
    {
        if (!count($this->_disallowedBlock)) {
            $disallowedBlockConfig = $this->_getDisallowedBlockConfigValue();
            if (is_array($disallowedBlockConfig)) {
                foreach (array_keys($disallowedBlockConfig) as $blockName) {
                    $this->_disallowedBlock[] = $blockName;
                }
            }
        }

        return $this->_disallowedBlock;
    }

    /**
     * @return mixed
     */
    protected function _getDisallowedBlockConfigValue()
    {
        return Mage::getStoreConfig(self::XML_PATH_LAYOUT_DISALLOWED_BLOCKS);
    }

    /**
     * Returns true if and only if $value meets the validation requirements
     *
     * If $value fails validation, then this method returns false, and
     * getMessages() will return an array of messages that explain why the
     * validation failed.
     *
     * @param  string|Varien_Simplexml_Element $value
     * @return bool
     * @throws Exception                       Throw exception when xml object is not
     *                                         instance of Varien_Simplexml_Element
     */
    public function isValid($value)
    {
        // Reset messages and give _error() a value: errors raised before the parsed value is set would
        // otherwise pass null to _createMessage() and throw a TypeError, which callers catching
        // Exception (e.g. Mage_Catalog_Model_Design) do not handle.
        $this->_setValue(is_string($value) ? $value : '');

        if (is_string($value)) {
            $value = trim($value);
            try {
                $value = new Varien_Simplexml_Element('<config>' . $value . '</config>');
            } catch (Exception) {
                $this->_error(self::XML_INVALID);
                return false;
            }
        } elseif (!($value instanceof Varien_Simplexml_Element)) {
            throw new Exception($this->_messageTemplates[self::INVALID_XML_OBJECT_EXCEPTION]);
        }

        // Layout XML never uses namespaces. SimpleXML (and so the layout generator) treats an element in a
        // default namespace as if it had none, while XPath name tests do not match it, so a namespaced
        // element could slip past the checks below. Reject any namespaced element or attribute outright.
        $namespacedNodes = $value->xpath("//*[namespace-uri() != ''] | //@*[namespace-uri() != '']");
        if (is_array($namespacedNodes) && $namespacedNodes !== []) {
            $this->_error(self::XML_INVALID);
            return false;
        }

        $disallowedBlocks = $value->xpath($this->getXpathBlockValidationExpression());
        if ((is_array($disallowedBlocks) && $disallowedBlocks !== [])
            || $this->_hasDisallowedBlock($value)
            || $this->_hasAdminhtmlArea($value)
        ) {
            $this->_error(self::INVALID_BLOCK_NAME);
            return false;
        }

        // if layout update declare custom templates then validate their paths
        if ($templatePaths = $value->xpath($this->getXpathValidationExpression())) {
            try {
                $this->validateTemplatePath($templatePaths);
            } catch (Exception) {
                $this->_error(self::INVALID_TEMPLATE_PATH);
                return false;
            }
        }

        $this->_setValue($value);

        foreach ($this->_protectedExpressions as $key => $xpr) {
            if ($this->_value->xpath($xpr)) {
                $this->_error($key);
                return false;
            }
        }

        return true;
    }

    /**
     * Return a stored custom layout update if it still passes validation, '' otherwise
     *
     * Custom layout updates are validated when they are saved, but an update saved before a rule
     * existed would keep being applied. The code that applies a stored product, category or CMS page
     * update validates it again with this method, so such an update stops working once the rule
     * exists. A refused update is logged and skipped: the page renders without it.
     *
     * Use a new validator instance for each update, as messages accumulate.
     *
     * @param mixed  $layoutUpdate the stored update
     * @param string $source       what the update belongs to, for the log, e.g. "product 42"
     */
    public function getValidStoredUpdate($layoutUpdate, string $source): string
    {
        if (!is_string($layoutUpdate) || trim($layoutUpdate) === '') {
            return '';
        }

        try {
            if ($this->isValid($layoutUpdate)) {
                return $layoutUpdate;
            }

            $reason = implode(' ', $this->getMessages());
        } catch (Throwable $throwable) {
            $reason = $throwable->getMessage();
        }

        Mage::log(
            sprintf('The custom layout update of %s was not applied because it is not valid: %s', $source, $reason),
            Level::Warning,
        );

        return '';
    }

    /**
     * @return array
     */
    public function getProtectedExpressions()
    {
        return $this->_protectedExpressions;
    }

    /**
     * Returns xPath for validate incorrect path to template
     *
     * @return string xPath for validate incorrect path to template
     */
    public function getXpathValidationExpression()
    {
        return implode(' | ', $this->_disallowedXPathExpressions);
    }

    /**
     * @return array
     */
    public function getDisallowedXpathValidationExpression()
    {
        return $this->_disallowedXPathExpressions;
    }

    /**
     * Returns xPath for validate incorrect block name
     *
     * @return string xPath for validate incorrect block name
     */
    public function getXpathBlockValidationExpression()
    {
        if (!$this->_xpathBlockValidationExpression && count($this->_disallowedBlock)) {
            foreach ($this->_disallowedBlock as $key => $value) {
                $this->_xpathBlockValidationExpression .= $key > 0 ? ' | ' : '';
                $this->_xpathBlockValidationExpression
                    .= "//*[local-name()='block'][translate(@type, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz') = ";
                $this->_xpathBlockValidationExpression
                    .= "translate('{$value}', 'ABCDEFGHIJKLMNOPQRSTUVWXYZ', 'abcdefghijklmnopqrstuvwxyz')]";
            }
        }

        return $this->_xpathBlockValidationExpression;
    }

    /**
     * Whether any value of the layout update names a disallowed block class
     *
     * Blocks are not only created from the "class" or "type" attribute of a <block> element. Block
     * methods that take a block type as an argument, such as Mage_Catalog_Block_Product_View_Tabs::addTab(),
     * addItemRender(), addPriceBlockType() or addOptionRenderer(), pass an action argument to
     * Mage_Core_Model_Layout::createBlock(), at once or when the page renders, and extensions add more such
     * methods. So every text and attribute value of the update, and the translation of every action
     * argument listed in a "translate" attribute, is treated as a block type: it is resolved the way the
     * layout generator resolves one ("module/name" aliases through the configuration, class names
     * case-insensitively, with or without a leading backslash) and refused when it is an admin block (see
     * _isAdminhtmlBlock()), a class on the disallowed_block list or a subclass of one. Template paths,
     * titles, numbers and block names do not resolve to such a class.
     */
    protected function _hasDisallowedBlock(Varien_Simplexml_Element $xml): bool
    {
        $disallowed = [];
        foreach ($this->getDisallowedBlocks() as $blockName) {
            $className = $this->_resolveBlockClassName((string) $blockName);
            if ($className !== '') {
                $disallowed[strtolower($className)] = $className;
            }
        }

        foreach ($this->_getValueCandidates($xml) as $value) {
            $className = $this->_resolveBlockClassName($value);
            if ($className === '') {
                continue;
            }

            if ($this->_isAdminhtmlBlock($className) || isset($disallowed[strtolower($className)])) {
                return true;
            }

            foreach ($disallowed as $disallowedClass) {
                if ($this->_isSubclassOf($className, $disallowedClass)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Whether the layout update switches a block to the admin design area
     *
     * A block renders its template from the design area returned by getArea(), which reads the "area"
     * data key, so a storefront block switched to "adminhtml" renders admin templates such as the grid
     * template. A storefront update has no reason to change the area of a block: any setArea() call is
     * refused, compared the way PHP resolves method names (case-insensitively, after any "Class::"
     * part), and so is any value that names the admin area, which covers setData(), addData() and
     * setDataUsingMethod() with the "area" key.
     */
    protected function _hasAdminhtmlArea(Varien_Simplexml_Element $xml): bool
    {
        $methods = $xml->xpath('//@method');
        foreach (is_array($methods) ? $methods : [] as $method) {
            $method = (string) $method;
            $separator = strrpos($method, '::');
            if ($separator !== false) {
                $method = substr($method, $separator + 2);
            }

            if (strtolower(trim($method)) === 'setarea') {
                return true;
            }
        }

        foreach ($this->_getValueCandidates($xml) as $value) {
            // the area is a directory name, so "./adminhtml/" or, on a case-insensitive file system,
            // "ADMINHTML" reaches the same templates
            if (strtolower(trim($value, " \t\n\r\0\x0B./\\")) === Mage_Core_Model_App_Area::AREA_ADMINHTML) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every value the layout generator could pass to a block method or a block factory
     *
     * That is the text of every element and the value of every attribute, as written and, for the
     * arguments an action lists in its "translate" attribute, as translated by the module the action
     * names, the way Mage_Core_Model_Layout::_translateLayoutNode() translates them. Translations depend
     * on the locale, so a stored update is validated again in the store's locale when it is applied.
     *
     * @return list<string>
     */
    protected function _getValueCandidates(Varien_Simplexml_Element $xml): array
    {
        $values = [];
        $nodes = $xml->xpath('//* | //@*');
        foreach (is_array($nodes) ? $nodes : [] as $node) {
            $values[(string) $node] = true;
        }

        $translatedNodes = $xml->xpath('//*[@translate]');
        foreach (is_array($translatedNodes) ? $translatedNodes : [] as $node) {
            $moduleName = isset($node['module']) ? (string) $node['module'] : 'core';
            foreach (explode(' ', (string) $node['translate']) as $argumentPath) {
                $argument = $node;
                foreach (explode('.', $argumentPath) as $argumentName) {
                    $child = null;
                    foreach ($argument->children() as $childName => $childElement) {
                        if ($childName === $argumentName) {
                            $child = $childElement;
                            break;
                        }
                    }

                    if ($child === null) {
                        continue 2;
                    }

                    $argument = $child;
                }

                try {
                    $values[(string) Mage::helper($moduleName)->__((string) $argument)] = true;
                } catch (Throwable) {
                    // the layout generator fails the same way before it calls the action
                }
            }
        }

        return array_map(strval(...), array_keys($values));
    }

    /**
     * Whether $className is an admin block
     *
     * Matches the core admin blocks ("adminhtml/..." resolves to Mage_Adminhtml_Block_...), the
     * "Block_Adminhtml" naming used by admin blocks of other modules and extensions, and any class
     * extending a core admin block base class.
     */
    protected function _isAdminhtmlBlock(string $className): bool
    {
        if (preg_match('/^Mage_Adminhtml_|[_\\\\]Block[_\\\\]Adminhtml(?:[_\\\\]|$)/i', $className) === 1) {
            return true;
        }

        foreach ($this->_adminhtmlBlockClasses as $adminhtmlBlockClass) {
            if ($this->_isSubclassOf($className, $adminhtmlBlockClass)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolve a block type or class name to the class name the layout generator would load
     */
    protected function _resolveBlockClassName(string $blockType): string
    {
        $blockType = trim($blockType);
        if (str_contains($blockType, '/')) {
            try {
                $blockType = (string) Mage::getConfig()?->getBlockClassName($blockType);
            } catch (Throwable) {
                // any value is resolved here, not only block types; the layout generator cannot create a
                // block from a value that cannot be resolved either
                return '';
            }
        }

        return ltrim($blockType, '\\');
    }

    /**
     * Whether $className extends $parentClassName
     *
     * A class that is not loaded yet is only autoloaded when its name is a plain class name with a
     * file on the include path, the same condition under which the layout generator loads it.
     */
    protected function _isSubclassOf(string $className, string $parentClassName): bool
    {
        foreach ([$className, $parentClassName] as $name) {
            // every value of an update is a candidate, so the include path lookup is remembered
            $this->_loadableClasses[$name] ??= class_exists($name, false)
                || (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 && is_string(mageFindClassFile($name)));
            if (!$this->_loadableClasses[$name]) {
                return false;
            }
        }

        return is_subclass_of($className, $parentClassName);
    }

    /**
     * Validate template path for preventing access to the directory above
     *
     * Every value that may reach setTemplate() is checked: the node itself or, for an action, each
     * of its arguments, both as written and decoded the way the layout may decode it (C-style
     * escapes, JSON for arguments listed in the "json" attribute). Template::fetchView() refuses
     * any ".." in a template path, so such paths are refused here as well.
     *
     * @throws Exception
     */
    public function validateTemplatePath(array $templatePaths)
    {
        /** @var Varien_Simplexml_Element $path */
        foreach ($templatePaths as $path) {
            foreach ($this->_getTemplatePathCandidates($path) as $candidate) {
                if (str_contains($candidate, '..')) {
                    throw new Exception();
                }
            }
        }
    }

    /**
     * @return list<string>
     */
    protected function _getTemplatePathCandidates(Varien_Simplexml_Element $path): array
    {
        $values = [];
        if ($path->hasChildren()) {
            foreach ($path->children() as $argument) {
                $values[] = (string) $argument;
            }
        } else {
            $values[] = (string) $path;
        }

        $candidates = [];
        foreach ($values as $value) {
            $candidates[] = $value;
            $candidates[] = stripcslashes(trim($value, '"'));
            $decoded = json_decode($value, true);
            if (is_string($decoded)) {
                $candidates[] = $decoded;
            }
        }

        return $candidates;
    }
}
