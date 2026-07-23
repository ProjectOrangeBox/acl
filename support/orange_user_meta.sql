CREATE TABLE `orange_user_meta` (
  `id` int(11) unsigned NOT NULL,
  `dashboard_url` varchar(255) DEFAULT NULL,
  `phone` varchar(32) DEFAULT NULL,
  `ext` varchar(16) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8;
