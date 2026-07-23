# ACL

An Access Control List service: users, roles, and permissions backed by PDO, with a session-aware `User` helper for tracking who's currently logged in (falling back to a configured guest user).

This package answers "what may this user do". Its sibling [`orange/auth`](../auth/README.md) answers "who is this" — see [Pairing with orange/auth](#pairing-with-orangeauth).

## Example

```php
use orange\acl\Acl;
use orange\acl\User;

$acl = Acl::getInstance($config, $pdo, $validate); // config merges over acl/src/config/acl.php

$role = $acl->createRole('editor', 'Can edit content');
$permission = $acl->createPermission('posts.edit', 'Edit posts', 'posts');
$role->addPermission($permission);          // also accepts 'posts.edit' or an id

$user = $acl->createUser('ada', 'ada@example.com', 'a-strong-password');
$user->addRole($role);                      // also accepts 'editor' or an id

$user->can('posts.edit');                   // true
$user->hasRole('editor');                   // by name - or hasRole($role->id) by id
$user->isAdmin();                           // has the configured 'admin role'

// session-aware "current user" helper
$currentUser = User::getInstance($config, $acl, $session); // config merges over acl/src/config/user.php

$loggedInUser = $currentUser->load();      // guest user if nothing (or something stale) in session
$currentUser->change($user->id);           // switch the session to this user
$currentUser->logout();                    // back to the guest user
```

`Acl` and `User` are both singletons — configure the underlying `userModel`/`roleModel`/`permissionModel` classes and table names via `acl/src/config/acl.php`, and the guest user id + session key via `acl/src/config/user.php` (the `'guest user'` value must match between the two files). Entities (`UserEntity`, `RoleEntity`, `PermissionEntity`) and models throw `RecordNotFoundException` (404, extends `AclException`) when a lookup fails, and `ValidationFailed` (from [`orange/validate`](../validate/README.md)) when `create()`/`update()` input doesn't pass the configured rules.

**A cookbook of worked examples lives in [example.md](example.md).**

## Configuration

`src/config/acl.php` (merged under anything you pass to `Acl::getInstance()`):

| Key | Default | Purpose |
| --- | --- | --- |
| `user table` | `orange_users` | user rows (DDL in `support/`) |
| `user meta table` | `orange_user_meta` | per-user meta rows (`dashboard_url`, `phone`, `ext`) |
| `role table` | `orange_roles` | role rows |
| `permission table` | `orange_permissions` | permission rows |
| `user role table` | `orange_user_role` | user↔role join |
| `role permission table` | `orange_role_permission` | role↔permission join |
| `admin user` / `guest user` | `1` / `2` | well-known user ids |
| `admin role` / `everyone role` | `1` / `2` | well-known role ids (`isAdmin()`, implicit `Everyone`) |
| `userModel` / `roleModel` / `permissionModel` | package models | swap in your own (must implement the matching interface) |
| `UserEntityClass` / `RoleEntityClass` / `PermissionEntityClass` | package entities | swap in your own entity classes |

`src/config/user.php` (merged under anything you pass to `User::getInstance()`):

| Key | Default | Purpose |
| --- | --- | --- |
| `guest user` | `2` | id `load()` falls back to — must match acl.php |
| `sessionKey` | `##user##session##` | session key the current user id is stored under |

## Behavior worth knowing

- **Construction fails fast.** `Acl` verifies every configured table name is a plain SQL identifier and every configured model class implements its interface — a typo'd config value throws immediately, not mid-request. The PDO handle is switched to `ERRMODE_EXCEPTION`.
- **Only validated columns are ever written.** `create()`/`update()` run input through the model's rule sets and insert exactly the validated whitelist — extra keys in the input are dropped, and the explicit `createUser()` arguments always win over anything in `$fields`.
- **Emails are stored trimmed + lowercase** (create and update), matching [`orange/auth`](../auth/README.md)'s normalized-login default — credential lookups never depend on the database collation.
- **Passwords are always hashed** (`password_hash()`, `PASSWORD_DEFAULT`) — at create and through `updatePassword()`, which enforces the same password rules as create before hashing. Importing pre-hashed passwords is a SQL job, not an API one.
- **`User::change()` regenerates the session id** before switching users (fixation defense) — and `logout()` routes through it, so logging out regenerates too. Skipped automatically when no session is active (CLI).
- **User meta lives in its own table** (`orange_user_meta`: `dashboard_url`, `phone`, `ext`), managed entirely through `UserModel` — meta fields ride along in `createUser()`'s `$fields` and are read/written on the entity like normal properties (`$user->phone`). A user without a meta row reads as empty meta, never an error. The user row and its meta row are written in one transaction.
- **Deleting**: `UserModel::delete()` soft-deletes (sets `is_deleted = 1`). Role and permission `delete()` are hard deletes that also clear their join-table references, atomically.
- **`relink()`** (user→roles, role→permissions) replaces the whole link set in a transaction and returns `true`; a failure rolls back and rethrows.
- **Inactive roles/permissions don't grant.** A deactivated role disappears from the user's roles (and stops contributing permissions); a deactivated permission stops granting while its role remains. A role with no permissions still counts as held.
- **The 'everyone role'** id from config is always present in every user's roles as `Everyone`.

## Pairing with orange/auth

Authentication (credential checking) deliberately lives in [`orange/auth`](../auth/README.md); this package trusts whatever user id you hand it. The glue:

```php
if ($auth->login($email, $password)) {
    $currentUser->change($auth->userId()); // regenerates the session id itself
}

$currentUser->logout(); // routes through change() - also regenerates
```

Both packages default to the same `orange_users` table (DDL in `support/`).

## Testing

```sh
composer test          # or: cd unittest && sh runUnitTests.sh
```

Tests run against an in-memory sqlite database — no MySQL needed.
