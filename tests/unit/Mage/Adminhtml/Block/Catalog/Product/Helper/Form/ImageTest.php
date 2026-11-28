<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Adminhtml\Block\Catalog\Product\Helper\Form;

use Mage_Adminhtml_Block_Catalog_Product_Helper_Form_Image as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Adminhtml\Block\Catalog\Product\Helper\Form\ImageTrait;
use Varien_Data_Form;
use Varien_Object;

final class ImageTest extends OpenMageTest
{
    use ImageTrait;

    /**
     * A required image attribute prints its value in a hidden input instead of the delete checkbox.
     *
     * @group Block
     */
    public function testRequiredAttributeHiddenInputEscapesValue(): void
    {
        $form = new Varien_Data_Form();
        $subject = new Subject(['name' => 'custom_image', 'value' => 'x.jpg" onfocus="alert(1)']);
        $subject->setId('custom_image');
        $subject->setEntityAttribute(new Varien_Object(['is_required' => true]));

        $form->addElement($subject);

        $html = $subject->getElementHtml();

        self::assertStringNotContainsString('onfocus="', (string) $html);
        self::assertStringContainsString('<input value="x.jpg&quot; onfocus=&quot;alert(1)" id="', (string) $html);
    }
}
