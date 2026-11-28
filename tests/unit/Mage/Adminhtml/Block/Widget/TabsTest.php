<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Adminhtml\Block\Widget;

use Generator;
use Mage;
use Mage_Adminhtml_Block_Widget_Tab_Interface;
use Mage_Adminhtml_Block_Widget_Tabs as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Adminhtml\Block\Widget\TabsTrait;
use Varien_Object;

final class TabsTest extends OpenMageTest
{
    use TabsTrait;

    /**
     * Tab titles can come from stored data, such as the attribute group names the category editor uses as tabs.
     *
     * @dataProvider provideTabTitleData
     * @group Block
     */
    public function testGetTabTitleEscapesTitle(string $title, string $expected): void
    {
        $subject = new Subject();

        self::assertSame($expected, $subject->getTabTitle(new Varien_Object(['title' => $title])));
        self::assertSame($expected, $subject->getTabTitle($this->createTab($title)));
    }

    /**
     * A tab added with only a label uses the label as its title.
     *
     * @group Block
     */
    public function testTitleCopiedFromLabelIsEscaped(): void
    {
        $subject = new Subject();
        $subject->addTab('group_4', ['label' => 'General" onmouseover="alert(1)']);

        $tabs = (fn(): array => $this->_tabs)->call($subject);

        self::assertSame('General&quot; onmouseover=&quot;alert(1)', $subject->getTabTitle($tabs['group_4']));
    }

    /**
     * A custom theme that copied the old widget/tabs.phtml escapes the title again; that must not change it.
     *
     * @dataProvider provideTabTitleData
     * @group Block
     */
    public function testEscapingTheTitleAgainDoesNotChangeIt(string $title): void
    {
        $escaped = (new Subject())->getTabTitle(new Varien_Object(['title' => $title]));

        self::assertSame($escaped, Mage::helper('core')->quoteEscape($escaped));
    }

    /**
     * @group Block
     */
    public function testMissingTitleIsAnEmptyString(): void
    {
        self::assertSame('', (new Subject())->getTabTitle(new Varien_Object()));
    }

    /**
     * @return Generator<string, array{string, string}>
     */
    public static function provideTabTitleData(): Generator
    {
        yield 'plain text' => ['General', 'General'];
        yield 'attribute break-out' => ['General" onmouseover="alert(1)', 'General&quot; onmouseover=&quot;alert(1)'];
        yield 'single quote' => ["Kid's", 'Kid&#039;s'];
        yield 'markup' => ['<img src=x onerror=alert(1)>', '&lt;img src=x onerror=alert(1)&gt;'];
        yield 'ampersand' => ['Images & Videos', 'Images &amp; Videos'];
        yield 'existing entity' => ['Images &amp; Videos', 'Images &amp; Videos'];
    }

    private function createTab(string $title): Mage_Adminhtml_Block_Widget_Tab_Interface
    {
        return new class ($title) implements Mage_Adminhtml_Block_Widget_Tab_Interface {
            public function __construct(private readonly string $title) {}

            public function getTabLabel()
            {
                return $this->title;
            }

            public function getTabTitle()
            {
                return $this->title;
            }

            public function canShowTab()
            {
                return true;
            }

            public function isHidden()
            {
                return false;
            }
        };
    }
}
