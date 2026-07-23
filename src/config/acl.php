<?php

return [
    'user table' => 'orange_users',
    'role table' => 'orange_roles',
    'permission table' => 'orange_permissions',
    'user role table' => 'orange_user_role',
    'role permission table' => 'orange_role_permission',
    'user meta table' => 'orange_user_meta',
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
