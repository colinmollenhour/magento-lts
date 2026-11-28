<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Catalog\Model;

use Generator;
use Mage;
use Mage_Catalog_Model_Category;
use Mage_Catalog_Model_Design as Subject;
use Mage_Catalog_Model_Product;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Catalog\Model\DesignTrait;
use ReflectionMethod;

final class DesignTest extends OpenMageTest
{
    use DesignTrait;

    /**
     * Stored product and category layout updates are validated again when they are applied
     *
     * @param class-string<Mage_Catalog_Model_Category|Mage_Catalog_Model_Product> $class
     * @param list<string>                                                         $expected
     * @dataProvider provideExtractSettingsLayoutUpdates
     * @group Model
     */
    public function testExtractSettingsValidatesStoredLayoutUpdate(string $class, array $expected, string $layoutUpdate): void
    {
        $object = $this->createPartialMock($class, ['getId']);
        $object->method('getId')->willReturn(1);
        $object->setData('custom_layout_update', $layoutUpdate);

        $method = new ReflectionMethod(Subject::class, '_extractSettings');
        $settings = $method->invoke(Mage::getModel('catalog/design'), $object);

        self::assertSame($expected, $settings->getLayoutUpdates());
    }

    public static function provideExtractSettingsLayoutUpdates(): Generator
    {
        $valid = '<reference name="content"><block type="core/text" name="t"/></reference>';
        $adminGridAsArgument = '<reference name="content"><block type="catalog/product_view_tabs" name="t">'
            . '<action method="addTab"><alias>g</alias><title>x</title><block>adminhtml/permissions_user_grid</block>'
            . '<template>widget/grid.phtml</template></action></block></reference>';
        $areaSwitch = '<reference name="content"><action method="setArea"><a>adminhtml</a></action></reference>';

        foreach ([Mage_Catalog_Model_Product::class, Mage_Catalog_Model_Category::class] as $class) {
            yield "$class: valid update is applied" => [$class, [$valid], $valid];
            yield "$class: admin grid passed as an addTab argument is skipped" => [$class, [''], $adminGridAsArgument];
            yield "$class: area switch is skipped" => [$class, [''], $areaSwitch];
        }
    }
}
