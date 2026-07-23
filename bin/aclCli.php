#!/usr/bin/env php
<?php

declare(strict_types=1);

use orange\acl\Acl;
use orange\acl\User;
use orange\validate\exceptions\ValidationFailed;

$container = require __DIR__ . '/../../../../bootstrapCli.php';

$pdo = $container->pdo;

$pdo->query('TRUNCATE TABLE orange_users');
$pdo->query('TRUNCATE TABLE orange_user_meta');
$pdo->query('TRUNCATE TABLE orange_permissions');
$pdo->query('TRUNCATE TABLE orange_roles');
$pdo->query('TRUNCATE TABLE orange_user_role');
$pdo->query('TRUNCATE TABLE orange_role_permission');

$acl = Acl::getInstance([], container()->pdo, container()->validate);

// session-aware "current user" helper
$currentUser = User::getInstance([], $acl, container()->session);

try {
    // #1
    $userEntity = $acl->createUser('dmyers', 'dmyers@email.com', 'password', ['is_active' => 1]);
} catch (ValidationFailed $e) {
    echo 'ValidationFailed: ' . $e->getErrorsAsHtml('<i class="fa-solid fa-triangle-exclamation"></i> ', '', '</br>') . PHP_EOL;
    exit(1);
} catch (Throwable $e) {
    echo 'Throwable: ' . $e->getMessage() . PHP_EOL;
    exit(1);
}

// #2 - matches 'guest user' => 2 in the config
$guest = $acl->createUser('guest', 'guest@example.com', 'password', ['is_active' => 1]);

// #1 - matches 'admin role' => 1 in the config
$role = $acl->createRole('admin', 'Administrator');

// #2
$guestRole = $acl->createRole('guest', 'Guest');

echo 'p1' . PHP_EOL;
$p1 = $acl->createPermission('uri://open/file', 'Open File', 'File');

echo 'p2' . PHP_EOL;
$p2 = $acl->createPermission('uri://close/file', 'Close File', 'File');

$role->addPermission($p1);
$role->addPermission($p2);

$userEntity->addRole($role);

echo 'p3' . PHP_EOL;
$p3 = $acl->createPermission('uri://delete/file', 'Delete File', 'File');

$role->addPermission($p3);

$userEntity->email = 'donmyers@foobar.com';

$userEntity->update();

echo 'has => ' . ($userEntity->can('uri://open/file') ? 'true' : 'false') . PHP_EOL;
echo 'does not have => ' . ($userEntity->can('uri://foo/bar') ? 'true' : 'false') . PHP_EOL;

echo 'Is Admin => ' . ($userEntity->isAdmin() ? 'true' : 'false') . PHP_EOL;
echo 'Is Logged In => ' . ($userEntity->loggedIn() ? 'true' : 'false') . PHP_EOL;

// nobody in the session yet - load() resolves to the guest user
$loaded = $currentUser->load();

echo 'current (guest): ' . $loaded->id . ' ' . $loaded->username . PHP_EOL;

// "log in" as the created user, then back out
$loaded = $currentUser->change($userEntity->id);

echo 'current (logged in): ' . $loaded->id . ' ' . $loaded->username . ' ' . $loaded->email . PHP_EOL;

$loaded = $currentUser->logout();

echo 'current (after logout): ' . $loaded->id . ' ' . $loaded->username . PHP_EOL;
