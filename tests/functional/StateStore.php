<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\functional;

/**
 * Append-only JSON log shared by the test app and the functional tests
 *
 * The JS integration tests keep run state in memory because the test and
 * the app share a process. Here the app runs under `php -S` in another
 * container (several workers, one process per request), so functions write
 * what they saw to files in a shared Docker volume and the tests read them.
 *
 * Usage:
 *
 * ```php
 * $store = StateStore::fromEnv();
 * $store->append($ctx->run_id, ['step' => 'a', 'attempt' => $ctx->attempt]);
 * $entries = $store->read($run_id);
 * ```
 *
 * @package DealNews\Inngest\Tests\functional
 */
class StateStore {

    /**
     * Env var holding the state directory
     *
     * @var string
     */
    public const ENV_DIR = 'FUNCTIONAL_STATE_DIR';

    /**
     * Creates a store
     *
     * @param string $dir Directory for the log files
     */
    public function __construct(protected string $dir) {
    }

    /**
     * Creates a store in the directory named by the env var
     *
     * @return self
     */
    public static function fromEnv(): self {
        $dir = getenv(self::ENV_DIR);
        if ($dir === false || $dir === '') {
            $dir = sys_get_temp_dir() . '/inngest-functional';
        }

        return new self($dir);
    }

    /**
     * Appends one entry to a key's log
     *
     * @param string $key   Log name (usually a run ID)
     * @param mixed  $value JSON-encodable value
     *
     * @return void
     */
    public function append(string $key, mixed $value): void {
        if (!is_dir($this->dir)) {
            @mkdir($this->dir, 0777, true);
        }

        file_put_contents(
            $this->path($key),
            json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR
            ) . "\n",
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * Returns every entry in a key's log, oldest first
     *
     * @param string $key Log name
     *
     * @return list<mixed>
     */
    public function read(string $key): array {
        $entries = [];

        $path = $this->path($key);
        if (is_file($path)) {
            $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach ($lines ?: [] as $line) {
                $entries[] = json_decode(
                    $line,
                    true,
                    512,
                    JSON_THROW_ON_ERROR
                );
            }
        }

        return $entries;
    }

    /**
     * Returns the file for a key
     *
     * @param string $key Log name
     *
     * @return string
     */
    protected function path(string $key): string {
        return $this->dir . '/' .
               preg_replace('/[^A-Za-z0-9_.-]/', '_', $key) . '.jsonl';
    }
}
