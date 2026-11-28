<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Catalog\Model\Product\Attribute\Set;

use Generator;
use Closure;
use Mage_Api_Exception;
use Mage_Catalog_Model_Product_Attribute_Group;
use Mage_Catalog_Model_Product_Attribute_Set_Api as Subject;
use Mage_Eav_Model_Entity_Attribute;
use Mage_Eav_Model_Entity_Attribute_Set;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Catalog\Model\Product\Attribute\Set\ApiTrait;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * The product attribute set API changes product attribute sets only.
 *
 * Product sets 4 (default) and 9, category set 3; group 10 is in set 9, group 30 in set 3;
 * attribute 100 is a product attribute, attribute 300 a category attribute.
 */
final class ApiTest extends OpenMageTest
{
    use ApiTrait;

    private const PRODUCT_TYPE = 4;

    private const CATEGORY_TYPE = 3;

    private const SETS = [4 => self::PRODUCT_TYPE, 9 => self::PRODUCT_TYPE, 3 => self::CATEGORY_TYPE];

    private const GROUPS = [10 => 9, 30 => 3];

    private const ATTRIBUTES = [100 => self::PRODUCT_TYPE, 300 => self::CATEGORY_TYPE];

    /**
     * @dataProvider provideRefusedCallData
     * @param Closure(Subject): mixed $call
     * @group Model
     */
    public function testRefusedCall(Closure $call, string $expectedFault): void
    {
        $this->expectException(Mage_Api_Exception::class);
        $this->expectExceptionMessage($expectedFault);

        $call($this->createSubject());
    }

    /**
     * @return Generator<string, array<mixed>>
     */
    public static function provideRefusedCallData(): Generator
    {
        yield 'create from a category set' => [static fn(Subject $api): mixed => $api->create('Shoes', '3'), 'invalid_skeleton_set_id'];
        yield 'create from a missing set' => [static fn(Subject $api): mixed => $api->create('Shoes', '99'), 'invalid_skeleton_set_id'];
        yield 'remove a category set' => [static fn(Subject $api): mixed => $api->remove('3', true), 'invalid_attribute_set_id'];
        yield 'remove the default product set' => [static fn(Subject $api): mixed => $api->remove('4', true), 'remove_attribute_set_error'];
        yield 'add a category attribute' => [static fn(Subject $api): mixed => $api->attributeAdd('300', '9'), 'invalid_attribute_id'];
        yield 'add an attribute to a category set' => [static fn(Subject $api): mixed => $api->attributeAdd('100', '3'), 'invalid_attribute_set_id'];
        yield 'add an attribute to a group of another set' => [static fn(Subject $api): mixed => $api->attributeAdd('100', '9', '30'), 'invalid_attribute_group_id'];
        yield 'add an attribute to a group of another product set' => [static fn(Subject $api): mixed => $api->attributeAdd('100', '4', '10'), 'invalid_attribute_group_id'];
        yield 'remove an attribute from a category set' => [static fn(Subject $api): mixed => $api->attributeRemove('100', '3'), 'invalid_attribute_set_id'];
        yield 'remove a category attribute' => [static fn(Subject $api): mixed => $api->attributeRemove('300', '9'), 'invalid_attribute_id'];
        yield 'add a group to a category set' => [static fn(Subject $api): mixed => $api->groupAdd('3', 'General'), 'invalid_attribute_set_id'];
        yield 'add a group to a missing set' => [static fn(Subject $api): mixed => $api->groupAdd('99', 'General'), 'invalid_attribute_set_id'];
        yield 'remove a group of a category set' => [static fn(Subject $api): mixed => $api->groupRemove('30'), 'invalid_attribute_group_id'];
        yield 'remove a missing group' => [static fn(Subject $api): mixed => $api->groupRemove('99'), 'invalid_attribute_group_id'];
        yield 'rename a group of a category set' => [static fn(Subject $api): mixed => $api->groupRename('30', 'General'), 'invalid_attribute_group_id'];
        yield 'rename a missing group' => [static fn(Subject $api): mixed => $api->groupRename('99', 'General'), 'invalid_attribute_group_id'];
        yield 'rename a group to quotes only' => [static fn(Subject $api): mixed => $api->groupRename('10', '"\'"'), 'invalid_data'];
    }

    /**
     * A renamed group name keeps no tags and no quotes, so it cannot end an HTML attribute it is printed in.
     *
     * @dataProvider provideGroupRenameData
     * @group Model
     */
    public function testGroupRename(string $name, string $expected): void
    {
        $group = $this->createPartialMock(Mage_Catalog_Model_Product_Attribute_Group::class, ['save']);
        $group->setIdFieldName('attribute_group_id');
        $group->setData(['attribute_group_id' => 10, 'attribute_set_id' => 9, 'attribute_group_name' => 'General']);
        $group->expects(self::once())->method('save')->willReturnSelf();

        $subject = $this->createSubject($group);

        self::assertTrue($subject->groupRename('10', $name));
        self::assertSame($expected, $group->getAttributeGroupName());
    }

    /**
     * @return Generator<string, array<string>>
     */
    public static function provideGroupRenameData(): Generator
    {
        yield 'plain name' => ['Prices', 'Prices'];
        yield 'attribute breakout' => ['General" onmouseover="alert(1)', 'General onmouseover=alert(1)'];
        yield 'single quoted breakout' => ["General' autofocus onfocus='alert(1)", 'General autofocus onfocus=alert(1)'];
        yield 'tags' => ['<img src=x onerror=alert(1)>Meta <b>Info</b>', 'Meta Info'];
        yield 'apostrophe' => ["Men's sizes", 'Mens sizes'];
    }

    /**
     * @param  null|Mage_Catalog_Model_Product_Attribute_Group $group group 10, if the test needs to inspect it
     * @return MockObject&Subject
     */
    private function createSubject(?Mage_Catalog_Model_Product_Attribute_Group $group = null): MockObject
    {
        $subject = $this->createPartialMock(Subject::class, [
            '_loadAttributeSet', '_loadAttributeGroup', '_loadAttribute',
            '_getProductEntityTypeId', '_getDefaultProductAttributeSetId',
        ]);
        $subject->method('_getProductEntityTypeId')->willReturn(self::PRODUCT_TYPE);
        $subject->method('_getDefaultProductAttributeSetId')->willReturn(4);
        $subject->method('_loadAttributeSet')->willReturnCallback(
            static fn(int $id): Mage_Eav_Model_Entity_Attribute_Set => new Mage_Eav_Model_Entity_Attribute_Set(
                isset(self::SETS[$id]) ? ['attribute_set_id' => $id, 'entity_type_id' => self::SETS[$id]] : [],
            ),
        );
        $subject->method('_loadAttributeGroup')->willReturnCallback(
            static function (int $id) use ($group): Mage_Catalog_Model_Product_Attribute_Group {
                if ($id === 10 && $group instanceof Mage_Catalog_Model_Product_Attribute_Group) {
                    return $group;
                }

                return new Mage_Catalog_Model_Product_Attribute_Group(
                    isset(self::GROUPS[$id]) ? ['attribute_group_id' => $id, 'attribute_set_id' => self::GROUPS[$id], 'attribute_group_name' => 'General'] : [],
                );
            },
        );
        $subject->method('_loadAttribute')->willReturnCallback(
            static fn(int $id): Mage_Eav_Model_Entity_Attribute => new Mage_Eav_Model_Entity_Attribute(
                isset(self::ATTRIBUTES[$id]) ? ['attribute_id' => $id, 'entity_type_id' => self::ATTRIBUTES[$id]] : [],
            ),
        );

        return $subject;
    }
}
