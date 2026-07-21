<?php

declare(strict_types=1);

use peels\acl\Acl;
use peels\validate\Validate;
use peels\acl\models\UserModel;
use peels\acl\models\RoleModel;
use peels\acl\interfaces\AclInterface;
use peels\acl\models\PermissionModel;
use peels\acl\interfaces\RoleEntityInterface;
use peels\acl\interfaces\UserEntityInterface;
use peels\validate\exceptions\ValidationFailed;
use peels\acl\exceptions\RecordNotFoundException;
use peels\acl\interfaces\PermissionEntityInterface;

final class AclTest extends unitTestHelper
{
    protected $instance;
    protected PDO $pdo;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

        // Note: UserEntity's __set() throws on any DB column it doesn't declare a
        // property for, so this deliberately omits orange_users.start_page_url/meta
        // (present in support/orange_users.sql but not on UserEntity).
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
            `role_id` INTEGER NOT NULL
        )');

        $this->pdo->exec('CREATE TABLE `orange_role_permission` (
            `role_id` INTEGER NOT NULL,
            `permission_id` INTEGER NOT NULL
        )');

        // no support/*.sql ships a schema for this table - columns inferred from
        // UserMetaModel's own $rules
        $this->pdo->exec('CREATE TABLE `orange_user_meta` (
            `id` INTEGER NOT NULL,
            `dashboard_url` TEXT,
            `phone` TEXT,
            `ext` TEXT
        )');

        // the isUnique validation rule looks up the PDO connection through the
        // container by service name (defaults to "pdo")
        \orange\framework\Container::getInstance()->set('pdo', $this->pdo);

        $this->instance = Acl::newInstance([], $this->pdo, Validate::newInstance([]));
    }

    public function testConstructWiresUserRoleAndPermissionModels(): void
    {
        $this->assertInstanceOf(UserModel::class, $this->instance->userModel);
        $this->assertInstanceOf(RoleModel::class, $this->instance->roleModel);
        $this->assertInstanceOf(PermissionModel::class, $this->instance->permissionModel);
    }

    public function testCreateUserReturnsUserEntityWithExpectedFields(): void
    {
        $user = $this->instance->createUser('dmyers', 'dmyers@example.com', 'password123', ['is_active' => 1]);

        $this->assertInstanceOf(UserEntityInterface::class, $user);
        $this->assertGreaterThan(0, $user->id);
        $this->assertSame('dmyers', $user->username);
        $this->assertSame('dmyers@example.com', $user->email);
        $this->assertSame(1, $user->is_active);
    }

    public function testCreateUserHashesThePassword(): void
    {
        $this->instance->createUser('dmyers', 'dmyers@example.com', 'password123');

        $stored = $this->pdo->query('select `password` from `orange_users` where `username` = \'dmyers\'')->fetchColumn();

        $this->assertNotSame('password123', $stored);
        $this->assertTrue(password_verify('password123', $stored));
    }

    public function testCreateUserDefaultsIsActiveToZeroWhenNotSpecified(): void
    {
        $user = $this->instance->createUser('dmyers', 'dmyers@example.com', 'password123');

        $this->assertSame(0, $user->is_active);
    }

    public function testCreateUserThrowsValidationFailedForDuplicateEmail(): void
    {
        $this->instance->createUser('dmyers', 'dmyers@example.com', 'password123');

        $this->expectException(ValidationFailed::class);

        $this->instance->createUser('someoneelse', 'dmyers@example.com', 'password123');
    }

    public function testCreateUserThrowsValidationFailedForShortUsername(): void
    {
        $this->expectException(ValidationFailed::class);

        $this->instance->createUser('ab', 'dmyers@example.com', 'password123');
    }

    public function testGetUserReturnsThePreviouslyCreatedUser(): void
    {
        $created = $this->instance->createUser('dmyers', 'dmyers@example.com', 'password123', ['is_active' => 1]);

        $fetched = $this->instance->getUser($created->id);

        $this->assertInstanceOf(UserEntityInterface::class, $fetched);
        $this->assertSame($created->id, $fetched->id);
        $this->assertSame('dmyers', $fetched->username);
    }

    public function testGetUserThrowsForMissingUser(): void
    {
        $this->expectException(RecordNotFoundException::class);

        $this->instance->getUser(999);
    }

    public function testCreateRoleReturnsRoleEntityWithExpectedFields(): void
    {
        $role = $this->instance->createRole('admin', 'Administrator');

        $this->assertInstanceOf(RoleEntityInterface::class, $role);
        $this->assertGreaterThan(0, $role->id);
        $this->assertSame('admin', $role->name);
        $this->assertSame('Administrator', $role->description);
    }

    public function testCreateRoleThrowsValidationFailedForDuplicateName(): void
    {
        $this->instance->createRole('admin', 'Administrator');

        $this->expectException(ValidationFailed::class);

        $this->instance->createRole('admin', 'Some Other Description');
    }

    public function testGetRoleByIdMatchesGetRoleByName(): void
    {
        $created = $this->instance->createRole('admin', 'Administrator');

        $byId = $this->instance->getRole($created->id);
        $byName = $this->instance->getRole('admin');

        $this->assertSame($created->id, $byId->id);
        $this->assertSame($created->id, $byName->id);
    }

    public function testGetRoleThrowsForMissingRole(): void
    {
        $this->expectException(RecordNotFoundException::class);

        $this->instance->getRole('nobody');
    }

    public function testCreatePermissionReturnsPermissionEntityWithExpectedFields(): void
    {
        $permission = $this->instance->createPermission('uri://open/file', 'Open File', 'File');

        $this->assertInstanceOf(PermissionEntityInterface::class, $permission);
        $this->assertGreaterThan(0, $permission->id);
        $this->assertSame('uri://open/file', $permission->key);
        $this->assertSame('Open File', $permission->description);
        $this->assertSame('File', $permission->group);
    }

    public function testCreatePermissionThrowsValidationFailedForDuplicateKey(): void
    {
        $this->instance->createPermission('uri://open/file', 'Open File', 'File');

        $this->expectException(ValidationFailed::class);

        $this->instance->createPermission('uri://open/file', 'Open File Again', 'File');
    }

    public function testGetPermissionByIdMatchesGetPermissionByKey(): void
    {
        $created = $this->instance->createPermission('uri://open/file', 'Open File', 'File');

        $byId = $this->instance->getPermission($created->id);
        $byKey = $this->instance->getPermission('uri://open/file');

        $this->assertSame($created->id, $byId->id);
        $this->assertSame($created->id, $byKey->id);
    }

    public function testGetPermissionThrowsForMissingPermission(): void
    {
        $this->expectException(RecordNotFoundException::class);

        $this->instance->getPermission('uri://does/not/exist');
    }

    public function testGetInstanceReturnsSameSingletonInstance(): void
    {
        $first = Acl::getInstance([], $this->pdo, Validate::newInstance([]));
        $second = Acl::getInstance([], $this->pdo, Validate::newInstance([]));

        $this->assertInstanceOf(AclInterface::class, $first);
        $this->assertSame($first, $second);
    }

    public function testNewInstanceReturnsADifferentInstanceEachTime(): void
    {
        $first = Acl::newInstance([], $this->pdo, Validate::newInstance([]));
        $second = Acl::newInstance([], $this->pdo, Validate::newInstance([]));

        $this->assertNotSame($first, $second);
    }
}
