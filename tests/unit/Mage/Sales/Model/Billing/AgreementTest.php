<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Sales\Model\Billing;

use Generator;
use Mage;
use Mage_Sales_Model_Billing_Agreement as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Sales\Model\Billing\AgreementTrait;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * A customer may pay only with an existing, active billing agreement of their own.
 */
final class AgreementTest extends OpenMageTest
{
    use AgreementTrait;

    /**
     * @param array<string, null|int|string> $agreementData
     */
    #[DataProvider('provideCanBeUsedByCustomer')]
    public function testCanBeUsedByCustomer(bool $expected, array $agreementData, mixed $customerId): void
    {
        /** @var Subject $subject */
        $subject = Mage::getModel('sales/billing_agreement');
        $subject->setData($agreementData);

        self::assertSame($expected, $subject->canBeUsedByCustomer($customerId));
    }

    public static function provideCanBeUsedByCustomer(): Generator
    {
        $active = ['agreement_id' => 7, 'customer_id' => 5, 'status' => Subject::STATUS_ACTIVE];

        yield 'own active agreement' => [true, $active, 5];
        yield 'own active agreement, id as string' => [true, $active, '5'];
        yield "another customer's agreement" => [false, $active, 6];
        yield 'own canceled agreement' => [false, ['status' => Subject::STATUS_CANCELED] + $active, 5];
        yield 'own agreement without status' => [false, ['status' => null] + $active, 5];
        yield 'agreement that does not exist' => [false, ['agreement_id' => null] + $active, 5];
        yield 'guest, agreement without customer' => [false, ['customer_id' => null] + $active, null];
        yield 'customer id 0, agreement without customer' => [false, ['customer_id' => 0] + $active, 0];
    }
}
