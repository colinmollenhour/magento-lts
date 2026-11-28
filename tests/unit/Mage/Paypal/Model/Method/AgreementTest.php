<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Paypal\Model\Method;

use Iterator;
use Mage;
use Mage_Core_Exception;
use Mage_Paypal_Model_Method_Agreement as Subject;
use Mage_Paypal_Model_Pro;
use Mage_Sales_Model_Billing_Agreement;
use Mage_Sales_Model_Order;
use Mage_Sales_Model_Order_Payment;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Paypal\Model\Method\AgreementTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * Placing an order charges the billing agreement in the payment's additional information only when it belongs to
 * the ordering customer and is active. The PayPal API is not called otherwise.
 */
final class AgreementTest extends OpenMageTest
{
    use AgreementTrait;

    private const CUSTOMER_ID = 5;

    /**
     * @param null|array<string, null|int|string> $agreementData
     */
    #[DataProvider('provideRefusedAgreements')]
    public function testPlaceOrderRefusesAgreementTheCustomerMayNotUse(
        mixed $agreementId,
        ?array $agreementData,
        ?int $orderCustomerId,
    ): void {
        $pro = $this->createMock(Mage_Paypal_Model_Pro::class);
        $pro->expects(self::never())->method('getApi');
        $pro->expects(self::never())->method('getConfig');

        $subject = $this->createSubject($pro, $agreementId, $agreementData);

        $this->expectException(Mage_Core_Exception::class);
        $this->expectExceptionMessage('Wrong billing agreement ID specified.');

        $subject->authorize($this->createPayment($agreementId, $orderCustomerId), 10.0);
    }

    public static function provideRefusedAgreements(): Iterator
    {
        $active = ['customer_id' => self::CUSTOMER_ID, 'status' => Mage_Sales_Model_Billing_Agreement::STATUS_ACTIVE];
        yield "another customer's agreement" => [9, ['customer_id' => self::CUSTOMER_ID + 1] + $active, self::CUSTOMER_ID];
        yield 'own canceled agreement' => [
            8,
            ['status' => Mage_Sales_Model_Billing_Agreement::STATUS_CANCELED] + $active,
            self::CUSTOMER_ID,
        ];
        yield 'agreement that does not exist' => [404, null, self::CUSTOMER_ID];
        yield 'no agreement in the payment' => [null, null, self::CUSTOMER_ID];
        yield 'guest order' => [7, ['customer_id' => null] + $active, null];
    }

    public function testPlaceOrderChargesOwnActiveAgreement(): void
    {
        $pro = $this->createMock(Mage_Paypal_Model_Pro::class);
        $pro->expects(self::once())->method('getApi')->willThrowException(new RuntimeException('API reached'));

        $subject = $this->createSubject($pro, 7, [
            'customer_id' => self::CUSTOMER_ID,
            'status' => Mage_Sales_Model_Billing_Agreement::STATUS_ACTIVE,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('API reached');

        $subject->authorize($this->createPayment(7, self::CUSTOMER_ID), 10.0);
    }

    /**
     * @param null|array<string, null|int|string> $agreementData
     */
    private function createSubject(Mage_Paypal_Model_Pro $pro, mixed $agreementId, ?array $agreementData): Subject
    {
        $agreement = null;
        if ($agreementData !== null) {
            /** @var Mage_Sales_Model_Billing_Agreement $agreement */
            $agreement = Mage::getModel('sales/billing_agreement');
            $agreement->setData(['agreement_id' => $agreementId, 'reference_id' => 'B-REF'] + $agreementData);
        }

        $subject = $this->getMockBuilder(Subject::class)
            ->setConstructorArgs([[$pro]])
            ->onlyMethods(['_loadBillingAgreement'])
            ->getMock();
        $subject->method('_loadBillingAgreement')->with($agreementId)->willReturn($agreement);

        return $subject;
    }

    private function createPayment(mixed $agreementId, ?int $orderCustomerId): Mage_Sales_Model_Order_Payment
    {
        /** @var Mage_Sales_Model_Order $order */
        $order = Mage::getModel('sales/order');
        $order->setCustomerId($orderCustomerId);

        /** @var Mage_Sales_Model_Order_Payment $payment */
        $payment = Mage::getModel('sales/order_payment');
        $payment->setOrder($order);
        if ($agreementId !== null) {
            $payment->setAdditionalInformation(Subject::TRANSPORT_BILLING_AGREEMENT_ID, $agreementId);
        }

        return $payment;
    }
}
