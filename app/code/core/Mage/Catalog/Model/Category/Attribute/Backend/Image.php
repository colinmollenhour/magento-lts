<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Catalog
 */

/**
 * Catalog category image attribute backend model
 *
 * @package    Mage_Catalog
 */
class Mage_Catalog_Model_Category_Attribute_Backend_Image extends Mage_Eav_Model_Entity_Attribute_Backend_Abstract
{
    public function getAllowedExtensions(): array
    {
        return Varien_Io_File::ALLOWED_IMAGES_EXTENSIONS;
    }

    /**
     * Refuse a new string value that is not a bare file name
     *
     * Uploads and the delete flag are handled in afterSave(). A string value is stored as it is
     * and later joined onto the category media URL, so it must name a file in that directory.
     * A value that has not changed since the category was loaded is not checked again.
     *
     * @param  object              $object
     * @return $this
     * @throws Mage_Core_Exception
     */
    #[Override]
    public function beforeSave($object)
    {
        if (!$object instanceof Varien_Object) {
            return $this;
        }

        parent::beforeSave($object);

        $name  = $this->getAttribute()->getName();
        $value = $object->getData($name);
        if ($value === null || $value === '' || is_array($value)) {
            return $this;
        }

        if ($object instanceof Mage_Core_Model_Abstract && !$object->dataHasChangedFor($name)) {
            return $this;
        }

        if (!$this->isValidFileName($value)) {
            Mage::throwException(Mage::helper('catalog')->__('The image file name is not valid.'));
        }

        return $this;
    }

    /**
     * Check that a value is a bare file name: no directory part, quotes, angle brackets or control characters
     *
     * Spaces and mixed case are allowed, because imports set such names.
     *
     * @param mixed $value
     */
    public function isValidFileName($value): bool
    {
        if (!is_string($value) && !is_int($value)) {
            return false;
        }

        $value = (string) $value;

        return !in_array($value, ['', '.', '..'], true)
            && preg_match('/[\/\\\\"\'<>\x00-\x1F\x7F]/', $value) !== 1;
    }

    /**
     * Save uploaded file and set its name to category attribute
     * @param  Varien_Object $object
     * @return $this
     * @SuppressWarnings("PHPMD.Superglobals")
     */
    #[Override]
    public function afterSave($object)
    {
        $name  = $this->getAttribute()->getName();
        $value = $object->getData($name);

        if (is_array($value) && !empty($value['delete'])) {
            $object->setData($name, '');
            $this->getAttribute()->getEntity()->saveAttribute($object, $name);
            return $this;
        }

        if (!empty($_FILES[$name])) {
            try {
                $validator = Mage::getModel('core/file_validator_image');
                $uploader  = Mage::getModel('core/file_uploader', $name);
                $uploader->setAllowedExtensions($this->getAllowedExtensions());
                $uploader->setAllowRenameFiles(true);
                $uploader->setFilesDispersion(false);
                $uploader->addValidateCallback(Mage_Core_Model_File_Validator_Image::NAME, $validator, 'validate');
                $uploader->save(Mage::getBaseDir('media') . DS . 'catalog' . DS . 'category');

                $fileName = $uploader->getUploadedFileName();
                if ($fileName) {
                    $object->setData($name, $fileName);
                    $this->getAttribute()->getEntity()->saveAttribute($object, $name);
                }
            } catch (Exception $exception) {
                if ($exception->getCode() != UPLOAD_ERR_NO_FILE) {
                    Mage::logException($exception);
                }
            }
        }

        return $this;
    }
}
