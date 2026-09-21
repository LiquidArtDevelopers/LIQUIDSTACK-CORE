<?php

declare(strict_types=1);

namespace App\Core\Commerce\Outbox;

use App\Core\WebAdmin\Mail\WebAdminMailTransportInterface;
use App\Core\WebAdmin\Security\ExceptionTraceGuard;
use App\Core\WebAdmin\Support\ClockInterface;
use InvalidArgumentException;
use Throwable;

final class CommerceInquiryOutboxDispatcher
{
    public function __construct(
        private readonly CommerceInquiryOutboxRepository $repository,
        private readonly CommerceInquiryMailMessageFactoryInterface $messages,
        private readonly WebAdminMailTransportInterface $transport,
        private readonly ClockInterface $clock
    ) {
    }

    public function dispatchBatch(int $limit): CommerceOutboxDispatchReport
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('The Commerce outbox batch limit must be between 1 and 100.');
        }
        ExceptionTraceGuard::assertEnabled();
        $examined = $claimed = $sent = $retry = $permanent = $fenced = 0;
        while ($examined < $limit) {
            $candidate = $this->repository->claimNext($this->clock->now());
            if ($candidate->isNone()) {
                break;
            }
            $examined++;
            if ($candidate->isTerminalFailure()) {
                $permanent++;
                continue;
            }
            $lease = $candidate->lease();
            $claimed++;
            try {
                $message = $this->messages->create($lease);
                $this->transport->send($message);
            } catch (Throwable) {
                $this->countFailure(
                    $this->repository->recordFailure($lease, $this->clock->now()),
                    $retry,
                    $permanent,
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

        return new CommerceOutboxDispatchReport(
            $examined,
            $claimed,
            $sent,
            $retry,
            $permanent,
            $fenced
        );
    }

    private function countFailure(string $result, int &$retry, int &$permanent, int &$fenced): void
    {
        if ($result === CommerceInquiryOutboxRepository::FAILURE_RETRY_SCHEDULED) {
            $retry++;
        } elseif ($result === CommerceInquiryOutboxRepository::FAILURE_PERMANENT) {
            $permanent++;
        } else {
            $fenced++;
        }
    }
}
