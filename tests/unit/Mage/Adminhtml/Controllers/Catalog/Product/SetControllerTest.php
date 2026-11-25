<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Adminhtml\Controllers\Catalog\Product;

use Generator;
use Mage_Adminhtml_Catalog_Product_SetController as Subject;
use Mage_Adminhtml_Model_Session;
use Mage_Core_Controller_Request_Http;
use Mage_Core_Exception;
use Mage_Eav_Model_Entity_Attribute_Set;
use OpenMage\Tests\Unit\OpenMageTest;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The product attribute set editor handles product sets only, and only the groups and attributes of the set it saves.
 */
final class SetControllerTest extends OpenMageTest
{
    private const PRODUCT_TYPE = 4;

    private const CATEGORY_TYPE = 3;

    /**
     * @dataProvider provideInitAttributeSetData
     * @group Controller
     */
    public function testInitAttributeSet(mixed $setId, ?int $entityTypeId, bool $expected): void
    {
        $subject = $this->createSubject([]);
        $subject->method('_loadAttributeSet')->willReturnCallback(
            static fn(int $id): Mage_Eav_Model_Entity_Attribute_Set => new Mage_Eav_Model_Entity_Attribute_Set(
                $entityTypeId === null ? [] : ['attribute_set_id' => $id, 'entity_type_id' => $entityTypeId],
            ),
        );

        $attributeSet = (new ReflectionMethod($subject, '_initAttributeSet'))->invoke($subject, $setId);

        self::assertSame($expected, $attributeSet !== null);
    }

    /**
     * @return Generator<string, array<mixed>>
     */
    public static function provideInitAttributeSetData(): Generator
    {
        yield 'product set' => ['9', self::PRODUCT_TYPE, true];
        yield 'category set' => ['3', self::CATEGORY_TYPE, false];
        yield 'missing set' => ['99', null, false];
        yield 'no id' => [null, self::PRODUCT_TYPE, false];
        yield 'zero' => ['0', self::PRODUCT_TYPE, false];
        yield 'array' => [['9'], self::PRODUCT_TYPE, false];
    }

    /**
     * Set 9 has groups 10 and 11 and attribute assignments 500 and 501; product attributes are 100 and 101.
     *
     * @dataProvider provideFilterAttributeSetData
     * @param array<string, mixed>      $data
     * @param null|array<string, mixed> $expected null when the data is refused
     * @group Controller
     */
    public function testFilterAttributeSetData(array $data, ?array $expected): void
    {
        $subject = $this->createSubject([]);
        $attributeSet = new Mage_Eav_Model_Entity_Attribute_Set(['attribute_set_id' => 9, 'entity_type_id' => self::PRODUCT_TYPE]);

        if ($expected === null) {
            $this->expectException(Mage_Core_Exception::class);
        }

        $filtered = (new ReflectionMethod($subject, '_filterAttributeSetData'))->invoke($subject, $attributeSet, $data);

        self::assertSame($expected, $filtered);
    }

    /**
     * @return Generator<string, array<mixed>>
     */
    public static function provideFilterAttributeSetData(): Generator
    {
        // What the editor posts: sparse arrays come back with null entries
        $editor = [
            'attribute_set_name' => 'Shoes',
            'groups' => [['10', 'General', 1], ['ynode-7', 'New group', 2]],
            'attributes' => [null, ['100', '10', 1, '500'], ['101', 'ynode-7', 1, null]],
            'not_attributes' => [null, '501'],
            'removeGroups' => [null, '11'],
        ];
        yield 'editor post kept' => [$editor, [...$editor, 'groups' => [[10, 'General', 1], ['ynode-7', 'New group', 2]]]];
        yield 'nothing changed' => [
            ['attribute_set_name' => 'Shoes', 'groups' => [], 'attributes' => false, 'not_attributes' => false, 'removeGroups' => []],
            ['attribute_set_name' => 'Shoes', 'groups' => [], 'attributes' => false, 'not_attributes' => false, 'removeGroups' => []],
        ];
        yield 'group name stripped of tags' => [
            [...$editor, 'groups' => [['10', '<img src=x onerror=alert(1)>General', 1]]],
            [...$editor, 'groups' => [[10, 'General', 1]]],
        ];

        yield 'group of another set refused' => [[...$editor, 'groups' => [['12', 'General', 1]]], null];
        yield 'removed group of another set refused' => [[...$editor, 'removeGroups' => ['12']], null];
        yield 'removed attribute of another set refused' => [[...$editor, 'not_attributes' => ['502']], null];
        yield 'attribute of another entity type refused' => [[...$editor, 'attributes' => [['102', '10', 1, null]]], null];
        yield 'malformed group refused' => [[...$editor, 'groups' => ['10']], null];
        yield 'malformed groups refused' => [[...$editor, 'groups' => '10'], null];
    }

    /**
     * Neither the default set nor a set of another entity type is deleted.
     *
     * @dataProvider provideDeleteRefusedData
     * @group Controller
     */
    public function testDeleteRefused(int $entityTypeId): void
    {
        $attributeSet = $this->createPartialMock(Mage_Eav_Model_Entity_Attribute_Set::class, ['delete']);
        $attributeSet->setData(['attribute_set_id' => 4, 'entity_type_id' => $entityTypeId]);
        $attributeSet->expects(self::never())->method('delete');

        $session = $this->createPartialMock(Mage_Adminhtml_Model_Session::class, ['addError', 'addSuccess']);
        $session->expects(self::once())->method('addError');
        $session->expects(self::never())->method('addSuccess');

        $subject = $this->createSubject(['_getSession', '_redirectReferer']);
        $subject->method('_loadAttributeSet')->willReturn($attributeSet);
        $subject->method('_getSession')->willReturn($session);
        $subject->getRequest()->setActionName('delete')->setParam('id', '4');

        $subject->deleteAction();
    }

    /**
     * @return Generator<string, array<int>>
     */
    public static function provideDeleteRefusedData(): Generator
    {
        yield 'product default set' => [self::PRODUCT_TYPE];
        yield 'set of another entity type' => [self::CATEGORY_TYPE];
    }

    /**
     * @param  string[]           $extraMethods
     * @return MockObject&Subject
     */
    private function createSubject(array $extraMethods): MockObject
    {
        $subject = $this->createPartialMock(Subject::class, [
            '_getEntityTypeId', '_getDefaultAttributeSetId', '_loadAttributeSet',
            '_getAttributeGroupIds', '_getEntityTypeAttributeIds', '_getEntityAttributeIds',
            ...$extraMethods,
        ]);
        $subject->method('_getEntityTypeId')->willReturn((string) self::PRODUCT_TYPE);
        $subject->method('_getDefaultAttributeSetId')->willReturn(4);
        $subject->method('_getAttributeGroupIds')->willReturnCallback(static fn(int $setId): array => $setId === 9 ? [10, 11] : []);
        $subject->method('_getEntityTypeAttributeIds')->willReturnCallback(
            static fn(int $typeId): array => $typeId === self::PRODUCT_TYPE ? [100, 101] : [102],
        );
        $subject->method('_getEntityAttributeIds')->willReturnCallback(static fn(int $setId): array => $setId === 9 ? [500, 501] : [502]);
        (new ReflectionProperty($subject, '_request'))->setValue($subject, new Mage_Core_Controller_Request_Http());

        return $subject;
    }
}
