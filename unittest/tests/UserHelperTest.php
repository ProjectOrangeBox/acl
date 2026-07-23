<?php

declare(strict_types=1);

use orange\acl\Acl;
use orange\acl\User;
use orange\validate\Validate;
use orange\session\SessionInterface;

/**
 * Array-backed stand-in for the session service - just enough for User,
 * which only calls get() and set().
 */
class MockSession implements SessionInterface
{
    public array $data = [];
    public int $regenerated = 0;

    public function __get(string $key): mixed
    {
        return $this->get($key);
    }

    public function __set(string $key, mixed $value): void
    {
        $this->set($key, $value);
    }

    public function __isset(string $key): bool
    {
        return $this->has($key);
    }

    public function __unset(string $key): void
    {
        $this->remove($key);
    }

    public function start(array $customOptions = []): bool
    {
        return true;
    }

    public function activate(): bool
    {
        return true;
    }

    public function isActive(): bool
    {
        return true;
    }

    public function destroy(): bool
    {
        $this->data = [];

        return true;
    }

    public function destroyCookie(): bool
    {
        return true;
    }

    public function stop(): bool
    {
        return true;
    }

    public function abort(): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function get(string $key): mixed
    {
        // mirror the real service's (key, default) calling convention
        $default = func_num_args() > 1 ? func_get_arg(1) : null;

        return $this->data[$key] ?? $default;
    }

    public function getAll(): array
    {
        return $this->data;
    }

    public function getMulti(array $keys): array
    {
        return array_intersect_key($this->data, array_flip($keys));
    }

    public function set(string $key, mixed $value): static
    {
        $this->data[$key] = $value;

        return $this;
    }

    public function setMulti(array $items): static
    {
        $this->data = $items + $this->data;

        return $this;
    }

    public function remove(string $key): static
    {
        unset($this->data[$key]);

        return $this;
    }

    public function removeMulti(array $keys): static
    {
        foreach ($keys as $key) {
            $this->remove($key);
        }

        return $this;
    }

    public function removeAll(): static
    {
        $this->data = [];

        return $this;
    }

    public function regenerateId(bool $deleteOldSession = false): bool
    {
        $this->regenerated++;

        return true;
    }

    public function reset(): bool
    {
        return true;
    }

    public function getFlash(string $key): mixed
    {
        return null;
    }

    public function setFlash(string $key, mixed $value): static
    {
        return $this;
    }

    public function removeFlash(string $key): static
    {
        return $this;
    }

    public function getTemp(string $key): mixed
    {
        return null;
    }

    public function setTemp(string $key, mixed $value, int $ttl = 60): static
    {
        return $this;
    }

    public function removeTemp(string $key): static
    {
        return $this;
    }

    public function id(?string $newId = null): string|false
    {
        return 'mock';
    }

    public function gc(): int|false
    {
        return 0;
    }
}

final class UserHelperTest extends unitTestHelper
{
    protected $instance;
    protected PDO $pdo;
    protected Acl $acl;
    protected MockSession $session;
    protected int $guestId;
    protected int $userId;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

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
        $this->pdo->exec('CREATE TABLE `orange_user_role` (`user_id` INTEGER NOT NULL, `role_id` INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE `orange_role_permission` (`role_id` INTEGER NOT NULL, `permission_id` INTEGER NOT NULL)');
        $this->pdo->exec('CREATE TABLE `orange_user_meta` (`id` INTEGER PRIMARY KEY, `dashboard_url` TEXT, `phone` TEXT, `ext` TEXT)');

        \orange\framework\Container::getInstance()->set('pdo', $this->pdo);

        $this->acl = Acl::newInstance([], $this->pdo, Validate::newInstance([]));

        // seeded in this order so the guest lands on id 2 - matching the
        // default 'guest user' => 2 the entities read from the acl config
        $this->userId = (int)$this->acl->createUser('dmyers', 'dmyers@example.com', 'password123', ['is_active' => 1])->id;
        $this->guestId = (int)$this->acl->createUser('guestuser', 'guest@example.com', 'password123', ['is_active' => 1])->id;

        $this->assertSame(2, $this->guestId);

        $this->session = new MockSession();

        $this->instance = User::newInstance(['guest user' => $this->guestId], $this->acl, $this->session);
    }

    public function testLoadWithEmptySessionReturnsGuest(): void
    {
        $entity = $this->instance->load();

        $this->assertSame($this->guestId, $entity->id);
        $this->assertTrue($entity->isGuest());
        $this->assertFalse($entity->loggedIn());
    }

    public function testChangeSwitchesTheCurrentUser(): void
    {
        $entity = $this->instance->change($this->userId);

        $this->assertSame($this->userId, $entity->id);

        // and load() now resolves through the session
        $this->assertSame($this->userId, $this->instance->load()->id);
        $this->assertTrue($this->instance->load()->loggedIn());
    }

    public function testLogoutReturnsToGuest(): void
    {
        $this->instance->change($this->userId);

        $entity = $this->instance->logout();

        $this->assertSame($this->guestId, $entity->id);
        $this->assertSame($this->guestId, $this->instance->load()->id);
    }

    public function testGarbageSessionValueFallsBackToGuest(): void
    {
        // the original compared the guest default instead of the session value
        $this->session->data['##user##session##'] = 'not-a-number';

        $this->assertSame($this->guestId, $this->instance->load()->id);

        $this->session->data['##user##session##'] = -5;

        $this->assertSame($this->guestId, $this->instance->load()->id);
    }

    public function testStaleSessionUserFallsBackToGuestAndResetsSession(): void
    {
        // a session pointing at a user that no longer exists
        $this->session->data['##user##session##'] = 999;

        $entity = $this->instance->load();

        $this->assertSame($this->guestId, $entity->id);

        // and the stale id was replaced in the session
        $this->assertSame($this->guestId, $this->session->data['##user##session##']);
    }

    public function testMissingGuestUserConfigThrows(): void
    {
        $this->expectException(\orange\framework\exceptions\MissingRequired::class);

        User::newInstance(['guest user' => 'nope'], $this->acl, $this->session);
    }

    public function testChangeRegeneratesTheSessionId(): void
    {
        $this->assertSame(0, $this->session->regenerated);

        // switching users is a privilege boundary - fixation defense
        $this->instance->change($this->userId);
        $this->assertSame(1, $this->session->regenerated);

        // logout() routes through change(), so it regenerates too
        $this->instance->logout();
        $this->assertSame(2, $this->session->regenerated);

        // plain loads never touch the session id
        $this->instance->load();
        $this->assertSame(2, $this->session->regenerated);
    }
}
