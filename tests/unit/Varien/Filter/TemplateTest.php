<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Varien\Filter;

use ArrayObject;
use Exception;
use Generator;
use Mage_Core_Model_Store;
use OpenMage\Tests\Unit\OpenMageTest;
use PHPUnit\Framework\Attributes\DataProvider;
use Varien_Filter_Template as Subject;
use Varien_Object;

final class TemplateTest extends OpenMageTest
{
    /**
     * GHSA-94p5-6j66-c9j6: getConfig() on a template variable must not return encrypted
     * configuration, whatever the case of the method name (PHP method names are
     * case-insensitive), whether the path names the field or one of its parents, and whether
     * it uses the `node@attribute=value` segment syntax that the config reader accepts.
     *
     * @group Filter
     */
    #[DataProvider('provideGetConfigDirectives')]
    public function testGetConfigDoesNotDiscloseEncryptedPaths(string $directive, string $expected): void
    {
        $store = new class extends Mage_Core_Model_Store {
            public function getConfig($path): string
            {
                return $path === null ? '' : 'value-of:' . $path;
            }
        };

        $subject = new Subject();
        $subject->setVariables(['store' => $store]);

        self::assertSame($expected, $subject->filter($directive));
    }

    public static function provideGetConfigDirectives(): Generator
    {
        yield 'plain field' => ['{{var store.getConfig("general/store_information/name")}}', 'value-of:general/store_information/name'];
        yield 'encrypted field' => ['{{var store.getConfig("paypal/wpp/api_password")}}', ''];
        yield 'lower case method' => ['{{var store.getconfig("paypal/wpp/api_password")}}', ''];
        yield 'upper case method' => ['{{var store.GETCONFIG("paypal/wpp/api_password")}}', ''];
        yield 'mixed case method' => ['{{var store.GetConfig("paypal/wpp/api_signature")}}', ''];
        yield 'encrypted parent group' => ['{{var store.getConfig("paypal/wpp")}}', ''];
        yield 'attribute selector on the field' => ['{{var store.getConfig("paypal/wpp/api_password@x=")}}', ''];
        yield 'attribute selector on the group' => ['{{var store.getConfig("paypal/wpp@x=/api_password")}}', ''];
        yield 'attribute selector in a condition' => ['{{depend store.getConfig("paypal/wpp/api_password@x=")}}set{{/depend}}', ''];
    }

    /**
     * A template expression may call a method without arguments only if its name starts with get,
     * is or has, and a method with arguments only if it is declared for the object's class under
     * global/template/variable_methods. Setters, dispatchers such as getDataUsingMethod() and
     * undeclared readers such as getConfigData() are refused and render as the default value.
     *
     * @group Filter
     */
    /**
     * @param list<string> $expectedCalls
     */
    #[DataProvider('provideMethodCallDirectives')]
    public function testMethodCallPolicy(string $directive, string $expected, array $expectedCalls): void
    {
        $calls = new ArrayObject();
        $object = $this->createRecordingObject($calls);

        $subject = $this->createSubject([[$object::class, 'declaredMethod']]);
        $subject->setVariables(['object' => $object]);

        self::assertSame($expected, $subject->filter($directive));
        self::assertSame($expectedCalls, $calls->getArrayCopy());
    }

    public static function provideMethodCallDirectives(): Generator
    {
        yield 'property access' => ['{{var object.secret}}', 'getter:secret', ['getSecret']];
        yield 'getter without arguments' => ['{{var object.getSecret()}}', 'getter:secret', ['getSecret']];
        yield 'is without arguments' => ['{{var object.isActive()}}', 'yes', ['isActive']];
        yield 'has without arguments' => ['{{var object.hasThing()}}', 'yes', ['hasThing']];
        yield 'magic getter without arguments' => ['{{var object.getPlain()}}', 'plain-data', []];
        yield 'declared method with arguments' => ['{{var object.declaredMethod("a", 2)}}', 'declared:a,2', ['declaredMethod']];
        yield 'declared method, other case' => ['{{var object.DECLAREDMETHOD("a")}}', 'declared:a', ['declaredMethod']];
        yield 'getter with arguments' => ['{{var object.getSecret("x")}}', '', []];
        yield 'getConfigData with arguments' => ['{{var object.getConfigData("paypal/wpp/api_password")}}', '', []];
        yield 'getDataUsingMethod dispatcher' => ['{{var object.getDataUsingMethod("secret", "x")}}', '', []];
        yield 'setter' => ['{{var object.setData("code", "x").getSecret()}}', '', []];
        yield 'setter without arguments' => ['{{var object.unsetData().getSecret()}}', '', []];
        yield 'mutating method' => ['{{var object.addBcc("attacker@example.com")}}', '', []];
        yield 'decrypt' => ['{{var object.decrypt("0:3:abc")}}', '', []];
        yield 'refused call in a condition' => ['{{depend object.addBcc("attacker@example.com")}}sent{{/depend}}', '', []];
        yield 'refused call in a parameter' => ['{{if object.addBcc("attacker@example.com")}}sent{{else}}refused{{/if}}', 'refused', []];
    }

    /**
     * An entry allows the declared method on the declared class and on its subclasses only.
     *
     * @group Filter
     */
    public function testDeclaredMethodIsOnlyAllowedOnDeclaredClass(): void
    {
        $calls = new ArrayObject();
        $object = $this->createRecordingObject($calls);

        $subject = $this->createSubject([[Mage_Core_Model_Store::class, 'declaredMethod']]);
        $subject->setVariables(['object' => $object]);

        self::assertSame('', $subject->filter('{{var object.declaredMethod("a")}}'));
        self::assertSame([], $calls->getArrayCopy());

        $subject = $this->createSubject([[Varien_Object::class, 'declaredMethod']]);
        $subject->setVariables(['object' => $object]);

        self::assertSame('declared:a', $subject->filter('{{var object.declaredMethod("a")}}'));
    }

    /**
     * Code that returns secrets checks isProcessingVariable(). It must be true while any template
     * expression is evaluated, including nested ones, and false again afterwards, even when the
     * expression throws.
     *
     * @group Filter
     */
    public function testIsProcessingVariableWhileExpressionIsEvaluated(): void
    {
        $object = new class extends Varien_Object {
            /** @var list<bool> */
            public array $seen = [];

            public function getState(): string
            {
                $this->seen[] = Subject::isProcessingVariable();
                $nested = new Subject();
                $nested->setVariables(['inner' => $this]);
                $nested->filter('{{var inner.getInner()}}');
                $this->seen[] = Subject::isProcessingVariable();

                return 'state';
            }

            public function getInner(): string
            {
                $this->seen[] = Subject::isProcessingVariable();
                return 'inner';
            }

            public function getBroken(): never
            {
                throw new Exception('broken');
            }
        };

        $subject = new Subject();
        $subject->setVariables(['object' => $object]);

        self::assertFalse(Subject::isProcessingVariable());
        self::assertSame('state', $subject->filter('{{var object.getState()}}'));
        self::assertSame([true, true, true], $object->seen);
        self::assertFalse(Subject::isProcessingVariable());

        try {
            $subject->filter('{{var object.getBroken()}}');
            self::fail('The exception should propagate from the base filter');
        } catch (Exception $exception) {
            self::assertSame('broken', $exception->getMessage());
        }

        self::assertFalse(Subject::isProcessingVariable());
    }

    /**
     * @param list<array{0: string, 1: string}> $allowedMethods
     */
    private function createSubject(array $allowedMethods): Subject
    {
        return new class ($allowedMethods) extends Subject {
            /**
             * @param list<array{0: string, 1: string}> $allowedMethods
             */
            public function __construct(array $allowedMethods)
            {
                $this->_allowedVariableMethods = array_map(
                    static fn(array $pair): array => [$pair[0], strtolower($pair[1])],
                    $allowedMethods,
                );
            }

            protected function _logDisallowedMethodCall(object $object, string $method): void {}
        };
    }

    /**
     * @param ArrayObject<int, string> $calls receives the name of each method the template calls
     */
    private function createRecordingObject(ArrayObject $calls): Varien_Object
    {
        return new class ($calls) extends Varien_Object {
            /**
             * @param ArrayObject<int, string> $calls
             */
            public function __construct(private readonly ArrayObject $calls)
            {
                parent::__construct(['plain' => 'plain-data']);
            }

            public function getSecret(): string
            {
                $this->calls->append('getSecret');
                return func_num_args() === 0 ? 'getter:secret' : 'getter-with-arguments';
            }

            public function isActive(): string
            {
                $this->calls->append('isActive');
                return 'yes';
            }

            public function hasThing(): string
            {
                $this->calls->append('hasThing');
                return 'yes';
            }

            public function declaredMethod(string ...$args): string
            {
                $this->calls->append('declaredMethod');
                return 'declared:' . implode(',', $args);
            }

            public function getConfigData(string $field): string
            {
                $this->calls->append('getConfigData');
                return 'config:' . $field;
            }

            public function getDataUsingMethod($key, $args = null)
            {
                $this->calls->append('getDataUsingMethod');
                return parent::getDataUsingMethod($key, $args);
            }

            /**
             * @param array<mixed, mixed>|string $key
             */
            public function setData($key, $value = null)
            {
                if ($key === 'code') {
                    $this->calls->append('setData');
                }

                return parent::setData($key, $value);
            }

            public function unsetData($key = null)
            {
                $this->calls->append('unsetData');
                return parent::unsetData($key);
            }

            public function addBcc(string $email): self
            {
                $this->calls->append('addBcc');
                return $this;
            }

            public function decrypt(string $data): string
            {
                $this->calls->append('decrypt');
                return 'plaintext';
            }
        };
    }
}
