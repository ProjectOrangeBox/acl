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
