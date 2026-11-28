<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\CatalogSearch\Model\Resource\Advanced;

use Iterator;
use Mage_CatalogSearch_Model_Resource_Advanced_Collection as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\CatalogSearch\Model\Resource\Advanced\CollectionTrait;
use Zend_Db_Expr;

/**
 * The advanced search uses the code of a static attribute as a column name: it must reach SQL quoted
 */
final class CollectionTest extends OpenMageTest
{
    use CollectionTrait;

    /**
     * @dataProvider provideStaticAttributeCode
     * @group Model
     */
    public function testAddFieldsToFilterQuotesStaticAttributeColumn(string $attributeCode, string $expectedCondition): void
    {
        $filter = null;
        $subject = $this->getMockBuilder(Subject::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getConnection', 'addFieldToFilter'])
            ->getMock();
        $subject->method('getConnection')->willReturn(new CollectionTestFixture(CollectionTestFixture::CONFIG));
        $subject->method('addFieldToFilter')->willReturnCallback(
            function (string $field, array $condition) use ($subject, &$filter): Subject {
                self::assertSame('entity_id', $field);
                $filter = $condition;
                return $subject;
            },
        );

        $subject->addFieldsToFilter(['catalog_product_entity' => [$attributeCode => ['like' => '%term%']]]);

        self::assertIsArray($filter);
        self::assertInstanceOf(Zend_Db_Expr::class, $filter['in']);
        $sql = (string) $filter['in'];
        self::assertStringContainsString('WHERE (' . $expectedCondition . ')', $sql);
    }

    public static function provideStaticAttributeCode(): Iterator
    {
        yield 'plain code' => ['sku', "`t1`.`sku` LIKE '%term%'"];
        yield 'backtick' => ['sku` = 1 OR 1=1 OR `sku', "`t1`.`sku`` = 1 OR 1=1 OR ``sku` LIKE '%term%'"];
        yield 'comment' => ['sku/**/OR/**/1=1', "`t1`.`sku/**/OR/**/1=1` LIKE '%term%'"];
        yield 'dot' => ['entity_id.sku', "`t1`.`entity_id.sku` LIKE '%term%'"];
    }
}
