<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    Mage_Sitemap
 */

/**
 * Sitemap model
 *
 * @package    Mage_Sitemap
 *
 * @method Mage_Sitemap_Model_Resource_Sitemap            _getResource()
 * @method Mage_Sitemap_Model_Resource_Sitemap_Collection getCollection()
 * @method Mage_Sitemap_Model_Resource_Sitemap            getResource()
 * @method Mage_Sitemap_Model_Resource_Sitemap_Collection getResourceCollection()
 * @method string                                         getSitemapFilename()
 * @method int                                            getSitemapId()
 * @method string                                         getSitemapPath()
 * @method string                                         getSitemapTime()
 * @method string                                         getSitemapType()
 * @method int                                            getStoreId()
 * @method $this                                          setSitemapFilename(string $value)
 * @method $this                                          setSitemapPath(string $value)
 * @method $this                                          setSitemapTime(string $value)
 * @method $this                                          setSitemapType(string $value)
 * @method $this                                          setStoreId(int $value)
 */
class Mage_Sitemap_Model_Sitemap extends Mage_Core_Model_Abstract
{
    /**
     * Real file path
     *
     * @var null|string
     */
    protected $_filePath;

    /**
     * @inheritDoc
     */
    protected function _construct()
    {
        $this->_init('sitemap/sitemap');
    }

    /**
     * @inheritDoc
     * @throws Mage_Core_Exception
     */
    #[Override]
    protected function _beforeSave()
    {
        $ioFile = new Varien_Io_File();
        $realPath = $ioFile->getCleanPath(Mage::getBaseDir() . '/' . $this->getSitemapPath());

        /**
         * Check path is allow
         */
        if (!$ioFile->allowedPath($realPath, Mage::getBaseDir())) {
            Mage::throwException(Mage::helper('sitemap')->__('Please define correct path'));
        }

        /**
         * Check the normalised path, which is the one stored and written to, against the path lists
         */
        $sitemapPath = rtrim(str_replace(str_replace('\\', '/', Mage::getBaseDir()), '', $realPath), '/') . '/';
        $sitemapFilename = (string) $this->getSitemapFilename();
        $this->_validatePath($sitemapPath, str_ends_with($sitemapFilename, '.xml') ? $sitemapFilename : $sitemapFilename . '.xml');

        /**
         * Check exists and writeable path
         */
        if (!$ioFile->fileExists($realPath, false)) {
            Mage::throwException(Mage::helper('sitemap')->__('Please create the specified folder "%s" before saving the sitemap.', Mage::helper('core')->escapeHtml($this->getSitemapPath())));
        }

        if (!$ioFile->isWriteable($realPath)) {
            Mage::throwException(Mage::helper('sitemap')->__('Please make sure that "%s" is writable by web-server.', $this->getSitemapPath()));
        }

        /**
         * Check allow filename
         */
        if (!preg_match('#^[a-zA-Z0-9_\.]+$#', $this->getSitemapFilename())) {
            Mage::throwException(Mage::helper('sitemap')->__('Please use only letters (a-z or A-Z), numbers (0-9) or underscore (_) in the filename. No spaces or other characters are allowed.'));
        }

        if (!preg_match('#\.xml$#', $this->getSitemapFilename())) {
            $this->setSitemapFilename($this->getSitemapFilename() . '.xml');
        }

        $this->setSitemapPath($sitemapPath);

        return parent::_beforeSave();
    }

    /**
     * Check that the sitemap file is in a permitted directory
     *
     * The admin form validates the path as posted, but the path is normalised before it is
     * stored, and rows can be written by other code, so the stored path is checked again
     * wherever the file is written or deleted (GHSA-7w9h-fhg7-6v5q).
     *
     * @throws Mage_Core_Exception
     */
    public function validatePath(): void
    {
        $this->_validatePath((string) $this->getSitemapPath(), (string) $this->getSitemapFilename());
    }

    /**
     * Validate a sitemap path and file name against the sitemap path lists
     *
     * @throws Mage_Core_Exception
     */
    protected function _validatePath(string $path, string $filename): void
    {
        /** @var Mage_Core_Model_File_Validator_AvailablePath $validator */
        $validator = Mage::getModel('core/file_validator_availablePath');
        /** @var Mage_Adminhtml_Helper_Catalog $helper */
        $helper = Mage::helper('adminhtml/catalog');
        $validator->setPaths($helper->getSitemapValidPaths());
        if (!$validator->isValid(rtrim($path, '\\/') . '/' . $filename)) {
            Mage::throwException(implode("\n", $validator->getMessages()));
        }
    }

    /**
     * Whether the sitemap file is in a permitted directory
     */
    public function isPathAllowed(): bool
    {
        try {
            $this->validatePath();
        } catch (Mage_Core_Exception) {
            return false;
        }

        return true;
    }

    /**
     * Return real file path
     *
     * Computed on every call so that it always follows the current sitemap_path, which is
     * the value validatePath() checks.
     *
     * @return string
     */
    protected function getPath()
    {
        $this->_filePath = str_replace('//', '/', Mage::getBaseDir()
            . $this->getSitemapPath());

        return $this->_filePath;
    }

    /**
     * Return full file name with path
     *
     * @return string
     */
    public function getPreparedFilename()
    {
        return $this->getPath() . $this->getSitemapFilename();
    }

    /**
     * Generate XML file
     *
     * @return $this
     * @throws Throwable
     */
    public function generateXml()
    {
        $this->validatePath();

        $ioFile = new Varien_Io_File();
        $ioFile->setAllowCreateFolders(true);
        $ioFile->open(['path' => $this->getPath()]);

        if ($ioFile->fileExists($this->getSitemapFilename()) && !$ioFile->isWriteable($this->getSitemapFilename())) {
            Mage::throwException(Mage::helper('sitemap')->__('File "%s" cannot be saved. Please, make sure the directory "%s" is writeable by web server.', $this->getSitemapFilename(), $this->getPath()));
        }

        $ioFile->streamOpen($this->getSitemapFilename());

        $ioFile->streamWrite('<?xml version="1.0" encoding="UTF-8"?>' . "\n");
        $ioFile->streamWrite('<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">');

        $storeId = $this->getStoreId();
        $date    = Mage::getSingleton('core/date')->gmtDate('Y-m-d');
        $baseUrl = Mage::app()->getStore($storeId)->getBaseUrl(Mage_Core_Model_Store::URL_TYPE_LINK);

        /**
         * Generate categories sitemap
         */
        $changefreq = (string) Mage::getStoreConfig('sitemap/category/changefreq', $storeId);
        $priority   = (string) Mage::getStoreConfig('sitemap/category/priority', $storeId);
        $lastmod    = Mage::getStoreConfigFlag('sitemap/category/lastmod', $storeId) ? $date : '';
        $collection = Mage::getResourceModel('sitemap/catalog_category')->getCollection($storeId);
        $categories = new Varien_Object();
        $categories->setItems($collection);
        Mage::dispatchEvent('sitemap_categories_generating_before', [
            'collection' => $categories,
            'store_id' => $storeId,
        ]);
        foreach ($categories->getItems() as $item) {
            $xml = $this->getSitemapRow($baseUrl . $item->getUrl(), $lastmod, $changefreq, $priority);
            $ioFile->streamWrite($xml);
        }

        unset($collection);

        /**
         * Generate products sitemap
         */
        $changefreq = (string) Mage::getStoreConfig('sitemap/product/changefreq', $storeId);
        $priority   = (string) Mage::getStoreConfig('sitemap/product/priority', $storeId);
        $lastmod    = Mage::getStoreConfigFlag('sitemap/product/lastmod', $storeId) ? $date : '';
        $collection = Mage::getResourceModel('sitemap/catalog_product')->getCollection($storeId);
        $products = new Varien_Object();
        $products->setItems($collection);
        Mage::dispatchEvent('sitemap_products_generating_before', [
            'collection' => $products,
            'store_id' => $storeId,
        ]);
        foreach ($products->getItems() as $item) {
            $xml = $this->getSitemapRow($baseUrl . $item->getUrl(), $lastmod, $changefreq, $priority);
            $ioFile->streamWrite($xml);
        }

        unset($collection);

        /**
         * Generate cms pages sitemap
         */
        $homepage = $this->getHomePageIdentifier($storeId);
        $changefreq = (string) Mage::getStoreConfig('sitemap/page/changefreq', $storeId);
        $priority   = (string) Mage::getStoreConfig('sitemap/page/priority', $storeId);
        $lastmod    = Mage::getStoreConfigFlag('sitemap/page/lastmod', $storeId) ? $date : '';
        $collection = Mage::getResourceModel('sitemap/cms_page')->getCollection($storeId);
        $pages = new Varien_Object();
        $pages->setItems($collection);
        Mage::dispatchEvent('sitemap_cms_pages_generating_before', [
            'collection' => $pages,
            'store_id' => $storeId,
        ]);
        foreach ($pages->getItems() as $item) {
            $url = $item->getUrl();
            if ($url == $homepage) {
                $url = '';
            }

            $xml = $this->getSitemapRow($baseUrl . $url, $lastmod, $changefreq, $priority);
            $ioFile->streamWrite($xml);
        }

        unset($collection);

        Mage::dispatchEvent('sitemap_urlset_generating_before', [
            'file'      => $ioFile ,
            'base_url'  => $baseUrl ,
            'date'      => $date,
            'store_id'  => $storeId,
        ]);

        $ioFile->streamWrite('</urlset>');
        $ioFile->streamClose();

        $this->setSitemapTime(
            Mage::getSingleton('core/date')->gmtDate(Varien_Db_Adapter_Pdo_Mysql::TIMESTAMP_FORMAT),
        );
        $this->save();

        return $this;
    }

    /**
     * Get sitemap row
     *
     * @param null|string $lastmod
     * @param null|string $changefreq
     * @param null|string $priority
     */
    protected function getSitemapRow(string $url, $lastmod = null, $changefreq = null, $priority = null): string
    {
        $row = '<loc>' . htmlspecialchars($url) . '</loc>';
        if ($lastmod) {
            $row .= '<lastmod>' . $lastmod . '</lastmod>';
        }

        if ($changefreq) {
            $row .= '<changefreq>' . $changefreq . '</changefreq>';
        }

        if ($priority) {
            $row .= sprintf('<priority>%.1f</priority>', $priority);
        }

        return '<url>' . $row . '</url>';
    }

    /**
     * Get the identifier of the store's CMS home page
     *
     * The configured value has the page id appended ("home|2") when several CMS pages share the identifier.
     *
     * @param int|string $storeId
     */
    protected function getHomePageIdentifier($storeId): string
    {
        return explode('|', (string) Mage::getStoreConfig(Mage_Cms_Helper_Page::XML_PATH_HOME_PAGE, $storeId))[0];
    }
}
