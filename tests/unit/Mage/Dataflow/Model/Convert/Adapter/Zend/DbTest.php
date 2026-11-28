<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Dataflow\Model\Convert\Adapter\Zend;

use Generator;
use Mage_Dataflow_Model_Convert_Adapter_Abstract;
use Mage_Dataflow_Model_Convert_Adapter_Db_Table;
use Mage_Dataflow_Model_Convert_Adapter_Zend_Db as Subject;
use Mage_Dataflow_Model_Convert_Exception;
use OpenMage\Tests\Unit\OpenMageTest;
use OpenMage\Tests\Unit\Traits\DataProvider\Mage\Dataflow\Model\Convert\Adapter\Zend\DbTrait;
use Zend_Db_Adapter_Abstract;
use Zend_Db_Adapter_Mysqli;
use Zend_Db_Adapter_Pdo_Mysql;

/**
 * Covers the Zend_Db and Db_Table containers, which share Subject::createDbAdapter()
 */
final class DbTest extends OpenMageTest
{
    use DbTrait;

    private const CONNECTION = [
        'host'     => 'db.example',
        'port'     => '3307',
        'username' => 'user',
        'password' => 'secret',
        'dbname'   => 'shop',
        'charset'  => 'utf8mb4',
    ];

    public static bool $included = false;

    public static function provideContainers(): Generator
    {
        yield 'zend_db' => [Subject::class, 'adapter'];
        yield 'db_table' => [Mage_Dataflow_Model_Convert_Adapter_Db_Table::class, 'type'];
    }

    public static function provideRefusedAdapters(): Generator
    {
        foreach (self::provideContainers() as $name => [$class, $adapterVar]) {
            foreach (['Pdo_Pgsql', 'Pdo_Sqlite', 'Pdo_Mssql', 'Sqlsrv', 'Db2', 'Oracle', 'Array'] as $adapter) {
                yield $name . ' ' . $adapter => [$class, $adapterVar, $adapter];
            }
        }
    }

    public static function provideAllowedAdapters(): Generator
    {
        foreach (self::provideContainers() as $name => [$class, $adapterVar]) {
            yield $name . ' Pdo_Mysql' => [$class, $adapterVar, 'Pdo_Mysql', Zend_Db_Adapter_Pdo_Mysql::class, 'unix_socket'];
            yield $name . ' pdo_mysql' => [$class, $adapterVar, 'pdo_mysql', Zend_Db_Adapter_Pdo_Mysql::class, 'unix_socket'];
            yield $name . ' Mysqli' => [$class, $adapterVar, 'Mysqli', Zend_Db_Adapter_Mysqli::class, 'socket'];
        }
    }

    /**
     * The reported chain: adapterNamespace + "array" constructs Zend_Translate_Adapter_Array,
     * which includes the file named in "content"
     *
     * @dataProvider provideContainers
     * @group Mage_Dataflow
     */
    public function testAdapterNamespaceCannotConstructAnotherClass(string $class, string $adapterVar): void
    {
        $file = tempnam(sys_get_temp_dir(), 'om-dataflow-db');
        self::assertIsString($file);
        file_put_contents($file, '<?php \\' . self::class . '::$included = true; return [];');
        self::$included = false;

        $container = $this->createContainer($class, [
            $adapterVar        => 'array',
            'adapterNamespace' => 'Zend_Translate_Adapter',
            'content'          => $file,
        ] + self::CONNECTION);

        try {
            $container->getResource();
            self::fail('The Array adapter was accepted');
        } catch (Mage_Dataflow_Model_Convert_Exception $mageDataflowModelConvertException) {
            self::assertSame(Mage_Dataflow_Model_Convert_Exception::FATAL, $mageDataflowModelConvertException->getLevel());
        } finally {
            unlink($file);
        }

        self::assertFalse(self::$included, 'The profile included a file of its choosing');
    }

    /**
     * @dataProvider provideContainers
     * @group Mage_Dataflow
     */
    public function testAdapterNamespaceIsIgnoredForAllowedAdapter(string $class, string $adapterVar): void
    {
        $container = $this->createContainer($class, [
            $adapterVar        => 'Pdo_Mysql',
            'adapterNamespace' => 'Varien_Db_Adapter',
        ] + self::CONNECTION);

        self::assertSame(Zend_Db_Adapter_Pdo_Mysql::class, $container->getResource()::class);
    }

    /**
     * @dataProvider provideRefusedAdapters
     * @group Mage_Dataflow
     */
    public function testOtherAdaptersAreRefused(string $class, string $adapterVar, string $adapter): void
    {
        $container = $this->createContainer($class, [$adapterVar => $adapter] + self::CONNECTION);

        $this->expectException(Mage_Dataflow_Model_Convert_Exception::class);
        $this->expectExceptionMessage('Unsupported database adapter.');

        $container->getResource();
    }

    /**
     * @dataProvider provideAllowedAdapters
     * @group Mage_Dataflow
     */
    public function testOnlyConnectionSettingsArePassed(
        string $class,
        string $adapterVar,
        string $adapter,
        string $expectedClass,
        string $socketKey,
    ): void {
        self::assertTrue(is_subclass_of($expectedClass, Zend_Db_Adapter_Abstract::class));
        $container = $this->createContainer($class, [
            $adapterVar      => $adapter,
            $socketKey       => '/run/mysqld/mysqld.sock',
            'persistent'     => '1',
            'profiler'       => '1',
            'options'        => 'x',
            'driver_options' => 'x',
            'unknown'        => 'x',
        ] + self::CONNECTION);

        $resource = $container->getResource();
        self::assertInstanceOf($expectedClass, $resource);

        $config = $resource->getConfig();
        foreach (self::CONNECTION + [$socketKey => '/run/mysqld/mysqld.sock'] as $key => $value) {
            self::assertSame($value, $config[$key], $key);
        }

        self::assertFalse($config['persistent']);
        self::assertSame([], $config['driver_options']);
        self::assertArrayNotHasKey('unknown', $config);
        self::assertArrayNotHasKey($adapterVar, $config);
        self::assertFalse($resource->getProfiler()->getEnabled());
    }

    /**
     * Pdo_Mysql puts the charset into a SET NAMES statement
     *
     * @dataProvider provideContainers
     * @group Mage_Dataflow
     */
    public function testCharsetMustBeAnIdentifier(string $class, string $adapterVar): void
    {
        $container = $this->createContainer($class, [
            $adapterVar => 'Pdo_Mysql',
            'charset'   => "utf8'; DROP TABLE admin_user; --",
        ] + self::CONNECTION);

        $this->expectException(Mage_Dataflow_Model_Convert_Exception::class);
        $this->expectExceptionMessage('Invalid value for the database setting "charset".');

        $container->getResource();
    }

    /**
     * @group Mage_Dataflow
     */
    public function testNormalizeAdapterNameMatchesZendDb(): void
    {
        self::assertSame('Pdo_Mysql', Subject::normalizeAdapterName('PDO_MYSQL'));
        self::assertSame('Pdo_Mysql', Subject::normalizeAdapterName('pdo mysql'));
        self::assertSame('Mysqli', Subject::normalizeAdapterName('mysqli'));
    }

    /**
     * @param array<string, string> $vars
     */
    private function createContainer(string $class, array $vars): Mage_Dataflow_Model_Convert_Adapter_Abstract
    {
        $container = new $class();
        self::assertInstanceOf(Mage_Dataflow_Model_Convert_Adapter_Abstract::class, $container);
        $container->setVar($vars);

        return $container;
    }
}
