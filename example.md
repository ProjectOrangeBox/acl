# orange/acl — Examples

Worked examples for the ACL service. Everything here runs against the tables
in `support/*.sql` (or the sqlite equivalents the test suite uses).
See [README.md](README.md) for the concepts and the configuration reference.

## Bootstrapping

```php
use orange\acl\Acl;
use orange\acl\User;
use orange\validate\Validate;

// inside an Orange Framework app the container provides these:
$acl = Acl::getInstance([], container()->pdo, container()->validate);
$currentUser = User::getInstance([], $acl, container()->session);

// standalone (a script, a different framework):
$pdo = new PDO('mysql:host=localhost;dbname=app', $dbUser, $dbPass);

// the isUnique validation rule resolves its PDO connection through the
// container by service name ("pdo") - register it once
\orange\framework\Container::getInstance()->set('pdo', $pdo);

$acl = Acl::getInstance([], $pdo, Validate::newInstance([]));
```

Pass config overrides as the first argument — they merge over
`src/config/acl.php` (and `src/config/user.php` for `User`).

## Seeding an application

The config declares well-known ids (`admin user` 1, `guest user` 2,
`admin role` 1, `everyone role` 2) — seed in an order that satisfies them:

```php
$admin = $acl->createUser('admin', 'admin@example.com', 'a-strong-password', ['is_active' => 1]); // id 1
$guest = $acl->createUser('guest', 'guest@example.com', bin2hex(random_bytes(24)), ['is_active' => 1]); // id 2

$adminRole = $acl->createRole('admin', 'Full administrative access');   // id 1
$everyone = $acl->createRole('everyone', 'Implicitly held by everyone'); // id 2

$admin->addRole($adminRole);
```

## Creating and linking

```php
$editor = $acl->createRole('editor', 'Can manage content');

$edit = $acl->createPermission('posts.edit', 'Edit posts', 'posts');
$publish = $acl->createPermission('posts.publish', 'Publish posts', 'posts');

// grant three equivalent ways: entity, key/name string, or id
$editor->addPermission($edit);
$editor->addPermission('posts.publish');
$editor->addPermission($edit->id);

$ada = $acl->createUser('ada', 'Ada@Example.COM', 'correct horse battery', [
    'is_active' => 1,
    // meta fields ride along and land in the meta table
    'phone' => '555-0100',
    'dashboard_url' => '/dashboard/editor',
]);

$ada->addRole($editor);        // or addRole('editor') or addRole($editor->id)

$ada->email;                   // 'ada@example.com' - stored trimmed + lowercase
$ada->phone;                   // '555-0100' - meta reads like a normal property
```

## Checking access

```php
$user = $acl->getUser($userId);

// permissions
$user->can('posts.edit');                            // true / false
$user->cannot('posts.delete');                       // the complement
$user->hasPermission('posts.edit');                  // alias of can()
$user->hasPermissions(['posts.edit', 'posts.publish']);   // ALL required
$user->hasOnePermissionOf(['posts.edit', 'posts.admin']); // ANY suffices

// roles - by name or id
$user->hasRole('editor');
$user->hasRole(3);
$user->hasRoles(['editor', 'reviewer']);             // ALL
$user->hasOneRoleOf(['admin', 'editor']);            // ANY
$user->hasRole('Everyone');                          // always true - implicit role

// identity
$user->isAdmin();     // holds the configured 'admin role'
$user->isGuest();     // is the configured 'guest user'
$user->loggedIn();    // is NOT the guest user

// the same checks as magic reads (handy in view templates)
$user->isadmin;
$user->isguest;
$user->loggedin;
$user->isactive;      // is_active == 1
```

Roles and permissions load lazily on first use — constructing an entity costs
one query; `can()`/`hasRole()` trigger the role/permission load once.

## The current-user session flow

```php
// a request begins: who is this?
$user = $currentUser->load();   // guest entity when the session is empty or stale

if ($user->cannot('posts.edit')) {
    // 403 ...
}

// logging in (credentials verified by orange/auth - see its example.md)
if ($auth->login($email, $password)) {
    $user = $currentUser->change($auth->userId()); // regenerates the session id
}

// logging out
$currentUser->logout();          // back to guest - also regenerates
```

## Updating users

```php
$user = $acl->getUser($userId);

// entity properties and meta fields write the same way; update() persists
// both halves in one transaction
$user->username = 'ada.lovelace';
$user->email = 'ada.lovelace@example.com';
$user->phone = '555-0199';
$user->update();

// passwords have their own path - the create-time password rules apply
$user->updatePassword('a brand new passphrase');

// activation & soft delete
$user->deactivate();                  // is_active = 0 - can() etc. still work
$user->activate();
$acl->userModel->delete($user->id);   // soft delete: is_deleted = 1
```

## Bulk assignment with relink()

The natural fit for a checkbox form — replace the whole set atomically:

```php
// the roles screen posts role_ids[] for one user. Cast to int: a STRING
// argument is resolved as a role NAME, and POST values arrive as strings
$acl->userModel->relink($userId, array_map(intval(...), $_POST['role_ids'] ?? []));

// the permissions screen posts permission_ids[] for one role
$acl->roleModel->relink($roleId, array_map(intval(...), $_POST['permission_ids'] ?? []));
```

Each relink removes the old links and writes the new ones in a transaction —
a failure rolls the whole thing back and rethrows.

## Listing for admin screens

```php
$users = $acl->userModel->readAll();             // plain associative rows
$roles = $acl->roleModel->readAll();
$permissions = $acl->permissionModel->readAll();
```

## Handling errors

```php
use orange\acl\exceptions\RecordNotFoundException;
use orange\validate\exceptions\ValidationFailed;

// create()/update() throw ValidationFailed carrying every failed rule
try {
    $user = $acl->createUser($username, $email, $password, ['is_active' => 1]);
} catch (ValidationFailed $e) {
    $e->getErrors();          // error objects - each carries ->text
    $e->getErrorsAsHtml('<li>', '</li>');    // '<li>Email is required</li>...'
    $e->getHttpCode();        // 406
}

// lookups throw RecordNotFoundException (code 404, extends AclException)
try {
    $role = $acl->getRole('no-such-role');
} catch (RecordNotFoundException $e) {
    http_response_code($e->getCode());   // 404
}
```

A JSON API endpoint:

```php
try {
    $user = $acl->createUser($in['username'], $in['email'], $in['password']);

    echo json_encode(['id' => $user->id]);
} catch (ValidationFailed $e) {
    http_response_code($e->getHttpCode());

    echo $e->getErrorsAsJson();
}
```

## Customizing

```php
// your own table names and entity class
$acl = Acl::getInstance([
    'user table' => 'app_users',
    'user meta table' => 'app_user_meta',
    'UserEntityClass' => \app\entities\AppUserEntity::class, // extend UserEntity
], $pdo, $validate);
```

Table names are validated as plain SQL identifiers and model classes against
their interfaces — a typo throws `InvalidArgumentException` at construction.
