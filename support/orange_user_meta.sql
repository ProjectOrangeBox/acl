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
