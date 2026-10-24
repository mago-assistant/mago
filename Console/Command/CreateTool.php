<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Console\Command;

use Magento\Framework\App\Filesystem\DirectoryList;
use Magento\Framework\Filesystem;
use MagoAssistant\Mago\Service\Tool\Scaffold\InvalidScaffoldException;
use MagoAssistant\Mago\Service\Tool\Scaffold\ToolScaffold;
use MagoAssistant\Mago\Service\Tool\Scaffold\ToolScaffolder;
use MagoAssistant\Mago\Service\Tool\Verify\AclResourceIndex;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Scaffolds a separate module holding one Mago tool, in app/code unless --path names another
 * directory under the Magento root (which composer then installs as a path repository). It never
 * overwrites: an existing target directory aborts the command.
 */
class CreateTool extends Command
{
    private const ARGUMENT_TOOL = 'tool';
    private const ARGUMENT_MODULE = 'module';
    private const OPTION_ACL = 'acl';
    private const OPTION_ACCESS = 'access';
    private const OPTION_CLASS = 'class';
    private const OPTION_PACKAGE = 'package';
    private const OPTION_PATH = 'path';
    private const APP_CODE = 'app/code';

    public function __construct(
        private readonly ToolScaffolder $scaffolder,
        private readonly AclResourceIndex $aclResources,
        private readonly Filesystem $filesystem,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('mago:tool:create')
            ->setDescription('Scaffold a module with a new Mago assistant tool.')
            ->addArgument(self::ARGUMENT_TOOL, InputArgument::REQUIRED, 'Tool name, e.g. vies_vat_check')
            ->addArgument(self::ARGUMENT_MODULE, InputArgument::REQUIRED, 'Module name, e.g. MagoAssistant_Vies')
            ->addOption(
                self::OPTION_ACL,
                null,
                InputOption::VALUE_REQUIRED,
                'Magento ACL resource an admin needs for the same action in the backend (required)'
            )
            ->addOption(self::OPTION_ACCESS, null, InputOption::VALUE_REQUIRED, '"read" or "write" (required)')
            ->addOption(self::OPTION_CLASS, null, InputOption::VALUE_REQUIRED, 'Tool class name', '')
            ->addOption(self::OPTION_PACKAGE, null, InputOption::VALUE_REQUIRED, 'Composer package name', '')
            ->addOption(
                self::OPTION_PATH,
                null,
                InputOption::VALUE_REQUIRED,
                'Module directory relative to the Magento root (default: app/code/<Vendor>/<Module>)',
                ''
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $scaffold = ToolScaffold::fromInput(
                (string)$input->getArgument(self::ARGUMENT_TOOL),
                (string)$input->getArgument(self::ARGUMENT_MODULE),
                (string)$input->getOption(self::OPTION_ACL),
                (string)$input->getOption(self::OPTION_ACCESS),
                (string)$input->getOption(self::OPTION_CLASS),
                (string)$input->getOption(self::OPTION_PACKAGE)
            );
            $moduleDirectory = $this->resolveModuleDirectory($scaffold, (string)$input->getOption(self::OPTION_PATH));
        } catch (InvalidScaffoldException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        }

        $root = $this->filesystem->getDirectoryWrite(DirectoryList::ROOT);
        if ($root->isExist($moduleDirectory)) {
            $output->writeln('<error>' . $moduleDirectory . ' already exists; nothing was written.</error>');
            return Command::FAILURE;
        }

        foreach ($this->scaffolder->render($scaffold) as $path => $contents) {
            $root->writeFile($moduleDirectory . '/' . $path, $contents);
            $output->writeln('Created ' . $moduleDirectory . '/' . $path);
        }

        $this->writeAclWarning($output, $scaffold);
        $output->writeln(['', 'Next steps, from the Magento root:']);
        $this->writeNextSteps($output, $scaffold, $moduleDirectory);
        $output->writeln(['', 'Guide: vendor/mago-assistant/magento2-mago/docs/building-a-tool.md']);

        return Command::SUCCESS;
    }

    /**
     * @throws InvalidScaffoldException
     */
    private function resolveModuleDirectory(ToolScaffold $scaffold, string $path): string
    {
        if ($path === '') {
            return self::APP_CODE . '/' . $scaffold->vendor . '/' . $scaffold->module;
        }

        $trimmed = trim($path, '/');
        if ($trimmed === '' || str_starts_with($path, '/') || in_array('..', explode('/', $trimmed), true)) {
            throw new InvalidScaffoldException('--path must be a directory inside the Magento root.');
        }

        return $trimmed;
    }

    private function isInAppCode(string $moduleDirectory): bool
    {
        return str_starts_with($moduleDirectory, self::APP_CODE . '/');
    }

    private function writeAclWarning(OutputInterface $output, ToolScaffold $scaffold): void
    {
        if ($scaffold->hasOwnAclResource() || $this->aclResources->has($scaffold->acl)) {
            return;
        }

        $output->writeln('<comment>ACL resource ' . $scaffold->acl . ' is not declared in any acl.xml;'
            . ' mago:tool:verify fails until it exists. Check the spelling.</comment>');
    }

    private function writeNextSteps(OutputInterface $output, ToolScaffold $scaffold, string $moduleDirectory): void
    {
        if ($this->isInAppCode($moduleDirectory)) {
            $this->writeAppCodeSteps($output, $scaffold);
            return;
        }

        $this->writePathRepositorySteps($output, $scaffold, $moduleDirectory);
    }

    private function writeAppCodeSteps(OutputInterface $output, ToolScaffold $scaffold): void
    {
        $output->writeln([
            '  bin/magento module:enable ' . $scaffold->vendor . '_' . $scaffold->module,
            '  bin/magento setup:upgrade',
            '  bin/magento mago:tool:verify ' . $scaffold->toolName . ' \'{}\'',
        ]);
    }

    private function writePathRepositorySteps(
        OutputInterface $output,
        ToolScaffold $scaffold,
        string $moduleDirectory
    ): void {
        $output->writeln([
            '  composer config repositories.' . str_replace('/', '-', $scaffold->packageName)
                . ' \'{"type": "path", "url": "' . $moduleDirectory . '"}\'',
            '  composer require ' . $scaffold->packageName . ':@dev',
        ]);
        $this->writeAppCodeSteps($output, $scaffold);
    }
}
