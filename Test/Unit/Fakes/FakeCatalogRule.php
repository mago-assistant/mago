<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Test\Unit\Fakes;

use Magento\CatalogRule\Model\Rule;
use Magento\Framework\DataObject;

/**
 * A catalog rule that is only its data: no resource model, serializer or condition tree. Magento's
 * validation answer is given up front with givenValidationFails(). Conditions are not faked; a test
 * that reaches them belongs in the integration suite.
 */
class FakeCatalogRule extends Rule
{
    /** @var true|string[] */
    private bool|array $validation = true;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(array $data = [])
    {
        $this->_data = $data;
    }

    /**
     * @param string[] $reasons
     */
    public function givenValidationFails(array $reasons): self
    {
        $this->validation = $reasons;

        return $this;
    }

    public function getId(): ?int
    {
        return isset($this->_data['rule_id']) ? (int)$this->_data['rule_id'] : null;
    }

    public function getWebsiteIds(): array
    {
        return (array)($this->_data['website_ids'] ?? []);
    }

    /**
     * @return true|string[]
     */
    public function validateData(DataObject $dataObject): bool|array
    {
        return $this->validation;
    }

    public function getConditions(): never
    {
        throw new \LogicException('FakeCatalogRule has no condition tree');
    }
}
