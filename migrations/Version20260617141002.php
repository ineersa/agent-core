<?php

declare(strict_types=1);

namespace DoctrineMigrations;

use Doctrine\DBAL\Schema\Schema;
use Doctrine\Migrations\AbstractMigration;

/**
 * Add answer_text and schema columns to the tool_question table
 * for SafeGuard approval string answers.
 *
 * The tool_question table previously only supported boolean answers
 * via the `answer` column (used by bash background prompts). SafeGuard
 * approvals need to store string answers ("Allow once", "Always allow",
 * "Deny") plus the question schema (JSON) for TUI rendering.
 *
 * Columns added:
 *   answer_text TEXT   — nullable string answer for Approval-kind questions
 *   schema      TEXT   — nullable JSON schema describing the question format
 *
 * Both are nullable for backward compatibility with existing Confirm-kind
 * questions (bash background prompts) that use only the boolean `answer`
 * column.
 */
final class Version20260617141002 extends AbstractMigration
{
    public function getDescription(): string
    {
        return 'Add answer_text and schema columns to tool_question for SafeGuard approval';
    }

    public function up(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tool_question ADD COLUMN answer_text VARCHAR(255) DEFAULT NULL');
        $this->addSql('ALTER TABLE tool_question ADD COLUMN schema TEXT DEFAULT NULL');
    }

    public function down(Schema $schema): void
    {
        $this->addSql('ALTER TABLE tool_question DROP COLUMN answer_text');
        $this->addSql('ALTER TABLE tool_question DROP COLUMN schema');
    }
}
