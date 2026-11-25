<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Widget\Model\Widget;

use Generator;
use Mage;
use Mage_Core_Exception;
use Mage_Core_Model_Logger;
use Mage_Widget_Model_Widget_Instance as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Widget\Model\Widget\InstanceTrait;
use ReflectionMethod;

final class InstanceTest extends OpenMageTest
{
    use InstanceTrait;

    private const DECLARED_TYPE = 'cms/widget_page_link';

    private const LOGGER_KEY = '_singleton/core/logger';

    protected function tearDown(): void
    {
        Mage::unregister(self::LOGGER_KEY);
        parent::tearDown();
    }

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

    /**
     * @dataProvider provideStoredLayoutUpdateXmlData
     * @group Model
     */
    public function testIsGeneratedLayoutUpdateXml(string $type, string $xml, bool $expected): void
    {
        self::assertSame($expected, $this->createSubject($type)->isGeneratedLayoutUpdateXml($xml));
    }

    /**
     * What generateLayoutUpdateXml() produces is always recognised, so the upgrade cleanup keeps it.
     *
     * @group Model
     */
    public function testGeneratedLayoutUpdateXmlIsRecognised(): void
    {
        $subject = $this->createSubject(self::DECLARED_TYPE);
        $subject->setWidgetParameters(['anchor_text' => 'Say "hi" & <b>bye</b>', 'title&x' => 'value', 'page_id' => ['1', '2']]);

        self::assertTrue($subject->isGeneratedLayoutUpdateXml($subject->generateLayoutUpdateXml('content')));
    }

    /**
     * Parameter values are escaped as XML, so what the generator writes is well-formed, recognised, and gives the block the value back.
     *
     * @dataProvider provideWidgetParameterValueData
     * @group Model
     */
    public function testGeneratedLayoutUpdateXmlRoundTrips(string $value, string $expected): void
    {
        $subject = $this->createSubject(self::DECLARED_TYPE);
        $subject->setWidgetParameters(['anchor_text' => $value]);

        $xml = $subject->generateLayoutUpdateXml('content');
        $element = simplexml_load_string('<root>' . $xml . '</root>');

        self::assertNotFalse($element, $xml);
        self::assertSame($expected, (string) $element->reference->block->action->value);
        self::assertTrue($subject->isGeneratedLayoutUpdateXml($xml));
        self::assertSame(self::DECLARED_TYPE, $subject->getGeneratedLayoutUpdateBlockType($xml));
    }

    /**
     * @dataProvider provideLayoutUpdateRemovalReasonData
     * @group Model
     */
    public function testGetLayoutUpdateRemovalReason(string $type, string $xml, ?string $expected): void
    {
        self::assertSame($expected, $this->createSubject($type)->getLayoutUpdateRemovalReason($xml));
    }

    /**
     * The block type is read from the XML, whatever the instance's own type is.
     *
     * @group Model
     */
    public function testGetGeneratedLayoutUpdateBlockType(): void
    {
        $subject = $this->createSubject(self::DECLARED_TYPE);

        self::assertSame('core/text_list', $subject->getGeneratedLayoutUpdateBlockType('<reference name="content"><block type="core/text_list" name="abc"/></reference>'));
        self::assertNull($subject->getGeneratedLayoutUpdateBlockType(''));
        self::assertNull($subject->getGeneratedLayoutUpdateBlockType('<remove name="content"/>'));
    }

    /**
     * Generating nothing for an incomplete or undeclared instance is not logged; only the refused save is, once.
     *
     * @group Model
     */
    public function testGenerateLayoutUpdateXmlDoesNotLog(): void
    {
        $logger = $this->createMock(Mage_Core_Model_Logger::class);
        $logger->expects(self::never())->method('log');
        Mage::unregister(self::LOGGER_KEY);
        Mage::register(self::LOGGER_KEY, $logger);

        /** @var Subject $incomplete */
        $incomplete = Mage::getModel('widget/widget_instance');
        self::assertSame('', $incomplete->generateLayoutUpdateXml('content'));

        $undeclared = $this->createSubject('core/text_list');
        $undeclared->setId(12);
        self::assertSame('', $undeclared->generateLayoutUpdateXml('content'));
    }

    /**
     * @group Model
     */
    public function testRefusedSaveIsLoggedOnce(): void
    {
        $logger = $this->createMock(Mage_Core_Model_Logger::class);
        $logger->expects(self::once())->method('log');
        Mage::unregister(self::LOGGER_KEY);
        Mage::register(self::LOGGER_KEY, $logger);

        $subject = $this->createSubject('core/text_list');
        $subject->setPageGroups([['page_group' => 'all_pages', 'all_pages' => ['page_id' => 0, 'for' => 'all', 'block' => 'content', 'template' => '']]]);

        $this->expectException(Mage_Core_Exception::class);
        $this->beforeSave($subject);
    }

    /**
     * A page group code that is not a string is rejected, not used as an array offset.
     *
     * @group Model
     */
    public function testPageGroupCodeMustBeAString(): void
    {
        $subject = $this->createSubject(self::DECLARED_TYPE);
        $subject->setPageGroups([['page_group' => ['all_pages']]]);

        $this->expectException(Mage_Core_Exception::class);
        $this->expectExceptionMessage('Wrong widget display option specified.');
        $this->beforeSave($subject);
    }

    /**
     * @group Model
     */
    public function testValidatePageGroupSkipsEntriesWithoutData(): void
    {
        $subject = $this->createSubject(self::DECLARED_TYPE);
        $data = ['page_id' => 0, 'for' => 'all', 'block' => 'content', 'template' => ''];

        self::assertNull($subject->validatePageGroup('all_pages'));
        self::assertNull($subject->validatePageGroup(['all_pages' => $data]));
        self::assertNull($subject->validatePageGroup(['page_group' => 'all_pages']));
        self::assertSame($data, $subject->validatePageGroup(['page_group' => 'all_pages', 'all_pages' => $data]));
    }

    /**
     * @dataProvider provideEntityIdsData
     * @group Model
     */
    public function testNormalizeEntityIds(mixed $entities, ?string $expected): void
    {
        self::assertSame($expected, $this->createSubject(self::DECLARED_TYPE)->normalizeEntityIds($entities));
    }

    /**
     * @return Generator<string, array{mixed, null|string}>
     */
    public static function provideEntityIdsData(): Generator
    {
        yield 'single id' => ['12', '12'];
        yield 'list posted by the chooser' => ['12,7,1033', '12,7,1033'];
        yield 'whitespace around ids' => [' 12 , 7 ,1033 ', '12,7,1033'];
        yield 'integer' => [12, '12'];
        yield 'empty string' => ['', ''];
        yield 'whitespace only' => ['  ', ''];
        yield 'markup' => ['1"><img src=x onerror=alert(1)>', null];
        yield 'quote after id' => ['1"', null];
        yield 'trailing comma' => ['1,2,', null];
        yield 'empty item' => ['1,,2', null];
        yield 'negative id' => ['-1', null];
        yield 'decimal id' => ['1.5', null];
        yield 'trailing newline only after an id' => ["1\nx", null];
        yield 'negative integer' => [-1, null];
        yield 'array' => [['1'], null];
        yield 'null' => [null, null];
    }

    /**
     * @dataProvider provideInvalidEntitiesData
     * @group Model
     */
    public function testSpecificPageGroupWithInvalidEntitiesIsRejected(mixed $entities): void
    {
        $subject = $this->createSubject(self::DECLARED_TYPE);
        $subject->setPageGroups([$this->createSpecificProductsPageGroup($entities)]);

        $this->expectException(Mage_Core_Exception::class);
        $this->expectExceptionMessage('Wrong widget display option specified.');
        $this->beforeSave($subject);
    }

    /**
     * @return Generator<string, array{mixed}>
     */
    public static function provideInvalidEntitiesData(): Generator
    {
        yield 'markup' => ['1"><img src=x onerror=alert(1)>'];
        yield 'quote' => ['1"'];
        yield 'array' => [['1']];
    }

    /**
     * The controller pre-check and _beforeSave() share validatePageGroup(), which returns the normalised ids.
     *
     * @group Model
     */
    public function testValidatePageGroupNormalizesEntities(): void
    {
        $subject = $this->createSubject(self::DECLARED_TYPE);
        $pageGroup = $this->createSpecificProductsPageGroup(' 5, 6 ');

        self::assertSame('5,6', $subject->validatePageGroup($pageGroup)['entities'] ?? null);
    }

    /**
     * @group Model
     */
    public function testSpecificPageGroupStoresNormalizedEntities(): void
    {
        $subject = $this->createSubject(self::DECLARED_TYPE);
        $subject->setPageGroups([$this->createSpecificProductsPageGroup(' 5, 6 ')]);

        $this->beforeSave($subject);

        $pageGroups = $subject->getData('page_groups');
        self::assertSame('5,6', $pageGroups[0]['entities']);
        self::assertSame(['PRODUCT_5', 'PRODUCT_6'], $pageGroups[0]['layout_handle_updates']);
    }

    /**
     * @return array<string, mixed>
     */
    private function createSpecificProductsPageGroup(mixed $entities): array
    {
        return [
            'page_group'   => 'all_products',
            'all_products' => [
                'page_id'  => 0,
                'for'      => 'specific',
                'entities' => $entities,
                'block'    => 'content',
                'template' => '',
            ],
        ];
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
