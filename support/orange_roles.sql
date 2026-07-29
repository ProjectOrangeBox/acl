CREATE TABLE `orange_roles` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name` varchar(128) NOT NULL DEFAULT '',
  `description` varchar(512) NOT NULL DEFAULT '',
  `migration` varchar(128) DEFAULT NULL,
  `is_active` tinyint(1) unsigned NOT NULL DEFAULT 1,
  PRIMARY KEY (`id`),
  -- UNIQUE, not the plain index this used to be: RoleModel resolves a role by
  -- name, so two rows sharing one would make which role you got arbitrary. See
  -- orange_users.sql for why the constraint is needed on top of the model check.
  UNIQUE KEY `idx_roles_name_unique` (`name`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
