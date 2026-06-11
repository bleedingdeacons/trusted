<?php

declare(strict_types=1);

namespace Trusted\Repository;

use Trusted\Contracts\AssignmentRepositoryInterface;
use Trusted\Contracts\RotaFactoryInterface;
use Trusted\Contracts\RotaRepositoryInterface;
use Trusted\Domain\Rota;
use Trusted\Support\Database;
use wpdb;

/**
 * Persists Rota slots in the custom {prefix}trusted_rota table.
 *
 * Using a dedicated table (rather than a custom post type) keeps week queries
 * to a single indexed range scan instead of the multi-join meta lookups a CPT
 * would require.
 */
final class RotaRepository implements RotaRepositoryInterface
{
    private wpdb $db;
    private string $table;

    public function __construct(
        private RotaFactoryInterface $factory,
        private AssignmentRepositoryInterface $assignments,
    ) {
        global $wpdb;

        $this->db    = $wpdb;
        $this->table = Database::rotaTable();
    }

    public function find(int $id): ?Rota
    {
        $row = $this->db->get_row(
            $this->db->prepare("SELECT * FROM {$this->table} WHERE id = %d", $id),
            ARRAY_A
        );

        if (! $row) {
            return null;
        }

        $rota = $this->factory->fromRow($row);

        return $rota->withAssignments($this->assignments->findByRota((int) $rota->id()));
    }

    public function findForWeek(string $weekStart): array
    {
        $weekEnd = $this->addDays($weekStart, 6);

        $rows = $this->db->get_results(
            $this->db->prepare(
                "SELECT * FROM {$this->table}
                 WHERE slot_date BETWEEN %s AND %s
                 ORDER BY slot_date ASC, start_time ASC, id ASC",
                $weekStart,
                $weekEnd
            ),
            ARRAY_A
        );

        if (! $rows) {
            return [];
        }

        $slots = array_map([$this->factory, 'fromRow'], $rows);

        // Eager-load every assignment for the week in one query.
        $ids       = array_map(static fn (Rota $r): int => (int) $r->id(), $slots);
        $byRota    = $this->assignments->findByRotaIds($ids);

        return array_map(
            static fn (Rota $r): Rota => $r->withAssignments($byRota[(int) $r->id()] ?? []),
            $slots
        );
    }

    public function save(Rota $rota): Rota
    {
        $data = [
            'slot_date'   => $rota->slotDate(),
            'start_time'  => $rota->startTime(),
            'end_time'    => $rota->endTime(),
            'label'       => $rota->label(),
            'template_id' => $rota->templateId(),
        ];

        $format = ['%s', '%s', '%s', '%s', '%d'];

        if ($rota->id() === null) {
            $this->db->insert($this->table, $data, $format);

            return $rota->withId((int) $this->db->insert_id)
                ->withAssignments($rota->assignments());
        }

        $data['updated_at'] = current_time('mysql');
        $format[]           = '%s';

        $this->db->update($this->table, $data, ['id' => $rota->id()], $format, ['%d']);

        return $rota;
    }

    public function delete(int $id): bool
    {
        $this->assignments->deleteByRota($id);

        return (bool) $this->db->delete($this->table, ['id' => $id], ['%d']);
    }

    public function deleteWeek(string $weekStart): int
    {
        $slots = $this->findForWeek($weekStart);

        foreach ($slots as $slot) {
            $this->delete((int) $slot->id());
        }

        return count($slots);
    }

    public function deleteAll(): int
    {
        // Clear assignments first, then every slot. Table name is built from
        // the trusted $wpdb prefix and a class constant, never user input.
        $this->assignments->deleteAll();

        $count = (int) $this->db->get_var("SELECT COUNT(*) FROM {$this->table}"); // phpcs:ignore
        $this->db->query("DELETE FROM {$this->table}"); // phpcs:ignore

        return $count;
    }

    private function addDays(string $date, int $days): string
    {
        $dt = \DateTimeImmutable::createFromFormat('!Y-m-d', $date) ?: new \DateTimeImmutable($date);

        return $dt->modify("+{$days} days")->format('Y-m-d');
    }
}
