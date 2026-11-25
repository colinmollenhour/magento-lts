<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Adminhtml
 */

/**
 * Backend for serialized array data
 *
 * @package    Mage_Adminhtml
 */
class Mage_Adminhtml_Model_System_Config_Backend_Serialized_Array extends Mage_Adminhtml_Model_System_Config_Backend_Serialized
{
    /**
     * Check object existence in incoming data and unset array element with '__empty' key
     *
     * Rows whose key is not a plain identifier are stored under a safe key instead, because
     * the array field renderer uses row keys as HTML ids and inside JavaScript.
     *
     * @inheritDoc
     * @throws Mage_Core_Exception
     */
    #[Override]
    protected function _beforeSave()
    {
        try {
            Mage::helper('core/unserializeArray')->unserialize(serialize($this->getValue()));
        } catch (Exception) {
            Mage::throwException(Mage::helper('adminhtml')->__('Serialized data is incorrect'));
        }

        $value = $this->getValue();
        if (is_array($value)) {
            unset($value['__empty']);

            $rows = [];
            foreach ($value as $rowKey => $row) {
                $rows[self::getSafeRowKey($rowKey)] = $row;
            }

            $value = $rows;
        }

        $this->setValue($value);
        return parent::_beforeSave();
    }

    /**
     * Return the row key unchanged if it is a plain identifier, otherwise a key derived from it
     *
     * A plain identifier consists of letters, digits, "_" and "-", which covers every row key
     * the array field form creates. Any other key is replaced with "_" and its MD5 hash, so the
     * same stored key always maps to the same safe key.
     *
     * @param  int|string $rowKey
     * @return string
     */
    public static function getSafeRowKey($rowKey)
    {
        $rowKey = (string) $rowKey;
        if (preg_match('/^[A-Za-z0-9_-]+$/D', $rowKey) === 1) {
            return $rowKey;
        }

        return '_' . md5($rowKey);
    }
}
