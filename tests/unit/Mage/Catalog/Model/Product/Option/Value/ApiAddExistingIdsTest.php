<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Catalog\Model\Product\Option\Value;

use Generator;
use Mage_Catalog_Model_Product_Option;
use Mage_Catalog_Model_Product_Option_Value_Api as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * catalog_product_custom_option_value.add must always add new values. An option_type_id on a row made
 * the value model update, move to this option, or delete an existing value of any option.
 */
final class ApiAddExistingIdsTest extends OpenMageTest
{
    public function testAddSavesRowsWithoutExistingIds(): void
    {
        $rows = [['option_type_id' => 12, 'title' => 'M', 'is_delete' => '1']];

        $subject = $this->createPartialMock(Subject::class, ['_prepareOption', '_removeExistingIds']);
        $subject->method('_prepareOption')->willReturn($this->createMock(Mage_Catalog_Model_Product_Option::class));
        $subject->expects(self::once())
            ->method('_removeExistingIds')
            ->with($rows)
            ->willReturn([]);

        self::assertTrue($subject->add('3', $rows));
    }

    #[DataProvider('provideRows')]
    public function testRemoveExistingIds(mixed $rows, mixed $expected): void
    {
        $subject = new Subject();
        self::assertSame($expected, (new ReflectionMethod($subject, '_removeExistingIds'))->invoke($subject, $rows));
    }

    public static function provideRows(): Generator
    {
        yield 'option_type_id and value_id are dropped' => [
            [
                ['option_type_id' => 12, 'title' => 'M', 'is_delete' => '1'],
                ['value_id' => 13, 'title' => 'L', 'price' => 1],
                ['title' => 'XL'],
            ],
            [
                ['title' => 'M', 'is_delete' => '1'],
                ['title' => 'L', 'price' => 1],
                ['title' => 'XL'],
            ],
        ];

        yield 'non-array input is left alone' => ['x', 'x'];
    }
}
