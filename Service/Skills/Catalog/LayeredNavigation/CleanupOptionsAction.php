<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Skills\Catalog\LayeredNavigation;

use MagoAssistant\Mago\Api\Skill\ActionInterface;
use MagoAssistant\Mago\Service\Privacy\PiiClass;

/**
 * An attribute's options with how many products use each, the unused ones, and groups of options that
 * are the same value written differently ("8 MP", "8MP", "8mp")
 */
class CleanupOptionsAction implements ActionInterface
{
    /** Options listed per group */
    private const UNUSED_SHOWN = 50;

    public function __construct(
        private readonly FilterAttributes $filterAttributes
    ) {
    }

    public function getName(): string
    {
        return 'cleanup_options';
    }

    public function getDescription(): string
    {
        return 'List an attribute\'s options without products and its duplicate options, with product counts, '
            . 'before delete_options or merge_options';
    }

    public function getParameterSchema(): array
    {
        return [
            'attribute' => [
                'type' => 'string',
                'description' => 'Attribute code, from list_filters or audit_filters',
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
        return [PiiClass::ANY => [PiiClass::PUBLIC]];
    }

    public function getInstructions(): string
    {
        return 'Show the duplicate groups first (each option with its id and product count; suggested_target is '
            . 'the one with most products), then the unused options. Product counts cover every store view. '
            . 'Propose delete_options for unused options and merge_options per duplicate group; never both in one '
            . 'call, and let the user pick the option to keep.';
    }

    public function execute(array $params, int $adminUserId): array
    {
        $code = trim((string)($params['attribute'] ?? ''));
        $attribute = $code === '' ? null : $this->filterAttributes->attribute($code);
        if ($attribute === null) {
            return ['error' => sprintf('There is no product attribute "%s". Use list_filters for the codes.', $code)];
        }
        if (!in_array($attribute['input'], FilterAttributes::OPTION_INPUTS, true)) {
            return ['error' => sprintf(
                'Attribute "%s" is a %s field and has no options to clean up.',
                $attribute['code'],
                $attribute['input']
            )];
        }

        $options = $this->filterAttributes->options($attribute);
        $counts = $this->filterAttributes->optionProductCounts($attribute);
        $unused = [];
        $groups = [];
        foreach ($options as $optionId => $label) {
            $count = $counts[$optionId] ?? 0;
            if ($count === 0) {
                $unused[] = ['option_id' => $optionId, 'label' => $label];
            }
            $groups[self::normalize($label)][] = ['option_id' => $optionId, 'label' => $label, 'products' => $count];
        }

        $duplicates = [];
        foreach (array_filter($groups, static fn (array $group) => count($group) > 1) as $group) {
            usort($group, static fn (array $a, array $b) => $b['products'] <=> $a['products']);
            $duplicates[] = ['suggested_target' => $group[0]['option_id'], 'options' => $group];
        }

        return [
            'attribute' => $attribute['code'],
            'label' => $attribute['label'],
            'option_count' => count($options),
            'used_by_configurables' => $this->filterAttributes->isConfigurableAttribute($attribute['attribute_id']),
            'duplicate_groups' => $duplicates,
            'unused_count' => count($unused),
            'unused_options' => array_slice($unused, 0, self::UNUSED_SHOWN),
        ];
    }

    /**
     * The same value however it is written: case, accents, spaces and separators ignored
     *
     * @param string $label
     * @return string
     */
    public static function normalize(string $label): string
    {
        $decomposed = (string)\Normalizer::normalize(mb_strtolower(trim($label)), \Normalizer::FORM_D);

        return (string)preg_replace(['/\p{Mn}+/u', '/[\s\-_.,\/]+/u'], '', $decomposed);
    }
}
