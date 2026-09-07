<?php

declare(strict_types=1);

namespace GameStore\Core\Database;

use GameStore\Support\Env;
use PDO;

final class ConnectionFactory
{
	public static function create(): PDO
	{
		return new PDO(
			Env::string('DATABASE_DSN', 'pgsql:host=db;port=5432;dbname=game_store'),
			Env::string('DATABASE_USER', 'game_store'),
			Env::string('DATABASE_PASSWORD', 'game_store'),
			[
				PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
				PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
				PDO::ATTR_EMULATE_PREPARES => false,
				PDO::ATTR_STRINGIFY_FETCHES => false,
			],
		);
	}
}
