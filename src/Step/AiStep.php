<?php

declare(strict_types=1);

namespace DealNews\Inngest\Step;

/**
 * AI-specific step operations
 */
class AiStep
{
    public function __construct(protected Step $step)
    {
    }

    /**
     * Perform an AI inference request as a step
     *
     * @param string $id Step identifier
     * @param string $url AI model URL
     * @param array<string, mixed> $body Request body
     * @param array<string, string>|null $headers Optional HTTP headers
     * @param string|null $format Optional response format
     * @return mixed
     */
    public function infer(
        string $id,
        string $url,
        array $body,
        ?array $headers = null,
        ?string $format = null,
        ?string $auth_key = null
    ): mixed {
        return $this->step->aiInfer($id, $url, $body, $headers, $format, $auth_key);
    }
}
