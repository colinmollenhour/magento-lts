<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Catalog\Model\Resource\Eav;

use Iterator;
use Mage;
use Mage_Catalog_Model_Resource_Eav_Attribute as Subject;
use Mage_Core_Exception;
use Mage_Core_Model_Config;
use Mage_Eav_Model_Entity_Type;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Core\CoreTrait;
use OpenMage\Tests\Unit\OpenMageTest;
use ReflectionMethod;

final class AttributeTest extends OpenMageTest
{
    use CoreTrait;

    private const CUSTOM_ENTITY_TYPE = 'openmage_test_entity';

    private static Subject $subject;

    protected function setUp(): void
    {
        self::$subject = Mage::getModel('catalog/resource_eav_attribute');
    }

    /**
     * @dataProvider provideGetStoreId
     * @group Model
     */
    public function testGetStoreId(?int $expectedResult, null|int|string $withStoreId): void
    {
        if ($withStoreId) {
            self::$subject->setStoreId($withStoreId);
        }

        self::assertSame($expectedResult, self::$subject->getStoreId());
    }

    /**
     * The input validation class is printed as the CSS class of the product form input, so a save
     * (admin form or attribute API) may only set one of the classes the attribute form offers.
     *
     * @dataProvider provideValidateFrontendClassData
     * @group Model
     */
    public function testValidateFrontendClass(
        bool $expectedValid,
        mixed $frontendClass,
        ?string $storedClass = null,
        string $entityTypeCode = 'catalog_product',
        bool $updateMode = false,
    ): void {
        $config = Mage::getConfig();
        self::assertInstanceOf(Mage_Core_Model_Config::class, $config);
        $config->setNode('global/eav_frontendclasses/' . self::CUSTOM_ENTITY_TYPE . '/custom/value', 'validate-custom-class');
        $config->setNode('global/eav_frontendclasses/' . self::CUSTOM_ENTITY_TYPE . '/custom/label', 'Custom');

        $entityType = $this->createMock(Mage_Eav_Model_Entity_Type::class);
        $entityType->method('getEntityTypeCode')->willReturn($entityTypeCode);

        $subject = $this->createPartialMock(Subject::class, ['getEntityType']);
        $subject->method('getEntityType')->willReturn($entityType);
        $subject->setEntityTypeId(4);
        if ($storedClass !== null) {
            $subject->setOrigData('frontend_class', $storedClass);
        }

        $subject->setData('frontend_class', $frontendClass);

        $method = new ReflectionMethod($subject, '_validateFrontendClass');
        $wasUpdateMode = Mage::app()->getUpdateMode();
        Mage::app()->setUpdateMode($updateMode);
        try {
            if (!$expectedValid) {
                $this->expectException(Mage_Core_Exception::class);
            }

            self::assertSame($subject, $method->invoke($subject));
        } finally {
            Mage::app()->setUpdateMode($wasUpdateMode);
        }
    }

    /**
     * @return Iterator<string, array{0: bool, 1: mixed, 2?: null|string, 3?: string, 4?: bool}>
     */
    public static function provideValidateFrontendClassData(): Iterator
    {
        $payload = 'validate-digits" onfocus="alert(1)" autofocus="';
        yield 'not posted' => [true, null];
        yield 'none' => [true, ''];
        yield 'offered class' => [true, 'validate-digits'];
        yield 'markup' => [false, $payload];
        yield 'class the form does not offer' => [false, 'validate-special-price'];
        yield 'not a string' => [false, ['validate-digits']];
        yield 'stored value kept on re-save' => [true, 'validate-special-price', 'validate-special-price'];
        yield 'stored value replaced with markup' => [false, $payload, 'validate-digits'];
        yield 'class registered for the entity type in config.xml' => [true, 'validate-custom-class', null, self::CUSTOM_ENTITY_TYPE];
        yield 'class registered for another entity type' => [false, 'validate-custom-class'];
        yield 'setup scripts' => [true, 'validate-length maximum-length-255', null, 'catalog_product', true];
    }
}
