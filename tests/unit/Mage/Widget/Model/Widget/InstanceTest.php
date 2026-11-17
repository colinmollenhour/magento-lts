<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Widget\Model\Widget;

use Mage;
use Mage_Core_Exception;
use Mage_Widget_Model_Widget_Instance as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Widget\Model\Widget\InstanceTrait;
use ReflectionMethod;

final class InstanceTest extends OpenMageTest
{
    use InstanceTrait;

    private const DECLARED_TYPE = 'cms/widget_page_link';

    /**
     * @dataProvider provideUndeclaredTypeData
     * @group Model
     */
    public function testUndeclaredTypeIsRejected(string $type): void
    {
        $subject = $this->createSubject($type);

        self::assertFalse($subject->isTypeDeclared());
        self::assertSame('Wrong widget type specified.', $subject->validate());
        self::assertSame('', $subject->generateLayoutUpdateXml('content'));

        $this->expectException(Mage_Core_Exception::class);
        $this->expectExceptionMessage('Wrong widget type specified.');
        $this->beforeSave($subject);
    }

    /**
     * An instance stored with an undeclared type can be loaded, but not saved again.
     *
     * @group Model
     */
    public function testStoredInstanceWithUndeclaredTypeCannotBeSaved(): void
    {
        /** @var Subject $subject */
        $subject = Mage::getModel('widget/widget_instance');
        $subject->setData(['instance_id' => 12, 'type' => 'core/text_list', 'package_theme' => 'base/default']);

        self::assertFalse($subject->isTypeDeclared());

        $this->expectException(Mage_Core_Exception::class);
        $this->expectExceptionMessage('Wrong widget type specified.');
        $this->beforeSave($subject);
    }

    /**
     * @group Model
     */
    public function testDeclaredTypeIsAccepted(): void
    {
        $subject = $this->createSubject(self::DECLARED_TYPE);

        self::assertTrue($subject->isTypeDeclared());
        self::assertTrue($subject->validate());
    }

    /**
     * The type as sent by the Settings tab ("-" instead of "/") still resolves.
     *
     * @group Model
     */
    public function testRequestTypeFormatIsAccepted(): void
    {
        self::assertTrue($this->createSubject('cms-widget_page_link')->isTypeDeclared());
    }

    /**
     * @dataProvider provideInvalidPageGroupData
     * @group Model
     */
    public function testInvalidPageGroupIsRejected(string $pageGroup, mixed $pageGroupData, string $message): void
    {
        $subject = $this->createSubject(self::DECLARED_TYPE);
        $subject->setPageGroups([['page_group' => $pageGroup, $pageGroup => $pageGroupData]]);

        $this->expectException(Mage_Core_Exception::class);
        $this->expectExceptionMessage($message);
        $this->beforeSave($subject);
    }

    /**
     * Generated XML for a declared type keeps its shape, and every interpolated value is escaped.
     *
     * @group Model
     */
    public function testGenerateLayoutUpdateXmlEscapesValues(): void
    {
        $subject = $this->createSubject(self::DECLARED_TYPE);
        $subject->setWidgetParameters([
            'anchor_text' => 'Say "hi" & <b>bye</b>',
            'title&x'     => 'value',
            'template'    => 'ignored.phtml',
        ]);

        $xml = $subject->generateLayoutUpdateXml('content');
        $element = simplexml_load_string('<root>' . $xml . '</root>');

        self::assertNotFalse($element);
        self::assertCount(1, $element->xpath('/root/reference') ?? []);
        self::assertSame('content', (string) $element->reference['name']);
        self::assertCount(1, $element->xpath('//block') ?? []);
        self::assertSame(self::DECLARED_TYPE, (string) $element->reference->block['type']);
        self::assertNull($element->reference->block['template']);

        $actions = [];
        foreach ($element->reference->block->action as $action) {
            self::assertSame('setData', (string) $action['method']);
            $actions[(string) $action->name] = (string) $action->value;
        }

        self::assertSame(['anchor_text' => 'Say "hi" & <b>bye</b>', 'title&x' => 'value'], $actions);
    }

    /**
     * @group Model
     */
    public function testGenerateLayoutUpdateXmlRejectsSpecialCharacters(): void
    {
        $subject = $this->createSubject(self::DECLARED_TYPE);

        $this->expectException(Mage_Core_Exception::class);
        $subject->generateLayoutUpdateXml('content"');
    }

    private function createSubject(string $type): Subject
    {
        /** @var Subject $subject */
        $subject = Mage::getModel('widget/widget_instance');
        $subject->setType($type)->setPackageTheme('base/default');

        return $subject;
    }

    private function beforeSave(Subject $subject): void
    {
        (new ReflectionMethod($subject, '_beforeSave'))->invoke($subject);
    }
}
