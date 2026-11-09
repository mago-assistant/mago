<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Console\Command;

use Magento\Framework\Filesystem\Driver\File;
use Magento\Framework\Module\Dir;
use Magento\Framework\Module\Dir\Reader as ModuleDirReader;
use MagoAssistant\Mago\Model\Manifest\Config\Reader;
use MagoAssistant\Mago\Service\Manifest\Scaffold\ManifestDrafter;
use MagoAssistant\Mago\Service\Tool\Scaffold\InvalidScaffoldException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Drafts etc/mago_tools.xml for a module from its REST routes, so a module that already has a web
 * API offers its tools to the chat without a line of PHP or any dependency on Mago. Printed by
 * default; --write puts it in the module's etc/ and never overwrites an existing manifest.
 */
class CreateManifest extends Command
{
    private const ARGUMENT_MODULE = 'module';
    private const OPTION_WRITE = 'write';

    public function __construct(
        private readonly ManifestDrafter $drafter,
        private readonly ModuleDirReader $moduleDirReader,
        private readonly File $fileDriver,
        ?string $name = null
    ) {
        parent::__construct($name);
    }

    protected function configure(): void
    {
        $this->setName('mago:tool:manifest')
            ->setDescription(
                'Draft etc/mago_tools.xml for a module from its webapi.xml, so its REST routes become chat tools.'
            )
            ->addArgument(self::ARGUMENT_MODULE, InputArgument::REQUIRED, 'Module name, e.g. MageOS_RMA')
            ->addOption(
                self::OPTION_WRITE,
                null,
                InputOption::VALUE_NONE,
                'Write the draft to the module\'s etc/' . Reader::FILE_NAME . ' instead of printing it'
            );
        parent::configure();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $module = (string)$input->getArgument(self::ARGUMENT_MODULE);
        try {
            $xml = $this->drafter->draft($module);
        } catch (InvalidScaffoldException $exception) {
            $output->writeln('<error>' . $exception->getMessage() . '</error>');
            return Command::INVALID;
        }

        if (!$input->getOption(self::OPTION_WRITE)) {
            $output->writeln($xml, OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        }

        $target = $this->moduleDirReader->getModuleDir(Dir::MODULE_ETC_DIR, $module) . '/' . Reader::FILE_NAME;
        if ($this->fileDriver->isExists($target)) {
            $output->writeln('<error>' . $target . ' already exists; nothing was written.</error>');
            return Command::FAILURE;
        }
        $this->fileDriver->filePutContents($target, $xml);
        $output->writeln([
            'Created ' . $target,
            '',
            'Next steps: resolve every TODO in the file, then',
            '  bin/magento cache:clean config',
            '  bin/magento mago:tool:verify <tool> \'{"action": "<action>"}\'',
            '',
            'Guide: vendor/mago-assistant/magento2-mago/docs/manifest-tools.md',
        ]);

        return Command::SUCCESS;
    }
}
