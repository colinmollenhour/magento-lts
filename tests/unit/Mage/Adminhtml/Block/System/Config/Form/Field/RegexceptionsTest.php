<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Adminhtml\Block\System\Config\Form\Field;

use Mage;
use Mage_Adminhtml_Block_System_Config_Form_Field_Regexceptions as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Adminhtml\Block\System\Config\Form\Field\RegexceptionsTrait;
use Varien_Data_Form;

final class RegexceptionsTest extends OpenMageTest
{
    use RegexceptionsTrait;

    private const PAYLOAD_KEY = "x'),alert(1),('\"><img src=x onerror=alert(2)>";

    /**
     * Stored row keys are used as HTML ids and inside JavaScript, so only plain identifiers are kept.
     *
     * @group Block
     */
    public function testGetArrayRowsReplacesRowKeysThatAreNotPlainIdentifiers(): void
    {
        $subject = $this->createSubject([
            '_1732550000000_123' => ['regexp' => 'iPhone', 'value' => 'iphone'],
            self::PAYLOAD_KEY    => ['regexp' => '<b>', 'value' => 'default'],
            0                    => ['regexp' => 'Android', 'value' => 'android'],
            'default-src_x_0'    => ['regexp' => 'Opera', 'value' => 'opera'],
        ]);

        $rows = $subject->getArrayRows();
        $safeKey = '_' . md5(self::PAYLOAD_KEY);

        self::assertSame(['_1732550000000_123', $safeKey, 0, 'default-src_x_0'], array_keys($rows));
        foreach ($rows as $rowId => $row) {
            self::assertSame((string) $rowId, $row->getData('_id'));
            self::assertMatchesRegularExpression('/^[A-Za-z0-9_-]+$/', $row->getData('_id'));
        }

        self::assertSame('&lt;b&gt;', $rows[$safeKey]->getData('regexp'));
    }

    /**
     * @group Block
     */
    public function testGetArrayRowsSkipsRowsThatAreNotArrays(): void
    {
        $rows = $this->createSubject(['_1' => ['regexp' => 'a', 'value' => 'b'], '_2' => 'broken'])->getArrayRows();

        self::assertSame(['_1'], array_keys($rows));
    }

    /**
     * The rendered field does not contain a stored row key that is not a plain identifier.
     *
     * @group Block
     */
    public function testRenderedFieldDoesNotContainUnsafeRowKey(): void
    {
        $design = Mage::getDesign();
        $area = $design->getArea();
        $design->setArea('adminhtml');

        try {
            $subject = $this->createSubject([self::PAYLOAD_KEY => ['regexp' => 'iPhone', 'value' => 'iphone']]);
            $subject->setLayout(Mage::app()->getLayout());
            $html = $subject->render($subject->getElement());
        } finally {
            $design->setArea($area);
        }

        self::assertStringContainsString('arrayRow', (string) $html);
        self::assertStringNotContainsString('onerror', (string) $html);
        self::assertStringNotContainsString('alert(1)', (string) $html);
        self::assertStringContainsString('_' . md5(self::PAYLOAD_KEY), (string) $html);
    }

    /**
     * @param array<int|string, mixed> $value
     */
    private function createSubject(array $value): Subject
    {
        $element = (new Varien_Data_Form())->addField('design_package_ua_regexp', 'text', [
            'name'  => 'groups[package][fields][ua_regexp][value]',
            'value' => $value,
        ]);

        $subject = new Subject();
        $subject->setElement($element);

        return $subject;
    }
}
