-- Combined installer: the six ACL tables in dependency order.
--
-- The join tables carry foreign keys, so the tables they point at have to
-- exist first. Alphabetical order - what 'cat *.sql' or a shell glob gives
-- you - does NOT work: orange_role_permission sorts before orange_roles.
-- This file exists so that ordering is executable rather than something
-- every consumer has to re-derive from the README.
--
--   mysql mydb < install.sql
--
-- Seed data is separate, in seed.sql - a guest user row is required, see there.

-- ---------- orange_users ----------
-- The credential record. Only the columns UserEntity declares appear here:
-- that entity's __set() throws on any column it has no property for, so an
-- extra column in this table breaks every read of it.
CREATE TABLE `orange_users` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `email` varchar(255) NOT NULL,
  -- password_hash(PASSWORD_DEFAULT) output. 255 leaves room for the algorithm
  -- to change: bcrypt is 60 bytes, argon2id runs to about 100.
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  -- Both UNIQUE. UserModel checks for a duplicate before it writes, but the
  -- check and the insert are two statements and a concurrent create can slip
  -- between them - these constraints are what actually prevent the duplicate,
  -- and the check exists only to make the ordinary case a readable per-field
  -- error rather than a driver-level integrity violation.
  --
  -- Note this deliberately spans soft-deleted rows: a deleted account keeps its
  -- email and username reserved, so the address cannot be claimed by someone
  -- else and silently inherit anything still keyed to it.
  UNIQUE KEY `idx_users_email_unique` (`email`) USING BTREE,
  UNIQUE KEY `idx_users_username_unique` (`username`) USING BTREE,
  -- the login lookup filters both, and the soft-delete flag is low-cardinality
  -- enough that leading with it would be useless
  KEY `idx_users_active_deleted` (`is_active`, `is_deleted`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- orange_roles ----------
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

-- ---------- orange_permissions ----------
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

-- ---------- orange_user_meta ----------
-- The optional per-user data that doesn't affect how the application behaves.
-- Shares its primary key with orange_users: one meta row per user, same id, so
-- there is no surrogate key and no separate foreign key column - `id` is both.
--
-- The columns here are the ones the user Dtos tag #[Table('orange_user_meta')];
-- adding a field to that half of the form means adding it in both places.
CREATE TABLE `orange_user_meta` (
  `id` int(10) unsigned NOT NULL,
  `dashboard_url` varchar(255) NOT NULL DEFAULT '',
  `phone` varchar(64) NOT NULL DEFAULT '',
  `ext` varchar(32) NOT NULL DEFAULT '',
  PRIMARY KEY (`id`),
  -- A meta row without its user is unreachable - nothing looks it up except by
  -- user id. CASCADE so a hard delete of a user cannot leave one behind.
  -- (UserModel::delete() soft-deletes and deliberately keeps the meta row, so
  -- this only fires on a real DELETE.)
  CONSTRAINT `fk_user_meta_user` FOREIGN KEY (`id`)
    REFERENCES `orange_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- orange_user_role ----------
-- user <-> role. The pair is the key, so a user cannot hold the same role twice
-- and addRole() is idempotent at the database level rather than only by
-- convention.
CREATE TABLE `orange_user_role` (
  `user_id` int(10) unsigned NOT NULL,
  `role_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`user_id`,`role_id`),
  -- the composite key serves user_id lookups; deleting a role scans by role_id,
  -- which that key cannot serve as it is not the leading column
  KEY `idx_user_role_role` (`role_id`),
  -- A grant naming a user or role that no longer exists is a privilege record
  -- pointing at nothing - and if the id is ever reused, at the wrong thing.
  -- The models already clean up in a transaction; these make it impossible to
  -- get wrong from outside them too.
  CONSTRAINT `fk_user_role_user` FOREIGN KEY (`user_id`)
    REFERENCES `orange_users` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_user_role_role` FOREIGN KEY (`role_id`)
    REFERENCES `orange_roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------- orange_role_permission ----------
-- role <-> permission. Same shape and same reasoning as orange_user_role.
CREATE TABLE `orange_role_permission` (
  `role_id` int(10) unsigned NOT NULL,
  `permission_id` int(10) unsigned NOT NULL,
  PRIMARY KEY (`role_id`,`permission_id`),
  -- deleting a permission scans by permission_id, which the composite key
  -- cannot serve as it is not the leading column
  KEY `idx_role_permission_permission` (`permission_id`),
  CONSTRAINT `fk_role_permission_role` FOREIGN KEY (`role_id`)
    REFERENCES `orange_roles` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
  CONSTRAINT `fk_role_permission_permission` FOREIGN KEY (`permission_id`)
    REFERENCES `orange_permissions` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

