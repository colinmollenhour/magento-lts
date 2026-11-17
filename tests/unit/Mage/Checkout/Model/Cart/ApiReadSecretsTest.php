<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Checkout\Model\Cart;

use Mage_Checkout_Model_Cart_Api as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use ReflectionMethod;
use Varien_Object;

/**
 * cart.info must not return the quote's password_hash, which holds the encrypted password of a customer
 * who registers during checkout.
 */
final class ApiReadSecretsTest extends OpenMageTest
{
    public function testQuoteAttributesExcludePasswordHash(): void
    {
        $quote = new Varien_Object([
            'entity_id' => 7,
            'customer_email' => 'a@example.com',
            'checkout_method' => 'register',
            'password_hash' => 'encrypted-password',
        ]);

        $result = $this->getAttributes($quote, 'quote');

        self::assertArrayNotHasKey('password_hash', $result);
        self::assertSame(
            ['customer_email' => 'a@example.com', 'checkout_method' => 'register', 'quote_id' => 7],
            $result,
        );
    }

    public function testExplicitAttributeFilterCannotReadmitPasswordHash(): void
    {
        $quote = new Varien_Object(['entity_id' => 7, 'password_hash' => 'encrypted-password']);

        self::assertArrayNotHasKey('password_hash', $this->getAttributes($quote, 'quote', ['password_hash']));
    }

    public function testOtherEntityTypesAreUnchanged(): void
    {
        $address = new Varien_Object(['entity_id' => 3, 'password_hash' => 'x', 'city' => 'Austin']);

        self::assertSame(
            ['password_hash' => 'x', 'city' => 'Austin', 'address_id' => 3],
            $this->getAttributes($address, 'quote_address'),
        );
    }

    /**
     * @param  null|string[]        $attributes
     * @return array<string, mixed>
     */
    private function getAttributes(Varien_Object $object, string $type, ?array $attributes = null): array
    {
        $subject = new Subject();

        return (new ReflectionMethod($subject, '_getAttributes'))->invoke($subject, $object, $type, $attributes);
    }
}
