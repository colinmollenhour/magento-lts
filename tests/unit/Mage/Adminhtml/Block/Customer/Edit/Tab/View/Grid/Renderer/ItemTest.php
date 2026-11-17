<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Adminhtml\Block\Customer\Edit\Tab\View\Grid\Renderer;

use Mage;
use Mage_Adminhtml_Block_Customer_Edit_Tab_View_Grid_Renderer_Item as Subject;
use Mage_Catalog_Helper_Product_Configuration_Interface;
use Mage_Catalog_Model_Product;
use Mage_Catalog_Model_Product_Configuration_Item_Interface;
use Mage_Core_Helper_Abstract;
use Mage_Wishlist_Model_Item;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Adminhtml\Block\Customer\Edit\Tab\View\Grid\Renderer\ItemTrait;
use ReflectionMethod;

final class ItemTest extends OpenMageTest
{
    use ItemTrait;

    private const HELPER_NAME = 'openmage_test/product_configuration';

    protected function setUp(): void
    {
        parent::setUp();
        $helper = new class extends Mage_Core_Helper_Abstract implements Mage_Catalog_Helper_Product_Configuration_Interface {
            /**
             * @return list<array<string, bool|string>>
             */
            public function getOptions(Mage_Catalog_Model_Product_Configuration_Item_Interface $item)
            {
                return ItemTest::getHelperOptions();
            }
        };
        Mage::register('_helper/' . self::HELPER_NAME, $helper, true);
    }

    protected function tearDown(): void
    {
        Mage::unregister('_helper/' . self::HELPER_NAME);
        parent::tearDown();
    }

    /**
     * @covers Mage_Adminhtml_Block_Customer_Edit_Tab_View_Grid_Renderer_Item::getOptionList()
     * @dataProvider provideGetOptionListData
     * @group Block
     * @param list<array<string, bool|string>> $expectedResult
     */
    public function testGetOptionList(array $expectedResult, string $productType): void
    {
        $product = new Mage_Catalog_Model_Product();
        $product->setTypeId($productType);

        $subject = new Subject();
        $subject->setProductHelpers(['default' => self::HELPER_NAME]);

        $item = new Mage_Wishlist_Model_Item();
        $item->setProduct($product);

        $subject->setItem($item);

        $method = new ReflectionMethod($subject, 'getOptionList');
        self::assertSame($expectedResult, $method->invoke($subject));
    }
}
