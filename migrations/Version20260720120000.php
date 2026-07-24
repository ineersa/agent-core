<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Drop obsolete SafeGuard string-answer column from tool_question.
 * Canonical approvals use WaitingHuman/human_input; bash ToolQuestion remains boolean-only.
 */
final class Version20260720120000 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Drop tool_question.answer_text (SafeGuard string answers removed)';
    }

    public function up(Schema $schema): void
    {
        // Fresh runtime DBs may not have tool_question yet when this version is
        // applied through ApplicationMigrationExecutor's ordered list. Only
        // drop the obsolete column when the table/column still exist.
        $schemaManager = $this->connection->createSchemaManager();
        if (!$schemaManager->tablesExist(['tool_question'])) {
            return;
        }

        $columns = $schemaManager->listTableColumns('tool_question');
        $hasAnswerText = false;
        foreach ($columns as $column) {
            if ('answer_text' === $column->getName()) {
                $hasAnswerText = true;
                break;
            }
        }
        if (!$hasAnswerText) {
            return;
        }

        $this->addSql('ALTER TABLE tool_question DROP COLUMN answer_text');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tool_question ADD answer_text VARCHAR(255) DEFAULT NULL');
    }
}
