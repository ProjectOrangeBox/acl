# ACL

An Access Control List service: users, roles, and permissions backed by PDO, with a session-aware `User` helper for tracking who's currently logged in (falling back to a configured guest user).

## Example

```php
use orange\acl\Acl;
use orange\acl\User;

$acl = Acl::getInstance($config, $pdo, $validate); // config merges over acl/src/config/acl.php

$role = $acl->createRole('editor', 'Can edit content');
$permission = $acl->createPermission('posts.edit', 'Edit posts', 'posts');

$user = $acl->createUser('ada', 'ada@example.com', 'a-strong-password');
$user->addRole($role);

// session-aware "current user" helper
$currentUser = User::getInstance($config, $acl, $session); // config merges over acl/src/config/user.php

$loggedInUser = $currentUser->load();      // guest user if nothing in session yet
$currentUser->change($user->id);           // log in
$currentUser->logout();                    // back to the guest user
```

`Acl` and `User` are both singletons — configure the underlying `userModel`/`roleModel`/`permissionModel` classes and table names via `acl/src/config/acl.php`, and the guest user id via `acl/src/config/user.php`. Entities (`UserEntity`, `RoleEntity`, `PermissionEntity`) and models throw `RecordNotFoundException` when a lookup fails, and `ValidationFailed` (from [`orange/validate`](../validate/README.md)) when `create()` input doesn't pass the configured rules.
