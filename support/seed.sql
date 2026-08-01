-- Minimum viable ACL data: a guest, an admin, and one role wiring them to
-- permissions.
--
-- The guest row is not demo decoration. User::load() resolves every request
-- with no login to the id in 'guest user' (config/user.php, default 2), so if
-- that row is absent every anonymous request fails. Load this - or something
-- like it - after install.sql.
--
--   mysql mydb < install.sql
--   mysql mydb < seed.sql
--
-- The ids are the well-known ones from the package config: 'admin user' => 1
-- and 'guest user' => 2. Change them together or not at all.
--
-- The admin password below is the bcrypt hash of 'orange123'. This is example
-- data published in a public repository: it is not a secret, it must never be
-- treated as one, and it should not survive into anything reachable.

INSERT INTO `orange_users` (`id`, `username`, `email`, `password`, `is_active`, `is_deleted`) VALUES
    (1, 'admin', 'admin@example.com', '$2y$12$1KTg1zV4BaqBwTbFNnE/p.XDN2l9EnBgTMjpM3SrfzkSo8mAFJD/C', 1, 0),
    (2, 'guest', 'guest@example.com', '', 0, 0);

-- The guest is deliberately is_active = 0 and has no usable password hash, so
-- nothing can ever log in *as* guest. It is an identity to fall back to, not
-- an account.

INSERT INTO `orange_roles` (`id`, `name`, `description`, `is_active`) VALUES
    (1, 'administrator', 'Full access', 1);

INSERT INTO `orange_user_role` (`user_id`, `role_id`) VALUES (1, 1);
