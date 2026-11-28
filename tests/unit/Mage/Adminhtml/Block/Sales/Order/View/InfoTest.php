<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Adminhtml\Block\Sales\Order\View;

use Mage_Adminhtml_Block_Sales_Order_View_Info as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Adminhtml\Block\Sales\Order\View\InfoTrait;
use Varien_Object;

final class InfoTest extends OpenMageTest
{
    use InfoTrait;

    /**
     * Once its store view is deleted, an order shows the store name it kept when it was placed.
     * That copy is printed as HTML and must be escaped like the live store names.
     *
     * @covers Mage_Adminhtml_Block_Sales_Order_View_Info::getOrderStoreName()
     * @group Block
     */
    public function testGetOrderStoreNameOfDeletedStoreIsEscaped(): void
    {
        $subject = new Subject();
        $subject->setOrder(new Varien_Object([
            'store_id' => null,
            'store_name' => "Main Website\nMain & Co\n<img src=x onerror=alert(1)>",
        ]));

        self::assertSame(
            "Main Website<br />\nMain &amp; Co<br />\n&lt;img src=x onerror=alert(1)&gt; [deleted]",
            $subject->getOrderStoreName(),
        );
    }
}
