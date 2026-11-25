<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Dataflow\Model\Convert\Parser\Xml;

use Mage_Dataflow_Model_Convert_Parser_Xml_Excel as Subject;
use Mage_Dataflow_Model_Convert_Exception;
use Mage_Dataflow_Model_Convert_Profile;
use OpenMage\Tests\Unit\Mage\Dataflow\Model\BatchTestFixture;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Dataflow\Model\Convert\Parser\Xml\ExcelTrait;

final class ExcelTest extends OpenMageTest
{
    use ExcelTrait;

    protected function setUp(): void
    {
        parent::setUp();
        BatchTestFixture::reset();
    }

    /**
     * @group Mage_Dataflow
     */
    public function testParseRefusesAdapterThatIsNotADataflowAdapter(): void
    {
        $profile = new Mage_Dataflow_Model_Convert_Profile();
        $subject = new Subject();
        $subject->setProfile($profile);
        $subject->setVar(['adapter' => BatchTestFixture::class, 'method' => 'saveRow']);

        $subject->parse();

        self::assertFalse(BatchTestFixture::$constructed, 'The profile constructed a class of its choosing');

        $exceptions = $profile->getExceptions();
        self::assertCount(1, $exceptions);
        self::assertInstanceOf(Mage_Dataflow_Model_Convert_Exception::class, $exceptions[0]);
        self::assertSame(Mage_Dataflow_Model_Convert_Exception::FATAL, $exceptions[0]->getLevel());
        self::assertSame(
            'The declared adapter was not found or is not a Dataflow adapter.',
            $exceptions[0]->getMessage(),
        );
    }
}
