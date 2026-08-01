<?php

declare(strict_types=1);

namespace App\Core\WebAdmin\Outbox;

use App\Core\WebAdmin\Mail\WebAdminMailTransportInterface;
use App\Core\WebAdmin\Security\ExceptionTraceGuard;
use App\Core\WebAdmin\Support\ClockInterface;
use InvalidArgumentException;
use Throwable;

final class WebAdminOutboxDispatcher
{
    public function __construct(
        private readonly WebAdminOutboxRepository $repository,
        private readonly WebAdminOutboxMessageFactoryInterface $messages,
        private readonly WebAdminMailTransportInterface $transport,
        private readonly ClockInterface $clock
    ) {
    }

    public function dispatchBatch(int $limit): WebAdminOutboxDispatchReport
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException(
                'The WebAdmin outbox batch limit must be between 1 and 100.'
            );
        }
        // PHP 8.1 lacks #[SensitiveParameter]. Fail closed before any raw
        // action token can be passed through factory/transport call frames.
        ExceptionTraceGuard::assertEnabled();

        $examined = 0;
        $claimed = 0;
        $sent = 0;
        $retryScheduled = 0;
        $permanentlyFailed = 0;
        $fenced = 0;

        while ($examined < $limit) {
            $candidate = $this->repository->claimNext($this->clock->now());
            if ($candidate->isNone()) {
                break;
            }
            $examined++;
            if ($candidate->isTerminalFailure()) {
                $permanentlyFailed++;
                continue;
            }

            $lease = $candidate->lease();
            $claimed++;
            try {
                $message = $this->messages->create(
                    $lease->kind(),
                    $lease->recipientEmail(),
                    $lease->locale(),
                    $lease->revealActionToken()
                );
            } catch (Throwable) {
                $this->countFailure(
                    $this->repository->recordFailure(
                        $lease,
                        $this->clock->now(),
                        'outbox.message_invalid'
                    ),
                    $retryScheduled,
                    $permanentlyFailed,
                    $fenced
                );
                continue;
            }

            try {
                // The repository has committed the claim before this call.
                $this->transport->send($message);
            } catch (Throwable) {
                $this->countFailure(
                    $this->repository->recordFailure(
                        $lease,
                        $this->clock->now(),
                        'outbox.delivery_failed'
                    ),
                    $retryScheduled,
                    $permanentlyFailed,
                    $fenced
                );
                unset($message);
                continue;
            }

            if ($this->repository->acknowledge($lease, $this->clock->now())) {
                $sent++;
            } else {
                $fenced++;
            }
            unset($message);
        }

        return new WebAdminOutboxDispatchReport(
            $examined,
            $claimed,
            $sent,
            $retryScheduled,
            $permanentlyFailed,
            $fenced
        );
    }

    private function countFailure(
        string $result,
        int &$retryScheduled,
        int &$permanentlyFailed,
        int &$fenced
    ): void {
        if ($result === WebAdminOutboxRepository::FAILURE_RETRY_SCHEDULED) {
            $retryScheduled++;
        } elseif ($result === WebAdminOutboxRepository::FAILURE_PERMANENT) {
            $permanentlyFailed++;
        } else {
            $fenced++;
        }
    }
}
