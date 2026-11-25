<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Checkout\Model\Cart\Customer;

use Generator;
use Mage;
use Mage_Api_Exception;
use Mage_Checkout_Model_Cart_Customer_Api as Subject;
use Mage_Checkout_Model_Cart_Customer_Api_V2 as SubjectV2;
use Mage_Sales_Model_Quote;
use OpenMage\Tests\Unit\OpenMageTest;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionMethod;

/**
 * cart_customer.set in register or guest mode describes a new customer, which cart.order saves. A
 * customer id there must be refused, or placing the order saves over that existing customer.
 */
final class ApiNewCustomerIdTest extends OpenMageTest
{
    private const MESSAGE = 'A customer ID cannot be set in register or guest mode. Use customer mode to assign an existing customer.';

    /**
     * @param array<string, mixed> $customerData
     */
    #[DataProvider('provideNewCustomerDataWithId')]
    public function testSetRefusesCustomerIdForNewCustomer(array $customerData): void
    {
        $this->assertRefused(fn() => $this->mockSubject(Subject::class)->set(1, $customerData));
    }

    /**
     * @param array<string, mixed> $customerData
     */
    #[DataProvider('provideNewCustomerDataWithId')]
    public function testSetV2RefusesCustomerIdForNewCustomer(array $customerData): void
    {
        $this->assertRefused(fn() => $this->mockSubject(SubjectV2::class)->set(1, $this->asSoapV2Struct($customerData)));
    }

    public static function provideNewCustomerDataWithId(): Generator
    {
        $data = ['firstname' => 'Eve', 'lastname' => 'Doe', 'email' => 'eve@example.com', 'password' => 'attacker-chosen'];

        foreach (['register', 'guest'] as $mode) {
            yield "$mode with customer_id" => [['mode' => $mode, 'customer_id' => 5] + $data];
            yield "$mode with customer_id as string" => [['mode' => $mode, 'customer_id' => '5'] + $data];
            yield "$mode with entity_id" => [['mode' => $mode, 'entity_id' => 5] + $data];
            yield "$mode with empty customer_id hiding entity_id" => [['mode' => $mode, 'customer_id' => 0, 'entity_id' => 5] + $data];
        }
    }

    #[DataProvider('provideHasCustomerId')]
    public function testHasCustomerId(bool $expected, mixed $data): void
    {
        $subject = new Subject();
        self::assertSame($expected, (new ReflectionMethod($subject, '_hasCustomerId'))->invoke($subject, $data));
    }

    public static function provideHasCustomerId(): Generator
    {
        yield 'no id' => [false, ['email' => 'a@example.com']];
        yield 'null customer_id' => [false, ['customer_id' => null]];
        yield 'zero customer_id' => [false, ['customer_id' => 0]];
        yield 'zero string customer_id' => [false, ['customer_id' => '0']];
        yield 'empty customer_id' => [false, ['customer_id' => '']];
        yield 'customer_id' => [true, ['customer_id' => 5]];
        yield 'entity_id' => [true, ['entity_id' => '5']];
        yield 'object with customer_id' => [true, (object) ['customer_id' => 5]];
        yield 'object without id' => [false, (object) ['email' => 'a@example.com']];
        yield 'not an array' => [false, 'customer'];
    }

    /**
     * @template T of Subject
     * @param  class-string<T> $class
     * @return MockObject&T
     */
    private function mockSubject(string $class): MockObject
    {
        $quote = $this->createMock(Mage_Sales_Model_Quote::class);
        $quote->expects(self::never())->method('setCustomer');

        // keep the constructor, which sets the customer_id alias
        $subject = $this->getMockBuilder($class)->onlyMethods(['_getQuote'])->getMock();
        $subject->method('_getQuote')->willReturn($quote);

        return $subject;
    }

    /**
     * The SOAP v2 adapter passes structs as stdClass, although set() documents an array
     *
     * @param array<string, mixed> $data
     */
    private function asSoapV2Struct(array $data): mixed
    {
        return (object) $data;
    }

    private function assertRefused(callable $set): void
    {
        try {
            $set();
            self::fail('Expected a customer_data_invalid fault');
        } catch (Mage_Api_Exception $mageApiException) {
            self::assertSame('customer_data_invalid', $mageApiException->getMessage());
            self::assertSame(Mage::helper('checkout')->__(self::MESSAGE), $mageApiException->getCustomMessage());
        }
    }
}
