<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Sitemap\Model;

use Iterator;
use Mage;
use Mage_Cms_Helper_Page;
use Mage_Core_Exception;
use Mage_Sitemap_Model_Sitemap as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Sitemap\SitemapTrait;
use ReflectionMethod;
use Throwable;

final class SitemapTest extends OpenMageTest
{
    use SitemapTrait;

    /**
     * @dataProvider provideGetPreparedFilenameData
     * @param array<string, string> $data
     * @group Model
     */
    public function testGetPreparedFilename(array $data): void
    {
        $mock = $this->getMockWithCalledMethods(Subject::class, []);
        $mock->setData($data);

        self::assertInstanceOf(Subject::class, $mock);
        self::assertIsString($mock->getPreparedFilename());
    }

    /**
     * The CMS home page is written as the base URL; the configured value may carry a page id ("home|2")
     *
     * @dataProvider provideGetHomePageIdentifierData
     * @group Model
     */
    public function testGetHomePageIdentifier(string $expected, string $configValue): void
    {
        $store = Mage::app()->getStore();
        $original = Mage::getStoreConfig(Mage_Cms_Helper_Page::XML_PATH_HOME_PAGE);
        $store->setConfig(Mage_Cms_Helper_Page::XML_PATH_HOME_PAGE, $configValue);

        try {
            $method = new ReflectionMethod(Subject::class, 'getHomePageIdentifier');
            $identifier = $method->invoke($this->getMockWithCalledMethods(Subject::class, []), $store->getId());
        } finally {
            $store->setConfig(Mage_Cms_Helper_Page::XML_PATH_HOME_PAGE, $original);
        }

        self::assertSame($expected, $identifier);
    }

    /**
     * @dataProvider provideGenerateXmlData
     * @param array<string, string> $data
     * @group Model
     * @throws Throwable
     * @todo  test validation
     * @todo  test content of xml
     */
    public function testGenerateXml(array $data): void
    {
        $mock = $this->getMockWithCalledMethods(Subject::class, ['save' => null]);
        $mock->setData($data);
        self::assertInstanceOf(Subject::class, $mock);

        $result = $mock->generateXml();
        self::assertInstanceOf(Subject::class, $result);

        /** @var string $file */
        $file = $data['sitemap_filename'];
        self::assertFileExists($file);
        unlink($file);
    }

    /**
     * A stored path into a protected directory is refused wherever the file is used (GHSA-7w9h-fhg7-6v5q)
     *
     * @dataProvider provideProtectedStoredPaths
     * @group Model
     */
    public function testIsPathAllowedRefusesProtectedStoredPath(string $path, string $filename): void
    {
        $subject = $this->getMockWithCalledMethods(Subject::class, []);
        $subject->setData(['sitemap_path' => $path, 'sitemap_filename' => $filename]);

        self::assertFalse($subject->isPathAllowed());
    }

    /**
     * @return Iterator<string, array{string, string}>
     */
    public static function provideProtectedStoredPaths(): Iterator
    {
        yield 'app/etc' => ['/app/etc/', 'local.xml'];
        yield 'app/etc, dot segment' => ['/./app/etc/', 'local.xml'];
        yield 'lib' => ['/lib/', 'config.xml'];
    }

    /**
     * @dataProvider provideAllowedStoredPaths
     * @group Model
     */
    public function testIsPathAllowedAcceptsPublicPath(string $path): void
    {
        $subject = $this->getMockWithCalledMethods(Subject::class, []);
        $subject->setData(['sitemap_path' => $path, 'sitemap_filename' => 'sitemap.xml']);

        self::assertTrue($subject->isPathAllowed());
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function provideAllowedStoredPaths(): Iterator
    {
        yield 'root' => ['/'];
        yield 'media' => ['/media/'];
    }

    /**
     * @group Model
     * @throws Throwable
     */
    public function testGenerateXmlRefusesProtectedStoredPath(): void
    {
        $filename = 'phpunit-protected-sitemap.xml';
        $file = Mage::getBaseDir('etc') . DIRECTORY_SEPARATOR . $filename;

        $subject = $this->getMockWithCalledMethods(Subject::class, ['save' => null]);
        $subject->setData(['sitemap_path' => '/app/etc/', 'sitemap_filename' => $filename]);

        try {
            $subject->generateXml();
            self::fail('A sitemap in app/etc was generated');
        } catch (Mage_Core_Exception $mageCoreException) {
            self::assertStringContainsString('is protected', $mageCoreException->getMessage());
            self::assertFileDoesNotExist($file);
        } finally {
            if (is_file($file)) {
                unlink($file);
            }
        }
    }

    /**
     * The path is checked once it has been normalised, as it is stored
     *
     * @group Model
     */
    public function testBeforeSaveRefusesDotSegmentPath(): void
    {
        $subject = $this->getMockWithCalledMethods(Subject::class, []);
        $subject->setData(['sitemap_path' => '/./app/etc/', 'sitemap_filename' => 'local']);

        $this->expectException(Mage_Core_Exception::class);
        $this->expectExceptionMessage('is protected');

        $method = new ReflectionMethod(Subject::class, '_beforeSave');
        $method->invoke($subject);
    }
}
