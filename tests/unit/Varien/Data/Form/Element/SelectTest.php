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
use Varien_Data_Form_Element_Select as Subject;

/**
 * Element attributes, option group labels and option styles must not be able to leave their quotes.
 */
final class SelectTest extends OpenMageTest
{
    private const PAYLOAD = 'x" onfocus="alert(1)" autofocus="';

    private const ESCAPED = 'x&quot; onfocus=&quot;alert(1)&quot; autofocus=&quot;';

    /**
     * An attribute's input validation class becomes the element class on the product form.
     *
     * @group Varien_Data
     */
    public function testHtmlAttributesAreEscaped(): void
    {
        $html = $this->createSubject([
            'class' => self::PAYLOAD,
            'title' => '<b>"Size"</b>',
            'values' => [],
        ])->getElementHtml();

        self::assertStringContainsString('class="' . self::ESCAPED . ' select"', $html);
        self::assertStringContainsString('title="&lt;b&gt;&quot;Size&quot;&lt;/b&gt;"', $html);
        self::assertStringNotContainsString('onfocus="', $html);
    }

    /**
     * @group Varien_Data
     */
    public function testOptionGroupLabelAndOptionStyleAreEscaped(): void
    {
        $html = $this->createSubject([
            'values' => [
                [
                    'label' => self::PAYLOAD,
                    'value' => [
                        ['value' => '1', 'label' => 'One', 'style' => self::PAYLOAD],
                        [
                            'label' => 'Fish &amp; Chips',
                            'value' => [['value' => '2', 'label' => 'Two']],
                        ],
                    ],
                ],
            ],
        ])->getElementHtml();

        self::assertStringContainsString('<optgroup label="' . self::ESCAPED . '">', $html);
        self::assertStringContainsString('style="' . self::ESCAPED . '"', $html);
        // Labels that callers already escaped, such as store names, are not encoded again.
        self::assertStringContainsString('<optgroup label="Fish &amp; Chips">', $html);
        self::assertStringNotContainsString('onfocus="', $html);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function createSubject(array $data): Subject
    {
        $subject = new Subject($data + ['html_id' => 'size', 'name' => 'size']);
        $subject->setForm(new Varien_Data_Form());
        return $subject;
    }
}
