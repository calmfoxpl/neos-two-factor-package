<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The second factor of each backend account, and the policy chosen in the backend.
 *
 * The platform check names MySQLPlatform, which both MySQL and MariaDB extend in DBAL 3 (Neos 9)
 * and which is the same class as DBAL 2's MySqlPlatform (Neos 8): PHP compares class names
 * without regard to case.
 */
final class Version20260924120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Calmfox.TwoFactor: second factors and the two-factor policy';
    }

    public function up(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql('CREATE TABLE calmfox_twofactor (persistence_object_identifier VARCHAR(40) NOT NULL, account VARCHAR(40) NOT NULL, totpsecret LONGTEXT DEFAULT NULL, lasttotpstep INT DEFAULT NULL, passkeys LONGTEXT NOT NULL, setuprequired TINYINT(1) NOT NULL, UNIQUE INDEX UNIQ_90A511227D3656A4 (account), PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('CREATE TABLE calmfox_twofactor_policy (persistence_object_identifier VARCHAR(40) NOT NULL, policy VARCHAR(16) NOT NULL, PRIMARY KEY(persistence_object_identifier)) DEFAULT CHARACTER SET utf8mb4 COLLATE `utf8mb4_unicode_ci` ENGINE = InnoDB');
        $this->addSql('ALTER TABLE calmfox_twofactor ADD CONSTRAINT FK_90A511227D3656A4 FOREIGN KEY (account) REFERENCES neos_flow_security_account (persistence_object_identifier) ON DELETE CASCADE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\MySQLPlatform,
            'Migration can only be executed safely on MySQL or MariaDB.',
        );

        $this->addSql('DROP TABLE calmfox_twofactor');
        $this->addSql('DROP TABLE calmfox_twofactor_policy');
    }
}
