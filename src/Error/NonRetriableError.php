<?php

declare(strict_types=1);

namespace DealNews\Inngest\Error;

/**
 * Exception indicating that a function or step should not be retried
 */
class NonRetriableError extends InngestException
{
}
