<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Console\Command;

use MagoAssistant\Mago\Api\Tool\ToolInterface;
use MagoAssistant\Mago\Service\Tool\ToolRegistry;
use MagoAssistant\Mago\Service\Tool\Verify\AdminAreaInterface;
use MagoAssistant\Mago\Service\Tool\Verify\ToolCheck;
use MagoAssistant\Mago\Service\Tool\Verify\ToolVerifier;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Checks a registered tool the way the chat would use it: its declarations, one call, and what of
 * the result reaches the model after the privacy filter. The raw result is only printed on request,
 * because it can hold the customer data the filter exists to keep out of an LLM context.
 */
class VerifyTool extends Command
{
    private const ARGUMENT_TOOL = 'tool';
    private const ARGUMENT_PARAMS = 'params';
    private const OPTION_ALLOW_WRITE = 'allow-write';
    private const OPTION_SHOW_RAW = 'show-raw';
    private const JSON_FLAGS = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(
        private readonly ToolRegistry $toolRegistry,
        private readonly ToolVerifier $toolVerifier,
        private readonly AdminAreaInterface $adminArea,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('mago:tool:verify')
            ->setDescription('Check a Mago assistant tool: declarations, one call, and what the model sees of it.')
            ->addArgument(self::ARGUMENT_TOOL, InputArgument::REQUIRED, 'Tool name, as getName() returns it')
            ->addArgument(self::ARGUMENT_PARAMS, InputArgument::OPTIONAL, 'Call parameters as a JSON object', '{}')
            ->addOption(self::OPTION_ALLOW_WRITE, null, InputOption::VALUE_NONE, 'Also run a call that writes')
            ->addOption(
                self::OPTION_SHOW_RAW,
                null,
                InputOption::VALUE_NONE,
                'Also print the unfiltered result (may contain customer data)'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $params = $this->decodeParams((string)$input->getArgument(self::ARGUMENT_PARAMS));
        if ($params === null) {
            $output->writeln('<error>Parameters must be a JSON object, e.g. \'{"action": "list"}\'.</error>');
            return Command::INVALID;
        }

        $this->adminArea->enter();

        $toolName = (string)$input->getArgument(self::ARGUMENT_TOOL);
        $tool = $this->toolRegistry->getToolByName($toolName);
        if ($tool === null) {
            $output->writeln('<error>No tool "' . $toolName . '" in the ToolRegistry. Check the di.xml item,'
                . ' bin/magento module:status, and what getName() returns.</error>');
            return Command::FAILURE;
        }

        $output->writeln('Tool: <info>' . $toolName . '</info> (' . $tool::class . ')');
        $checks = $this->toolVerifier->inspect($tool, $params);
        $this->writeChecks($output, $checks);
        $this->writeJson($output, 'Definition sent to the model', $this->toolRegistry->getToolDefinition($tool, null));

        if (!$tool->isReadOnlyAction($params) && !$input->getOption(self::OPTION_ALLOW_WRITE)) {
            $output->writeln('<comment>Skipped execute(): this call writes. Pass --allow-write to run it'
                . ' against this store.</comment>');
            return $this->exitCode($checks);
        }

        return $this->exitCode([...$checks, ...$this->runTool($tool, $params, $input, $output)]);
    }

    /**
     * @param array<string,mixed> $params
     * @return ToolCheck[]
     */
    private function runTool(ToolInterface $tool, array $params, InputInterface $input, OutputInterface $output): array
    {
        $run = $this->toolVerifier->run($tool, $params);

        if ($input->getOption(self::OPTION_SHOW_RAW)) {
            $this->writeJson($output, 'Raw result', $run->rawResult);
        }
        $this->writeJson($output, 'What the model sees', $run->modelView);
        $this->writeChecks($output, $run->checks);

        return $run->checks;
    }

    /**
     * @return array<string,mixed>|null
     */
    private function decodeParams(string $json): ?array
    {
        try {
            $params = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }

        return is_array($params) && ($params === [] || !array_is_list($params)) ? $params : null;
    }

    /**
     * @param ToolCheck[] $checks
     */
    private function writeChecks(OutputInterface $output, array $checks): void
    {
        array_walk(
            $checks,
            static fn (ToolCheck $check) => $output->writeln(
                sprintf('  [%s] %s', $check->status->value, $check->message)
            )
        );
    }

    /**
     * @param array<array-key,mixed> $data
     */
    private function writeJson(OutputInterface $output, string $label, array $data): void
    {
        $output->writeln($label . ':');
        $output->writeln((string)json_encode($data, self::JSON_FLAGS), OutputInterface::OUTPUT_RAW);
    }

    /**
     * @param ToolCheck[] $checks
     */
    private function exitCode(array $checks): int
    {
        return array_filter($checks, static fn (ToolCheck $check) => $check->isFailure()) === []
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}
