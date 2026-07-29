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
