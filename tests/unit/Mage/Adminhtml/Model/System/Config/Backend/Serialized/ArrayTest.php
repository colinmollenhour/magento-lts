<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Adminhtml\Model\System\Config\Backend\Serialized;

use Generator;
use Mage;
use Mage_Adminhtml_Model_System_Config_Backend_Serialized_Array as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Adminhtml\Model\System\Config\Backend\Serialized\ArrayTrait;
use ReflectionMethod;

final class ArrayTest extends OpenMageTest
{
    use ArrayTrait;

    /**
     * @dataProvider provideRowKeyData
     * @group Model
     */
    public function testGetSafeRowKey(int|string $rowKey, string $expected): void
    {
        self::assertSame($expected, Subject::getSafeRowKey($rowKey));
    }

    /**
     * @return Generator<string, array{int|string, string}>
     */
    public static function provideRowKeyData(): Generator
    {
        $payload = "x'),alert(1),('\"><img src=x onerror=alert(2)>";

        yield 'id created by the form' => ['_1732550000000_123', '_1732550000000_123'];
        yield 'id created by uniqHash' => ['_9f86d081884c7d659a2feaa0c55ad015', '_9f86d081884c7d659a2feaa0c55ad015'];
        yield 'integer key' => [3, '3'];
        yield 'key with hyphen' => ['default-src_frontend_0', 'default-src_frontend_0'];
        yield 'markup and quotes' => [$payload, '_' . md5($payload)];
        yield 'space' => ['a b', '_' . md5('a b')];
        yield 'trailing newline' => ["abc\n", '_' . md5("abc\n")];
        yield 'empty key' => ['', '_' . md5('')];
    }

    /**
     * Rows are stored under safe keys, in their posted order, without the "__empty" marker.
     *
     * @group Model
     */
    public function testBeforeSaveReplacesUnsafeRowKeys(): void
    {
        $payload = "x'),alert(1),('";

        /** @var Subject $subject */
        $subject = Mage::getModel('adminhtml/system_config_backend_serialized_array');
        $subject->setValue([
            '__empty'  => '',
            '_1_2'     => ['regexp' => 'iPhone', 'value' => 'iphone'],
            $payload   => ['regexp' => 'Android', 'value' => 'android'],
        ]);

        (new ReflectionMethod($subject, '_beforeSave'))->invoke($subject);

        $stored = unserialize($subject->getValue(), ['allowed_classes' => false]);

        self::assertSame([
            '_1_2'                => ['regexp' => 'iPhone', 'value' => 'iphone'],
            '_' . md5($payload)   => ['regexp' => 'Android', 'value' => 'android'],
        ], $stored);
    }
}
