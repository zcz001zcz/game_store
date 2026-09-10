<?php

declare(strict_types=1);

namespace GameStore\Domain\Provider;

use RuntimeException;

class RetryableDeliveryException extends RuntimeException
{
}
