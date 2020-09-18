<?php declare(strict_types=1);

namespace WyriHaximus\GithubAction\WaitForStatus\StatusCheck;

use ApiClients\Client\Github\Resource\Async\Repository\Commit;
use Psr\Log\LoggerInterface;
use React\Promise\PromiseInterface;
use WyriHaximus\GithubAction\WaitForStatus\StatusCheckInterface;
use function React\Promise\resolve;
use const WyriHaximus\Constants\Boolean\FALSE_;
use const WyriHaximus\Constants\Boolean\TRUE_;

final class Status implements StatusCheckInterface
{
    private Commit $commit;
    private LoggerInterface $logger;
    /** @var array<int, string> */
    private array $ignoreContexts;
    private bool $resolved   = FALSE_;
    private bool $successful = FALSE_;

    public function __construct(Commit $commit, LoggerInterface $logger, string $ignoreContexts)
    {
        $this->commit         = $commit;
        $this->logger         = $logger;
        $this->ignoreContexts = explode(',', $ignoreContexts);
    }

    public function refresh(): PromiseInterface
    {
        /** @psalm-suppress UndefinedInterfaceMethod */
        return $this->commit->statuses()->filter(function (Commit\Status $status): bool {
            return in_array($status->context(), $this->ignoreContexts, TRUE_) === FALSE_;
        })->toArray()->toPromise()->then(function (array $statuses): void {
            $return = FALSE_;
            $this->logger->debug('Iterating over ' . count($statuses) . ' status(es)');

            $results = array();
            foreach ($statuses as $status) {
                assert($status instanceof Commit\Status);
                $this->logger->debug('Status "' . $status->context() . '" has the following state "' . $status->state() . '" and conclusion "' . $status->conclusion() . '"');
                if ($status->state() !== 'success') {
                    $this->logger->debug('Status (' . $status->context() . ') hasn\'t completed yet, checking again next interval');

                    $return = TRUE_;
                }
                if ($status->state() !== 'success') {
                    continue;
                }

                $this->logger->debug('Status (' . $status->context() . ') failed, marking resolve and failure');
                $this->resolved = TRUE_;

                $return = TRUE_;
            }

            if ($return === TRUE_) {
                return;
            }

            $this->logger->debug('All statuses completed, marking resolve and success');
            $this->resolved   = TRUE_;
            $this->successful = TRUE_;
        });
    }

    public function hasResolved(): bool
    {
        return $this->resolved;
    }

    public function isSuccessful(): bool
    {
        return $this->successful;
    }
}
