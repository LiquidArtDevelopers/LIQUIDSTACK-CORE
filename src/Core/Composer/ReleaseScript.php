<?php

declare(strict_types=1);

namespace App\Core\Composer;

use Composer\IO\IOInterface;
use Composer\Script\Event;
use LiquidStack\Release\ReleaseCommand;

final class ReleaseScript
{
    public function __construct(private string $projectRoot)
    {
    }

    public static function run(Event $event): bool
    {
        return (new self(dirname(__DIR__, 3)))->handle($event);
    }

    public function handle(Event $event): bool
    {
        $io = $event->getIO();

        try {
            if (!class_exists(ReleaseCommand::class, false)) {
                require_once $this->projectRoot . '/tools/release.php';
            }

            $command = new ReleaseCommand(
                $this->projectRoot,
                static function (string $message, string $default) use ($io): string {
                    self::assertInteractive($io);
                    $answer = $io->ask($message, $default);

                    return is_string($answer) ? $answer : $default;
                },
                static function (string $message) use ($io): bool {
                    self::assertInteractive($io);

                    return $io->askConfirmation($message, true);
                },
                static function (string $message) use ($io): void {
                    $io->writeRaw($message);
                }
            );

            return $command->run($event->getArguments()) === 0;
        } catch (\Throwable $exception) {
            $message = htmlspecialchars(
                $exception->getMessage(),
                ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1,
                'UTF-8'
            );
            $io->writeError(sprintf('<error>ERROR: %s</error>', $message));

            return false;
        }
    }

    private static function assertInteractive(IOInterface $io): void
    {
        if ($io->isInteractive()) {
            return;
        }

        throw new \RuntimeException(
            'No hay una consola interactiva. En automatizaciones usa --version y --yes.'
        );
    }
}
