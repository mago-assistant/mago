<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Etc;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Deleting a conversation must leave nothing behind that still points at it. A table carrying a
 * conversation_id therefore has to say what happens on that delete: CASCADE when the row only
 * exists for the conversation, or SET NULL when it outlives it for its own reasons (usage and cost
 * records do). What is not allowed is no foreign key at all, which is not a decision but an
 * omission, and leaves rows holding what the admin asked us to forget. This reads the declaration
 * rather than a migrated database, so the schema is held to the rule, not one install's state.
 */
final class DbSchemaTest extends TestCase
{
    private const CONVERSATION_KEY = 'conversation_id';

    private \SimpleXMLElement $schema;

    protected function setUp(): void
    {
        $schema = simplexml_load_file(__DIR__ . '/../../../etc/db_schema.xml');
        self::assertInstanceOf(\SimpleXMLElement::class, $schema, 'etc/db_schema.xml must parse');
        $this->schema = $schema;
    }

    #[Test]
    public function everyTableKeyedToAConversationCascadesOnDelete(): void
    {
        $offenders = [];
        foreach ($this->schema->table as $table) {
            $name = (string)$table['name'];
            if (!$this->hasColumn($table, self::CONVERSATION_KEY) || $name === 'mago_conversation') {
                continue;
            }
            if (!$this->declaresConversationDelete($table)) {
                $offenders[] = $name;
            }
        }

        self::assertSame(
            [],
            $offenders,
            'these tables carry a conversation_id without a foreign key saying what a conversation '
            . 'delete does to them: ' . implode(', ', $offenders)
        );
    }

    private function hasColumn(\SimpleXMLElement $table, string $column): bool
    {
        foreach ($table->column as $candidate) {
            if ((string)$candidate['name'] === $column) {
                return true;
            }
        }

        return false;
    }

    private function declaresConversationDelete(\SimpleXMLElement $table): bool
    {
        foreach ($table->constraint as $constraint) {
            $type = (string)$constraint->attributes('xsi', true)['type'];
            if ($type !== 'foreign' || (string)$constraint['column'] !== self::CONVERSATION_KEY) {
                continue;
            }
            if ((string)$constraint['referenceTable'] !== 'mago_conversation') {
                continue;
            }

            $onDelete = strtoupper((string)$constraint['onDelete']);
            if ($onDelete === 'CASCADE') {
                return true;
            }
            // SET NULL only says something if the column can actually hold one.
            if ($onDelete === 'SET NULL' && $this->columnIsNullable($table, self::CONVERSATION_KEY)) {
                return true;
            }
        }

        return false;
    }

    private function columnIsNullable(\SimpleXMLElement $table, string $column): bool
    {
        foreach ($table->column as $candidate) {
            if ((string)$candidate['name'] === $column) {
                return (string)$candidate['nullable'] === 'true';
            }
        }

        return false;
    }
}
