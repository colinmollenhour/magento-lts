<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Dataflow\Model\Convert\Adapter;

use Iterator;
use Mage;
use Mage_Core_Exception;
use Mage_Dataflow_Model_Convert_Adapter_Io as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Dataflow\Model\Convert\Adapter\IoTrait;
use Varien_Io_File;

final class IoTest extends OpenMageTest
{
    use IoTrait;

    /**
     * The file name is read and written as it is, so it must stay below the path (GHSA-gc76-8qvx-h92g)
     *
     * @dataProvider provideFileNamesOutsidePath
     * @group Model
     */
    public function testGetResourceRefusesFileNameOutsidePath(string $filename): void
    {
        $subject = $this->createSubject('var/export', $filename);

        $this->expectException(Mage_Core_Exception::class);
        $this->expectExceptionMessage('must be a relative name below the configured path');

        $subject->getResource();
    }

    /**
     * @return Iterator<string, array{string}>
     */
    public static function provideFileNamesOutsidePath(): Iterator
    {
        yield 'absolute' => ['/etc/x.xml'];
        yield 'http' => ['http://127.0.0.1/x.xml'];
        yield 'file url' => ['file:///tmp/x.xml'];
        yield 'php filter' => ['php://filter/resource=x.xml'];
        yield 'drive letter' => ['C:/x.csv'];
        yield 'UNC' => ['\\\\server\\share\\x.csv'];
        yield 'dot segment' => ['./x.csv'];
        yield 'empty segment' => ['sub//x.csv'];
    }

    /**
     * An absolute path is no longer validated as if it were relative to the OpenMage root
     *
     * @group Model
     */
    public function testGetResourceRefusesAbsolutePathOutsideBaseDir(): void
    {
        $subject = $this->createSubject('/var/export', 'x.csv');

        $this->expectException(Mage_Core_Exception::class);
        $this->expectExceptionMessage('is not available and cannot be used');

        $subject->getResource();
    }

    /**
     * @group Model
     */
    public function testGetResourceOpensPathForRelativeFileName(): void
    {
        $resource = $this->createSubject('var/export', 'sub/dir/export.csv')->getResource();

        self::assertInstanceOf(Varien_Io_File::class, $resource);
        self::assertSame(realpath(Mage::getBaseDir('var') . DIRECTORY_SEPARATOR . 'export'), $resource->pwd());
    }

    private function createSubject(string $path, string $filename): Subject
    {
        /** @var Subject $subject */
        $subject = Mage::getModel('dataflow/convert_adapter_io');
        $subject->setVar('type', 'file');
        $subject->setVar('path', $path);
        $subject->setVar('filename', $filename);

        return $subject;
    }
}
