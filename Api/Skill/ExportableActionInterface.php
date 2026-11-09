<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Api\Skill;

use MagoAssistant\Mago\Api\Data\ExportColumn;

/**
 * A read-only list action whose full result the admin can download as a file.
 *
 * In the chat the action answers with at most a page of rows, sized for the model. The chat panel
 * offers a download under the answer, and the export runs the call again with the same
 * arguments through exportRows(), without that page limit. The file goes to the admin only, never
 * to the AI provider, so the rows carry real values.
 *
 * The skill-level counterpart of ExportableToolInterface.
 * @api
 */
interface ExportableActionInterface extends ActionInterface
{
    /**
     * What the download is called under the answer, such as "Full low stock list"
     *
     * @param array $params Tool call parameters, as execute() receives them
     */
    public function getExportTitle(array $params): string;

    /**
     * How many rows the call matches in total, so the admin knows what the file holds before
     * downloading it. Counted with one cheap query when the call runs in the chat.
     *
     * @param array $params Tool call parameters, as execute() receives them
     * @param int $adminUserId Current admin user ID
     */
    public function countExportRows(array $params, int $adminUserId): int;

    /**
     * The columns of the file, in order
     *
     * @return list<ExportColumn>
     */
    public function getExportColumns(): array;

    /**
     * Every row the call matches, up to $maxRows, keyed by column key. Read in pages, so a large
     * result does not load in one query.
     *
     * @param array $params Tool call parameters, as execute() receives them
     * @param int $adminUserId Current admin user ID
     * @param int $maxRows The most rows to return
     * @return iterable<array<string, scalar|null>>
     */
    public function exportRows(array $params, int $adminUserId, int $maxRows): iterable;
}
