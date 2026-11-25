<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Core\Model\File\Validator;

use Iterator;
use Mage;
use Mage_Adminhtml_Helper_Catalog;
use Mage_Core_Model_File_Validator_AvailablePath as Subject;
use Mage_Dataflow_Model_Convert_Adapter_Io;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Core\Model\File\Validator\AvailablePathTrait;

final class AvailablePathTest extends OpenMageTest
{
    use AvailablePathTrait;

    /**
     * Every spelling of a protected directory is refused (GHSA-7w9h-fhg7-6v5q)
     *
     * @dataProvider provideProtectedSitemapPaths
     * @group Model
     */
    public function testRefusesProtectedSitemapPathSpellings(string $path): void
    {
        $validator = $this->createSitemapValidator();

        self::assertFalse($validator->isValid($path));
        self::assertArrayHasKey(Subject::PROTECTED_PATH, $validator->getMessages());
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function provideProtectedSitemapPaths(): Iterator
    {
        yield 'leading dot segment' => ['/./app/etc/local.xml'];
        yield 'dot segment and doubled slash' => ['/.//app/etc/local.xml'];
        yield 'dot segment, lib' => ['/./lib/config.xml'];
        yield 'backslashes and dot segment' => ['.\\app\\etc\\local.xml'];
        yield 'upper case directory' => ['/APP/etc/local.xml'];
    }

    /**
     * @dataProvider provideAvailableSitemapPaths
     * @group Model
     */
    public function testAcceptsSitemapPaths(string $path): void
    {
        self::assertTrue($this->createSitemapValidator()->isValid($path));
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function provideAvailableSitemapPaths(): Iterator
    {
        yield 'root' => ['/sitemap.xml'];
        yield 'media' => ['/media/sitemap.xml'];
        yield 'media, dot segment and doubled slash' => ['/.//media//sitemap.xml'];
    }

    /**
     * Names that PHP hands to a stream wrapper or resolves as absolute are refused (GHSA-gc76-8qvx-h92g)
     *
     * @dataProvider provideSchemeAndDrivePaths
     * @group Model
     */
    public function testRefusesSchemesAndDriveLetters(string $path): void
    {
        $validator = $this->createImportExportValidator();

        self::assertFalse($validator->isValid($path));
        self::assertArrayHasKey(Subject::NOT_AVAILABLE_PATH, $validator->getMessages());
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function provideSchemeAndDrivePaths(): Iterator
    {
        yield 'http' => ['var/import/http://127.0.0.1/x.xml'];
        yield 'file' => ['var/export/file:///tmp/x.xml'];
        yield 'php filter' => ['var/import/php://filter/resource=x.xml'];
        yield 'data' => ['var/import/data:text/plain,x.csv'];
        yield 'drive letter' => ['var/export/C:/x.csv'];
        yield 'drive letter, backslash' => ['var\\export\\C:\\x.csv'];
    }

    /**
     * @dataProvider provideAvailableImportExportPaths
     * @group Model
     */
    public function testAcceptsImportExportPaths(string $path): void
    {
        self::assertTrue($this->createImportExportValidator()->isValid($path));
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function provideAvailableImportExportPaths(): Iterator
    {
        yield 'export' => ['var/export/export_all_products.csv'];
        yield 'import subdirectory' => ['var/import/sub/dir/products.xml'];
    }

    /**
     * @group Model
     */
    public function testRefusesTrailingParentSegment(): void
    {
        /** @var Subject $validator */
        $validator = Mage::getModel('core/file_validator_availablePath');
        $validator->setPaths(['available' => ['any' => '/*/*']]);

        self::assertFalse($validator->isValid('/media/..'));
        self::assertArrayHasKey(Subject::PROTECTED_LFI, $validator->getMessages());
    }

    private function createSitemapValidator(): Subject
    {
        /** @var Mage_Adminhtml_Helper_Catalog $helper */
        $helper = Mage::helper('adminhtml/catalog');
        /** @var Subject $validator */
        $validator = Mage::getModel('core/file_validator_availablePath');
        $validator->setPaths($helper->getSitemapValidPaths());

        return $validator;
    }

    private function createImportExportValidator(): Subject
    {
        /** @var Subject $validator */
        $validator = Mage::getModel('core/file_validator_availablePath');
        $validator->setPaths(Mage::getStoreConfig(Mage_Dataflow_Model_Convert_Adapter_Io::XML_PATH_EXPORT_LOCAL_VALID_PATH));

        return $validator;
    }
}
