<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Varien;

use Iterator;
use Override;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Varien\ObjectTrait;
use Varien_Exception;
use Varien_Object as Subject;

final class ObjectTest extends OpenMageTest
{
    use ObjectTrait;

    private static Subject $subject;

    #[Override]
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$subject = new Subject();
    }

    /**
     * @dataProvider provideGetDataData
     * @param mixed           $expectedResult
     * @param string          $setKey
     * @param mixed           $setValue
     * @param null|int|string $index
     *
     * @group Varien_Object
     */
    public function testGetData($expectedResult, $setKey, $setValue, ?string $key, $index = null): void
    {
        self::$subject->setData($setKey, $setValue);
        self::assertSame($expectedResult, self::$subject->getData($key, $index));
    }

    /**
     * @dataProvider provideToStringData
     * @group Varien_Object
     */
    public function testToString(string $expectedResult, string $format): void
    {
        self::$subject->setString0('0');
        self::$subject->setString1('one');
        self::$subject->setString2('two');
        self::$subject->setString3('three');

        self::assertSame($expectedResult, self::$subject->toString($format));
    }

    /**
     * @group Varien_Object
     */
    public function testGetSetUnsData(): void
    {
        self::assertTrue(self::$subject->isEmpty());
        self::$subject->setABC('abc');
        self::$subject->setData('efg', 'efg');
        self::$subject->set123('123');
        self::$subject->setData('345', '345');
        self::$subject->setKeyAFirst('value_a_first');
        self::$subject->setData('key_a_2nd', 'value_a_2nd');
        self::$subject->setKeyA3rd('value_a_3rd');
        self::$subject->setData('left', 'over');
        self::assertFalse(self::$subject->isEmpty());

        self::assertSame('abc', self::$subject->getDataByKey('a_b_c'));
        self::assertSame('abc', self::$subject->getABC());
        self::$subject->unsetData('a_b_c');

        self::assertSame('efg', self::$subject->getDataByKey('efg'));
        self::assertSame('efg', self::$subject->getEfg());
        self::$subject->unsEfg();

        self::assertSame('123', self::$subject->getDataByKey('123'));
        self::assertSame('123', self::$subject->get123());
        self::$subject->uns123();

        self::$subject->unsetData('345');

        self::assertSame('value_a_first', self::$subject->getDataByKey('key_a_first'));
        self::assertSame('value_a_first', self::$subject->getKeyAFirst());
        self::$subject->unsetData('key_a_first');

        self::assertSame('value_a_2nd', self::$subject->getDataByKey('key_a_2nd'));
        self::assertSame('value_a_2nd', self::$subject->getKeyA_2nd());
        self::$subject->unsetData('key_a_2nd');

        self::assertSame('value_a_3rd', self::$subject->getDataByKey('key_a3rd'));
        self::assertSame('value_a_3rd', self::$subject->getKeyA3rd());
        self::$subject->unsetData('key_a3rd');

        self::assertSame(['left' => 'over'], self::$subject->getData());

        self::$subject->unsetData();
        self::assertSame([], self::$subject->getData());
        self::assertTrue(self::$subject->isEmpty());

        try {
            /** @phpstan-ignore-next-line */
            self::$subject->notData();
            self::fail('Invalid __call');
        } catch (Varien_Exception $varienException) {
            self::assertStringStartsWith('Invalid method', $varienException->getMessage());
        }
    }

    /**
     * @group Varien_Object
     */
    public function testOffset(): void
    {
        self::assertFalse(self::$subject->offsetExists('off'));

        self::$subject->offsetSet('off', 'set');
        self::assertTrue(self::$subject->offsetExists('off'));
        self::assertSame('set', self::$subject->offsetGet('off'));
        self::assertNull(self::$subject->offsetGet('not-exists'));

        self::$subject->offsetUnset('off');
        self::assertFalse(self::$subject->offsetExists('off'));
    }

    /**
     * serialize() writes HTML attributes, so a value must not be able to leave its quotes.
     *
     * @dataProvider provideSerializeData
     * @param array<string, mixed> $data
     * @param list<string>         $attributes
     *
     * @group Varien_Object
     */
    public function testSerialize(string $expectedResult, array $data, array $attributes = []): void
    {
        self::$subject->setData($data);
        self::assertSame($expectedResult, self::$subject->serialize($attributes));
    }

    /**
     * @return Iterator<string, array{0: string, 1: array<string, mixed>, 2?: list<string>}>
     */
    public static function provideSerializeData(): Iterator
    {
        yield 'plain values' => [
            'class="input-text validate-digits" title="Qty"',
            ['class' => 'input-text validate-digits', 'title' => 'Qty'],
        ];
        yield 'double quote cannot close the attribute' => [
            'class="x&quot; onfocus=&quot;alert(1)&quot; autofocus=&quot;"',
            ['class' => 'x" onfocus="alert(1)" autofocus="'],
        ];
        yield 'angle brackets, ampersand and single quote' => [
            'title="&lt;b&gt;Fish &amp; Chips&#039;s&lt;/b&gt;"',
            ['title' => "<b>Fish & Chips's</b>"],
        ];
        yield 'existing entities are not encoded again' => [
            'title="Fish &amp; Chips &quot;Ltd&quot;"',
            ['title' => 'Fish &amp; Chips &quot;Ltd&quot;'],
        ];
        yield 'inline JavaScript keeps working after the browser decodes it' => [
            'onclick="return confirm(&#039;Sure?&#039;)"',
            ['onclick' => "return confirm('Sure?')"],
        ];
        yield 'only the requested attributes' => [
            'class="a&quot;b"',
            ['class' => 'a"b', 'style' => 'color:red'],
            ['class'],
        ];
        yield 'scalars' => [
            'size="10" disabled="1" title=""',
            ['size' => 10, 'disabled' => true, 'title' => null],
        ];
    }

    protected function tearDown(): void
    {
        self::$subject->unsetData();
    }
}
