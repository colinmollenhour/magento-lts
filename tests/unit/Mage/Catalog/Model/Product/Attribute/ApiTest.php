<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Catalog\Model\Product\Attribute;

use Iterator;
use Mage;
use Mage_Api_Exception;
use Mage_Catalog_Model_Product_Attribute_Api as Subject;
use Mage_Core_Model_Config;
use Mage_Core_Model_Config_Element;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Catalog\Model\Product\Attribute\ApiTrait;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionProperty;

/**
 * create() and update() take only attribute properties from the caller, at the top level of the input and in
 * additional_fields, never the attribute code, id, entity type, backend type or models
 */
final class ApiTest extends OpenMageTest
{
    use ApiTrait;

    private const ENTITY_TYPE_ID = 4;

    private const STORED_ATTRIBUTE_ID = 135;

    /**
     * Keys a caller tries to set, with values the attribute must not end up with
     */
    private const PROTECTED_INPUT = [
        'attribute_code' => 'sku` = 1 OR 1=1) -- ',
        'attribute_id' => 12,
        'attribute_model' => 'core/session',
        'backend_model' => 'core/session',
        'backend_table' => 'admin_user',
        'backend_type' => 'static',
        'entity_type_id' => 1,
        'frontend_input' => 'gallery',
        'frontend_input_renderer' => 'core/text',
        'frontend_model' => 'core/session',
        'is_user_defined' => 0,
        'source_model' => 'core/session',
        'option' => ['value' => ['option_1' => ['<b>x</b>']]],
    ];

    /**
     * Where Mage::getModel('catalog/resource_eav_attribute') looks up its class name
     */
    private const MODEL_CLASS_CACHE = ['global/models', 'catalog', 'resource_eav_attribute'];

    private ?string $previousModelClass = null;

    protected function setUp(): void
    {
        parent::setUp();
        ApiTestFixture::$saved = null;
        [$root, $group, $class] = self::MODEL_CLASS_CACHE;
        $cache = $this->getClassNameCache();
        $this->previousModelClass = $cache[$root][$group][$class] ?? null;
        $cache[$root][$group][$class] = ApiTestFixture::class;
        $this->setClassNameCache($cache);
    }

    protected function tearDown(): void
    {
        [$root, $group, $class] = self::MODEL_CLASS_CACHE;
        $cache = $this->getClassNameCache();
        if ($this->previousModelClass === null) {
            unset($cache[$root][$group][$class]);
        } else {
            $cache[$root][$group][$class] = $this->previousModelClass;
        }

        $this->setClassNameCache($cache);
        $attributeNode = Mage::app()->getConfig()->getNode('global/catalog/product/attribute');
        if ($attributeNode instanceof Mage_Core_Model_Config_Element) {
            unset($attributeNode->api);
        }

        parent::tearDown();
    }

    /**
     * @dataProvider provideProtectedInputPlacement
     * @group Model
     */
    public function testCreateIgnoresProtectedKeys(bool $inAdditionalFields): void
    {
        $data = $this->getCreateInput();
        if ($inAdditionalFields) {
            $data['additional_fields'] += self::PROTECTED_INPUT;
        } else {
            $data += self::PROTECTED_INPUT;
        }

        self::assertSame(ApiTestFixture::NEW_ATTRIBUTE_ID, $this->getSubject()->create($data));

        $saved = ApiTestFixture::$saved;
        self::assertIsArray($saved);
        self::assertSame(ApiTestFixture::NEW_ATTRIBUTE_ID, $saved['attribute_id']);
        self::assertSame('valid_code', $saved['attribute_code']);
        self::assertSame('text', $saved['frontend_input']);
        self::assertSame('varchar', $saved['backend_type']);
        self::assertSame(self::ENTITY_TYPE_ID, $saved['entity_type_id']);
        self::assertSame(1, $saved['is_user_defined']);
        self::assertNull($saved['backend_model']);
        self::assertNull($saved['source_model']);
        self::assertArrayNotHasKey('attribute_model', $saved);
        self::assertArrayNotHasKey('backend_table', $saved);
        self::assertArrayNotHasKey('frontend_input_renderer', $saved);
        self::assertArrayNotHasKey('frontend_model', $saved);
        self::assertArrayNotHasKey('option', $saved);
        self::assertSame([0 => 'Valid label'], $saved['frontend_label']);
        self::assertSame(1, $saved['is_searchable']);
        self::assertSame(1, $saved['is_visible_in_advanced_search']);
    }

    /**
     * @dataProvider provideProtectedInputPlacement
     * @group Model
     */
    public function testUpdateIgnoresProtectedKeys(bool $inAdditionalFields): void
    {
        $data = [
            'scope' => 'global',
            'frontend_label' => [['store_id' => 0, 'label' => 'New label']],
            'additional_fields' => ['is_visible_in_advanced_search' => 1],
        ];
        if ($inAdditionalFields) {
            $data['additional_fields'] += self::PROTECTED_INPUT;
        } else {
            $data += self::PROTECTED_INPUT;
        }

        self::assertTrue($this->getSubject($this->getStoredAttribute())->update('stored_code', $data));

        $saved = ApiTestFixture::$saved;
        self::assertIsArray($saved);
        self::assertSame(self::STORED_ATTRIBUTE_ID, $saved['attribute_id']);
        self::assertSame('stored_code', $saved['attribute_code']);
        self::assertSame('select', $saved['frontend_input']);
        self::assertSame('int', $saved['backend_type']);
        self::assertSame(self::ENTITY_TYPE_ID, $saved['entity_type_id']);
        self::assertSame(1, $saved['is_user_defined']);
        self::assertSame('eav/entity_attribute_source_table', $saved['source_model']);
        self::assertNull($saved['backend_model']);
        self::assertNull($saved['backend_table']);
        self::assertNull($saved['frontend_model']);
        self::assertArrayNotHasKey('attribute_model', $saved);
        self::assertArrayNotHasKey('frontend_input_renderer', $saved);
        self::assertArrayNotHasKey('option', $saved);
        self::assertSame([0 => 'New label'], $saved['frontend_label']);
        self::assertSame(1, $saved['is_visible_in_advanced_search']);
    }

    public static function provideProtectedInputPlacement(): Iterator
    {
        yield 'in additional_fields' => [true];
        yield 'at the top level' => [false];
    }

    /**
     * @dataProvider provideInvalidCode
     * @group Model
     */
    public function testCreateRejectsInvalidCode(mixed $code): void
    {
        $data = $this->getCreateInput();
        $data['attribute_code'] = $code;

        try {
            $this->getSubject()->create($data);
            self::fail('The code was accepted.');
        } catch (Mage_Api_Exception $mageApiException) {
            self::assertSame('invalid_code', $mageApiException->getMessage());
        }

        self::assertNull(ApiTestFixture::$saved);
    }

    public static function provideInvalidCode(): Iterator
    {
        yield 'trailing newline' => ["valid_code\n"];
        yield 'uppercase' => ['Valid_code'];
        yield 'backtick' => ['valid`code'];
        yield 'non-string' => [['valid_code']];
    }

    /**
     * Extensions can allow their own properties in config, but not the protected ones
     *
     * @group Model
     */
    public function testConfigAllowsExtraPropertiesButNotProtectedOnes(): void
    {
        $config = Mage::app()->getConfig();
        foreach (['extension_flag', 'backend_model', 'attribute_code'] as $property) {
            $config->setNode(Subject::XML_PATH_ALLOWED_PROPERTIES . '/' . $property, '');
        }

        $data = $this->getCreateInput();
        $data['additional_fields'] += [
            'extension_flag' => 1,
            'backend_model' => 'core/session',
            'attribute_code' => 'Other code',
        ];

        $this->getSubject()->create($data);

        $saved = ApiTestFixture::$saved;
        self::assertIsArray($saved);
        self::assertSame(1, $saved['extension_flag']);
        self::assertNull($saved['backend_model']);
        self::assertSame('valid_code', $saved['attribute_code']);
    }

    /**
     * The frontend class is printed into the class attribute of form inputs
     *
     * @dataProvider provideFrontendClass
     * @group Model
     */
    public function testFrontendClassMustBeClassNames(string $frontendClass, bool $isValid): void
    {
        $data = $this->getCreateInput();
        $data['additional_fields']['frontend_class'] = $frontendClass;

        if (!$isValid) {
            $this->expectException(Mage_Api_Exception::class);
        }

        $this->getSubject()->create($data);

        $saved = ApiTestFixture::$saved;
        self::assertIsArray($saved);
        self::assertSame($frontendClass, $saved['frontend_class']);
    }

    public static function provideFrontendClass(): Iterator
    {
        yield 'none' => ['', true];
        yield 'one class' => ['validate-number', true];
        yield 'several classes' => ['validate-digits validate_zero-or-greater', true];
        yield 'attribute break-out' => ['validate-number" onfocus="alert(1)" autofocus="', false];
        yield 'tag break-out' => ['"><script>alert(1)</script>', false];
        yield 'line break' => ["validate-number\nonclick", false];
    }

    /**
     * @return array<string, array<int, array<string, int|string>>|array<string, int>|int|string>
     */
    /**
     * @return array{attribute_code: mixed, frontend_input: string, scope: string, is_searchable: int, frontend_label: list<array{store_id: int, label: string}>, additional_fields: array<string, mixed>}
     */
    private function getCreateInput(): array
    {
        return [
            'attribute_code' => 'valid_code',
            'frontend_input' => 'text',
            'scope' => 'global',
            'is_searchable' => 1,
            'frontend_label' => [['store_id' => 0, 'label' => '<b>Valid label</b>']],
            'additional_fields' => ['is_visible_in_advanced_search' => 1],
        ];
    }

    private function getStoredAttribute(): ApiTestFixture
    {
        $attribute = new ApiTestFixture();
        $attribute->setData([
            'attribute_id' => self::STORED_ATTRIBUTE_ID,
            'entity_type_id' => self::ENTITY_TYPE_ID,
            'attribute_code' => 'stored_code',
            'frontend_input' => 'select',
            'backend_type' => 'int',
            'backend_model' => null,
            'backend_table' => null,
            'frontend_model' => null,
            'source_model' => 'eav/entity_attribute_source_table',
            'is_user_defined' => 1,
        ]);

        return $attribute;
    }

    private function getSubject(?ApiTestFixture $stored = null): MockObject&Subject
    {
        $subject = $this->getMockBuilder(Subject::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['_getAttribute'])
            ->getMock();
        $subject->method('_getAttribute')->willReturn($stored ?? new ApiTestFixture());

        (new ReflectionProperty(Subject::class, '_entityTypeId'))->setValue($subject, self::ENTITY_TYPE_ID);

        return $subject;
    }

    /**
     * @return array<string, array<string, array<string, string>>>
     */
    private function getClassNameCache(): array
    {
        return (new ReflectionProperty(Mage_Core_Model_Config::class, '_classNameCache'))->getValue(Mage::app()->getConfig());
    }

    /**
     * @param array<string, array<string, array<string, string>>> $cache
     */
    private function setClassNameCache(array $cache): void
    {
        (new ReflectionProperty(Mage_Core_Model_Config::class, '_classNameCache'))->setValue(Mage::app()->getConfig(), $cache);
    }
}
