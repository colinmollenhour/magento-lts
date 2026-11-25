<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Customer\Model\Customer;

use Generator;
use Mage;
use Mage_Api_Exception;
use Mage_Customer_Model_Customer;
use Mage_Customer_Model_Customer_Api as Subject;
use Mage_Customer_Model_Customer_Api_V2 as SubjectV2;
use OpenMage\Tests\Unit\OpenMageTest;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * customer.create must always create a customer. A customer id in the input must not turn it into an
 * update of an existing account, and only customer attributes and the password are written, as
 * customer.update already does.
 */
final class ApiCreateTest extends OpenMageTest
{
    private const ID_ON_CREATE_MESSAGE = 'A customer ID cannot be set when creating a customer. Update the existing customer instead.';

    /**
     * @param array<string, mixed> $customerData
     */
    #[DataProvider('provideCustomerIds')]
    public function testCreateRefusesCustomerId(array $customerData): void
    {
        $subject = $this->createPartialMock(Subject::class, ['getAllowedAttributes']);
        $subject->expects(self::never())->method('getAllowedAttributes');

        $this->assertRefused(static fn() => $subject->create($customerData));
    }

    /**
     * @param array<string, mixed> $customerData
     */
    #[DataProvider('provideCustomerIds')]
    public function testCreateV2RefusesCustomerId(array $customerData): void
    {
        $subject = $this->createPartialMock(SubjectV2::class, ['getAllowedAttributes']);
        $subject->expects(self::never())->method('getAllowedAttributes');

        $this->assertRefused(static fn() => $subject->create((object) $customerData));
    }

    public static function provideCustomerIds(): Generator
    {
        $data = ['email' => 'victim@example.com', 'password' => 'attacker-chosen'];

        yield 'customer_id as int' => [['customer_id' => 5] + $data];
        yield 'customer_id as string' => [['customer_id' => '5'] + $data];
        yield 'entity_id' => [['entity_id' => 5] + $data];
        yield 'empty customer_id does not hide entity_id' => [['customer_id' => 0, 'entity_id' => 5] + $data];
        yield 'customer_id as array' => [['customer_id' => [5]] + $data];
    }

    public function testPrepareDataForCreateCopiesOnlyAllowedFields(): void
    {
        $customer = $this->createPartialMock(Mage_Customer_Model_Customer::class, ['getRandomConfirmationKey']);
        $customer->method('getRandomConfirmationKey')->willReturn('new-random-key');

        $allowed = ['email', 'firstname', 'website_id', 'group_id', 'password_hash', 'confirmation'];
        $subject = $this->createPartialMock(Subject::class, ['getAllowedAttributes']);
        $subject->expects(self::once())
            ->method('getAllowedAttributes')
            ->with($customer)
            ->willReturn(array_fill_keys($allowed, true));

        $result = $this->invoke($subject, '_prepareDataForCreate', $customer, [
            'email'          => 'john@example.com',
            'firstname'      => 'John',
            'website_id'     => 1,
            'group_id'       => null,
            'password'       => 'secret123',
            'password_hash'  => 'imported-hash',
            'confirmation'   => true,
            'entity_type_id' => 99,
            'is_subscribed'  => 1,
            'not_a_field'    => 'x',
        ]);

        self::assertSame([
            'email'         => 'john@example.com',
            'firstname'     => 'John',
            'website_id'    => 1,
            'group_id'      => null,
            'password_hash' => 'imported-hash',
            'confirmation'  => 'new-random-key',
            'password'      => 'secret123',
        ], $result);
    }

    public function testPrepareDataForCreateDropsTheIdEvenWhenListedAsAllowed(): void
    {
        $customer = $this->createMock(Mage_Customer_Model_Customer::class);
        $subject = $this->createPartialMock(Subject::class, ['getAllowedAttributes']);
        $subject->method('getAllowedAttributes')->willReturn(array_fill_keys(['email', 'entity_id', 'customer_id'], true));

        $result = $this->invoke($subject, '_prepareDataForCreate', $customer, ['email' => 'a@example.com', 'entity_id' => 0, 'customer_id' => null]);

        self::assertSame(['email' => 'a@example.com'], $result);
    }

    public function testPrepareDataForCreateV2CopiesOnlyAllowedFields(): void
    {
        $customer = $this->createMock(Mage_Customer_Model_Customer::class);
        $subject = $this->createPartialMock(SubjectV2::class, ['getAllowedAttributes']);
        $subject->method('getAllowedAttributes')->willReturn(['email' => true, 'firstname' => true]);

        $result = $this->invoke($subject, '_prepareDataForCreate', $customer, (object) [
            'customer_id'    => null,
            'email'          => 'john@example.com',
            'firstname'      => 'John',
            'password'       => 'secret123',
            'entity_type_id' => 99,
        ]);

        self::assertSame(['email' => 'john@example.com', 'firstname' => 'John', 'password' => 'secret123'], $result);
    }

    #[DataProvider('provideEmptyCustomerIds')]
    public function testPrepareDataForCreateAcceptsEmptyCustomerId(mixed $customerId): void
    {
        $customer = $this->createMock(Mage_Customer_Model_Customer::class);
        $subject = $this->createPartialMock(Subject::class, ['getAllowedAttributes']);
        $subject->method('getAllowedAttributes')->willReturn(['email' => true]);

        $result = $this->invoke($subject, '_prepareDataForCreate', $customer, ['customer_id' => $customerId, 'email' => 'a@example.com']);

        self::assertSame(['email' => 'a@example.com'], $result);
    }

    public static function provideEmptyCustomerIds(): Generator
    {
        yield 'null' => [null];
        yield 'zero' => [0];
        yield 'zero string' => ['0'];
        yield 'empty string' => [''];
    }

    public function testPrepareDataForCreateRefusesNonArrayInput(): void
    {
        $customer = $this->createMock(Mage_Customer_Model_Customer::class);

        try {
            $this->invoke(new Subject(), '_prepareDataForCreate', $customer, 'email');
            self::fail('Expected a data_invalid fault');
        } catch (Mage_Api_Exception $mageApiException) {
            self::assertSame('data_invalid', $mageApiException->getMessage());
        }
    }

    private function assertRefused(callable $create): void
    {
        try {
            $create();
            self::fail('Expected a data_invalid fault');
        } catch (Mage_Api_Exception $mageApiException) {
            self::assertSame('data_invalid', $mageApiException->getMessage());
            self::assertSame(
                Mage::helper('customer')->__(self::ID_ON_CREATE_MESSAGE),
                $mageApiException->getCustomMessage(),
            );
        }
    }

    private function invoke(object $subject, string $method, mixed ...$args): mixed
    {
        return (new ReflectionMethod($subject, $method))->invoke($subject, ...$args);
    }
}
