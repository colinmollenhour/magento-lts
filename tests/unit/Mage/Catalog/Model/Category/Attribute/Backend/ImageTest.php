<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Catalog\Model\Category\Attribute\Backend;

use Generator;
use Mage;
use Mage_Catalog_Model_Category;
use Mage_Catalog_Model_Category_Attribute_Backend_Image as Subject;
use Mage_Core_Exception;
use Mage_Eav_Model_Entity_Attribute;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Catalog\Model\Category\Attribute\Backend\ImageTrait;

final class ImageTest extends OpenMageTest
{
    use ImageTrait;

    /**
     * @dataProvider provideFileNameData
     * @group Model
     */
    public function testIsValidFileName(mixed $value, bool $expected): void
    {
        self::assertSame($expected, $this->createSubject()->isValidFileName($value));
    }

    /**
     * @return Generator<string, array{mixed, bool}>
     */
    public static function provideFileNameData(): Generator
    {
        yield 'uploaded file name' => ['shoes_1.jpg', true];
        yield 'imported name with spaces and mixed case' => ['Summer Sale Banner.JPG', true];
        yield 'unicode name' => ['été.png', true];
        yield 'ampersand' => ['a&b.png', true];
        yield 'double quote' => ['x" onerror="alert(1)', false];
        yield 'single quote' => ["x' onerror='alert(1)", false];
        yield 'angle brackets' => ['<script>.png', false];
        yield 'slash' => ['../../../app/etc/local.xml', false];
        yield 'backslash' => ['..\\local.xml', false];
        yield 'newline' => ["x.png\n", false];
        yield 'NUL byte' => ["x.png\0.php", false];
        yield 'dot' => ['.', false];
        yield 'dot dot' => ['..', false];
        yield 'empty string' => ['', false];
        yield 'array' => [['value' => 'x.png'], false];
    }

    /**
     * Valid values, values from the upload form (arrays) and values unchanged since load are saved as before;
     * a new string value that is not a bare file name is refused, so the API returns a data_invalid fault.
     *
     * @group Model
     */
    public function testBeforeSaveChecksNewStringValues(): void
    {
        $subject = $this->createSubject();

        $category = $this->createCategory('old.jpg');
        $category->setData('image', 'Summer Sale Banner.JPG');
        self::assertSame($subject, $subject->beforeSave($category));

        $category = $this->createCategory('legacy/path.jpg');
        self::assertSame($subject, $subject->beforeSave($category));

        $category = $this->createCategory('old.jpg');
        $category->setData('image', ['value' => 'old.jpg', 'delete' => '1']);
        self::assertSame($subject, $subject->beforeSave($category));

        $category = $this->createCategory('old.jpg');
        $category->setData('image', '');
        self::assertSame($subject, $subject->beforeSave($category));

        $category = $this->createCategory('old.jpg');
        $category->setData('image', 'x" onerror="alert(1)');

        $this->expectException(Mage_Core_Exception::class);
        $subject->beforeSave($category);
    }

    /**
     * @group Model
     */
    public function testBeforeSaveRejectsInvalidValueOnNewCategory(): void
    {
        /** @var Mage_Catalog_Model_Category $category */
        $category = Mage::getModel('catalog/category');
        $category->setData('image', '../x.jpg');

        $this->expectException(Mage_Core_Exception::class);
        $this->createSubject()->beforeSave($category);
    }

    private function createSubject(): Subject
    {
        $attribute = new Mage_Eav_Model_Entity_Attribute();
        $attribute->setAttributeCode('image');

        $subject = new Subject();
        $subject->setAttribute($attribute);

        return $subject;
    }

    /**
     * A category as loaded from the database, with the given stored image value
     */
    private function createCategory(string $image): Mage_Catalog_Model_Category
    {
        /** @var Mage_Catalog_Model_Category $category */
        $category = Mage::getModel('catalog/category');
        $category->setData(['entity_id' => 5, 'image' => $image]);
        $category->setOrigData();

        return $category;
    }
}
