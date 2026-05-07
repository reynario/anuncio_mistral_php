<?php

declare(strict_types=1);

namespace App\Database;

use PDO;

class DatabaseMigrator
{
    public function migrate(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS ad_accounts (
              id           INT AUTO_INCREMENT PRIMARY KEY,
              label        VARCHAR(255) NOT NULL DEFAULT '',
              ad_account_id VARCHAR(100) NOT NULL UNIQUE,
              status       VARCHAR(50)  NOT NULL DEFAULT 'active',
              agency       VARCHAR(255) NOT NULL DEFAULT ''
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS pages (
              id                            INT AUTO_INCREMENT PRIMARY KEY,
              page_id                       VARCHAR(100) NOT NULL UNIQUE,
              cliente                       VARCHAR(255) NOT NULL DEFAULT '',
              page_name                     VARCHAR(255) NOT NULL DEFAULT '',
              ad_account_id                 VARCHAR(100) NOT NULL DEFAULT '',
              primary_whatsapp_phone_number VARCHAR(100) NOT NULL DEFAULT '',
              primary_whatsapp_phone_id     VARCHAR(100) NOT NULL DEFAULT '',
              whatsapp_business_account_id  VARCHAR(100) NOT NULL DEFAULT '',
              instagram_actor_id            VARCHAR(100) NOT NULL DEFAULT '',
              instagram_username            VARCHAR(255) NOT NULL DEFAULT '',
              lead_form_id                  VARCHAR(100) NOT NULL DEFAULT '',
              status                        VARCHAR(50)  NOT NULL DEFAULT 'active'
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        // Add cliente column if missing (migration for older installs)
        $cols = $pdo->query("
            SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'pages' AND COLUMN_NAME = 'cliente'
        ")->fetchAll();
        if (empty($cols)) {
            $pdo->exec("ALTER TABLE pages ADD COLUMN cliente VARCHAR(255) NOT NULL DEFAULT ''");
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS geo_targets (
              id          INT AUTO_INCREMENT PRIMARY KEY,
              geo_key     VARCHAR(100) NOT NULL UNIQUE,
              name        VARCHAR(255) NOT NULL DEFAULT '',
              type        VARCHAR(50)  NOT NULL DEFAULT 'country',
              countries   VARCHAR(255) NOT NULL DEFAULT 'BR',
              regions_json TEXT,
              cities_json  TEXT,
              radius_km   VARCHAR(50)  NOT NULL DEFAULT ''
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS units (
              id                 INT AUTO_INCREMENT PRIMARY KEY,
              unit_id            VARCHAR(100) NOT NULL UNIQUE,
              ad_account         VARCHAR(100) NOT NULL DEFAULT '',
              id_page            VARCHAR(100) NOT NULL DEFAULT '',
              id_form            VARCHAR(100) NOT NULL DEFAULT '',
              instagram_actor_id VARCHAR(100) NOT NULL DEFAULT '',
              geo_key            VARCHAR(100) NOT NULL DEFAULT ''
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS background_jobs (
              id           VARCHAR(36)  PRIMARY KEY,
              status       VARCHAR(20)  NOT NULL DEFAULT 'pending',
              payload_json TEXT         NOT NULL,
              logs_json    MEDIUMTEXT   NOT NULL,
              error        TEXT,
              done         TINYINT(1)   NOT NULL DEFAULT 0,
              created_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
              updated_at   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ");
    }
}
