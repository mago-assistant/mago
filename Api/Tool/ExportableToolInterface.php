<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Tool;

use MagoAssistant\Mago\Api\Data\ExportColumn;

/**
 * Tool whose read-only calls the admin can download in full as a file.
 *
 * ChatService marks a successful call of an exportable action as exportable, with its title and row
 * count, and the chat panel then offers the download under the answer. The export runs the stored call again through
 * exportRows(), after the same permission and ACL checks as the chat.
 * @api
 */
interface ExportableToolInterface extends ToolInterface
{
    /**
     * Whether this invocation can be exported
     *
     * @param array $input The tool call input parameters
     */
    public function isExportableAction(array $input): bool;

    /**
     * What the download of this invocation is called under the answer, such as "Full low stock list"
     *
     * @param array $input The tool call input parameters
     */
    public function getExportTitle(array $input): string;

    /**
     * How many rows the invocation matches in total, counted with one cheap query
     *
     * @param array $input The tool call input parameters, with _admin_user_id as for execute()
     */
    public function countExportRows(array $input): int;

    /**
     * The columns of the file for this invocation, in order
     *
     * @param array $input The tool call input parameters
     * @return list<ExportColumn>
     */
    public function getExportColumns(array $input): array;

    /**
     * Every row the invocation matches, up to $maxRows, keyed by column key
     *
     * @param array $input The tool call input parameters, with _admin_user_id as for execute()
     * @param int $maxRows The most rows to return
     * @return iterable<array<string, scalar|null>>
     */
    public function exportRows(array $input, int $maxRows): iterable;
}
