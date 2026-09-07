<?php

declare(strict_types=1);

namespace GameStore\Domain\Provider;

use RuntimeException;

final class RetryableDeliveryException extends RuntimeException
{
}
