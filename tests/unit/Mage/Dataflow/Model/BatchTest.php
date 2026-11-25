<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Dataflow\Model;

use Generator;
use Mage;
use Mage_Catalog_Model_Convert_Adapter_Product;
use Mage_Core_Exception;
use Mage_Customer_Model_Convert_Adapter_Customer;
use Mage_Dataflow_Model_Batch as Subject;
use Mage_Dataflow_Model_Convert_Adapter_Interface;
use Mage_Dataflow_Model_Convert_Adapter_Std;
use Mage_Dataflow_Model_Profile;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Dataflow\Model\BatchTrait;

final class BatchTest extends OpenMageTest
{
    use BatchTrait;

    private const REFUSED_MESSAGE = 'The declared adapter was not found or is not a Dataflow adapter.';

    protected function setUp(): void
    {
        parent::setUp();
        BatchTestFixture::reset();
    }

    /**
     * Adapters of the system profiles and the GUI profiles
     */
    public static function provideCoreAdapters(): Generator
    {
        foreach (Mage_Dataflow_Model_Profile::GUI_ADAPTERS as $entityType => $adapter) {
            yield 'gui ' . $entityType => [$adapter];
        }

        $installScript = (string) file_get_contents(
            __DIR__ . '/../../../../../app/code/core/Mage/Dataflow/data/dataflow_setup/data-install-1.6.0.0.php',
        );
        preg_match_all('#<var name="adapter">([^<]+)</var>#', $installScript, $matches);
        self::assertNotSame([], $matches[1]);
        foreach (array_unique($matches[1]) as $adapter) {
            yield 'system profile ' . $adapter => [$adapter];
        }

        yield 'product class name' => [Mage_Catalog_Model_Convert_Adapter_Product::class];
        yield 'customer class name' => [Mage_Customer_Model_Convert_Adapter_Customer::class];
    }

    public static function provideRefusedAdapters(): Generator
    {
        yield 'class with import methods' => [BatchTestFixture::class];
        yield 'include gadget' => ['Zend_Translate_Adapter_Array'];
        yield 'model alias that is not an adapter' => ['core/store'];
        yield 'abstract adapter' => ['dataflow/convert_adapter_abstract'];
        yield 'adapter interface' => [Mage_Dataflow_Model_Convert_Adapter_Interface::class];
        yield 'unknown alias' => ['nonexistent/model'];
        yield 'empty' => [''];
        yield 'blank' => ['  '];
        yield 'array' => [['catalog/convert_adapter_product']];
        yield 'null' => [null];
    }

    /**
     * The core adapters load EAV config when constructed, so only the class check runs here
     *
     * @dataProvider provideCoreAdapters
     * @group Mage_Dataflow
     */
    public function testCoreAdaptersAreAllowed(string $adapter): void
    {
        self::assertTrue(Subject::isAdapterAllowed($adapter));
    }

    /**
     * @group Mage_Dataflow
     */
    public function testCreateAdapterConstructsAllowedAdapter(): void
    {
        self::assertInstanceOf(
            Mage_Dataflow_Model_Convert_Adapter_Std::class,
            Subject::createAdapter('dataflow/convert_adapter_std'),
        );
    }

    /**
     * @dataProvider provideRefusedAdapters
     * @group Mage_Dataflow
     */
    public function testCreateAdapterRefusesOtherClasses(mixed $adapter): void
    {
        self::assertFalse(Subject::isAdapterAllowed($adapter));

        try {
            Subject::createAdapter($adapter);
            self::fail('The adapter was accepted');
        } catch (Mage_Core_Exception $mageCoreException) {
            self::assertSame(self::REFUSED_MESSAGE, $mageCoreException->getMessage());
        }

        self::assertFalse(BatchTestFixture::$constructed);
    }

    /**
     * @group Mage_Dataflow
     */
    public function testBeforeFinishDoesNotConstructStoredNonAdapter(): void
    {
        $batch = Mage::getModel('dataflow/batch');
        self::assertInstanceOf(Subject::class, $batch);
        $batch->setAdapter(BatchTestFixture::class);

        try {
            $batch->beforeFinish();
            self::fail('The stored adapter was accepted');
        } catch (Mage_Core_Exception $mageCoreException) {
            self::assertSame(self::REFUSED_MESSAGE, $mageCoreException->getMessage());
        }

        self::assertFalse(BatchTestFixture::$constructed);
        self::assertFalse(BatchTestFixture::$finished);
    }
}
