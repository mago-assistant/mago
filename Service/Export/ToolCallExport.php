<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Export;

use MagoAssistant\Mago\Api\ConversationRepositoryInterface;
use MagoAssistant\Mago\Api\Tool\ExportableToolInterface;
use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Privacy\PrivacyService;
use MagoAssistant\Mago\Service\Skills\ResultLimit;
use MagoAssistant\Mago\Service\Tool\ToolCallGate;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;

/**
 * Runs a read-only tool call from a conversation again, without the chat's page limit, and writes
 * the full result as a file (#298).
 *
 * Read results are not stored, so the export repeats the stored call with the same arguments: the
 * file holds the data as it is when the admin downloads it. The call goes through the same checks
 * as in the chat: the conversation must be the admin's own, and the skill permission and Magento
 * ACL must allow the call. The file is for the admin only and never reaches the AI provider, so the
 * stored arguments are rehydrated and the rows carry real values, as on the admin's own grids.
 */
class ToolCallExport
{
    /**
     * @param array<string, TabularWriterInterface> $writers Keyed by format
     */
    public function __construct(
        private readonly ConversationRepositoryInterface $conversationRepository,
        private readonly ToolRegistry $toolRegistry,
        private readonly ToolCallGate $toolCallGate,
        private readonly PrivacyService $privacyService,
        private readonly array $writers = []
    ) {
    }

    /**
     * @throws ExportRefusedException when the call cannot be exported for this admin
     */
    public function export(int $conversationId, string $toolCallId, string $format, int $adminUserId): ExportFile
    {
        $writer = $this->writers[$format] ?? throw new ExportRefusedException('Unknown export format.');
        $toolCall = $this->storedToolCall($conversationId, $toolCallId, $adminUserId);
        $tool = $this->exportableTool($toolCall, $adminUserId);
        $input = $this->toolCallGate->inputForAction($tool, (array)($toolCall['input'] ?? []));

        $denial = $this->toolCallGate->denialReason($tool, $input, $adminUserId);
        if ($denial !== null) {
            throw new ExportRefusedException($denial);
        }
        if (!$tool->isReadOnlyAction($input) || !$tool->isExportableAction($input)) {
            throw new ExportRefusedException('This result cannot be downloaded.');
        }

        $this->privacyService->beginConversation($conversationId);
        $input = $this->privacyService->rehydrateArguments($input);
        $input['_admin_user_id'] = $adminUserId;
        [$rows, $isCut] = $this->rowsWithin($tool->exportRows($input, ResultLimit::EXPORT_MAX + 1));

        return new ExportFile(
            $this->fileName($tool, $input, $isCut) . '.' . $writer->getFileExtension(),
            $writer->getContentType(),
            $writer->write($tool->getExportColumns($input), $rows)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function storedToolCall(int $conversationId, string $toolCallId, int $adminUserId): array
    {
        try {
            $this->conversationRepository->getByIdForUser($conversationId, $adminUserId);
        } catch (\InvalidArgumentException) {
            throw new ExportRefusedException('This conversation was not found.');
        }

        $toolCalls = array_merge(...array_map(
            static fn (array $message): array => ($message['role'] ?? '') === 'assistant'
                ? (array)(json_decode((string)($message['tool_calls'] ?? ''), true) ?: [])
                : [],
            $this->conversationRepository->getMessages($conversationId)
        ));
        foreach ($toolCalls as $toolCall) {
            if (is_array($toolCall) && $toolCallId !== '' && (string)($toolCall['id'] ?? '') === $toolCallId
                && ($toolCall['status'] ?? '') === 'done') {
                return $toolCall;
            }
        }

        throw new ExportRefusedException('This result was not found in the conversation.');
    }

    /**
     * @param array<string, mixed> $toolCall
     */
    private function exportableTool(array $toolCall, int $adminUserId): ExportableToolInterface
    {
        $tool = $this->toolRegistry->getTool((string)($toolCall['name'] ?? ''), $adminUserId);
        if (!$tool instanceof ExportableToolInterface) {
            throw new ExportRefusedException('This result cannot be downloaded.');
        }

        return $tool;
    }

    /**
     * The rows up to the ceiling, and whether there were more
     *
     * @param iterable<array<string, scalar|null>> $rows
     * @return array{0: list<array<string, scalar|null>>, 1: bool}
     */
    private function rowsWithin(iterable $rows): array
    {
        $kept = [];
        foreach ($rows as $row) {
            if (count($kept) === ResultLimit::EXPORT_MAX) {
                return [$kept, true];
            }
            $kept[] = $row;
        }

        return [$kept, false];
    }

    /**
     * @param array<string, mixed> $input
     */
    private function fileName(ToolInterface $tool, array $input, bool $isCut): string
    {
        $parts = array_filter(
            [$tool->getName(), (string)($input['action'] ?? ''), date('Y-m-d'), $isCut ? 'first-' . ResultLimit::EXPORT_MAX : ''],
            static fn (string $part): bool => $part !== ''
        );

        return (string)preg_replace('/[^a-z0-9-]+/', '-', strtolower(implode('-', $parts)));
    }
}
