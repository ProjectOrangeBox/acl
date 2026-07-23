CREATE TABLE `orange_users` (
  `id` int(11) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(64) NOT NULL,
  `email` varchar(255) NOT NULL,
  `password` varchar(255) NOT NULL,
  `is_active` tinyint(1) unsigned NOT NULL DEFAULT 0,
  `is_deleted` tinyint(1) unsigned NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  UNIQUE KEY `idx_email_unique` (`email`) USING BTREE,
  UNIQUE KEY `idx_username_unique` (`username`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
