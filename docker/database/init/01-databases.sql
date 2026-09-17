-- Creates both databases and grants privileges to the demo user.
CREATE DATABASE IF NOT EXISTS `sulu_demo`        CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `sylius_demo`      CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `sulu_demo_test`   CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE DATABASE IF NOT EXISTS `sylius_demo_test` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

GRANT ALL PRIVILEGES ON `sulu_demo`.*        TO 'demo'@'%';
GRANT ALL PRIVILEGES ON `sylius_demo`.*      TO 'demo'@'%';
GRANT ALL PRIVILEGES ON `sulu_demo_test`.*   TO 'demo'@'%';
GRANT ALL PRIVILEGES ON `sylius_demo_test`.* TO 'demo'@'%';
FLUSH PRIVILEGES;
