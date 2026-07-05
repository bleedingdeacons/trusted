<?php

declare(strict_types=1);

namespace Trusted\Repository;

use Trusted\Contracts\AssignmentFactoryInterface;
use Trusted\Contracts\AssignmentRepositoryInterface;
use Trusted\Domain\Assignment;
use Trusted\Support\Database;
use Trusted\Support\MemberPresenter;
use Unity\Members\Interfaces\MemberRepository;
use wpdb;

/**
 * Persists Assignments in {prefix}trusted_assignments and decorates each one
 * with its Member, resolved from Unity's MemberRepository.
 */
final class AssignmentRepository implements AssignmentRepositoryInterface
{
    private wpdb $db;
    private string $table;

    public function __construct(
        private AssignmentFactoryInterface $factory,
        private MemberRepository $members,
    ) {
        global $wpdb;

        $this->db    = $wpdb;
        $this->table = Database::assignmentsTable();
    }

    public function find(int $id): ?Assignment
    {
        $row = $this->db->get_row(
            $this->db->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );

        return $row ? $this->hydrate($row) : null;
    }

    public function findByRota(int $rotaId): array
    {
        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->table} WHERE rota_id = %d ORDER BY id ASC",
                $rotaId
            ),
            ARRAY_A
        );

        return array_map([$this, 'hydrate'], $rows ?: []);
    }

    public function findByRotaIds(array $rotaIds): array
    {
        $rotaIds = array_values(array_unique(array_filter(array_map('intval', $rotaIds))));

        if ($rotaIds === []) {
            return [];
        }

        $placeholders = implode(', ', array_fill(0, count($rotaIds), '%d'));

        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->table} WHERE rota_id IN ({$placeholders}) ORDER BY id ASC",
                ...$rotaIds
            ),
            ARRAY_A
        );

        $grouped = [];

        foreach ($rows ?: [] as $row) {
            $assignment                          = $this->hydrate($row);
            $grouped[$assignment->rotaId()][]    = $assignment;
        }

        return $grouped;
    }

    public function save(Assignment $assignment): Assignment
    {
        $data   = [
            'rota_id'   => $assignment->rotaId(),
            'member_id' => $assignment->memberId(),
            'notes'     => $assignment->notes(),
        ];
        $format = ['%d', '%s', '%s'];

        if ($assignment->id() === null) {
            $this->db->insert($this->table, $data, $format);
            $saved = $assignment->withId((int) $this->db->insert_id);
        } else {
            $this->db->update($this->table, $data, ['id' => $assignment->id()], $format, ['%d']);
            $saved = $assignment;
        }

        return $saved->withMember($this->resolveMember($saved->memberId()));
    }

    public function assignIfOpen(int $rotaId, string $memberId, string $notes): ?Assignment
    {
        // INSERT IGNORE + the UNIQUE(rota_id) constraint make this atomic: a
        // second concurrent sign-up for the same slot is silently rejected
        // (0 rows affected) rather than racing past an application-level
        // emptiness check. This is the source of truth for "one per slot".
        $affected = $this->db->query(
            $this->db->prepare(
                "INSERT IGNORE INTO {$this->table} (rota_id, member_id, notes) VALUES (%d, %s, %s)",
                $rotaId,
                $memberId,
                $notes
            )
        );

        if (! is_int($affected) || $affected < 1 || (int) $this->db->insert_id < 1) {
            return null; // slot already taken (or the insert failed)
        }

        $assignment = $this->factory->create($rotaId, $memberId, $notes)
            ->withId((int) $this->db->insert_id);

        return $assignment->withMember($this->resolveMember($memberId));
    }

    public function delete(int $id): bool
    {
        return (bool) $this->db->delete($this->table, ['id' => $id], ['%d']);
    }

    public function deleteByRota(int $rotaId): bool
    {
        return false !== $this->db->delete($this->table, ['rota_id' => $rotaId], ['%d']);
    }

    public function deleteAll(): int
    {
        // Table name is built from the trusted $wpdb prefix and a class
        // constant, never from user input.
        $count = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table}"); // phpcs:ignore
        $this->db->query("DELETE FROM {$this->table}"); // phpcs:ignore

        return $count;
    }

    /**
     * @param array<string, mixed> $row
     */
    private function hydrate(array $row): Assignment
    {
        $assignment = $this->factory->fromRow($row);

        return $assignment->withMember($this->resolveMember($assignment->memberId()));
    }

    /**
     * Look the member up in Unity's repository and adapt it. Returns null when
     * the id is non-numeric or the member no longer exists.
     */
    private function resolveMember(string $memberId): ?\Trusted\Domain\Member
    {
        if (! ctype_digit($memberId)) {
            return null;
        }

        $unityMember = $this->members->findById((int) $memberId);

        return $unityMember !== null ? MemberPresenter::toMember($unityMember) : null;
    }
}
