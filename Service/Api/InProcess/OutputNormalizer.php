<?php
/**
 * Copyright © Mago Assistant
 */
declare(strict_types=1);

namespace MagoAssistant\Mago\Service\Api\InProcess;

use Magento\Framework\Serialize\Serializer\Json;

/**
 * Gives a service's processed output exactly the shape a REST response decodes to: what went over the
 * wire as JSON is JSON again (Phrase objects become strings, floats and ints read back as JSON reads
 * them), and a scalar answer such as an id or a bool sits under "result", where the tools look for it.
 */
class OutputNormalizer
{
    public const SCALAR_KEY = 'result';

    public function __construct(
        private readonly Json $json
    ) {
    }

    /**
     * @return array<array-key, mixed>
     */
    public function normalize(mixed $output): array
    {
        $decoded = $this->json->unserialize((string)$this->json->serialize($output));

        return is_array($decoded) ? $decoded : [self::SCALAR_KEY => $decoded];
    }
}
