<?php

declare(strict_types=1);

namespace DealNews\Inngest\Step;

/**
 * Request/response formats Inngest's AI gateway understands
 *
 * Port of `AiAdapter.Format` from `@inngest/ai`. `step->ai->infer()` also
 * takes a plain string, for formats added after this list.
 *
 * @package DealNews\Inngest\Step
 */
enum AiFormat: string {
    case OpenAiChat      = 'openai-chat';
    case OpenAiResponses = 'openai-responses';
    case Anthropic       = 'anthropic';
    case Gemini          = 'gemini';
    case Grok            = 'grok';
    case AzureOpenAi     = 'azure-openai';
}
