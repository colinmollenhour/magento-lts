<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Adminhtml\Model\Config;

use Iterator;
use Mage_Adminhtml_Model_Config_Data as Subject;
use Mage_Core_Exception;
use Mage_Core_Model_Config_Element;
use Override;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Adminhtml\Model\Config\DataTrait;
use ReflectionMethod;

final class DataTest extends OpenMageTest
{
    use DataTrait;

    private static Subject $subject;

    #[Override]
    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$subject = new Subject();
    }

    /**
     * Group and field keys are concatenated into an XPath expression for the
     * dynamic_group fallback lookup (GHSA-wrp2-grgr-7gr9). Only a plain
     * identifier may reach that expression, or a key such as
     * "x|//admin/groups/url/fields/use_custom" selects a node outside the
     * requested section.
     *
     * @dataProvider provideIdentifiers
     */
    public function testIsPlainIdentifier(bool $expected, mixed $value): void
    {
        $method = new ReflectionMethod(self::$subject, '_isPlainIdentifier');
        self::assertSame($expected, $method->invoke(self::$subject, $value));
    }

    public static function provideIdentifiers(): Iterator
    {
        yield 'simple field name' => [true, 'use_custom'];
        yield 'name with underscore' => [true, 'base_url'];
        yield 'numeric string' => [true, '123'];
        yield 'xpath union injection' => [false, 'x|//admin/groups/url/fields/use_custom'];
        yield 'absolute path injection' => [false, '//admin/groups/url/fields/use_custom'];
        yield 'path traversal' => [false, '../../web/cookie_domain'];
        yield 'slash separated path' => [false, 'url/fields/use_custom'];
        yield 'attribute predicate injection' => [false, 'use_custom[@type="group"]'];
        yield 'empty string' => [false, ''];
        yield 'array value' => [false, ['use_custom']];
        yield 'null value' => [false, null];
    }

    /**
     * Numeric validation classes and Yes/No sources declared by a field are enforced on the server.
     *
     * @dataProvider provideFieldValues
     * @group Model
     */
    public function testValidateFieldValue(string $fieldXml, mixed $value, ?string $expectedError): void
    {
        $method = new ReflectionMethod(self::$subject, '_validateFieldValue');
        $fieldConfig = new Mage_Core_Model_Config_Element($fieldXml);

        if ($expectedError !== null) {
            $this->expectException(Mage_Core_Exception::class);
            $this->expectExceptionMessage($expectedError);
        }

        $method->invoke(self::$subject, $fieldConfig, $value);
        self::assertNull($expectedError);
    }

    public static function provideFieldValues(): Iterator
    {
        $digits = '<max_dimension><label>Maximum Dimension</label><validate>required-entry validate-digits validate-greater-than-zero</validate></max_dimension>';
        $digitsClass = '<downloads_number><label>Downloads</label><frontend_class>validate-digits</frontend_class></downloads_number>';
        $number = '<qty_increments><label>Qty Increments</label><validate>validate-number validate-greater-than-zero</validate></qty_increments>';
        $notNegative = '<delete_quote_after><label>Quote Lifetime</label><validate>validate-not-negative-number</validate></delete_quote_after>';
        $zeroOrGreater = '<lifetime><label>Lifetime</label><validate>validate-zero-or-greater</validate></lifetime>';
        $yesNo = '<manage_stock><label>Manage Stock</label><frontend_type>select</frontend_type><source_model>adminhtml/system_config_source_yesno</source_model></manage_stock>';
        $enableDisable = '<active><label>Status</label><frontend_type>select</frontend_type><source_model>adminhtml/system_config_source_enabledisable</source_model></active>';
        $yesNoText = '<api_url><label>API URL</label><source_model>adminhtml/system_config_source_yesno</source_model></api_url>';
        $plain = '<title><label>Title</label><frontend_type>text</frontend_type></title>';

        $digitsError = 'Maximum Dimension: Please use numbers only in this field.';
        $positiveError = 'Please enter a number greater than 0 in this field.';
        $numberError = 'Please enter a valid number in this field.';
        $notNegativeError = 'Please enter a number 0 or greater in this field.';
        $selectError = 'Please select an option.';

        yield 'digits: valid' => [$digits, '1200', null];
        yield 'digits: markup' => [$digits, '1200"><img src=x onerror=alert(1)>', $digitsError];
        yield 'digits: decimal' => [$digits, '12.5', $digitsError];
        yield 'digits: leading space' => [$digits, ' 12', $digitsError];
        yield 'digits: zero fails greater than zero' => [$digits, '0', $positiveError];
        yield 'digits: empty passes like in the browser' => [$digits, '', null];
        yield 'digits: not posted' => [$digits, null, null];
        yield 'digits: array' => [$digits, ['1'], $numberError];
        yield 'digits from frontend_class: markup' => [$digitsClass, '5</script>', 'Downloads: Please use numbers only'];
        yield 'digits from frontend_class: integer' => [$digitsClass, 5, null];
        yield 'number: decimal' => [$number, '2.5', null];
        yield 'number: trailing text' => [$number, '2abc', $numberError];
        yield 'number: negative fails greater than zero' => [$number, '-1', $positiveError];
        yield 'not negative: comma decimal' => [$notNegative, '1,5', null];
        yield 'not negative: zero' => [$notNegative, '0', null];
        yield 'not negative: trailing text accepted by parseFloat in the browser' => [$notNegative, '5abc', $notNegativeError];
        yield 'not negative: negative' => [$notNegative, '-2', $notNegativeError];
        yield 'zero or greater: thousands separator' => [$zeroOrGreater, '1,000.5', null];
        yield 'zero or greater: markup' => [$zeroOrGreater, '1"><b>', $notNegativeError];
        yield 'yes/no: 1' => [$yesNo, '1', null];
        yield 'yes/no: 0' => [$yesNo, '0', null];
        yield 'yes/no: integer' => [$yesNo, 1, null];
        yield 'yes/no: boolean' => [$yesNo, false, null];
        yield 'yes/no: markup' => [$yesNo, '1"><img src=x onerror=alert(1)>', 'Manage Stock: ' . $selectError];
        yield 'yes/no: empty' => [$yesNo, '', $selectError];
        yield 'yes/no: 2' => [$yesNo, '2', $selectError];
        yield 'enable/disable: markup' => [$enableDisable, '1 onclick=x', $selectError];
        yield 'yes/no source on a text field is not a select' => [$yesNoText, 'https://example.com/', null];
        yield 'field without checks' => [$plain, '<b>any</b>', null];
    }

    /**
     * save() refuses a value that fails its field's checks before anything is written.
     *
     * @group Model
     */
    public function testSaveRejectsInvalidFieldValue(): void
    {
        $subject = $this->createPartialMock(Subject::class, ['_getConfig']);
        $subject->method('_getConfig')->willReturn([]);
        $subject->setSection('catalog')
            ->setWebsite('')
            ->setStore('')
            ->setGroups(['product_image' => ['fields' => [
                'max_dimension' => ['value' => '5000<img src=x onerror=alert(1)>'],
            ]]]);

        $this->expectException(Mage_Core_Exception::class);
        $this->expectExceptionMessage('Please use numbers only in this field.');
        $subject->save();
    }
}
