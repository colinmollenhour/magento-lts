<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Dataflow
 */

/**
 * Convert IO adapter
 *
 * @package    Mage_Dataflow
 */
class Mage_Dataflow_Model_Convert_Adapter_Io extends Mage_Dataflow_Model_Convert_Adapter_Abstract
{
    public const XML_PATH_EXPORT_LOCAL_VALID_PATH = 'general/file/importexport_local_valid_paths';

    /**
     * @return false|Varien_Io_Abstract
     */
    #[Override]
    public function getResource($forWrite = false)
    {
        if (!$this->_resource) {
            $type = $this->getVar('type', 'file');
            $className = 'Varien_Io_' . ucwords($type);
            $this->_resource = new $className();

            $ioConfig = $this->getVars();
            switch (strtolower($this->getVar('type', 'file'))) {
                case 'file':
                    $filename = (string) ($ioConfig['filename'] ?? '');
                    self::validateLocalFileName($filename);

                    if (preg_match('#^' . preg_quote(DS, '#') . '#', $this->getVar('path'))
                        || preg_match('#^[a-z]:' . preg_quote(DS, '#') . '#i', $this->getVar('path'))
                    ) {
                        $path = $this->_resource->getCleanPath($this->getVar('path'));
                    } else {
                        $baseDir = Mage::getBaseDir();
                        $path = $this->_resource->getCleanPath($baseDir . DS . trim($this->getVar('path'), DS));
                    }

                    //validate the resolved export/import path, which is what is read and written
                    $this->_validateLocalPath($path, $filename);

                    $this->_resource->checkAndCreateFolder($path);

                    $realPath = realpath($path);
                    if ($realPath === false) {
                        $message = Mage::helper('dataflow')->__('The destination folder "%s" does not exist or there is no access to create it.', $ioConfig['path']);
                        Mage::throwException($message);
                    } elseif (!is_dir($realPath)) {
                        $message = Mage::helper('dataflow')->__('Destination folder "%s" is not a directory.', $realPath);
                        Mage::throwException($message);
                    } elseif ($forWrite && !is_writable($realPath)) {
                        $message = Mage::helper('dataflow')->__('Destination folder "%s" is not writable.', $realPath);
                        Mage::throwException($message);
                    } else {
                        $ioConfig['path'] = rtrim($realPath, DS);
                    }

                    break;
                default:
                    $ioConfig['path'] = rtrim($this->getVar('path'), '/');
                    break;
            }

            try {
                $this->_resource->open($ioConfig);
            } catch (Exception $exception) {
                $message = Mage::helper('dataflow')->__('An error occurred while opening file: "%s".', $exception->getMessage());
                Mage::throwException($message);
            }
        }

        return $this->_resource;
    }

    /**
     * Refuse a local file name that could leave the configured path
     *
     * The file name is passed to Varien_Io_File::read() and write() as it is, after the
     * path has been validated, so it must be a plain relative name below that path: no
     * absolute name, URL scheme or stream wrapper, drive letter, NUL byte, or ".", ".."
     * or empty segment (GHSA-gc76-8qvx-h92g). Subdirectories are allowed.
     *
     * @throws Mage_Core_Exception
     */
    public static function validateLocalFileName(string $filename): void
    {
        $isValid = $filename !== '' && !str_contains($filename, chr(0));
        foreach ((array) preg_split('#[\\\\/]#', $filename) as $segment) {
            // an empty first segment is an absolute name; a scheme or drive letter is a stream wrapper or an absolute name
            $segment = (string) $segment;
            if (in_array($segment, ['', '.', '..'], true) || preg_match('#^[a-z][a-z0-9+.\-]*:#i', $segment) === 1) {
                $isValid = false;
            }
        }

        if (!$isValid) {
            Mage::throwException(Mage::helper('dataflow')->__(
                'File name "%s" must be a relative name below the configured path.',
                Mage::helper('core')->escapeHtml(str_replace(chr(0), '', $filename)),
            ));
        }
    }

    /**
     * Check the resolved folder and file name against the import/export path masks
     *
     * The masks are relative to the OpenMage root, so the folder must be inside it. It is
     * compared as resolved by getCleanPath(), without following symlinks, so a var/import
     * or var/export that is a symlink to shared storage keeps working.
     *
     * @param  string              $path     Folder as resolved from the path variable
     * @param  string              $filename File name, already checked by validateLocalFileName()
     * @throws Mage_Core_Exception
     */
    protected function _validateLocalPath(string $path, string $filename): void
    {
        // getCleanPath() resolves ".." without following symlinks, so it is refused rather than resolved
        $rawPath = (string) $this->getVar('path');
        $folder = rtrim(str_replace('\\', '/', $path), '/') . '/';
        $baseDir = rtrim(str_replace('\\', '/', Mage::getBaseDir()), '/') . '/';
        if (str_contains($rawPath, chr(0))
            || preg_match('#(^|[\\\\/])\.\.($|[\\\\/])#', $rawPath) === 1
            || !str_starts_with($folder, $baseDir)
        ) {
            Mage::throwException(Mage::helper('dataflow')->__(
                'Path "%s" is not available and cannot be used.',
                Mage::helper('core')->escapeHtml(str_replace(chr(0), '', $rawPath)),
            ));
        }

        /** @var Mage_Core_Model_File_Validator_AvailablePath $validator */
        $validator = Mage::getModel('core/file_validator_availablePath');
        $validator->setPaths(Mage::getStoreConfig(self::XML_PATH_EXPORT_LOCAL_VALID_PATH));
        if (!$validator->isValid(substr($folder, strlen($baseDir)) . $filename)) {
            foreach ($validator->getMessages() as $message) {
                Mage::throwException($message);
            }
        }
    }

    /**
     * Load data
     *
     * @return $this
     */
    public function load()
    {
        if (!$this->getResource()) {
            return $this;
        }

        $batchModel = Mage::getSingleton('dataflow/batch');
        $destFile = $batchModel->getIoAdapter()->getFile(true);

        $result = $this->getResource()->read($this->getVar('filename'), $destFile);
        $filename = $this->getResource()->pwd() . '/' . $this->getVar('filename');
        if ($result === false) {
            $message = Mage::helper('dataflow')->__('Could not load file: "%s".', $filename);
            Mage::throwException($message);
        } else {
            $message = Mage::helper('dataflow')->__('Loaded successfully: "%s".', $filename);
            $this->addException($message);
        }

        $this->setData($result);
        return $this;
    }

    /**
     * Save result to destination file from temporary
     *
     * @return $this
     */
    public function save()
    {
        if (!$this->getResource(true)) {
            return $this;
        }

        $batchModel = Mage::getSingleton('dataflow/batch');

        $dataFile = $batchModel->getIoAdapter()->getFile(true);

        $filename = $this->getVar('filename');

        $result   = $this->getResource()->write($filename, $dataFile, 0777);

        if ($result === false) {
            $message = Mage::helper('dataflow')->__('Could not save file: %s.', $filename);
            Mage::throwException($message);
        } else {
            $message = Mage::helper('dataflow')->__('Saved successfully: "%s" [%d byte(s)].', $filename, $batchModel->getIoAdapter()->getFileSize());
            if ($this->getVar('link')) {
                $message .= Mage::helper('dataflow')->__(
                    '<a href="%s" target="_blank" rel="noopener noreferrer">Link</a>',
                    $this->getVar('link'),
                );
            }

            $this->addException($message);
        }

        return $this;
    }
}
