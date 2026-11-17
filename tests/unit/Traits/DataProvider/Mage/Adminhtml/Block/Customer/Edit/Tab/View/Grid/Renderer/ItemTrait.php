<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Traits\DataProvider\Mage\Adminhtml\Block\Customer\Edit\Tab\View\Grid\Renderer;

use Generator;
use Mage_Catalog_Model_Product_Type;
use Mage_Catalog_Model_Product_Type_Configurable;

trait ItemTrait
{
    /**
     * Options as returned by the product configuration helper
     *
     * @return list<array<string, bool|string>>
     */
    public static function getHelperOptions(): array
    {
        return [
            // configurable attribute: raw option label
            ['label' => 'Color', 'value' => '<img src=x onerror=alert(1)>'],
            // custom option: escaped by the option type
            ['label' => 'Engraving', 'value' => 'Fish &amp; Chips', 'option_id' => '5', 'option_type' => 'field', 'custom_view' => false],
        ];
    }

    /**
     * @return Generator<string, array{list<array<string, bool|string>>, string}>
     */
    public static function provideGetOptionListData(): Generator
    {
        yield 'configurable attribute value is escaped, custom option is not double-encoded' => [
            [
                ['label' => 'Color', 'value' => '&lt;img src=x onerror=alert(1)&gt;'],
                ['label' => 'Engraving', 'value' => 'Fish &amp; Chips', 'option_id' => '5', 'option_type' => 'field', 'custom_view' => false],
            ],
            Mage_Catalog_Model_Product_Type_Configurable::TYPE_CODE,
        ];

        yield 'other product types keep the helper output' => [
            self::getHelperOptions(),
            Mage_Catalog_Model_Product_Type::TYPE_BUNDLE,
        ];
    }
}
