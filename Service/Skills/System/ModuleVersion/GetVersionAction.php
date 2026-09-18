<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\System\ModuleVersion;

use MagoAssistant\Mago\Api\ModuleInfo\RepositoryInterface as ModuleInfoRepository;
use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

class GetVersionAction implements ActionInterface
{
    private const MAX_CANDIDATES = 10;

    public function __construct(
        private readonly ModuleInfoRepository $moduleInfoRepository
    ) {
    }

    public function getName(): string
    {
        return 'get_version';
    }

    public function getDescription(): string
    {
        return 'Look up the installed version of a Magento module or extension by name, human-readable '
            . 'or partial (e.g. "Channable" or "Magmodules Channable").';
    }

    public function getParameterSchema(): array
    {
        return [
            'module_name' => [
                'type' => 'string',
                'description' => 'The module, extension or vendor name to look up. Partial and '
                    . 'human-readable names are matched, e.g. "Channable" resolves to "Magmodules_Channable".',
            ],
        ];
    }

    public function getAclResource(): ?string
    {
        return null;
    }

    public function isReadOnly(): bool
    {
        return true;
    }

    public function getFieldClassification(): array
    {
        return [
            'message' => [PiiClass::PUBLIC],
            'ambiguous' => [PiiClass::PUBLIC],
            'module_name' => [PiiClass::PUBLIC],
            'package_name' => [PiiClass::PUBLIC],
            'version' => [PiiClass::PUBLIC],
            'version_source' => [PiiClass::PUBLIC],
            'is_enabled' => [PiiClass::PUBLIC],
        ];
    }

    public function execute(array $params, int $adminUserId): array
    {
        $query = trim((string)($params['module_name'] ?? ''));
        if ($query === '') {
            return ['error' => 'module_name parameter is required'];
        }

        $matches = $this->moduleInfoRepository->findModuleNames($query);

        if ($matches === []) {
            return ['error' => sprintf('No installed module matches "%s"', $query)];
        }

        if (count($matches) > 1) {
            return [
                'ambiguous' => true,
                'message' => sprintf(
                    '%d installed modules match "%s". Ask which one, then call again with its exact module name.',
                    count($matches),
                    $query
                ),
                'candidates' => array_map(
                    fn (string $moduleName): array => $this->describeModule($moduleName),
                    array_slice($matches, 0, self::MAX_CANDIDATES)
                ),
            ];
        }

        return $this->describeModule($matches[0]);
    }

    public function getInstructions(): string
    {
        return 'module_name is matched fuzzily: every word in it must appear somewhere in the module '
            . "name or its composer package name, so \"Channable\" or \"Magmodules Channable\" both work.\n"
            . 'version_source says where the number came from: "composer" is what Composer '
            . 'actually installed and is the one to trust, "composer.json" is the version field '
            . 'declared by the module itself, and "module.xml" is its setup_version (common for '
            . 'in-house app/code modules Composer does not manage). Mention the source when it is '
            . 'not "composer", because the other two can be stale. '
            . 'When the result has "ambiguous": true, ask the user which of the candidates they mean '
            . 'instead of guessing.';
    }

    private function describeModule(string $moduleName): array
    {
        $versionInfo = $this->moduleInfoRepository->getVersionInfo($moduleName);

        return [
            'module_name' => $moduleName,
            'package_name' => $this->moduleInfoRepository->getPackageName($moduleName),
            'version' => $versionInfo['version'] !== '' ? $versionInfo['version'] : 'unknown',
            'version_source' => $versionInfo['source'],
            'is_enabled' => $this->moduleInfoRepository->isEnabled($moduleName),
        ];
    }
}
