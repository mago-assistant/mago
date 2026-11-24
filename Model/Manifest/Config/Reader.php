<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Manifest\Config;

use Magento\Framework\Component\ComponentRegistrar;
use Magento\Framework\Component\ComponentRegistrarInterface;
use Magento\Framework\Config\Dom;
use Magento\Framework\Config\FileResolverInterface;
use Magento\Framework\Config\Reader\Filesystem;
use Magento\Framework\Config\ValidationStateInterface;
use Magento\Framework\Exception\LocalizedException;
use MagoAssistant\Mago\Service\Error\ErrorReporter;

/**
 * Reads every module's etc/mago_tools.xml into the tools they declare.
 *
 * A manifest is written by another party and is never read by setup:upgrade or setup:di:compile,
 * so a mistake in one first shows at runtime. Three things follow from that:
 *
 * - Each file is validated against etc/mago_tools.xsd on its own, in every mode. Magento only
 *   validates configuration in developer mode, and what the runtime relies on (tool and parameter
 *   names, methods, a resource on every tool) is exactly what the schema guarantees.
 * - A file that does not validate is logged under ManifestTools and left out; the other modules'
 *   tools are read as usual.
 * - Files are not merged. A tool belongs to the module that declares it first: a later file that
 *   declares a tool of the same name is logged and that tool left out, so one module cannot change
 *   another module's tool, its resource or its routes.
 */
class Reader extends Filesystem
{
    public const FILE_NAME = 'mago_tools.xml';
    public const SCHEMA_VERSION = '1';
    public const LOG_CONTEXT = 'ManifestTools';

    private const MAX_REPORTED_ERRORS = 5;

    /**
     * @param array<string, string> $idAttributes
     */
    public function __construct(
        FileResolverInterface $fileResolver,
        private readonly Converter $manifestConverter,
        SchemaLocator $schemaLocator,
        ValidationStateInterface $validationState,
        private readonly ErrorReporter $errorReporter,
        private readonly ComponentRegistrarInterface $componentRegistrar,
        string $fileName = self::FILE_NAME,
        array $idAttributes = [],
        string $domDocumentClass = Dom::class,
        string $defaultScope = 'global'
    ) {
        parent::__construct(
            $fileResolver,
            $manifestConverter,
            $schemaLocator,
            $validationState,
            $fileName,
            $idAttributes,
            $domDocumentClass,
            $defaultScope
        );
    }

    /**
     * The module whose directory holds the file, or an empty string when none does.
     */
    private function moduleOf(string $file): string
    {
        $owner = '';
        $longest = 0;
        foreach ($this->componentRegistrar->getPaths(ComponentRegistrar::MODULE) as $module => $path) {
            $path = rtrim((string)$path, '/') . '/';
            if (str_starts_with($file, $path) && strlen($path) > $longest) {
                $owner = (string)$module;
                $longest = strlen($path);
            }
        }

        return $owner;
    }

    /**
     * @param iterable<string, string> $fileList file name => content
     * @return array<string, array<string, mixed>> Tool definitions keyed by tool name
     */
    protected function _readFiles($fileList)
    {
        $tools = [];
        $declaredIn = [];
        foreach ($fileList as $file => $content) {
            try {
                $declared = $this->toolsOf((string)$content);
            } catch (\Throwable $exception) {
                $this->report(new LocalizedException(
                    __('The manifest "%1" is left out: %2', (string)$file, $exception->getMessage())
                ));
                continue;
            }
            $owner = $this->moduleOf((string)$file);
            foreach ($declared as $name => $tool) {
                if ($tool['module'] !== $owner) {
                    $this->report(new LocalizedException(__(
                        'The manifest "%1" declares the tool "%2" for the module "%3", but the file belongs to '
                        . '"%4". A module declares its own tools only, so this declaration is left out.',
                        (string)$file,
                        $name,
                        $tool['module'],
                        $owner
                    )));
                    continue;
                }
                if (isset($tools[$name])) {
                    $this->report(new LocalizedException(__(
                        'The manifest "%1" declares the tool "%2", which "%3" already declares. A tool belongs '
                        . 'to one module, so this declaration is left out; give the tool its own name.',
                        (string)$file,
                        $name,
                        $declaredIn[$name]
                    )));
                    continue;
                }
                $tools[$name] = $tool;
                $declaredIn[$name] = (string)$file;
            }
        }

        return $tools;
    }

    /**
     * The tools one file declares, after validating it against the schema.
     *
     * @return array<string, array<string, mixed>>
     * @throws LocalizedException When the file is not well-formed or does not follow the schema
     */
    private function toolsOf(string $content): array
    {
        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();
        try {
            $document = new \DOMDocument();
            if (!$document->loadXML($content)) {
                throw new LocalizedException(__('it is not well-formed XML (%1)', $this->libxmlErrors()));
            }
            // Entities are not expanded on load; a document type has no use in a manifest either
            if ($document->doctype !== null) {
                throw new LocalizedException(__('it carries a DOCTYPE, which a manifest may not have'));
            }
            if (!$document->schemaValidate((string)$this->_schemaFile)) {
                throw new LocalizedException(__('it does not follow mago_tools.xsd (%1)', $this->libxmlErrors()));
            }

            return $this->manifestConverter->convert($document);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    private function libxmlErrors(): string
    {
        $messages = array_map(
            static fn (\LibXMLError $error): string => trim($error->message) . ' on line ' . $error->line,
            array_slice(libxml_get_errors(), 0, self::MAX_REPORTED_ERRORS)
        );

        return implode('; ', $messages);
    }

    /**
     * Logging must not be what takes the registry down: the log directory may not be writable.
     */
    private function report(\Throwable $exception): void
    {
        try {
            $this->errorReporter->log(self::LOG_CONTEXT, $exception);
        } catch (\Throwable) {
            return;
        }
    }
}
