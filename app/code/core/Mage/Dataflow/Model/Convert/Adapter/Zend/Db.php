<?php

declare(strict_types=1);

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Dataflow
 */

/**
 * Convert zend db adapter
 *
 * @package    Mage_Dataflow
 */
class Mage_Dataflow_Model_Convert_Adapter_Zend_Db extends Mage_Dataflow_Model_Convert_Adapter_Abstract
{
    /**
     * Database adapters a profile may name, with the connection settings each one accepts
     *
     * Zend_Db::factory() takes the namespace of the class it constructs from the adapterNamespace
     * key of its config and runs the constructor before checking the type, and the adapters read
     * profiler classes and driver options from the same config. The variables come from profile
     * actions XML, so they never reach the factory unfiltered (GHSA-22f6-grcr-2h6g).
     */
    public const ADAPTER_CONNECTION_KEYS = [
        'Pdo_Mysql' => ['host', 'port', 'username', 'password', 'dbname', 'charset', 'unix_socket'],
        'Mysqli'    => ['host', 'port', 'username', 'password', 'dbname', 'charset', 'socket'],
    ];

    #[Override]
    public function getResource()
    {
        if (!$this->_resource) {
            $this->_resource = self::createDbAdapter($this, $this->getVar('adapter', 'Pdo_Mysql'));
        }

        return $this->_resource;
    }

    /**
     * Build a database adapter from the connection settings in a container's variables
     *
     * Only the adapters in ADAPTER_CONNECTION_KEYS are accepted, and only their connection
     * settings are passed on. Every other variable, adapterNamespace included, is dropped.
     *
     * @param  mixed                                 $adapter adapter name from the profile variables
     * @throws Mage_Dataflow_Model_Convert_Exception
     * @throws Zend_Db_Exception
     */
    public static function createDbAdapter(
        Mage_Dataflow_Model_Convert_Container_Abstract $container,
        $adapter,
    ): Zend_Db_Adapter_Abstract {
        $adapterName = is_string($adapter) ? self::normalizeAdapterName($adapter) : '';
        if (!isset(self::ADAPTER_CONNECTION_KEYS[$adapterName])) {
            throw $container->addException(
                Mage::helper('dataflow')->__(
                    'Unsupported database adapter. Supported adapters: %s.',
                    implode(', ', array_keys(self::ADAPTER_CONNECTION_KEYS)),
                ),
                Mage_Dataflow_Model_Convert_Exception::FATAL,
            );
        }

        $vars = (array) $container->getVars();
        $config = [];
        foreach (self::ADAPTER_CONNECTION_KEYS[$adapterName] as $key) {
            if (!isset($vars[$key])) {
                continue;
            }

            $value = $vars[$key];
            if (!is_string($value) || ($key === 'charset' && preg_match('/^\w*$/', $value) !== 1)) {
                throw $container->addException(
                    Mage::helper('dataflow')->__('Invalid value for the database setting "%s".', $key),
                    Mage_Dataflow_Model_Convert_Exception::FATAL,
                );
            }

            $config[$key] = $value;
        }

        return Zend_Db::factory($adapterName, $config);
    }

    /**
     * Normalize an adapter name the way Zend_Db::factory() does, e.g. "pdo_mysql" to "Pdo_Mysql"
     */
    public static function normalizeAdapterName(string $adapter): string
    {
        return str_replace(' ', '_', ucwords(str_replace('_', ' ', strtolower($adapter))));
    }

    public function load()
    {
        return $this;
    }

    public function save()
    {
        return $this;
    }
}
