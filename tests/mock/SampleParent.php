<?php

declare(strict_types=1);

namespace DealNews\Inngest\Tests\mock;

use DealNews\Inngest\ValueObject\ValueObject;

/**
 * Value object exercising every ValueObject feature in tests
 *
 * @package DealNews\Inngest\Tests\mock
 */
class SampleParent extends ValueObject {

    protected const WIRE_KEYS = [
        'app_name' => 'appName',
    ];

    protected const KEEP_NULL = [
        'env',
    ];

    protected const OBJECT_KEYS = [
        'steps',
    ];

    protected const COLLECTION_TYPES = [
        'children' => SampleChild::class,
        'steps'    => SampleChild::class,
    ];

    /**
     * Static properties are never exported
     *
     * @var string
     */
    public static string $ignored = 'static';

    /**
     * Encoded as `appName`
     *
     * @var string
     */
    public string $app_name = '';

    /**
     * Omitted when null
     *
     * @var string|null
     */
    public ?string $version = null;

    /**
     * Encoded as `null` when null (listed in KEEP_NULL)
     *
     * @var string|null
     */
    public ?string $env = null;

    /**
     * Required, non-nullable value with a default
     *
     * @var int
     */
    public int $attempt = 0;

    /**
     * Backed enum
     *
     * @var SampleStatus|null
     */
    public ?SampleStatus $status = null;

    /**
     * Nested value object
     *
     * @var SampleChild|null
     */
    public ?SampleChild $child = null;

    /**
     * List of value objects
     *
     * @var list<SampleChild>
     */
    public array $children = [];

    /**
     * Map of value objects; must encode as `{}` when empty
     *
     * @var array<string, SampleChild>
     */
    public array $steps = [];

    /**
     * Untyped value passed through as-is
     *
     * @var mixed
     */
    public mixed $data = null;

    /**
     * Typed property with no default; omitted until set
     *
     * @var string
     */
    public string $no_default;

    /**
     * Protected state is never exported
     *
     * @var string
     */
    protected string $internal = 'hidden';
}
