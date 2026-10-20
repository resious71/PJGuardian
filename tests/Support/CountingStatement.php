<?php

declare(strict_types=1);

namespace PJGuardian\Tests\Support;

/**
 * 실행한 SQL 문 수 세기(X17 N+1 시험): $db->pdo()->setAttribute(PDO::ATTR_STATEMENT_CLASS, [CountingStatement::class, []]).
 */
final class CountingStatement extends \PDOStatement
{
    public static int $count = 0;

    protected function __construct()
    {
    }

    public function execute(?array $params = null): bool
    {
        self::$count++;
        return parent::execute($params);
    }
}
