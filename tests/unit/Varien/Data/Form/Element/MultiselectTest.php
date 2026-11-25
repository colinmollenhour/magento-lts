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
use Varien_Data_Form_Element_Multiselect as Subject;

/**
 * Option group labels and option styles must not be able to leave their quotes.
 */
final class MultiselectTest extends OpenMageTest
{
    private const PAYLOAD = 'x" onfocus="alert(1)" autofocus="';

    private const ESCAPED = 'x&quot; onfocus=&quot;alert(1)&quot; autofocus=&quot;';

    /**
     * @group Varien_Data
     */
    public function testOptionGroupLabelAndOptionStyleAreEscaped(): void
    {
        $subject = new Subject([
            'html_id' => 'stores',
            'name' => 'stores',
            'values' => [
                [
                    'label' => self::PAYLOAD,
                    'value' => [['value' => '1', 'label' => 'One', 'style' => self::PAYLOAD]],
                ],
                [
                    'label' => 'Fish &amp; Chips',
                    'value' => [['value' => '2', 'label' => 'Two']],
                ],
            ],
        ]);
        $subject->setForm(new Varien_Data_Form());

        $html = $subject->getElementHtml();

        self::assertStringContainsString('<optgroup label="' . self::ESCAPED . '">', $html);
        self::assertStringContainsString('style="' . self::ESCAPED . '"', $html);
        // Labels that callers already escaped, such as store names, are not encoded again.
        self::assertStringContainsString('<optgroup label="Fish &amp; Chips">', $html);
        self::assertStringNotContainsString('onfocus="', $html);
    }
}
