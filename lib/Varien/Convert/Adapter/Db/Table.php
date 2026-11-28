<?php

declare(strict_types=1);

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Varien_Convert
 */

/**
 * Convert db table adapter
 *
 * @package    Varien_Convert
 */
class Varien_Convert_Adapter_Db_Table extends Varien_Convert_Adapter_Abstract
{
    /**
     * Database adapter named by the type variable
     *
     * @throws Varien_Convert_Exception
     * @see Varien_Convert_Adapter_Zend_Db::createDbAdapter()
     */
    #[Override]
    public function getResource()
    {
        if (!$this->_resource) {
            $this->_resource = Varien_Convert_Adapter_Zend_Db::createDbAdapter($this, $this->getVar('type'));
        }

        return $this->_resource;
    }

    public function load() {}

    public function save() {}
}
