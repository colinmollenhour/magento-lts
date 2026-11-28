<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Eav\Model\Entity;

use Iterator;
use Mage_Eav_Exception;
use Mage_Eav_Model_Entity_Attribute as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Eav\Model\Entity\AttributeTrait;
use ReflectionMethod;

/**
 * A new or renamed attribute code must be an identifier, whatever saves it; stored codes are not checked again
 */
final class AttributeTest extends OpenMageTest
{
    use AttributeTrait;

    /**
     * @dataProvider provideInvalidCode
     * @group Model
     */
    public function testBeforeSaveRejectsInvalidNewCode(mixed $code): void
    {
        $subject = new Subject();
        $subject->setAttributeCode($code);

        $this->expectException(Mage_Eav_Exception::class);
        $this->callBeforeSave($subject);
    }

    /**
     * @dataProvider provideInvalidCode
     * @group Model
     */
    public function testBeforeSaveRejectsInvalidChangedCode(mixed $code): void
    {
        $subject = new Subject(['attribute_id' => 135, 'attribute_code' => 'stored_code']);
        $subject->setOrigData();
        $subject->setAttributeCode($code);

        $this->expectException(Mage_Eav_Exception::class);
        $this->callBeforeSave($subject);
    }

    public static function provideInvalidCode(): Iterator
    {
        yield 'backtick' => ['sku` = 1 OR 1=1 OR `sku'];
        yield 'comment' => ['sku/**/OR/**/1'];
        yield 'parenthesis' => ['sku)'];
        yield 'asterisk' => ['sku*'];
        yield 'tab' => ["sku\tx"];
        yield 'space' => ['my code'];
        yield 'hyphen' => ['my-code'];
        yield 'dot' => ['e.sku'];
        yield 'leading digit' => ['1code'];
        yield 'leading underscore' => ['_code'];
        yield 'trailing newline' => ["code\n"];
        yield 'non-string' => [['code']];
    }

    /**
     * Identifier codes, including uppercase ones the admin and API do not offer, still save
     *
     * @dataProvider provideValidCode
     * @group Model
     */
    public function testBeforeSaveAcceptsValidNewCode(string $code): void
    {
        $subject = new Subject();
        $subject->setAttributeCode($code);

        $this->callBeforeSave($subject);
        self::assertSame($code, $subject->getAttributeCode());
    }

    public static function provideValidCode(): Iterator
    {
        yield 'lowercase' => ['color'];
        yield 'single letter' => ['c'];
        yield 'digits and underscores' => ['color_2_name'];
        yield 'uppercase' => ['ColorName'];
    }

    /**
     * An attribute stored with a code outside the format keeps saving while its code is unchanged
     *
     * @group Model
     */
    public function testBeforeSaveKeepsStoredLegacyCode(): void
    {
        $subject = new Subject(['attribute_id' => 135, 'attribute_code' => 'legacy-code']);
        $subject->setOrigData();
        $subject->setFrontendLabel('Changed');

        $this->callBeforeSave($subject);
        self::assertSame('legacy-code', $subject->getAttributeCode());
    }

    private function callBeforeSave(Subject $subject): void
    {
        (new ReflectionMethod($subject, '_beforeSave'))->invoke($subject);
    }
}
