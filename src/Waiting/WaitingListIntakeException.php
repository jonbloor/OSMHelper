<?php
declare(strict_types=1);
namespace App\Waiting;
use RuntimeException;
use Throwable;
final class WaitingListIntakeException extends RuntimeException
{
    public function __construct(string $message, int $status = 400, ?Throwable $previous = null)
    {
        parent::__construct($message, $status, $previous);
    }

    public function status(): int
    {
        $code = (int) $this->getCode();
        return $code >= 400 && $code < 600 ? $code : 400;
    }
}
