<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Customer\Model\Api2\Customer;

use Generator;
use Mage_Api2_Exception;
use Mage_Api2_Model_Request;
use Mage_Customer_Model_Api2_Customer_Rest_Admin_V1 as Subject;
use OpenMage\Tests\Unit\OpenMageTest;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionMethod;

/**
 * REST customer reads must not return credentials or the raw confirmation key, whatever attributes the
 * API role allows, and must not let a collection filter recover the confirmation key.
 */
final class RestReadSecretsTest extends OpenMageTest
{
    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $expected
     */
    #[DataProvider('provideCustomerData')]
    public function testPrepareCustomerDataForResponse(array $data, array $expected): void
    {
        $subject = new Subject();
        $method = new ReflectionMethod($subject, '_prepareCustomerDataForResponse');

        self::assertSame($expected, $method->invoke($subject, $data));
    }

    public static function provideCustomerData(): Generator
    {
        $secrets = [
            'password_hash' => 'hash:salt:2',
            'rp_token' => 'b7a1c0d2e3f4a5b6c7d8e9f0a1b2c3d4',
            'rp_token_created_at' => '2026-11-17 10:00:00',
            'rp_customer_id' => 'c0ffee',
        ];

        yield 'pending confirmation' => [
            ['entity_id' => '5', 'email' => 'a@example.com', 'confirmation' => 'abc123'] + $secrets,
            ['entity_id' => '5', 'email' => 'a@example.com', 'confirmation' => true],
        ];
        yield 'confirmed' => [
            ['entity_id' => '5', 'confirmation' => null],
            ['entity_id' => '5', 'confirmation' => false],
        ];
        yield 'confirmation not loaded' => [
            ['entity_id' => '5'],
            ['entity_id' => '5'],
        ];
    }

    /**
     * @param list<array<string, mixed>> $filter
     */
    #[DataProvider('provideRejectedFilters')]
    public function testConfirmationFilterRejectsValueConditions(array $filter): void
    {
        $this->expectException(Mage_Api2_Exception::class);
        $this->validateConfirmationFilter($filter);
    }

    public static function provideRejectedFilters(): Generator
    {
        yield 'like' => [[['attribute' => 'confirmation', 'like' => 'a%']]];
        yield 'eq' => [[['attribute' => 'confirmation', 'eq' => 'abc']]];
        yield 'notnull plus like' => [[['attribute' => 'confirmation', 'notnull' => 1, 'like' => 'a%']]];
        yield 'after an allowed entry' => [[
            ['attribute' => 'email', 'like' => '%@example.com'],
            ['attribute' => 'confirmation', 'in' => ['abc']],
        ]];
    }

    /**
     * @param null|list<array<string, mixed>> $filter
     */
    #[DataProvider('provideAllowedFilters')]
    public function testConfirmationFilterAllowsOtherFilters(?array $filter): void
    {
        $this->expectNotToPerformAssertions();
        $this->validateConfirmationFilter($filter);
    }

    public static function provideAllowedFilters(): Generator
    {
        yield 'no filter' => [null];
        yield 'confirmation notnull' => [[['attribute' => 'confirmation', 'notnull' => '1']]];
        yield 'confirmation null' => [[['attribute' => 'confirmation', 'null' => '1']]];
        yield 'other attribute like' => [[['attribute' => 'email', 'like' => '%@example.com']]];
    }

    /**
     * @param null|list<array<string, mixed>> $filter
     */
    private function validateConfirmationFilter(?array $filter): void
    {
        $request = $this->createMock(Mage_Api2_Model_Request::class);
        $request->method('getFilter')->willReturn($filter);

        $subject = new Subject();
        $subject->setRequest($request);

        (new ReflectionMethod($subject, '_validateConfirmationFilter'))->invoke($subject);
    }
}
