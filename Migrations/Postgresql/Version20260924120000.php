<?php

declare(strict_types=1);

namespace Neos\Flow\Persistence\Doctrine\Migrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * The second factor of each backend account, and the policy chosen in the backend.
 *
 * PostgreSQLPlatform is DBAL 3's name (Neos 9) and the same class as DBAL 2's
 * PostgreSqlPlatform (Neos 8): PHP compares class names without regard to case.
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
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql('CREATE TABLE calmfox_twofactor (persistence_object_identifier VARCHAR(40) NOT NULL, account VARCHAR(40) NOT NULL, totpsecret TEXT DEFAULT NULL, lasttotpstep INT DEFAULT NULL, passkeys TEXT NOT NULL, setuprequired BOOLEAN NOT NULL, PRIMARY KEY(persistence_object_identifier))');
        $this->addSql('CREATE UNIQUE INDEX UNIQ_90A511227D3656A4 ON calmfox_twofactor (account)');
        $this->addSql('CREATE TABLE calmfox_twofactor_policy (persistence_object_identifier VARCHAR(40) NOT NULL, policy VARCHAR(16) NOT NULL, PRIMARY KEY(persistence_object_identifier))');
        $this->addSql('ALTER TABLE calmfox_twofactor ADD CONSTRAINT FK_90A511227D3656A4 FOREIGN KEY (account) REFERENCES neos_flow_security_account (persistence_object_identifier) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE');
    }

    public function down(Schema $schema): void
    {
        $this->abortIf(
            !$this->connection->getDatabasePlatform() instanceof \Doctrine\DBAL\Platforms\PostgreSQLPlatform,
            'Migration can only be executed safely on PostgreSQL.',
        );

        $this->addSql('DROP TABLE calmfox_twofactor');
        $this->addSql('DROP TABLE calmfox_twofactor_policy');
    }
}
