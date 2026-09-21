<?php

declare(strict_types=1);

namespace App\Core\Commerce\Admin;

use App\Core\Commerce\CommerceCapabilities;
use App\Core\Commerce\CommerceConflictException;
use App\Core\Commerce\CommerceInput;
use App\Core\Commerce\CommerceValidationException;
use App\Core\Commerce\Persistence\CommercePersistenceException;
use App\Core\Commerce\Persistence\CommerceTableNames;
use App\Core\WebAdmin\Authorization\WebAdminMutationActorGate;
use DateTimeImmutable;
use PDO;
use PDOStatement;
use Throwable;

final class CommerceAdminTaxonomyRepository
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly CommerceTableNames $tables,
        private readonly WebAdminMutationActorGate $actorGate
    ) {
    }

    public function setCategorySortOrder(
        string $categoryPublicId,
        int $sortOrder,
        int $expectedLockVersion,
        #[\SensitiveParameter] string $sessionToken,
        #[\SensitiveParameter] string $csrfToken,
        DateTimeImmutable $now
    ): bool {
        $categoryPublicId = CommerceInput::uuid($categoryPublicId);
        if ($sortOrder < 0 || $sortOrder > 10_000 || $expectedLockVersion < 1) {
            throw new CommerceValidationException('Invalid category order.');
        }
        if ($this->pdo->inTransaction()) {
            throw new CommercePersistenceException();
        }
        try {
            if (!$this->pdo->beginTransaction()) {
                throw new CommercePersistenceException();
            }
            if ($this->actorGate->authorize(
                $sessionToken,
                $csrfToken,
                CommerceCapabilities::TAXONOMIES_EDIT
            ) === null) {
                $this->pdo->rollBack();

                return false;
            }
            $statement = $this->pdo->prepare(
                'UPDATE ' . $this->tables->table('categories')
                . ' SET sort_order = :sort_order, lock_version = lock_version + 1, '
                . 'updated_at = :updated_at WHERE public_id = :public_id '
                . 'AND lock_version = :expected'
            );
            if (!$statement instanceof PDOStatement || !$statement->execute([
                'sort_order' => $sortOrder,
                'updated_at' => CommerceInput::formatUtc($now),
                'public_id' => $categoryPublicId,
                'expected' => $expectedLockVersion,
            ])) {
                throw new CommercePersistenceException();
            }
            if ($statement->rowCount() !== 1) {
                throw new CommerceConflictException(CommerceConflictException::STALE);
            }
            if (!$this->pdo->commit()) {
                throw new CommercePersistenceException();
            }

            return true;
        } catch (CommerceValidationException|CommerceConflictException $exception) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $exception;
        } catch (Throwable) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw new CommercePersistenceException();
        }
    }
}
