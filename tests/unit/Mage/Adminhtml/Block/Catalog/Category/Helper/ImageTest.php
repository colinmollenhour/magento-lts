<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Adminhtml\Block\Catalog\Category\Helper;

use Mage;
use Mage_Adminhtml_Block_Catalog_Category_Helper_Image as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Adminhtml\Block\Catalog\Category\Helper\ImageTrait;
use Varien_Data_Form;

final class ImageTest extends OpenMageTest
{
    use ImageTrait;

    /**
     * A category image value set through the API or the category form is printed escaped.
     *
     * @group Block
     */
    public function testGetElementHtmlEscapesStoredValue(): void
    {
        $value = 'x" onerror="alert(1)" a="';
        $escaped = 'x&quot; onerror=&quot;alert(1)&quot; a=&quot;';

        $form = new Varien_Data_Form();
        $subject = new Subject(['name' => 'image', 'value' => $value]);
        $subject->setId('image');

        $form->addElement($subject);

        $html = $subject->getElementHtml();

        self::assertStringNotContainsString('onerror="', (string) $html);
        self::assertStringContainsString('src="' . Mage::getBaseUrl('media') . 'catalog/category/' . $escaped . '"', (string) $html);
        self::assertStringContainsString('alt="' . $escaped . '"', (string) $html);
        self::assertStringContainsString('value="' . $escaped . '"', (string) $html);
    }
}
