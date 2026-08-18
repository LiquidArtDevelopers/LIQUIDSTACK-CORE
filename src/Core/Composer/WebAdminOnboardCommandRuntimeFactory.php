<?php

declare(strict_types=1);

namespace App\Core\Composer;

use Throwable;

final class WebAdminOnboardCommandRuntimeFactory implements
    WebAdminOnboardCommandRuntimeFactoryInterface
{
    private readonly WebAdminOnboardMailRuntimeFactoryInterface $mailRuntimeFactory;
    private readonly WebAdminBootstrapCommandRuntimeFactoryInterface $bootstrapRuntimeFactory;

    public function __construct(
        ?WebAdminOnboardMailRuntimeFactoryInterface $mailRuntimeFactory = null,
        ?WebAdminBootstrapCommandRuntimeFactoryInterface $bootstrapRuntimeFactory = null
    ) {
        if ($mailRuntimeFactory === null) {
            $defaultMailFactory = new WebAdminMailDispatchCommandRuntimeFactory();
            if (!$defaultMailFactory instanceof WebAdminOnboardMailRuntimeFactoryInterface) {
                throw new WebAdminOnboardCommandRuntimeException(
                    'webadmin.onboard.runtime_unavailable'
                );
            }
            $mailRuntimeFactory = $defaultMailFactory;
        }

        $this->mailRuntimeFactory = $mailRuntimeFactory;
        $this->bootstrapRuntimeFactory = $bootstrapRuntimeFactory
            ?? new WebAdminBootstrapCommandRuntimeFactory();
    }

    public function create(
        string $projectRoot,
        string $coreRoot
    ): WebAdminOnboardCommandRuntimeInterface {
        try {
            // Mail configuration, routing, schema and transport are resolved
            // first. No bootstrap mutation is attempted if this preflight
            // cannot construct a usable delivery runtime.
            $mailRuntime = $this->mailRuntimeFactory->createOnboard(
                $projectRoot,
                $coreRoot
            );
            $bootstrapRuntime = $this->bootstrapRuntimeFactory->create(
                $projectRoot,
                $coreRoot
            );

            return new WebAdminOnboardCommandRuntime(
                $bootstrapRuntime,
                $mailRuntime
            );
        } catch (WebAdminOnboardCommandRuntimeException $exception) {
            throw $exception;
        } catch (WebAdminMailDispatchCommandRuntimeException $exception) {
            throw new WebAdminOnboardCommandRuntimeException(
                $exception->issueCode()
            );
        } catch (WebAdminBootstrapCommandRuntimeException $exception) {
            throw new WebAdminOnboardCommandRuntimeException(
                $exception->issueCode()
            );
        } catch (Throwable) {
            throw new WebAdminOnboardCommandRuntimeException(
                'webadmin.onboard.runtime_unavailable'
            );
        }
    }
}
