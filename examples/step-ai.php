<?php

/**
 * Step: AI Inference example
 *
 * This example demonstrates:
 * - Making AI inference requests as retriable steps
 * - Using the step.ai()->infer() API
 * - Processing AI model responses
 */

require_once __DIR__ . '/../vendor/autoload.php';

use DealNews\Inngest\Client\Inngest;
use DealNews\Inngest\Event\Event;
use DealNews\Inngest\Function\InngestFunction;
use DealNews\Inngest\Function\TriggerEvent;
use DealNews\Inngest\Http\ServeHandler;

// Create the Inngest client
$client = new Inngest('ai-app');

// Define a function that uses AI inference
$content_moderator_function = new InngestFunction(
    id: 'moderate-content',
    handler: function ($ctx) {
        $step = $ctx->getStep();
        $event = $ctx->getEvent();
        $content = $event->getData()['content'] ?? '';
        $content_id = $event->getData()['content_id'] ?? uniqid();

        error_log("Moderating content: {$content_id}");

        // Step 1: Use AI to analyze content moderation
        $moderation_response = $step->ai()->infer(
            id: 'ai-moderate-content',
            url: 'https://api.openai.com/v1/chat/completions',
            body: [
                'model' => 'gpt-4o-mini',
                'messages' => [
                    [
                        'role' => 'system',
                        'content' => 'You are a content moderation system. Analyze the provided content and respond with a JSON object containing: {"is_safe": boolean, "categories": array of violation categories, "confidence": number 0-1}',
                    ],
                    [
                        'role' => 'user',
                        'content' => "Analyze this content for policy violations:\n\n{$content}",
                    ],
                ],
            ],
            headers: [
                // NOTE: In production, use environment variables for API keys
                // 'Authorization' => 'Bearer ' . getenv('OPENAI_API_KEY'),
            ],
            format: 'json'
        );

        error_log("  AI moderation complete");

        // Step 2: Process the moderation result
        $decision = $step->run('make-decision', function () use ($moderation_response, $content_id) {
            error_log("  Making moderation decision for: {$content_id}");

            // Parse the AI response
            $is_safe = true;
            $violation_categories = [];

            if (is_array($moderation_response) && isset($moderation_response['choices'])) {
                // Extract parsed JSON from AI response
                // In a real scenario, you'd parse this properly
                $is_safe = true;
                $violation_categories = [];
            }

            return [
                'content_id' => $content_id,
                'is_safe' => $is_safe,
                'violation_categories' => $violation_categories,
                'decided_at' => date('c'),
            ];
        });

        // Step 3: Send notification if content needs review
        if (!$decision['is_safe']) {
            $step->sendEvent('notify-moderation-team', new Event(
                name: 'content/needs-review',
                data: [
                    'content_id' => $content_id,
                    'violations' => $decision['violation_categories'],
                    'severity' => 'high',
                ]
            ));
        }

        return [
            'content_id' => $content_id,
            'moderation_decision' => $decision,
            'moderated_at' => date('c'),
        ];
    },
    triggers: [new TriggerEvent('content/submit')],
    retries: 3
);

// Register the function
$client->registerFunction($content_moderator_function);

// Create the serve handler
$handler = new ServeHandler($client, '/api/inngest');

// Example: Handle HTTP request
// In a real application, this would be integrated with your framework
if (php_sapi_name() === 'cli') {
    echo "=== Inngest AI Step Example ===\n\n";

    // Simulate sending an event
    echo "Sending content/submit event...\n";
    try {
        $result = $client->send(new Event(
            name: 'content/submit',
            data: [
                'content_id' => 'CNT-12345',
                'content' => 'This is a user-submitted comment that needs moderation.',
            ]
        ));
        echo "Event sent successfully!\n";
    } catch (Exception $e) {
        echo "Error sending event: {$e->getMessage()}\n";
        echo "(This is expected if INNGEST_EVENT_KEY is not set)\n";
    }

    echo "\n=== To test this example manually ===\n";
    echo "1. Set environment variables:\n";
    echo "   export INNGEST_EVENT_KEY=your-event-key\n";
    echo "   export INNGEST_SIGNING_KEY=your-signing-key\n";
    echo "   export INNGEST_DEV=1\n";
    echo "   export OPENAI_API_KEY=your-openai-api-key\n\n";
    echo "2. Start the Inngest dev server:\n";
    echo "   npx inngest-cli@latest dev\n\n";
    echo "3. Run this example in a web server\n\n";
    echo "4. Send a content/submit event with text content\n";
    echo "5. The function calls the OpenAI API using step.ai()->infer()\n";
    echo "6. AI responses are automatically retried if the request fails\n";
    echo "7. The AI response is available to subsequent steps for processing\n";
    echo "8. This pattern works with any OpenAI-compatible API endpoint\n";
} else {
    // Handle actual HTTP request
    $response = $handler->handle(
        method: $_SERVER['REQUEST_METHOD'],
        path: $_SERVER['REQUEST_URI'],
        headers: getallheaders() ?: [],
        body: file_get_contents('php://input') ?: '',
        query: $_GET
    );
    http_response_code($response['status']);
    foreach ($response['headers'] as $key => $value) {
        header("{$key}: {$value}");
    }
    echo $response['body'];
}
