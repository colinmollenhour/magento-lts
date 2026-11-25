<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Varien\Data\Form\Element;

use Mage;
use OpenMage\Tests\Unit\OpenMageTest;
use Varien_Data_Form;
use Varien_Data_Form_Element_Image as Subject;

final class ImageTest extends OpenMageTest
{
    private const PAYLOAD = 'x.jpg" onerror="alert(1)';

    private const ESCAPED_PAYLOAD = 'x.jpg&quot; onerror=&quot;alert(1)';

    /**
     * The value is printed in href, src, title, alt and the hidden input; every one of them is escaped.
     *
     * @group Varien
     */
    public function testGetElementHtmlEscapesValueAndUrl(): void
    {
        $html = $this->createSubject(self::PAYLOAD)->getElementHtml();

        self::assertStringNotContainsString(self::PAYLOAD, $html);
        self::assertStringContainsString('href="' . Mage::getBaseUrl('media') . self::ESCAPED_PAYLOAD . '"', $html);
        self::assertStringContainsString('src="' . Mage::getBaseUrl('media') . self::ESCAPED_PAYLOAD . '"', $html);
        self::assertStringContainsString('title="' . self::ESCAPED_PAYLOAD . '"', $html);
        self::assertStringContainsString('alt="' . self::ESCAPED_PAYLOAD . '"', $html);
        self::assertStringContainsString('name="image[value]" value="' . self::ESCAPED_PAYLOAD . '"', $html);
    }

    /**
     * Only a value that starts with http:// or https:// is used as the URL without the media base URL.
     *
     * @group Varien
     */
    public function testValueContainingAnHttpsUrlLaterIsPrefixedWithMediaUrl(): void
    {
        $value = 'javascript:alert(1)//https://example.com/';
        $html = $this->createSubject($value)->getElementHtml();

        self::assertStringContainsString('href="' . Mage::getBaseUrl('media') . $value . '"', $html);
        self::assertStringNotContainsString('href="javascript:', $html);
    }

    private function createSubject(string $value): Subject
    {
        $form = new Varien_Data_Form();
        /** @var Subject $element */
        $element = $form->addField('image', 'image', ['name' => 'image', 'value' => $value]);

        return $element;
    }
}
