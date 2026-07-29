<?php

/**
 * The table names are deliberately absent.
 *
 * They used to live here as 'user table', 'role table' and four more, but a Dto
 * declares its table with #[Table(...)] and an attribute cannot read a config
 * value - so a renamed table moved the models and left the Dtos behind. They are
 * constants on {@see \orange\acl\dtos\AclTables} now, and Acl rejects the old
 * keys by name rather than ignoring them. See that class for what overriding a
 * table name takes.
 */

declare(strict_types=1);

return [
    'admin user' => 1,
    // must match 'guest user' in user.php - entities use it for
    // loggedIn()/isGuest() checks
    'guest user' => 2,
    'admin role' => 1,
    'everyone role' => 2,
    'userModel' => \orange\acl\models\UserModel::class,
    'roleModel' => \orange\acl\models\RoleModel::class,
    'permissionModel' => \orange\acl\models\PermissionModel::class,
];
