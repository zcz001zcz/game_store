<?php

declare(strict_types=1);

namespace GameStore\Core\Http;

final class NotFoundException extends HttpException
{
	public function __construct(string $message = 'Resource not found')
	{
		parent::__construct(404, $message, 'not_found');
	}
}
