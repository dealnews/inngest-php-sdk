<?php

declare(strict_types=1);

namespace DealNews\Inngest\Error;

/**
 * Thrown into a function when a step it depends on failed
 *
 * When a step exhausts its retries, Inngest re-runs the function with the
 * step's serialized error. Calling that step then throws a `StepError`,
 * which the function can catch and handle. If it doesn't, the function
 * fails without further retries. Port of the JS SDK's `StepError`.
 *
 * Usage:
 *
 * ```php
 * try {
 *     $step->run('charge-card', fn () => $billing->charge($order));
 * } catch (StepError $e) {
 *     $step->run('notify-failure', fn () => $mailer->paymentFailed($order));
 * }
 * ```
 *
 * Edge cases:
 *
 * - Invalid error data becomes name `Error`, message `Unknown error`
 *   (JS `jsonErrorSchema` falls back the same way).
 * - A legacy `error` field is used as the message when `message` is
 *   missing.
 *
 * @package DealNews\Inngest\Error
 */
class StepError extends RemoteError {

    /**
     * Creates the error from the step's serialized error
     *
     * @param string $step_id The step's (unhashed) ID
     * @param mixed  $error   Serialized error data from Inngest
     */
    public function __construct(protected string $step_id, mixed $error) {
        $fields = static::normalize($error);

        $cause = null;
        if (isset($fields['cause'])) {
            $cause = (new ErrorSerializer())->deserializeCause(
                $fields['cause']
            );
        }

        parent::__construct(
            $fields['name'],
            $fields['message'],
            $fields['stack'],
            null,
            $cause instanceof \Throwable ? $cause : null,
            $cause instanceof \Throwable ? null : $cause
        );
    }

    /**
     * Returns the ID of the step that failed
     *
     * @return string
     */
    public function getStepId(): string {
        return $this->step_id;
    }

    /**
     * Normalizes error data like the JS `jsonErrorSchema`
     *
     * Any non-string name/message/error/stack invalidates the whole value,
     * which then falls back to defaults.
     *
     * @param mixed $error Serialized error data
     *
     * @return array<string, mixed> Keys: name (string), message (string),
     *                              stack (?string), cause (mixed)
     */
    public static function normalize(mixed $error): array {
        $data = is_array($error) ? $error : [];

        foreach (['name', 'error', 'message', 'stack'] as $key) {
            if (isset($data[$key]) && !is_string($data[$key])) {
                $data = [];
                break;
            }
        }

        $name    = trim($data['name'] ?? '');
        $message = trim($data['message'] ?? '');
        $legacy  = trim($data['error'] ?? '');
        $stack   = isset($data['stack']) ? trim($data['stack']) : null;

        if ($message === '') {
            $message = $legacy !== '' ? $legacy : 'Unknown error';
        }

        return [
            'name'    => $name !== '' ? $name : 'Error',
            'message' => $message,
            'stack'   => $stack,
            'cause'   => $data['cause'] ?? null,
        ];
    }
}
