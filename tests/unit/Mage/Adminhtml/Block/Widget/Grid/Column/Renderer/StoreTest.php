<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Adminhtml\Block\Widget\Grid\Column\Renderer;

use Mage_Adminhtml_Block_Widget_Grid_Column;
use Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Store as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Adminhtml\Block\Widget\Grid\Column\Renderer\StoreTrait;
use Varien_Object;

final class StoreTest extends OpenMageTest
{
    use StoreTrait;

    /**
     * Rows of a deleted store view (the Sales > Orders grid) show the store name kept with the row.
     * That copy is printed as HTML and must be escaped like the live store names.
     *
     * @covers Mage_Adminhtml_Block_Widget_Grid_Column_Renderer_Store::render()
     * @group Block
     */
    public function testRenderDeletedStoreNameIsEscaped(): void
    {
        $column = new Mage_Adminhtml_Block_Widget_Grid_Column();
        $column->setIndex('store_id');

        $subject = new Subject();
        $subject->setColumn($column);

        $row = new Varien_Object([
            'store_id' => null,
            'store_name' => "Main Website\nMain & Co\n<img src=x onerror=alert(1)>",
        ]);

        self::assertSame(
            'Main Website<br/>&nbsp;&nbsp;&nbsp;Main &amp; Co<br/>'
                . '&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&nbsp;&lt;img src=x onerror=alert(1)&gt; [deleted]',
            $subject->render($row),
        );
    }
}
