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
use Varien_Data_Form_Element_Checkboxes as Subject;

/**
 * Checkbox option values, labels and attributes are printed as HTML and must be escaped.
 */
final class CheckboxesTest extends OpenMageTest
{
    /**
     * @group Varien_Data
     */
    public function testOptionIsEscaped(): void
    {
        $subject = new Subject([
            'html_id' => 'tags',
            'name' => 'tags[]',
            'onclick' => 'pick(this)" onfocus="alert(1)',
            'values' => [
                ['value' => 'a"b', 'label' => '<img src=x onerror=alert(1)>'],
            ],
        ]);
        $subject->setForm(new Varien_Data_Form());

        $html = $subject->getElementHtml();

        self::assertStringContainsString('onclick="pick(this)&quot; onfocus=&quot;alert(1)"', (string) $html);
        self::assertStringContainsString('value="a&quot;b"', (string) $html);
        self::assertStringContainsString('>&lt;img src=x onerror=alert(1)&gt;</label>', (string) $html);
        self::assertStringNotContainsString('<img', (string) $html);
        self::assertStringNotContainsString('onfocus="', (string) $html);
    }
}
