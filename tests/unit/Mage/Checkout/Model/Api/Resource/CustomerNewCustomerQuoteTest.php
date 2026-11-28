<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Checkout\Model\Api\Resource;

use Mage;
use Mage_Api_Exception;
use Mage_Checkout_Model_Api_Resource_Customer as Subject;
use Mage_Customer_Model_Address;
use Mage_Customer_Model_Customer;
use Mage_Sales_Model_Quote;
use Mage_Sales_Model_Quote_Address;
use OpenMage\Tests\Unit\OpenMageTest;
use ReflectionMethod;

/**
 * cart.order in register mode saves the quote's customer with the order, so it must never copy the
 * quote's name, email and password onto a customer that already exists.
 */
final class CustomerNewCustomerQuoteTest extends OpenMageTest
{
    public function testRegisteringRefusesExistingCustomer(): void
    {
        $customer = $this->createPartialMock(Mage_Customer_Model_Customer::class, ['getId', 'addAddress', 'decryptPassword']);
        $customer->method('getId')->willReturn(5);
        $customer->method('decryptPassword')->willReturn('attacker-chosen');
        $customer->expects(self::never())->method('addAddress');

        $billing = $this->createMock(Mage_Sales_Model_Quote_Address::class);
        $billing->method('exportCustomerAddress')->willReturn($this->createMock(Mage_Customer_Model_Address::class));

        $quote = $this->createPartialMock(Mage_Sales_Model_Quote::class, ['getCustomer', 'getBillingAddress', 'isVirtual']);
        $quote->method('getCustomer')->willReturn($customer);
        $quote->method('getBillingAddress')->willReturn($billing);
        $quote->method('isVirtual')->willReturn(true);
        $quote->setCustomerEmail('eve@example.com');

        $subject = new Subject();

        try {
            (new ReflectionMethod($subject, '_prepareNewCustomerQuote'))->invoke($subject, $quote);
            self::fail('Expected a create_order_fault');
        } catch (Mage_Api_Exception $mageApiException) {
            self::assertSame('create_order_fault', $mageApiException->getMessage());
            self::assertSame(
                Mage::helper('checkout')->__('The quote is set to register a new customer but is assigned to an existing customer.'),
                $mageApiException->getCustomMessage(),
            );
        }

        self::assertNotSame('eve@example.com', $customer->getEmail());
    }
}
