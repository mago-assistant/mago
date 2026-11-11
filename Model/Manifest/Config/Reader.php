<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Model\Manifest\Config;

use Magento\Framework\Config\Reader\Filesystem;
use Magento\Framework\Exception\LocalizedException;

/**
 * Reads every module's etc/mago_tools.xml, validated against etc/mago_tools.xsd, into one tree.
 * Tools, actions, params and steps merge by name across modules, in module load order, the way
 * Magento merges its other configuration: a later module that declares a <tool> with a name an
 * earlier module used adds to and overrides it, module, acl and readonly included. Nothing rejects
 * that yet; a module should therefore prefix its tool names. The file name, converter and schema
 * locator come from di.xml.
 *
 * Every file declares the version of the format it is written in (config@version). Merging keeps
 * one root, so the version is stamped on each <tool> of the file before the files are merged; the
 * converter hands it on as schema_version, and a later format can read or refuse a tool by it.
 */
class Reader extends Filesystem
{
    public const FILE_NAME = 'mago_tools.xml';
    public const SCHEMA_VERSION = '1';
    public const SCHEMA_ATTRIBUTE = 'schema';

    /**
     * @var array<string, string>
     */
    protected $_idAttributes = [
        '/config/tool' => 'name',
        '/config/tool/action' => 'name',
        '/config/tool/action/param' => 'name',
        '/config/tool/action/step' => 'name',
        '/config/tool/action/step/field' => 'name',
        '/config/tool/action/field' => 'name',
        '/config/tool/action/pick' => 'field',
        '/config/tool/action/link' => 'route',
    ];

    /**
     * @param iterable<string, string> $fileList file name => content
     * @return array<string, mixed>
     * @throws LocalizedException
     */
    protected function _readFiles($fileList)
    {
        $stamped = [];
        foreach ($fileList as $key => $content) {
            $stamped[$key] = $this->stampVersion((string)$key, (string)$content);
        }

        return parent::_readFiles($stamped);
    }

    /**
     * The file's content with its config@version copied onto each of its tools.
     *
     * @throws LocalizedException
     */
    private function stampVersion(string $file, string $content): string
    {
        $previous = libxml_use_internal_errors(true);
        $document = new \DOMDocument();
        $loaded = $document->loadXML($content);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->documentElement;
        if (!$loaded || $root === null) {
            // Not well-formed: leave it to the merger, which reports it with the file name
            return $content;
        }
        $version = $root->getAttribute('version');
        if ($version === '') {
            throw new LocalizedException(__(
                'The manifest "%1" has no version: declare the format it is written in, <config version="%2">.',
                $file,
                self::SCHEMA_VERSION
            ));
        }
        foreach ($root->getElementsByTagName('tool') as $tool) {
            if ($tool instanceof \DOMElement && $tool->parentNode === $root) {
                $tool->setAttribute(self::SCHEMA_ATTRIBUTE, $version);
            }
        }

        return (string)$document->saveXML();
    }
}
