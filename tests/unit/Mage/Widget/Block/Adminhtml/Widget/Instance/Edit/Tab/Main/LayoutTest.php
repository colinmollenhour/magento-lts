<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Widget\Block\Adminhtml\Widget\Instance\Edit\Tab\Main;

use Mage_Widget_Block_Adminhtml_Widget_Instance_Edit_Tab_Main_Layout as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Widget\Block\Adminhtml\Widget\Instance\Edit\Tab\Main\LayoutTrait;
use Varien_Object;

final class LayoutTest extends OpenMageTest
{
    use LayoutTrait;

    /**
     * The page group template inserts the page id and the entity ids as HTML, so a value stored
     * before entity ids were validated must come out escaped.
     *
     * @group Block
     */
    public function testGetPageGroupsEscapesEntitiesAndPageId(): void
    {
        $subject = new Subject();
        $subject->setWidgetInstance(new Varien_Object(['page_groups' => [
            $this->createPageGroup('7"><img src=x onerror=alert(1)>', '1,2'),
            $this->createPageGroup('8', '1"><img src=x onerror=alert(2)>'),
        ]]));

        $pageGroups = $subject->getPageGroups();

        self::assertSame(7, $pageGroups[0]['page_id']);
        self::assertSame('1,2', $pageGroups[0]['all_products_entities']);
        self::assertSame(8, $pageGroups[1]['page_id']);
        self::assertSame('1&quot;&gt;&lt;img src=x onerror=alert(2)&gt;', $pageGroups[1]['all_products_entities']);
    }

    /**
     * @return array<string, string>
     */
    private function createPageGroup(string $pageId, string $entities): array
    {
        return [
            'page_id'         => $pageId,
            'page_group'      => 'all_products',
            'block_reference' => 'content',
            'page_for'        => 'specific',
            'layout_handle'   => 'catalog_product_view',
            'entities'        => $entities,
            'page_template'   => '',
        ];
    }
}
