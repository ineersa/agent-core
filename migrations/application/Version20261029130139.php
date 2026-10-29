<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Final unmerged MEMORY-14 ownership schema: Bash receipts/fences and child reservation refusals.
 */
final class Version20261029130139 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Bash ownership receipts and durable child reservation refusals.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('CREATE TABLE bash_launch_ownership (identity VARCHAR(64) NOT NULL, accepted BOOLEAN NOT NULL, owner_session_id INTEGER NOT NULL, PRIMARY KEY (identity), CONSTRAINT FK_D73FCEE33355759A FOREIGN KEY (owner_session_id) REFERENCES hatfield_session (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_D73FCEE33355759A ON bash_launch_ownership (owner_session_id)');
        $this->addSql('CREATE TABLE bash_registration_receipt (identity VARCHAR(64) NOT NULL, input_hash VARCHAR(64) NOT NULL, record_id INTEGER NOT NULL, pid INTEGER NOT NULL, log_path CLOB NOT NULL, owner_session_id INTEGER NOT NULL, PRIMARY KEY (identity), CONSTRAINT FK_4F82099F3355759A FOREIGN KEY (owner_session_id) REFERENCES hatfield_session (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_4F82099F3355759A ON bash_registration_receipt (owner_session_id)');
        $this->addSql('CREATE TABLE child_reservation_refusal (identity VARCHAR(64) NOT NULL, input_hash VARCHAR(64) NOT NULL, refusal_message CLOB NOT NULL, owner_session_id INTEGER NOT NULL, PRIMARY KEY (identity), CONSTRAINT FK_19787BF63355759A FOREIGN KEY (owner_session_id) REFERENCES hatfield_session (id) ON DELETE CASCADE NOT DEFERRABLE INITIALLY IMMEDIATE)');
        $this->addSql('CREATE INDEX IDX_19787BF63355759A ON child_reservation_refusal (owner_session_id)');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('DROP TABLE bash_launch_ownership');
        $this->addSql('DROP TABLE bash_registration_receipt');
        $this->addSql('DROP TABLE child_reservation_refusal');
    }
}
