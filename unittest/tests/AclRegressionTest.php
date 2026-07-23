<?php

declare(strict_types=1);

use orange\acl\Acl;
use orange\validate\Validate;
use orange\acl\exceptions\AclException;
use orange\validate\exceptions\ValidationFailed;
use orange\acl\exceptions\RecordNotFoundException;

/**
 * Regression coverage for the role/permission linking, output shapes, and
 * data-integrity behavior the original AclTest didn't reach.
 */
final class AclRegressionTest extends unitTestHelper
{
    protected $instance;
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // mirrors support/*.sql (sqlite dialect)
        $this->pdo->exec('CREATE TABLE `orange_users` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `username` TEXT NOT NULL,
            `email` TEXT NOT NULL,
            `password` TEXT NOT NULL,
            `is_active` INTEGER NOT NULL DEFAULT 0,
            `is_deleted` INTEGER NOT NULL DEFAULT 0
        )');

        $this->pdo->exec('CREATE TABLE `orange_roles` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `name` TEXT NOT NULL,
            `description` TEXT NOT NULL,
            `migration` TEXT,
            `is_active` INTEGER NOT NULL DEFAULT 1
        )');

        $this->pdo->exec('CREATE TABLE `orange_permissions` (
            `id` INTEGER PRIMARY KEY AUTOINCREMENT,
            `key` TEXT NOT NULL,
            `description` TEXT NOT NULL,
            `group` TEXT NOT NULL,
            `migration` TEXT,
            `is_active` INTEGER NOT NULL DEFAULT 1
        )');

        $this->pdo->exec('CREATE TABLE `orange_user_role` (
            `user_id` INTEGER NOT NULL,
            `role_id` INTEGER NOT NULL,
            PRIMARY KEY (`user_id`, `role_id`)
        )');

        $this->pdo->exec('CREATE TABLE `orange_role_permission` (
            `role_id` INTEGER NOT NULL,
            `permission_id` INTEGER NOT NULL,
            PRIMARY KEY (`role_id`, `permission_id`)
        )');

        $this->pdo->exec('CREATE TABLE `orange_user_meta` (
            `id` INTEGER PRIMARY KEY,
            `dashboard_url` TEXT,
            `phone` TEXT,
            `ext` TEXT
        )');

        // the isUnique validation rule looks up the PDO connection through the
        // container by service name (defaults to "pdo")
        \orange\framework\Container::getInstance()->set('pdo', $this->pdo);

        $this->instance = Acl::newInstance([], $this->pdo, Validate::newInstance([]));
    }

    private function makeUser(string $name = 'dmyers'): \orange\acl\interfaces\UserEntityInterface
    {
        return $this->instance->createUser($name, $name . '@example.com', 'password123', ['is_active' => 1]);
    }

    /* role <-> user linking (needs the Acl wired into the models) */

    public function testAddRoleByNameEntityAndId(): void
    {
        $user = $this->makeUser();

        $editor = $this->instance->createRole('editor', 'Can edit');
        $author = $this->instance->createRole('author', 'Can author');
        $poster = $this->instance->createRole('poster', 'Can post');

        // string resolves through the wired acl, entity and id resolve locally
        $this->assertTrue($user->addRole('editor'));
        $this->assertTrue($user->addRole($author));
        $this->assertTrue($user->addRole((int)$poster->id));

        $fresh = $this->instance->getUser($user->id);

        $this->assertTrue($fresh->hasRole((int)$editor->id));
        $this->assertTrue($fresh->hasRole((int)$author->id));
        $this->assertTrue($fresh->hasRole((int)$poster->id));
    }

    public function testHasRoleByName(): void
    {
        $user = $this->makeUser();
        $this->instance->createRole('editor', 'Can edit');

        $user->addRole('editor');

        $fresh = $this->instance->getUser($user->id);

        $this->assertTrue($fresh->hasRole('editor'));
        $this->assertFalse($fresh->hasRole('publisher'));
    }

    public function testRemoveRoleByName(): void
    {
        $user = $this->makeUser();
        $role = $this->instance->createRole('editor', 'Can edit');

        $user->addRole($role);
        $this->assertTrue($user->removeRole('editor'));

        $this->assertFalse($this->instance->getUser($user->id)->hasRole((int)$role->id));
    }

    public function testRelinkRolesReplacesAtomicallyAndReturnsTrue(): void
    {
        $user = $this->makeUser();

        $r1 = $this->instance->createRole('roleone', 'Role One');
        $r2 = $this->instance->createRole('roletwo', 'Role Two');
        $r3 = $this->instance->createRole('rolethree', 'Role Three');

        $user->addRole($r1);

        // returns TRUE on success (was inverted before the fix)
        $this->assertTrue($this->instance->userModel->relink((int)$user->id, [(int)$r2->id, (int)$r3->id]));

        $fresh = $this->instance->getUser($user->id);

        $this->assertFalse($fresh->hasRole((int)$r1->id));
        $this->assertTrue($fresh->hasRole((int)$r2->id));
        $this->assertTrue($fresh->hasRole((int)$r3->id));
    }

    /* role <-> permission linking */

    public function testAddAndRemovePermissionByStringKey(): void
    {
        $role = $this->instance->createRole('editor', 'Can edit');
        $permission = $this->instance->createPermission('posts.edit', 'Edit posts', 'posts');

        // a string resolves through getPermission() (was getRole() before the fix)
        $this->assertTrue($role->addPermission('posts.edit'));

        $user = $this->makeUser();
        $user->addRole($role);

        $this->assertTrue($this->instance->getUser($user->id)->can('posts.edit'));

        $this->assertTrue($role->removePermission('posts.edit'));

        $this->assertFalse($this->instance->getUser($user->id)->can('posts.edit'));
    }

    public function testRemoveAllPermissionsOnlyClearsThatRole(): void
    {
        $roleA = $this->instance->createRole('rolea', 'Role A');
        $roleB = $this->instance->createRole('roleb', 'Role B');

        $p1 = $this->instance->createPermission('perm.one', 'Perm One', 'test');
        $p2 = $this->instance->createPermission('perm.two', 'Perm Two', 'test');

        $roleA->addPermission($p1);
        $roleB->addPermission($p2);

        // deleted by role_id (was a nonexistent user_id column before the fix)
        $this->assertTrue($roleA->removeAllPermissions());

        $count = fn (int $roleId): int => (int)$this->pdo
            ->query('select count(*) from `orange_role_permission` where `role_id` = ' . $roleId)
            ->fetchColumn();

        $this->assertSame(0, $count((int)$roleA->id));
        $this->assertSame(1, $count((int)$roleB->id));
    }

    public function testRelinkPermissionsReplacesAndReturnsTrue(): void
    {
        $role = $this->instance->createRole('editor', 'Can edit');

        $p1 = $this->instance->createPermission('perm.one', 'Perm One', 'test');
        $p2 = $this->instance->createPermission('perm.two', 'Perm Two', 'test');

        $role->addPermission($p1);

        $this->assertTrue($this->instance->roleModel->relink((int)$role->id, [(int)$p2->id]));

        $user = $this->makeUser();
        $user->addRole($role);

        $fresh = $this->instance->getUser($user->id);

        $this->assertFalse($fresh->can('perm.one'));
        $this->assertTrue($fresh->can('perm.two'));
    }

    /* getRolesPermissions shapes */

    public function testRoleWithNoPermissionsStillCounts(): void
    {
        $user = $this->makeUser();
        $role = $this->instance->createRole('empty', 'No permissions at all');

        $user->addRole($role);

        // before the fix the is_active filter on the joined permission table
        // silently dropped permissionless roles from the result
        $fresh = $this->instance->getUser($user->id);

        $this->assertTrue($fresh->hasRole((int)$role->id));
    }

    public function testInactiveRoleAndPermissionAreExcluded(): void
    {
        $user = $this->makeUser();

        // consume ids 1 and 2 so neither test role collides with the
        // 'everyone role' => 2 pseudo-role every user implicitly has
        $this->instance->createRole('filler-one', 'Filler role one');
        $this->instance->createRole('filler-two', 'Filler role two');

        $activeRole = $this->instance->createRole('activerole', 'Active role');
        $inactiveRole = $this->instance->createRole('inactiverole', 'Inactive role');

        $keep = $this->instance->createPermission('perm.keep', 'Kept', 'test');
        $drop = $this->instance->createPermission('perm.drop', 'Dropped', 'test');

        $activeRole->addPermission($keep);
        $activeRole->addPermission($drop);
        $inactiveRole->addPermission($keep);

        $user->addRole($activeRole);
        $user->addRole($inactiveRole);

        $inactiveRole->deactivate();
        $drop->deactivate();

        $fresh = $this->instance->getUser($user->id);

        $this->assertTrue($fresh->hasRole((int)$activeRole->id));
        $this->assertFalse($fresh->hasRole((int)$inactiveRole->id));
        $this->assertTrue($fresh->can('perm.keep'));
        $this->assertFalse($fresh->can('perm.drop'));
    }

    public function testEveryoneRoleIsAlwaysPresent(): void
    {
        $user = $this->makeUser();

        $fresh = $this->instance->getUser($user->id);

        $this->assertTrue($fresh->hasRole('Everyone'));
    }

    /* data integrity */

    public function testDeleteSoftDeletesTheRow(): void
    {
        $user = $this->makeUser();

        $this->assertTrue($this->instance->userModel->delete((int)$user->id));

        // is_deleted = 1 (the original set it back to 0)
        $row = $this->pdo->query('select `is_deleted` from `orange_users` where `id` = ' . (int)$user->id)->fetchColumn();

        $this->assertSame(1, (int)$row);
    }

    public function testDeactivateAndActivate(): void
    {
        $user = $this->makeUser();

        $this->assertTrue($user->deactivate());
        $this->assertSame(0, $this->instance->getUser($user->id)->is_active);

        $this->assertTrue($user->activate());
        $this->assertSame(1, $this->instance->getUser($user->id)->is_active);
    }

    public function testCreateUserIgnoresUnvalidatedColumns(): void
    {
        // is_deleted is not in the create ruleset; bogus isn't a column at all -
        // before the fix both went straight into the INSERT
        $user = $this->instance->createUser('dmyers', 'dmyers@example.com', 'password123', [
            'is_active' => 1,
            'is_deleted' => 1,
            'bogus' => 'x',
        ]);

        $row = $this->pdo->query('select `is_deleted` from `orange_users` where `id` = ' . (int)$user->id)->fetchColumn();

        $this->assertSame(0, (int)$row);
    }

    public function testCreateUserExplicitArgumentsWinOverFields(): void
    {
        $user = $this->instance->createUser('dmyers', 'dmyers@example.com', 'password123', [
            'username' => 'evil',
            'is_active' => 1,
        ]);

        $this->assertSame('dmyers', $user->username);
    }

    public function testPasswordIsAlwaysHashedEvenWhenItLooksLikeAHash(): void
    {
        // a plaintext that parses as a bcrypt hash - the old heuristic stored it verbatim
        $bcryptShaped = password_hash('unrelated', PASSWORD_BCRYPT);

        $user = $this->instance->createUser('dmyers', 'dmyers@example.com', $bcryptShaped, ['is_active' => 1]);

        $stored = $this->pdo->query('select `password` from `orange_users` where `id` = ' . (int)$user->id)->fetchColumn();

        $this->assertNotSame($bcryptShaped, $stored);
        $this->assertTrue(password_verify($bcryptShaped, $stored));
    }

    public function testCreateUserWritesTheMetaRow(): void
    {
        $user = $this->instance->createUser('dmyers', 'dmyers@example.com', 'password123', [
            'is_active' => 1,
            'phone' => '555-1234',
            'ext' => '77',
            'dashboard_url' => '/dash',
        ]);

        $this->assertSame('555-1234', $user->phone);
        $this->assertSame('77', $user->ext);
        $this->assertSame('/dash', $user->dashboard_url);
    }

    public function testEntityMetaSetBeforeAnyReadThenUpdatePersists(): void
    {
        $user = $this->instance->createUser('dmyers', 'dmyers@example.com', 'password123', [
            'is_active' => 1,
            'phone' => '555-1234',
        ]);

        $fresh = $this->instance->getUser($user->id);

        // __set before any __get - lazyLoad now runs in __set too
        $fresh->phone = '555-9999';
        $fresh->email = 'new@example.com';

        $this->assertTrue($fresh->update());

        $again = $this->instance->getUser($user->id);

        $this->assertSame('new@example.com', $again->email);
        $this->assertSame('555-9999', $again->phone);
    }

    public function testUserWithoutMetaRowStillWorks(): void
    {
        // seeded outside createUser() - no meta row exists
        $this->pdo->exec('insert into `orange_users` (`username`,`email`,`password`,`is_active`) values (\'raw\', \'raw@example.com\', \'x\', 1)');
        $id = (int)$this->pdo->lastInsertId();

        $entity = $this->instance->getUser($id);

        // lazyLoad meta resolves to [] instead of throwing
        $this->assertFalse($entity->can('anything'));
        $this->assertTrue($entity->loggedIn());
    }

    public function testReadAllReturnsPlainRowsEvenAfterRead(): void
    {
        $user = $this->makeUser();

        // read() switches the shared Sql instance to entity fetch mode
        $this->instance->getUser($user->id);

        $rows = $this->instance->userModel->readAll();

        $this->assertIsArray($rows[0]);
        $this->assertSame('dmyers', $rows[0]['username']);
    }

    public function testRecordNotFoundCarries404AndBaseException(): void
    {
        try {
            $this->instance->getUser(999);

            $this->fail('expected RecordNotFoundException');
        } catch (RecordNotFoundException $e) {
            $this->assertSame(404, $e->getCode());
            $this->assertInstanceOf(AclException::class, $e);
        }
    }

    /* fail-fast construction */

    public function testInvalidTableIdentifierThrowsAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Acl::newInstance(['user table' => 'bad`name; drop --'], $this->pdo, Validate::newInstance([]));
    }

    public function testModelClassMustImplementItsInterface(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Acl::newInstance(['userModel' => \stdClass::class], $this->pdo, Validate::newInstance([]));
    }

    public function testConstructorForcesPdoExceptionMode(): void
    {
        // a handle deliberately created in silent mode
        $silent = new PDO('sqlite::memory:');

        Acl::newInstance([], $silent, Validate::newInstance([]));

        $this->assertSame(PDO::ERRMODE_EXCEPTION, $silent->getAttribute(PDO::ATTR_ERRMODE));
    }

    /* normalization & password policy */

    public function testEmailIsNormalizedOnCreateAndUpdate(): void
    {
        // stored trimmed + lowercase, matching orange/auth's normalized login
        $user = $this->instance->createUser('dmyers', '  DMyers@Example.COM  ', 'password123', ['is_active' => 1]);

        $this->assertSame('dmyers@example.com', $user->email);

        $fresh = $this->instance->getUser($user->id);
        $fresh->email = '  NEW@Example.COM ';

        $this->assertTrue($fresh->update());
        $this->assertSame('new@example.com', $this->instance->getUser($user->id)->email);
    }

    public function testUpdatePasswordEnforcesThePasswordPolicy(): void
    {
        $user = $this->makeUser();

        $this->expectException(ValidationFailed::class);

        $user->updatePassword('ab'); // below minLength[4]
    }

    public function testUpdatePasswordHashesAndStores(): void
    {
        $user = $this->makeUser();

        $this->assertTrue($user->updatePassword('a-new-password'));

        $stored = $this->pdo->query('select `password` from `orange_users` where `id` = ' . (int)$user->id)->fetchColumn();

        $this->assertTrue(password_verify('a-new-password', $stored));
    }
}
