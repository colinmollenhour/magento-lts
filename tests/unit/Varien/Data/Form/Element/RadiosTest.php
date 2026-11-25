<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Varien\Data\Form\Element;

use OpenMage\Tests\Unit\OpenMageTest;
use Varien_Data_Form;
use Varien_Data_Form_Element_Radios as Subject;
use Varien_Object;

/**
 * Radio option values, labels and attributes are printed as HTML and must be escaped.
 */
final class RadiosTest extends OpenMageTest
{
    /**
     * @group Varien_Data
     */
    public function testArrayOptionIsEscaped(): void
    {
        $html = $this->createSubject([
            ['value' => 'a"b', 'label' => '<img src=x onerror=alert(1)>'],
        ])->getElementHtml();

        self::assertStringContainsString('id="colora&quot;b"', $html);
        self::assertStringContainsString('for="colora&quot;b"', $html);
        self::assertStringContainsString('>&lt;img src=x onerror=alert(1)&gt;</label>', $html);
        self::assertStringNotContainsString('<img', $html);
    }

    /**
     * @group Varien_Data
     */
    public function testObjectOptionIsEscaped(): void
    {
        $html = $this->createSubject([
            new Varien_Object([
                'value' => 'a"b',
                'label' => '<img src=x onerror=alert(1)>',
                'title' => 'x" onfocus="alert(1)',
            ]),
        ], 'a"b')->getElementHtml();

        self::assertStringContainsString('id="colora&quot;b"', $html);
        self::assertStringContainsString('title="x&quot; onfocus=&quot;alert(1)"', $html);
        self::assertStringContainsString('>&lt;img src=x onerror=alert(1)&gt;</label>', $html);
        self::assertStringNotContainsString('<img', $html);
        self::assertStringNotContainsString('onfocus="', $html);
    }

    /**
     * @param list<array<string, string>|Varien_Object> $values
     * @param list<string>|string                       $value
     */
    private function createSubject(array $values, array|string $value = ''): Subject
    {
        $subject = new Subject([
            'html_id' => 'color',
            'name' => 'color',
            'class' => 'radio',
            'values' => $values,
            'value' => is_string($value) ? [$value] : $value,
        ]);
        $subject->setForm(new Varien_Data_Form());
        return $subject;
    }
}
