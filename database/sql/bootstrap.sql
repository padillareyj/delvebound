-- Create the Delvebound database and its three users.
-- Passwords match .env.example. scripts/reset_db.sh rewrites them from .env
-- before this file is applied. Run as MySQL root:
--   sudo mysql < db/bootstrap.sql

CREATE DATABASE IF NOT EXISTS delvebound CHARACTER SET utf8mb4 COLLATE utf8mb4_0900_ai_ci;

CREATE USER IF NOT EXISTS 'delve_admin'@'localhost' IDENTIFIED BY 'change-me-admin';
CREATE USER IF NOT EXISTS 'delve_sim'@'localhost' IDENTIFIED BY 'change-me-sim';
CREATE USER IF NOT EXISTS 'delve_web'@'localhost' IDENTIFIED BY 'change-me-web';
CREATE USER IF NOT EXISTS 'delve_admin'@'127.0.0.1' IDENTIFIED BY 'change-me-admin';
CREATE USER IF NOT EXISTS 'delve_sim'@'127.0.0.1' IDENTIFIED BY 'change-me-sim';
CREATE USER IF NOT EXISTS 'delve_web'@'127.0.0.1' IDENTIFIED BY 'change-me-web';

ALTER USER 'delve_admin'@'localhost' IDENTIFIED BY 'change-me-admin';
ALTER USER 'delve_sim'@'localhost' IDENTIFIED BY 'change-me-sim';
ALTER USER 'delve_web'@'localhost' IDENTIFIED BY 'change-me-web';
ALTER USER 'delve_admin'@'127.0.0.1' IDENTIFIED BY 'change-me-admin';
ALTER USER 'delve_sim'@'127.0.0.1' IDENTIFIED BY 'change-me-sim';
ALTER USER 'delve_web'@'127.0.0.1' IDENTIFIED BY 'change-me-web';

GRANT ALL PRIVILEGES ON delvebound.* TO 'delve_admin'@'localhost';
GRANT ALL PRIVILEGES ON delvebound.* TO 'delve_admin'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON delvebound.* TO 'delve_sim'@'localhost';
GRANT SELECT, INSERT, UPDATE, DELETE ON delvebound.* TO 'delve_sim'@'127.0.0.1';

FLUSH PRIVILEGES;
