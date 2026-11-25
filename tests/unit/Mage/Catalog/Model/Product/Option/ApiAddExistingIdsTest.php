<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Catalog\Model\Product\Option;

use Generator;
use Mage_Catalog_Model_Product;
use Mage_Catalog_Model_Product_Option_Api as Subject;
use Mage_Catalog_Model_Product_Option_Api_V2 as SubjectV2;
use OpenMage\Tests\Unit\OpenMageTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * catalog_product_custom_option.add must always add a new option. An option_id, or a value_id or
 * option_type_id on a row, made the option model update, move or delete an existing option or value of
 * any product.
 */
final class ApiAddExistingIdsTest extends OpenMageTest
{
    private const ID_KEYS = ['option_id', 'option_type_id', 'value_id'];

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('provideOptionsWithIds')]
    public function testAddDropsExistingIds(array $data, int $expectedRows): void
    {
        $subject = $this->mockSubject(Subject::class, $saved);
        self::assertTrue($subject->add('1', $data));
        self::assertIsArray($saved);

        self::assertSame([], $this->findIdKeys($saved));
        self::assertCount($expectedRows, $saved['values'] ?? []);
        self::assertSame($data['title'], $saved['title']);
    }

    /**
     * @param array<string, mixed> $data
     */
    #[DataProvider('provideOptionsWithIds')]
    public function testAddV2DropsExistingIds(array $data, int $expectedRows): void
    {
        $subject = $this->mockSubject(SubjectV2::class, $saved);
        self::assertTrue($subject->add('1', $this->toObjects($data)));
        self::assertIsArray($saved);

        self::assertSame([], $this->findIdKeys($saved));
        self::assertCount($expectedRows, $saved['values'] ?? []);
    }

    public static function provideOptionsWithIds(): Generator
    {
        yield 'select option copied from info() with value_id rows' => [[
            'title' => 'Size',
            'type' => 'drop_down',
            'option_id' => 7,
            'additional_fields' => [
                ['value_id' => 11, 'title' => 'S', 'price' => 1, 'price_type' => 'fixed', 'is_delete' => '1'],
                ['value_id' => 11, 'title' => 'M', 'price' => 2, 'price_type' => 'fixed'],
                ['option_type_id' => 12, 'title' => 'L', 'price' => 3, 'price_type' => 'fixed'],
            ],
        ], 3];

        yield 'text option with option_id in additional_fields' => [[
            'title' => 'Engraving',
            'type' => 'field',
            'additional_fields' => [
                ['option_id' => 7, 'price' => 1, 'price_type' => 'fixed', 'max_characters' => 10],
            ],
        ], 0];

        yield 'select option with values rows' => [[
            'title' => 'Color',
            'type' => 'drop_down',
            'additional_fields' => [['title' => 'Red', 'price' => 0, 'price_type' => 'fixed']],
            'values' => [['option_type_id' => 13, 'title' => 'Blue', 'is_delete' => '1']],
        ], 2];
    }

    /**
     * @template T of Subject
     * @param  class-string<T> $class
     * @param  mixed           $saved receives the data add() saves
     * @return MockObject&T
     */
    private function mockSubject(string $class, mixed &$saved): MockObject
    {
        $saved = null;
        $subject = $this->createPartialMock($class, ['_getProduct', '_saveProductCustomOption']);
        $subject->method('_getProduct')->willReturn($this->createPartialMock(Mage_Catalog_Model_Product::class, []));
        $subject->expects(self::once())
            ->method('_saveProductCustomOption')
            ->willReturnCallback(static function ($product, $data) use (&$saved): void {
                $saved = $data;
            });

        return $subject;
    }

    /**
     * @param  array<int|string, mixed> $data
     * @return string[]                 paths of id keys found anywhere in $data
     */
    private function findIdKeys(array $data, string $path = ''): array
    {
        $found = [];
        foreach ($data as $key => $value) {
            if (in_array($key, self::ID_KEYS, true)) {
                $found[] = $path . $key;
            }

            if (is_array($value)) {
                $found = [...$found, ...$this->findIdKeys($value, $path . $key . '.')];
            }
        }

        return $found;
    }

    private function toObjects(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $value = array_map($this->toObjects(...), $value);
        return array_is_list($value) ? $value : (object) $value;
    }
}
