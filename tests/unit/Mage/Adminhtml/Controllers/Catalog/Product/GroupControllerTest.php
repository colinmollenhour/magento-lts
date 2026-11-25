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
use Mage_Adminhtml_Model_Session;
use Mage_Core_Controller_Request_Http;
use Mage_Eav_Model_Entity_Attribute_Group;
use OpenMage\Tests\Unit\OpenMageTest;
use ReflectionMethod;
use ReflectionProperty;

/**
 * The deprecated group action of the product attribute set editor adds groups to product sets only.
 *
 * Sets 4 and 9 are product sets, set 3 is the category set.
 */
final class GroupControllerTest extends OpenMageTest
{
    /**
     * The controller is deprecated (it is still routed), so it is named rather than imported
     */
    private const SUBJECT = 'Mage_Adminhtml_Catalog_Product_GroupController';

    private const PRODUCT_TYPE = 4;

    private const SETS = [4 => self::PRODUCT_TYPE, 9 => self::PRODUCT_TYPE, 3 => 3];

    /**
     * @dataProvider provideSaveData
     * @param null|string $expectedName name saved, null when the group is refused
     * @group Controller
     */
    public function testSave(string $setId, string $name, ?string $expectedName): void
    {
        $group = $this->createPartialMock(Mage_Eav_Model_Entity_Attribute_Group::class, ['itemExists', 'save']);
        $group->method('itemExists')->willReturn(false);
        $group->expects($expectedName === null ? self::never() : self::once())->method('save')->willReturnSelf();

        $session = $this->createPartialMock(Mage_Adminhtml_Model_Session::class, ['addError']);
        $session->expects($expectedName === null ? self::once() : self::never())->method('addError');

        $request = new Mage_Core_Controller_Request_Http();
        $request->setActionName('save')->setParams(['attribute_set_id' => $setId, 'attribute_group_name' => $name]);

        $subject = $this->createPartialMock(self::SUBJECT, ['_getAttributeSetEntityTypeId', '_getProductEntityTypeId', '_createGroupModel', '_getSession']);
        $subject->method('_getAttributeSetEntityTypeId')->willReturnCallback(static fn(int $id): ?int => self::SETS[$id] ?? null);
        $subject->method('_getProductEntityTypeId')->willReturn(self::PRODUCT_TYPE);
        $subject->method('_createGroupModel')->willReturn($group);
        $subject->method('_getSession')->willReturn($session);
        (new ReflectionProperty($subject, '_request'))->setValue($subject, $request);

        (new ReflectionMethod($subject, 'saveAction'))->invoke($subject);

        if ($expectedName !== null) {
            self::assertSame($expectedName, $group->getAttributeGroupName());
            self::assertSame((int) $setId, (int) $group->getAttributeSetId());
        }
    }

    /**
     * @return Generator<string, array<mixed>>
     */
    public static function provideSaveData(): Generator
    {
        yield 'product set' => ['9', 'Shoe sizes', 'Shoe sizes'];
        yield 'category set refused' => ['3', 'Shoe sizes', null];
        yield 'missing set refused' => ['99', 'Shoe sizes', null];
        yield 'no set refused' => ['', 'Shoe sizes', null];
        yield 'attribute breakout stripped of quotes' => ['9', 'General" onmouseover="alert(1)', 'General onmouseover=alert(1)'];
        yield 'single quoted breakout stripped of quotes' => ['9', "General' onfocus='alert(1)", 'General onfocus=alert(1)'];
        yield 'tags stripped' => ['9', '<img src=x onerror=alert(1)>Meta <b>Info</b>', 'Meta Info'];
        yield 'quotes only refused' => ['9', '"\'"', null];
    }
}
