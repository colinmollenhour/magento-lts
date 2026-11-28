<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Varien_Filter
 */

/**
 * Template constructions filter
 *
 * @package    Varien_Filter
 */

class Varien_Filter_Template implements Zend_Filter_Interface
{
    /**
     * Cunstruction regular expression
     */
    public const CONSTRUCTION_PATTERN = '/{{([a-z]{0,10})(.*?)}}/si';

    /**
     * Cunstruction logic regular expression
     */
    public const CONSTRUCTION_DEPEND_PATTERN = '/{{depend\s*(.*?)}}(.*?){{\\/depend\s*}}/si';

    public const CONSTRUCTION_IF_PATTERN = '/{{if\s*(.*?)}}(.*?)({{else}}(.*?))?{{\\/if\s*}}/si';

    /**
     * Config node listing the methods a template expression may call with arguments
     *
     * Each child declares one method of one class (subclasses included):
     * <entry_name><class>Mage_Sales_Model_Order</class><method>getCreatedAtFormated</method></entry_name>
     */
    public const XML_PATH_VARIABLE_METHODS = 'global/template/variable_methods';

    /**
     * Name prefixes of the methods a template expression may call without arguments
     */
    public const VARIABLE_GETTER_PATTERN = '/^(?:get|is|has)/i';

    /**
     * How many template expressions are being evaluated right now (they nest, for example when
     * an address format() renders its own template)
     *
     * @var int
     */
    protected static $_variableProcessingDepth = 0;

    /**
     * Methods that template expressions may call with arguments, see XML_PATH_VARIABLE_METHODS
     *
     * @var null|list<array{0: string, 1: string}> pairs of class name and lower-case method name
     */
    protected $_allowedVariableMethods = null;

    /**
     * Assigned template variables
     *
     * @var array
     */
    protected $_templateVars = [];

    /**
     * Template processor
     *
     * @var null|Closure|non-empty-array
     */
    protected $_templateProcessor = null;

    /**
     * Include processor
     *
     * @var null|Closure|non-empty-array
     */
    protected $_includeProcessor = null;

    /**
     * Sets template variables that's can be called through {var ...} statement
     */
    public function setVariables(array $variables)
    {
        foreach ($variables as $name => $value) {
            $this->_templateVars[$name] = $value;
        }

        return $this;
    }

    /**
     * Sets the proccessor of templates. Templates are directives that include email templates based on system
     * configuration path.
     *
     * @phpstan-param non-empty-array|Closure $callback it must return string
     * @return $this
     */
    public function setTemplateProcessor(array|Closure $callback)
    {
        $this->_templateProcessor = $callback;
        return $this;
    }

    /**
     * Sets the proccessor of templates.
     *
     * @phpstan-return null|non-empty-array|Closure
     */
    public function getTemplateProcessor()
    {
        return is_callable($this->_templateProcessor) ? $this->_templateProcessor : null;
    }

    /**
     * Sets the proccessor of includes.
     *
     * @phpstan-param non-empty-array|Closure $callback it must return string
     * @return $this
     */
    public function setIncludeProcessor(array|Closure $callback)
    {
        $this->_includeProcessor = $callback;
        return $this;
    }

    /**
     * Sets the proccessor of includes.
     *
     * @phpstan-return null|non-empty-array|Closure
     */
    public function getIncludeProcessor()
    {
        return is_callable($this->_includeProcessor) ? $this->_includeProcessor : null;
    }

    /**
     * Filter the string as template.
     *
     * @param  string $value
     * @return string
     */
    public function filter($value)
    {
        if ($value === null) {
            return '';
        }

        // "depend" and "if" operands should be first
        $directives = [
            self::CONSTRUCTION_DEPEND_PATTERN => 'dependDirective',
            self::CONSTRUCTION_IF_PATTERN     => 'ifDirective',
        ];
        foreach ($directives as $pattern => $directive) {
            if (preg_match_all($pattern, $value, $constructions, PREG_SET_ORDER)) {
                foreach ($constructions as $construction) {
                    $callback = [$this, $directive];
                    try {
                        $replacedValue = call_user_func($callback, $construction);
                    } catch (Exception $exception) {
                        throw $exception;
                    }

                    $value = str_replace($construction[0], $replacedValue, $value);
                }
            }
        }

        if (preg_match_all(self::CONSTRUCTION_PATTERN, $value, $constructions, PREG_SET_ORDER)) {
            foreach ($constructions as $construction) {
                $callback = [$this, $construction[1] . 'Directive'];
                if (!is_callable($callback)) {
                    continue;
                }

                try {
                    $replacedValue = call_user_func($callback, $construction);
                } catch (Exception $exception) {
                    throw $exception;
                }

                $value = str_replace($construction[0], $replacedValue ?? '', $value);
            }
        }

        return $value;
    }

    public function varDirective($construction)
    {
        if (count($this->_templateVars) === 0) {
            // If template preprocessing
            return $construction[0];
        }

        return $this->_getVariable($construction[2], '');
    }

    public function includeDirective($construction)
    {
        // Processing of {include template=... [...]} statement
        $includeParameters = $this->_getIncludeParameters($construction[2]);
        if (!isset($includeParameters['template']) || is_null($this->getIncludeProcessor())) {
            // Not specified template or not seted include processor
            return '{Error in include processing}';
        }

        // Including of template
        $templateCode = $includeParameters['template'];
        unset($includeParameters['template']);
        $includeParameters = array_merge_recursive($includeParameters, $this->_templateVars);

        return call_user_func($this->getIncludeProcessor(), $templateCode, $includeParameters);
    }

    /**
     * This directive allows email templates to be included inside other email templates using the following syntax:
     * {{template config_path="<PATH>"}}, where <PATH> equals the XPATH to the system configuration value that contains
     * the value of the email template. For example "sales_email/order/template", which is stored in the
     * Mage_Sales_Model_Order::sales_email/order/template. This directive is useful to include things like a global
     * header/footer.
     *
     * @param               $construction
     * @return mixed|string
     */
    public function templateDirective($construction)
    {
        // Processing of {template config_path=... [...]} statement
        $templateParameters = $this->_getIncludeParameters($construction[2]);
        if (!isset($templateParameters['config_path']) || is_null($this->getTemplateProcessor())) {
            return '{Error in template processing}';
        }

        // Including of template
        $configPath = $templateParameters['config_path'];
        unset($templateParameters['config_path']);
        $templateParameters = array_merge_recursive($templateParameters, $this->_templateVars);

        return call_user_func($this->getTemplateProcessor(), $configPath, $templateParameters);
    }

    public function dependDirective($construction)
    {
        if (count($this->_templateVars) === 0) {
            // If template preprocessing
            return $construction[0];
        }

        if ($this->_getVariable($construction[1], '') == '') {
            return '';
        }

        return $construction[2];
    }

    public function ifDirective($construction)
    {
        if (count($this->_templateVars) === 0) {
            return $construction[0];
        }

        if ($this->_getVariable($construction[1], '') == '') {
            if (isset($construction[3]) && isset($construction[4])) {
                return $construction[4];
            }

            return '';
        }

        return $construction[2];
    }

    /**
     * Return associative array of include construction.
     *
     * @param  string $value raw parameters
     * @return array
     */
    protected function _getIncludeParameters($value)
    {
        $tokenizer = new Varien_Filter_Template_Tokenizer_Parameter();
        $tokenizer->setString($value);

        $params = $tokenizer->tokenize();
        foreach ($params as $key => $value) {
            if (str_starts_with($value, '$')) {
                $params[$key] = $this->_getVariable(substr($value, 1), null);
            }
        }

        return $params;
    }

    /**
     * Return variable value for var construction
     *
     * @param  string      $value   raw parameters
     * @param  null|string $default default value
     * @return string
     */
    protected function _getVariable($value, $default = '{no_value_defined}')
    {
        Varien_Profiler::start('email_template_proccessing_variables');
        self::$_variableProcessingDepth++;
        try {
            $tokenizer = new Varien_Filter_Template_Tokenizer_Variable();
            $tokenizer->setString($value);

            $stackVars = $tokenizer->tokenize();
            $result = $default;
            $last = 0;
            $counter = count($stackVars);
            for ($i = 0; $i < $counter; $i++) {
                if ($i === 0 && isset($this->_templateVars[$stackVars[$i]['name']])) {
                    // Getting of template value
                    $stackVars[$i]['variable'] = & $this->_templateVars[$stackVars[$i]['name']];
                } elseif (isset($stackVars[$i - 1]['variable']) && $stackVars[$i - 1]['variable'] instanceof Varien_Object) {
                    // If object calling methods or getting properties
                    $object = $stackVars[$i - 1]['variable'];
                    if ($stackVars[$i]['type'] == 'property') {
                        $caller = 'get' . uc_words($stackVars[$i]['name'], '');
                        $stackVars[$i]['variable'] = method_exists($object, $caller)
                            ? $object->$caller()
                            : $object->getData($stackVars[$i]['name']);
                    } elseif ($stackVars[$i]['type'] == 'method') {
                        // Calling of object method
                        $method = $stackVars[$i]['name'];
                        $args = $stackVars[$i]['args'];
                        if (method_exists($object, $method) || str_starts_with($method, 'get')) {
                            if ($this->_isMethodCallAllowed($object, $method, $args)) {
                                // PHP method names are case-insensitive, store.GetConfig() reaches the same method
                                if (strcasecmp($method, 'getConfig') === 0
                                    && $this->getEmailPathValidator()->isValid($args)
                                ) {
                                    $args = [null];
                                }

                                $stackVars[$i]['variable'] = call_user_func_array([$object, $method], $args);
                            } else {
                                // A refused call renders as the default value, like a method that does not exist
                                $this->_logDisallowedMethodCall($object, $method);
                            }
                        }
                    }

                    $last = $i;
                }
            }

            if (isset($stackVars[$last]['variable'])) {
                // If value for construction exists set it
                $result = $stackVars[$last]['variable'];
            }
        } finally {
            self::$_variableProcessingDepth--;
            Varien_Profiler::stop('email_template_proccessing_variables');
        }

        return $result;
    }

    /**
     * Whether a template expression may call $method on $object with $args
     *
     * Template content is edited by admin roles that are not trusted with code execution or with
     * the store configuration, so templates may call only:
     * - methods declared for the object's class (or a parent class) under XML_PATH_VARIABLE_METHODS,
     *   with or without arguments;
     * - other methods without arguments, if their name starts with get, is or has.
     * Everything else (setters, dispatchers such as getDataUsingMethod(), methods that send, save or
     * decrypt) is refused, whatever arguments the template passes.
     *
     * @param array<int, mixed> $args
     */
    protected function _isMethodCallAllowed(object $object, string $method, array $args): bool
    {
        if (preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $method) !== 1) {
            return false;
        }

        $methodKey = strtolower($method);
        foreach ($this->getAllowedVariableMethods() as [$class, $allowedMethod]) {
            if ($allowedMethod === $methodKey && $object instanceof $class) {
                return true;
            }
        }

        return $args === [] && preg_match(self::VARIABLE_GETTER_PATTERN, $method) === 1;
    }

    /**
     * Methods that template expressions may call with arguments, from XML_PATH_VARIABLE_METHODS
     *
     * @return list<array{0: string, 1: string}> pairs of class name and lower-case method name
     */
    public function getAllowedVariableMethods(): array
    {
        if ($this->_allowedVariableMethods === null) {
            $this->_allowedVariableMethods = [];
            $config = class_exists('Mage', false) ? Mage::getConfig() : null;
            $node = $config instanceof Mage_Core_Model_Config ? $config->getNode(self::XML_PATH_VARIABLE_METHODS) : false;
            if ($node instanceof Varien_Simplexml_Element) {
                foreach ($node->children() as $entry) {
                    $class = trim((string) $entry->class);
                    $method = trim((string) $entry->method);
                    if ($class !== '' && $method !== '') {
                        $this->_allowedVariableMethods[] = [$class, strtolower($method)];
                    }
                }
            }
        }

        return $this->_allowedVariableMethods;
    }

    /**
     * Whether a template expression is being evaluated
     *
     * Code that returns secrets (encrypted configuration values, decryption) checks this and
     * returns nothing while it is true, whichever method the template reached it through.
     */
    public static function isProcessingVariable(): bool
    {
        return self::$_variableProcessingDepth > 0;
    }

    /**
     * Log a call that _isMethodCallAllowed() refused, so that a merchant can find the template to fix
     */
    protected function _logDisallowedMethodCall(object $object, string $method): void
    {
        if (class_exists('Mage', false)) {
            Mage::log(sprintf(
                'Security problem: template variable method %s::%s() is not allowed. Declare it under %s to allow it.',
                $object::class,
                preg_replace('/[^A-Za-z0-9_]/', '?', $method),
                self::XML_PATH_VARIABLE_METHODS,
            ));
        }
    }

    /**
     * Retrieve model object
     *
     * @return Mage_Adminhtml_Model_Email_PathValidator
     */
    protected function getEmailPathValidator()
    {
        return Mage::getModel('adminhtml/email_pathValidator');
    }
}
