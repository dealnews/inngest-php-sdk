<?php

declare(strict_types=1);

namespace DealNews\Inngest\Event;

/**
 * Represents an Inngest event
 * 
 * Events are standardized JSON payloads that represent a single event within
 * an Inngest Server. They can trigger one or more functions and can be sent
 * from server or client code.
 */
class Event
{
    /**
     * @param string $name A unique ID for the type of event (e.g., "user/created")
     * @param array<string, mixed> $data Any data pertinent to the event
     * @param string|null $id A unique ID used to idempotently process the event
     * @param array<string, mixed>|null $user User data associated with the event
     * @param int|null $ts Unix timestamp in milliseconds when the event occurred
     */
    public function __construct(
        protected string $name,
        protected array $data = [],
        protected ?string $id = null,
        protected ?array $user = null,
        protected ?int $ts = null
    ) {
        if ($this->ts === null) {
            $this->ts = (int) (microtime(true) * 1000);
        }
        
        if ($this->id === null) {
            $this->id = $this->generateId();
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'id' => $this->id,
            'name' => $this->name,
            'data' => $this->data,
            'ts' => $this->ts,
        ];

        if ($this->user !== null) {
            $payload['user'] = $this->user;
        }

        return $payload;
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return array<string, mixed>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function getUser(): ?array
    {
        return $this->user;
    }

    public function getTs(): int
    {
        return $this->ts;
    }

    protected function generateId(): string
    {
        return sprintf(
            '%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0x0fff) | 0x4000,
            mt_rand(0, 0x3fff) | 0x8000,
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff),
            mt_rand(0, 0xffff)
        );
    }
}
