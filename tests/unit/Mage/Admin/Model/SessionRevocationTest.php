<?php

/**
 * @copyright  For copyright and license information, read the COPYING.txt file.
 * @link       /COPYING.txt
 * @license    Open Software License (OSL 3.0)
 * @package    OpenMage_Tests
 */

declare(strict_types=1);

namespace OpenMage\Tests\Unit\Mage\Admin\Model;

use Iterator;
use Mage_Admin_Model_Acl;
use Mage_Admin_Model_Acl_Role_Group;
use Mage_Admin_Model_Acl_Role_User;
use Mage_Admin_Model_Observer;
use Mage_Admin_Model_Resource_Roles;
use Mage_Admin_Model_Resource_Rules;
use Mage_Admin_Model_Resource_User;
use Mage_Admin_Model_Roles;
use Mage_Admin_Model_Rules;
use Mage_Admin_Model_Session;
use Mage_Admin_Model_User;
use OpenMage\Tests\Unit\OpenMageTest;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionMethod;
use ReflectionProperty;
use Varien_Db_Adapter_Pdo_Mysql;
use Zend_Db_Expr;

/**
 * Revoking an admin user's access must reach the sessions the user already has:
 * deactivation and role removal end them, and permission changes reach every one of them.
 */
final class SessionRevocationTest extends OpenMageTest
{
    /**
     * Every session of the user rebuilds its ACL after a change, not only the first one.
     *
     * @group Model
     */
    public function testEverySessionReloadsAfterAclVersionChange(): void
    {
        $resource = $this->createMock(Mage_Admin_Model_Resource_User::class);
        $resource->expects(self::never())->method('saveReloadAclFlag');
        $resource->expects(self::never())->method('bumpReloadAclFlag');

        $first = $this->createSession(5, 1);
        $second = $this->createSession(5, 1);

        $first->refreshAcl($this->createUser(6, $resource));
        $second->refreshAcl($this->createUser(6, $resource));

        self::assertSame(6, $first->getAclVersion());
        self::assertSame(6, $second->getAclVersion());
    }

    /**
     * @dataProvider provideRefreshAclData
     * @group Model
     */
    public function testRefreshAcl(?int $storedVersion, bool $hasAcl, int $userVersion, int $expectedLoads): void
    {
        $resource = $this->createMock(Mage_Admin_Model_Resource_User::class);
        $resource->expects(self::never())->method('saveReloadAclFlag');

        $session = $this->createSession($storedVersion, $expectedLoads, $hasAcl);
        $session->refreshAcl($this->createUser($userVersion, $resource));

        self::assertSame($userVersion, $session->getAclVersion());
    }

    /**
     * @return Iterator<string, array{(null|int), bool, int, int}>
     */
    public static function provideRefreshAclData(): Iterator
    {
        yield 'unchanged version keeps the ACL' => [7, true, 7, 0];
        yield 'never changed user keeps the ACL' => [0, true, 0, 0];
        yield 'changed version reloads' => [7, true, 8, 1];
        yield 'version wrapped around reloads' => [32000, true, 2, 1];
        yield 'session from before the upgrade reloads once' => [null, true, 0, 1];
        yield 'session without ACL loads it' => [7, false, 7, 1];
    }

    /**
     * A legacy "reload once" value of 1 becomes a new version, so it is not left in place.
     *
     * @group Model
     */
    public function testLegacyReloadFlagBecomesNewVersion(): void
    {
        $resource = $this->createMock(Mage_Admin_Model_Resource_User::class);
        $resource->expects(self::once())->method('bumpReloadAclFlag')->with([42])->willReturn(1);
        $resource->expects(self::once())->method('getReloadAclFlag')->willReturn(9);
        $resource->expects(self::never())->method('saveReloadAclFlag');

        $user = $this->createUser(Mage_Admin_Model_Resource_User::ACL_VERSION_LEGACY_RELOAD, $resource);
        $session = $this->createSession(8, 1);
        $session->refreshAcl($user);
        // a second refresh in the same request does not bump again
        $session->refreshAcl($user);

        self::assertSame(9, $session->getAclVersion());
        self::assertSame(9, $user->getReloadAclFlag());
    }

    /**
     * @dataProvider provideSaveReloadAclFlagData
     * @group Model
     */
    public function testSaveReloadAclFlag(int $flag, bool $expectBump): void
    {
        $resource = $this->createPartialMock(Mage_Admin_Model_Resource_User::class, ['bumpReloadAclFlag']);
        $resource->expects($expectBump ? self::once() : self::never())
            ->method('bumpReloadAclFlag')
            ->with([42]);

        $resource->saveReloadAclFlag((new Mage_Admin_Model_User())->setIdFieldName('user_id')->setId(42), $flag);
    }

    /**
     * @return Iterator<string, array{int, bool}>
     */
    public static function provideSaveReloadAclFlagData(): Iterator
    {
        yield 'request a reload' => [1, true];
        yield 'clearing is ignored' => [0, false];
    }

    /**
     * @group Model
     */
    public function testBumpReloadAclFlagIsOneAtomicUpdate(): void
    {
        $table = '';
        $bind = [];
        $where = [];
        $adapter = $this->createMock(Varien_Db_Adapter_Pdo_Mysql::class);
        $adapter->method('quoteIdentifier')->willReturnCallback(static fn(string $name): string => '`' . $name . '`');
        $adapter->expects(self::once())->method('update')->willReturnCallback(
            static function (string $updateTable, array $updateBind, array $updateWhere) use (&$table, &$bind, &$where): int {
                $table = $updateTable;
                $bind = $updateBind;
                $where = $updateWhere;
                return 2;
            },
        );

        $resource = $this->createPartialMock(Mage_Admin_Model_Resource_User::class, ['_getWriteAdapter', 'getMainTable']);
        $resource->method('_getWriteAdapter')->willReturn($adapter);
        $resource->method('getMainTable')->willReturn('admin_user');

        self::assertSame(2, $resource->bumpReloadAclFlag(['3', 0, '-1', 5]));

        self::assertSame('admin_user', $table);
        self::assertSame(['user_id IN (?)' => [3, 5]], $where);
        self::assertInstanceOf(Zend_Db_Expr::class, $bind['reload_acl_flag']);
        self::assertSame(
            'CASE WHEN `reload_acl_flag` >= 2 AND `reload_acl_flag` < 32000 THEN `reload_acl_flag` + 1 ELSE 2 END',
            (string) $bind['reload_acl_flag'],
        );
    }

    /**
     * @group Model
     */
    public function testBumpReloadAclFlagWithoutUsersDoesNothing(): void
    {
        $resource = $this->createPartialMock(Mage_Admin_Model_Resource_User::class, ['_getWriteAdapter']);
        $resource->expects(self::never())->method('_getWriteAdapter');

        self::assertSame(0, $resource->bumpReloadAclFlag([]));
    }

    /**
     * @group Model
     */
    public function testDeleteFromRoleReloadsUserAcl(): void
    {
        $log = [];
        $adapter = $this->createMock(Varien_Db_Adapter_Pdo_Mysql::class);
        $adapter->method('delete')->willReturnCallback(static function () use (&$log): int {
            $log[] = 'delete';
            return 1;
        });

        $resource = $this->createPartialMock(
            Mage_Admin_Model_Resource_User::class,
            ['_getWriteAdapter', 'getTable', 'saveReloadAclFlag'],
        );
        $resource->method('_getWriteAdapter')->willReturn($adapter);
        $resource->method('getTable')->willReturn('admin_role');
        $resource->expects(self::once())->method('saveReloadAclFlag')->willReturnCallback(
            static function (Mage_Admin_Model_User $user, int $flag) use (&$log, $resource): Mage_Admin_Model_Resource_User {
                $log[] = 'reload ' . $user->getId() . ':' . $flag;
                return $resource;
            },
        );

        $user = (new Mage_Admin_Model_User())->setIdFieldName('user_id')->setUserId(42)->setRoleId(3);
        $resource->deleteFromRole($user);

        self::assertSame(['delete', 'reload 42:1'], $log);
    }

    /**
     * Members are read before their rows are deleted, and their ACL is reloaded.
     *
     * @group Model
     */
    public function testRoleDeleteReloadsMembersAcl(): void
    {
        $log = [];
        $adapter = $this->createMock(Varien_Db_Adapter_Pdo_Mysql::class);
        $adapter->method('delete')->willReturnCallback(static function (string $table) use (&$log): int {
            $log[] = 'delete ' . $table;
            return 1;
        });

        $userResource = $this->createMock(Mage_Admin_Model_Resource_User::class);
        $userResource->expects(self::once())->method('bumpReloadAclFlag')->willReturnCallback(
            static function (array $userIds) use (&$log): int {
                $log[] = 'reload ' . implode(',', $userIds);
                return count($userIds);
            },
        );

        $resource = $this->createPartialMock(
            Mage_Admin_Model_Resource_Roles::class,
            ['getRoleUsers', '_getWriteAdapter', 'getMainTable', '_getUserResource'],
        );
        $resource->method('getRoleUsers')->willReturnCallback(static function () use (&$log): array {
            $log[] = 'read members';
            return ['4', '5'];
        });
        $resource->method('_getWriteAdapter')->willReturn($adapter);
        $resource->method('getMainTable')->willReturn('admin_role');
        $resource->method('_getUserResource')->willReturn($userResource);
        (new ReflectionProperty(Mage_Admin_Model_Resource_Roles::class, '_ruleTable'))->setValue($resource, 'admin_rule');

        $afterDelete = new ReflectionMethod(Mage_Admin_Model_Resource_Roles::class, '_afterDelete');
        $afterDelete->invoke($resource, (new Mage_Admin_Model_Roles())->setIdFieldName('role_id')->setId(3));

        self::assertSame(['read members', 'delete admin_role', 'delete admin_rule', 'reload 4,5'], $log);
    }

    /**
     * Members reload their ACL only after the role's new rules are committed.
     *
     * @group Model
     */
    public function testSaveRelReloadsMembersAfterCommit(): void
    {
        $log = [];
        $adapter = $this->createMock(Varien_Db_Adapter_Pdo_Mysql::class);
        $adapter->method('beginTransaction')->willReturnCallback(static function () use (&$log, $adapter): Varien_Db_Adapter_Pdo_Mysql {
            $log[] = 'begin';
            return $adapter;
        });
        $adapter->method('delete')->willReturnCallback(static function () use (&$log): int {
            $log[] = 'delete rules';
            return 1;
        });
        $adapter->method('commit')->willReturnCallback(static function () use (&$log, $adapter): Varien_Db_Adapter_Pdo_Mysql {
            $log[] = 'commit';
            return $adapter;
        });

        $resource = $this->createPartialMock(
            Mage_Admin_Model_Resource_Rules::class,
            ['_getWriteAdapter', 'getMainTable', '_reloadRoleUsersAcl'],
        );
        $resource->method('_getWriteAdapter')->willReturn($adapter);
        $resource->method('getMainTable')->willReturn('admin_rule');
        $resource->expects(self::once())->method('_reloadRoleUsersAcl')->with(3)->willReturnCallback(
            static function () use (&$log): void {
                $log[] = 'reload members';
            },
        );

        $resource->saveRel((new Mage_Admin_Model_Rules())->setRoleId(3)->setResources([]));

        self::assertSame(['begin', 'delete rules', 'commit', 'reload members'], $log);
    }

    /**
     * @dataProvider provideSessionUserAllowedData
     * @group Model
     */
    public function testSessionUserAllowed(null|int|string $isActive, bool $hasRole, bool $expected): void
    {
        $acl = new Mage_Admin_Model_Acl();
        $acl->addRole(new Mage_Admin_Model_Acl_Role_Group('G1'));
        if ($hasRole) {
            $acl->addRole(new Mage_Admin_Model_Acl_Role_User('U42'), 'G1');
        }

        $session = $this->createPartialMock(Mage_Admin_Model_Session::class, ['refreshAcl']);
        $session->method('refreshAcl')->willReturnSelf();
        $session->setAcl($acl);

        $user = (new Mage_Admin_Model_User())->setIdFieldName('user_id')->setUserId(42)->setIsActive($isActive);

        $method = new ReflectionMethod(Mage_Admin_Model_Observer::class, '_isSessionUserAllowed');
        self::assertSame($expected, $method->invoke(new Mage_Admin_Model_Observer(), $session, $user));
    }

    /**
     * @return Iterator<string, array{(null|int|string), bool, bool}>
     */
    public static function provideSessionUserAllowedData(): Iterator
    {
        yield 'active user with role' => ['1', true, true];
        yield 'active user with role (int)' => [1, true, true];
        yield 'inactive user' => ['0', true, false];
        yield 'inactive user (int)' => [0, true, false];
        yield 'missing status' => [null, true, false];
        yield 'active user without role' => ['1', false, false];
    }

    /**
     * @return Mage_Admin_Model_Session&MockObject
     */
    private function createSession(?int $version, int $expectedLoads, bool $hasAcl = true): MockObject
    {
        $session = $this->createPartialMock(Mage_Admin_Model_Session::class, ['_loadAcl']);
        $session->expects(self::exactly($expectedLoads))->method('_loadAcl')->willReturn(new Mage_Admin_Model_Acl());
        if ($hasAcl) {
            $session->setAcl(new Mage_Admin_Model_Acl());
        }

        if ($version !== null) {
            $session->setAclVersion($version);
        }

        return $session;
    }

    /**
     * @return Mage_Admin_Model_User&MockObject
     */
    private function createUser(int $version, Mage_Admin_Model_Resource_User $resource): MockObject
    {
        $user = $this->createPartialMock(Mage_Admin_Model_User::class, ['getResource']);
        $user->method('getResource')->willReturn($resource);
        $user->setIdFieldName('user_id')->setId(42)->setReloadAclFlag($version);

        return $user;
    }
}
