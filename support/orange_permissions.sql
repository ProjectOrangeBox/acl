CREATE TABLE `orange_permissions` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `key` varchar(255) NOT NULL,
  `description` varchar(512) NOT NULL,
  `group` varchar(128) NOT NULL,
  `migration` varchar(128) DEFAULT NULL,
  -- PermissionEntity declares is_active and getRolesPermissions() filters on
  -- it, but this table never had the column - every permission lookup was
  -- against a column that did not exist.
  `is_active` tinyint(1) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  -- permissions are resolved by key, so a duplicate makes the resolution
  -- arbitrary in exactly the way it does for role names
  UNIQUE KEY `idx_permissions_key_unique` (`key`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
