<?php

declare(strict_types=1);

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Dataflow
 */

/**
 * Convert db table adapter
 *
 * @package    Mage_Dataflow
 */
class Mage_Dataflow_Model_Convert_Adapter_Db_Table extends Mage_Dataflow_Model_Convert_Adapter_Abstract
{
    /**
     * Database adapter named by the type variable
     *
     * @throws Mage_Dataflow_Model_Convert_Exception
     * @see Mage_Dataflow_Model_Convert_Adapter_Zend_Db::createDbAdapter()
     */
    #[Override]
    public function getResource()
    {
        if (!$this->_resource) {
            $this->_resource = Mage_Dataflow_Model_Convert_Adapter_Zend_Db::createDbAdapter($this, $this->getVar('type'));
        }

        return $this->_resource;
    }

    public function load() {}

    public function save() {}
}
