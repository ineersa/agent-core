<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

final class Version20261004000200 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Record execution claim exclusion and committed unknown notices.';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE execution_operation ADD claim_lock_key VARCHAR(64) DEFAULT NULL');
        $this->addSql('ALTER TABLE execution_operation ADD unknown_notice_transition VARCHAR(64) DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE execution_operation DROP COLUMN unknown_notice_transition');
        $this->addSql('ALTER TABLE execution_operation DROP COLUMN claim_lock_key');
    }
}
