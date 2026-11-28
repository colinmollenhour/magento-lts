<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Core\Model\Email\Template;

use Generator;
use Mage;
use Mage_Core_Exception;
use Mage_Core_Model_Config;
use Mage_Core_Model_Email_Template;
use Mage_Core_Model_Email_Template_Filter as Subject;
use Mage_Core_Model_Store;
use Mage_Core_Model_Template;
use Mage_Payment_Model_Info;
use Mage_Sales_Model_Order;
use Mage_Sales_Model_Order_Address;
use OpenMage\Tests\Unit\OpenMageTest;
use Override;
use PHPUnit\Framework\Attributes\DataProvider;
use ReflectionProperty;
use Varien_Event_Observer;
use Varien_Filter_Template;
use Varien_Filter_Template_Tokenizer_Variable;
use Varien_Object;

/**
 * Template expressions may only call declared methods with arguments and getters without, and
 * read no secrets whichever method they reach them through.
 */
final class FilterVariableMethodTest extends OpenMageTest
{
    private const SECRET_PATH = 'paypal/wpp/api_password';

    private const SECRET = 'ghsa-secret-value';

    private const SECRET_STORE_CODE = 'admin';

    /**
     * Config paths the test writes the secret to, with their original value (null when the node did not exist).
     * `default/` is what an uninstalled instance falls back to; `stores/<code>/` is what an installed one reads.
     *
     * @var array<string, null|string>
     */
    private array $originalSecrets = [];

    #[Override]
    protected function setUp(): void
    {
        $node = $this->config()->getNode('default/' . self::SECRET_PATH);
        self::assertNotFalse($node, 'The encrypted test field must be declared in config.xml');
        self::assertSame('adminhtml/system_config_backend_encrypted', (string) $node['backend_model']);

        // Store the secret the way the admin saves it, so it decrypts on installed and uninstalled instances alike
        $encrypted = (string) Mage::helper('core')->encrypt(self::SECRET);
        foreach ($this->secretPaths() as $path) {
            $existing = $this->config()->getNode($path);
            $this->originalSecrets[$path] = $existing === false ? null : (string) $existing;
            $this->config()->setNode($path, $encrypted);
            $written = $this->config()->getNode($path);
            if ($written !== false && !isset($written['backend_model'])) {
                $written->addAttribute('backend_model', 'adminhtml/system_config_backend_encrypted');
            }
        }
    }

    #[Override]
    protected function tearDown(): void
    {
        foreach ($this->originalSecrets as $path => $value) {
            if ($value !== null) {
                $this->config()->setNode($path, $value);
                continue;
            }

            $parent = $this->config()->getNode(dirname($path));
            if ($parent !== false) {
                unset($parent->{basename($path)});
            }
        }

        $this->originalSecrets = [];
    }

    /**
     * @return list<string>
     */
    private function secretPaths(): array
    {
        return ['default/' . self::SECRET_PATH, 'stores/' . self::SECRET_STORE_CODE . '/' . self::SECRET_PATH];
    }

    /**
     * `this` is the sending template model. Its addBcc(), setReplyTo() and setReturnPath() would
     * let a template add a recipient to every message it sends; a condition or a chained getter
     * avoids printing the returned object. The policy refuses them.
     *
     * @group Model
     */
    public function testTemplateCannotChangeRecipientsOfThis(): void
    {
        /** @var Mage_Core_Model_Email_Template $template */
        $template = Mage::getModel('core/email_template');
        $template->setTemplateType(Mage_Core_Model_Template::TYPE_HTML);
        $template->setTemplateText(
            '{{depend this.addBcc("attacker@example.com")}}bcc{{/depend}}'
            . '{{var this.setReplyTo("attacker@example.com").getTemplateType()}}'
            . '{{var this.setReturnPath("attacker@example.com").getTemplateType()}}',
        );

        $template->getProcessedTemplate(['customer' => new Varien_Object(['name' => 'Customer'])]);

        self::assertSame([], (new ReflectionProperty($template, '_bccEmails'))->getValue($template));
        self::assertSame([], $template->getMail()->getRecipients());
        self::assertNull($template->getMail()->getReplyTo());
        self::assertNull($template->getMail()->getReturnPath());
    }

    /**
     * Reported routes to the encrypted configuration: a dispatcher that calls getConfig() under
     * another name, and payment and customer methods that decrypt what the template passes in.
     *
     * @group Model
     */
    #[DataProvider('provideRefusedDirectives')]
    public function testRefusedDirectives(string $directive): void
    {
        $subject = $this->createSubject();

        self::assertSame('', $subject->filter($directive));
    }

    public static function provideRefusedDirectives(): Generator
    {
        yield 'getDataUsingMethod dispatcher' => ['{{var store.getDataUsingMethod("config", "' . self::SECRET_PATH . '")}}'];
        yield 'getDataUsingMethod, other case' => ['{{var store.GETDATAUSINGMETHOD("config", "' . self::SECRET_PATH . '")}}'];
        yield 'payment decrypt' => ['{{var payment.decrypt("' . self::SECRET . '")}}'];
        yield 'customer decryptPassword' => ['{{var customer.decryptPassword("' . self::SECRET . '")}}'];
        yield 'one-bit oracle in a condition' => ['{{depend store.getDataUsingMethod("config", "' . self::SECRET_PATH . '")}}set{{/depend}}'];
    }

    /**
     * A getter without arguments that reads an encrypted value (an extension's, or one reached
     * through a chain) returns nothing while the expression is evaluated: for a fresh read, for a
     * value another part of the request has already cached decrypted, and for the parent group.
     * The rest of the request still reads the value.
     *
     * @group Model
     */
    public function testEncryptedConfigIsHiddenWhileExpressionIsEvaluated(): void
    {
        $store = $this->createStore();
        $reader = $this->createReader($store);
        $subject = $this->createSubject(['reader' => $reader]);

        // Fresh read inside the expression, not cached as empty
        self::assertSame('', $subject->filter('{{var reader.getSecret()}}'));
        self::assertSame(self::SECRET, $store->getConfig(self::SECRET_PATH));

        // Value already cached decrypted by the request
        self::assertSame('', $subject->filter('{{var reader.getSecret()}}'));
        self::assertSame('', $subject->filter('{{depend reader.getSecret()}}set{{/depend}}'));
        self::assertSame(self::SECRET, $store->getConfig(self::SECRET_PATH));

        // Parent group, fresh and cached
        $groupStore = $this->createStore();
        $groupSubject = $this->createSubject(['reader' => $this->createReader($groupStore)]);
        self::assertSame('', $groupSubject->filter('{{var reader.getGroupSecret()}}'));
        self::assertSame(self::SECRET, $this->readGroupSecret($groupStore));
        self::assertSame('', $groupSubject->filter('{{var reader.getGroupSecret()}}'));
        self::assertSame(self::SECRET, $this->readGroupSecret($groupStore));

        // Other values are unaffected
        self::assertSame('en_US', $subject->filter('{{var reader.getPlain()}}'));
    }

    /**
     * Decryption returns an empty string while an expression is evaluated, which also covers the
     * card number that Mage_Payment_Model_Info decrypts on read.
     *
     * @group Model
     */
    public function testDecryptReturnsNothingWhileExpressionIsEvaluated(): void
    {
        /** @var Mage_Payment_Model_Info $payment */
        $payment = Mage::getModel('payment/info');
        $payment->setData('cc_number_enc', 'encrypted-card-number');

        $subject = $this->createSubject(['reader' => $this->createReader($this->createStore()), 'payment' => $payment]);

        self::assertSame('', $subject->filter('{{var reader.getDecrypted()}}'));
        self::assertSame('', $subject->filter('{{var payment.cc_number}}'));
        self::assertSame('', $subject->filter('{{var payment.getCcNumber()}}'));

        // Outside an expression the helper decrypts again
        self::assertSame('ciphertext', Mage::helper('core')->decrypt(Mage::helper('core')->encrypt('ciphertext')));
    }

    /**
     * Saving and deleting is refused while an expression is evaluated, in every template filter,
     * including the plain Varien_Filter_Template that renders newsletter subjects.
     *
     * @group Model
     */
    public function testSaveIsRefusedInPlainFilter(): void
    {
        $object = new class extends Varien_Object {
            public function getSaved(): string
            {
                Mage::getModel('core/observer')->secureVarProcessing(new Varien_Event_Observer());
                return 'saved';
            }
        };

        $subject = new Varien_Filter_Template();
        $subject->setVariables(['object' => $object]);

        $this->expectException(Mage_Core_Exception::class);
        $subject->filter('{{var object.getSaved()}}');
    }

    /**
     * A getter may render another template (an address format() does). The nested expression
     * must neither fail on the varProcessing flag nor clear it for the outer one.
     *
     * @group Model
     */
    public function testNestedExpressionsKeepTheProcessingFlag(): void
    {
        $object = new class extends Varien_Object {
            /** @var list<bool> */
            public array $flags = [];

            public function getOuter(): string
            {
                $nested = Mage::getModel('core/email_template_filter');
                $nested->setVariables(['object' => $this]);

                $result = $nested->filter('{{var object.getInner()}}');
                $this->flags[] = (bool) Mage::registry('varProcessing');

                return 'outer+' . $result;
            }

            public function getInner(): string
            {
                $this->flags[] = (bool) Mage::registry('varProcessing');
                return 'inner';
            }
        };

        $subject = $this->createSubject(['object' => $object]);

        self::assertSame('outer+inner', $subject->filter('{{var object.getOuter()}}'));
        self::assertSame([true, true], $object->flags);
        self::assertNull(Mage::registry('varProcessing'));
    }

    /**
     * The methods core templates call with arguments are declared, and still work.
     *
     * @group Model
     */
    public function testCoreTemplateCallsAreDeclared(): void
    {
        $declared = [];
        foreach ((new Varien_Filter_Template())->getAllowedVariableMethods() as [, $method]) {
            $declared[$method] = true;
        }

        $files = [];
        foreach ([
            '/app/locale/*/template/email/*.html',
            '/app/locale/*/template/email/*/*.html',
            '/app/locale/*/template/email/*/*/*.html',
            '/app/code/core/Mage/*/data/*/*.php',
        ] as $pattern) {
            $found = glob(Mage::getBaseDir() . $pattern);
            if ($found !== false) {
                $files = array_merge($files, $found);
            }
        }

        self::assertGreaterThan(0, count($files));

        $calls = 0;
        foreach ($files as $file) {
            preg_match_all('/{{(?:var|depend|if)\s+([^}|]+)|=\$([^\s}]+)/', (string) file_get_contents($file), $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $tokenizer = new Varien_Filter_Template_Tokenizer_Variable();
                $expression = trim($match[2] ?? '');
                $tokenizer->setString($expression !== '' ? $expression : trim($match[1] ?? ''));
                foreach ($tokenizer->tokenize() as $token) {
                    if ($token['type'] !== 'method') {
                        continue;
                    }

                    $calls++;
                    $method = $token['name'];
                    $allowed = isset($declared[strtolower($method)])
                        || ($token['args'] === [] && preg_match(Varien_Filter_Template::VARIABLE_GETTER_PATTERN, $method) === 1);
                    self::assertTrue($allowed, sprintf('%s calls %s(), which templates may not call', basename($file), $method));
                }
            }
        }

        self::assertGreaterThan(0, $calls);

        $order = new Mage_Sales_Model_Order();
        $order->setCreatedAt('2026-01-02 03:04:05');
        // The address renderer needs a database, so only the call is checked
        $address = new class extends Mage_Sales_Model_Order_Address {
            public function format($type): string
            {
                return 'formatted as ' . $type;
            }
        };
        $subject = $this->createSubject(['order' => $order, 'address' => $address]);
        self::assertSame('formatted as html', $subject->filter("{{var address.format('html')}}"));
        self::assertNotSame('', $subject->filter("{{var order.getCreatedAtFormated('long')}}"));
        self::assertStringContainsString('/customer/account/', (string) $subject->filter("{{var store.getUrl('customer/account/')}}"));
        self::assertSame('en_US', $subject->filter("{{var store.getConfig('general/locale/code')}}"));
    }

    private function config(): Mage_Core_Model_Config
    {
        $config = Mage::getConfig();
        self::assertInstanceOf(Mage_Core_Model_Config::class, $config);

        return $config;
    }

    private function readGroupSecret(Mage_Core_Model_Store $store): mixed
    {
        $group = $store->getConfig('paypal/wpp');
        self::assertIsArray($group);

        return $group['api_password'];
    }

    private function createStore(): Mage_Core_Model_Store
    {
        /** @var Mage_Core_Model_Store $store */
        $store = Mage::getModel('core/store');
        // Installed instances read stores/<code>/…; without a code there is no store config to read
        $store->setCode(self::SECRET_STORE_CODE);
        return $store;
    }

    private function createReader(Mage_Core_Model_Store $store): Varien_Object
    {
        return new class ($store) extends Varien_Object {
            public function __construct(private readonly Mage_Core_Model_Store $store)
            {
                parent::__construct();
            }

            public function getSecret(): string
            {
                return (string) $this->store->getConfig('paypal/wpp/api_password');
            }

            public function getGroupSecret(): string
            {
                $group = $this->store->getConfig('paypal/wpp');
                return is_array($group) ? (string) ($group['api_password'] ?? '') : '';
            }

            public function getPlain(): string
            {
                return (string) $this->store->getConfig('general/locale/code');
            }

            public function getDecrypted(): string
            {
                return Mage::helper('core')->decrypt('ciphertext');
            }
        };
    }

    /**
     * @param array<string, Varien_Object> $variables
     */
    private function createSubject(array $variables = []): Subject
    {
        /** @var Subject $subject */
        $subject = Mage::getModel('core/email_template_filter');
        $subject->setVariables($variables + [
            'store' => Mage::app()->getStore(),
            'payment' => Mage::getModel('payment/info'),
            'customer' => Mage::getModel('customer/customer'),
        ]);

        return $subject;
    }
}
