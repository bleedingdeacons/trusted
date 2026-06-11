<?php

declare(strict_types=1);

namespace Trusted\Http;

use Trusted\Contracts\AssignmentFactoryInterface;
use Trusted\Contracts\AssignmentRepositoryInterface;
use Trusted\Contracts\RotaFactoryInterface;
use Trusted\Contracts\RotaRepositoryInterface;
use Trusted\Support\MemberPresenter;
use Trusted\Template\TemplateApplicator;
use Unity\Members\Interfaces\Member as UnityMember;
use Unity\Members\Interfaces\MemberRepository;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * All read/write traffic from the calendar goes through these endpoints under
 * the `trusted/v1` namespace. Every route requires the Trusted capability.
 */
final class RestController
{
    public const NAMESPACE = 'trusted/v1';

    public function __construct(
        private RotaRepositoryInterface $rota,
        private AssignmentRepositoryInterface $assignments,
        private MemberRepository $members,
        private TemplateApplicator $applicator,
        private RotaFactoryInterface $rotaFactory,
        private AssignmentFactoryInterface $assignmentFactory,
    ) {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/week/(?P<start>\d{4}-\d{2}-\d{2})', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getWeek'],
            'permission_callback' => [$this, 'can'],
            'args'                => [
                'start' => ['validate_callback' => [$this, 'isDate']],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/rota', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'createSlot'],
            'permission_callback' => [$this, 'can'],
        ]);

        register_rest_route(self::NAMESPACE, '/rota/(?P<id>\d+)', [
            [
                'methods'             => WP_REST_Server::EDITABLE,
                'callback'            => [$this, 'updateSlot'],
                'permission_callback' => [$this, 'can'],
            ],
            [
                'methods'             => WP_REST_Server::DELETABLE,
                'callback'            => [$this, 'deleteSlot'],
                'permission_callback' => [$this, 'can'],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/assignment', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'createAssignment'],
            'permission_callback' => [$this, 'can'],
        ]);

        register_rest_route(self::NAMESPACE, '/assignments', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'bulkAssign'],
            'permission_callback' => [$this, 'can'],
        ]);

        register_rest_route(self::NAMESPACE, '/assignment/(?P<id>\d+)', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'deleteAssignment'],
            'permission_callback' => [$this, 'can'],
        ]);

        register_rest_route(self::NAMESPACE, '/members', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getMembers'],
            'permission_callback' => [$this, 'can'],
        ]);

        register_rest_route(self::NAMESPACE, '/templates', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'getTemplates'],
            'permission_callback' => [$this, 'can'],
        ]);

        register_rest_route(self::NAMESPACE, '/apply-template', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'applyTemplate'],
            'permission_callback' => [$this, 'can'],
        ]);
    }

    // --- Permission / validation -------------------------------------------

    public function can(): bool
    {
        $capability = (string) apply_filters('trusted_capability', 'manage_options');

        return current_user_can($capability);
    }

    public function isDate(mixed $value): bool
    {
        return is_string($value)
            && (bool) \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    }

    // --- Endpoints ----------------------------------------------------------

    public function getWeek(WP_REST_Request $request): WP_REST_Response
    {
        $weekStart = $this->mondayOf((string) $request['start']);
        $slots     = $this->rota->findForWeek($weekStart);

        // Group slots into the seven days, always returning all 7.
        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $date        = $this->addDays($weekStart, $i);
            $days[$date] = [
                'date'    => $date,
                'weekday' => (int) (new \DateTimeImmutable($date))->format('N'),
                'slots'   => [],
            ];
        }

        foreach ($slots as $slot) {
            $date = $slot->slotDate();
            if (isset($days[$date])) {
                $days[$date]['slots'][] = $slot->toArray();
            }
        }

        return new WP_REST_Response([
            'week_start' => $weekStart,
            'days'       => array_values($days),
        ]);
    }

    public function createSlot(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $date  = $this->sanitiseDate($request->get_param('date'));
        $start = $this->sanitiseTime($request->get_param('start'));
        $end   = $this->sanitiseTime($request->get_param('end'));
        $label = sanitize_text_field((string) $request->get_param('label'));

        if ($date === null || $start === null || $end === null || $label === '') {
            return new WP_Error('trusted_invalid', __('A name, date, start time and end time are all required.', 'trusted'), ['status' => 400]);
        }

        $slot  = $this->rotaFactory->create($date, $start, $end, $label);
        $saved = $this->rota->save($slot);

        return new WP_REST_Response($saved->toArray(), 201);
    }

    public function updateSlot(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $id      = (int) $request['id'];
        $current = $this->rota->find($id);

        if ($current === null) {
            return new WP_Error('trusted_not_found', __('Slot not found.', 'trusted'), ['status' => 404]);
        }

        $date  = $this->sanitiseDate($request->get_param('date')) ?? $current->slotDate();
        $start = $this->sanitiseTime($request->get_param('start')) ?? $current->startTime();
        $end   = $this->sanitiseTime($request->get_param('end')) ?? $current->endTime();
        $label = $current->label();

        if ($request->get_param('label') !== null) {
            $label = sanitize_text_field((string) $request->get_param('label'));

            if ($label === '') {
                return new WP_Error('trusted_invalid', __('A shift name is required.', 'trusted'), ['status' => 400]);
            }
        }

        $updated = $this->rotaFactory
            ->create($date, $start, $end, $label, $current->templateId())
            ->withId($id);

        $this->rota->save($updated);

        return new WP_REST_Response($this->rota->find($id)?->toArray());
    }

    public function deleteSlot(WP_REST_Request $request): WP_REST_Response
    {
        $deleted = $this->rota->delete((int) $request['id']);

        return new WP_REST_Response(['deleted' => $deleted]);
    }

    /**
     * Assign a single member to a slot.
     *
     * A shift holds at most one member. If the slot already has someone, the
     * request is rejected (409) and the caller must remove the current assignee
     * before reassigning. Accepts either `member_id` or `member_ids` (the first
     * id is used) for backward compatibility.
     */
    public function createAssignment(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $rotaId    = (int) $request->get_param('rota_id');
        $notes     = sanitize_textarea_field((string) $request->get_param('notes'));
        $memberIds = $this->memberIdsFromRequest($request);

        if ($rotaId <= 0 || $memberIds === []) {
            return new WP_Error('trusted_invalid', __('rota_id and a member_id are required.', 'trusted'), ['status' => 400]);
        }

        if ($this->rota->find($rotaId) === null) {
            return new WP_Error('trusted_not_found', __('Rota slot not found.', 'trusted'), ['status' => 404]);
        }

        // One member per shift: a slot that already has an assignee is full.
        if ($this->assignments->findByRota($rotaId) !== []) {
            return new WP_Error(
                'trusted_slot_full',
                __('This shift already has a member assigned. Remove them first to reassign.', 'trusted'),
                ['status' => 409]
            );
        }

        $memberId = $memberIds[0];

        if (! ctype_digit($memberId) || $this->members->findById((int) $memberId) === null) {
            return new WP_Error('trusted_invalid', __('That member could not be found.', 'trusted'), ['status' => 400]);
        }

        $saved = $this->assignments->save($this->assignmentFactory->create($rotaId, $memberId, $notes));

        return new WP_REST_Response(['created' => [$saved->toArray()], 'skipped' => []], 201);
    }

    /**
     * Assign one member to many slots across the week in a single request.
     *
     * Backs the calendar's member-first bulk panel: the caller sends one
     * `member_id` and a `rota_ids` array. The "one member per shift" rule still
     * holds — any slot that is missing or already filled is skipped (not an
     * error) and reported back so the UI can tell the user what was left out.
     */
    public function bulkAssign(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $memberId = sanitize_text_field((string) $request->get_param('member_id'));
        $notes    = sanitize_textarea_field((string) $request->get_param('notes'));
        $rotaIds  = $this->rotaIdsFromRequest($request);

        if ($memberId === '' || $rotaIds === []) {
            return new WP_Error('trusted_invalid', __('A member and at least one shift are required.', 'trusted'), ['status' => 400]);
        }

        if (! ctype_digit($memberId) || $this->members->findById((int) $memberId) === null) {
            return new WP_Error('trusted_invalid', __('That member could not be found.', 'trusted'), ['status' => 400]);
        }

        $created = [];
        $skipped = [];

        foreach ($rotaIds as $rotaId) {
            if ($this->rota->find($rotaId) === null) {
                $skipped[] = ['rota_id' => $rotaId, 'reason' => 'not_found'];
                continue;
            }

            // One member per shift: a slot that already has an assignee is full.
            if ($this->assignments->findByRota($rotaId) !== []) {
                $skipped[] = ['rota_id' => $rotaId, 'reason' => 'full'];
                continue;
            }

            $saved     = $this->assignments->save($this->assignmentFactory->create($rotaId, $memberId, $notes));
            $created[] = $saved->toArray();
        }

        return new WP_REST_Response(['created' => $created, 'skipped' => $skipped], 201);
    }

    /**
     * Normalise rota (slot) ids from the request into a unique, positive list.
     *
     * @return int[]
     */
    private function rotaIdsFromRequest(WP_REST_Request $request): array
    {
        $ids = $request->get_param('rota_ids');

        if (! is_array($ids)) {
            return [];
        }

        $clean = [];
        foreach ($ids as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $clean[$id] = $id; // De-duplicate within the request.
            }
        }

        return array_values($clean);
    }

    /**
     * Normalise member ids from the request into a unique, sanitised list.
     *
     * @return string[]
     */
    private function memberIdsFromRequest(WP_REST_Request $request): array
    {
        $ids = $request->get_param('member_ids');

        if (! is_array($ids)) {
            $single = $request->get_param('member_id');
            $ids    = $single !== null && $single !== '' ? [$single] : [];
        }

        $clean = [];
        foreach ($ids as $id) {
            $id = sanitize_text_field((string) $id);
            if ($id !== '') {
                $clean[$id] = $id; // De-duplicate within the request.
            }
        }

        return array_values($clean);
    }

    public function deleteAssignment(WP_REST_Request $request): WP_REST_Response
    {
        $deleted = $this->assignments->delete((int) $request['id']);

        return new WP_REST_Response(['deleted' => $deleted]);
    }

    /**
     * The assignable member list: every Unity member flagged as a telephone
     * responder, adapted to Trusted's shape and optionally filtered by a search
     * term over name / email / telephone.
     */
    public function getMembers(WP_REST_Request $request): WP_REST_Response
    {
        $search = strtolower(trim(sanitize_text_field((string) $request->get_param('search'))));
        $out    = [];

        foreach ($this->members->findAll([]) as $unityMember) {
            if (! $unityMember instanceof UnityMember || ! $unityMember->isTelephoneResponder()) {
                continue;
            }

            $member = MemberPresenter::toMember($unityMember);

            if ($search !== '' && ! $this->memberMatches($member, $search)) {
                continue;
            }

            $out[] = $member->toArray();
        }

        usort($out, static fn (array $a, array $b): int => strcasecmp($a['name'], $b['name']));

        return new WP_REST_Response($out);
    }

    private function memberMatches(\Trusted\Domain\Member $member, string $term): bool
    {
        return str_contains(strtolower($member->name()), $term)
            || str_contains(strtolower($member->email()), $term)
            || str_contains(strtolower($member->telephone()), $term);
    }

    public function getTemplates(): WP_REST_Response
    {
        $options = $this->applicator->options();

        $out = [];
        foreach ($options as $id => $title) {
            $out[] = ['id' => $id, 'title' => $title];
        }

        return new WP_REST_Response($out);
    }

    public function applyTemplate(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $templateId = (int) $request->get_param('template_id');
        $weekStart  = $this->sanitiseDate($request->get_param('week_start'));
        $replace    = (bool) $request->get_param('replace');

        if ($templateId <= 0 || $weekStart === null) {
            return new WP_Error('trusted_invalid', __('template_id and week_start are required.', 'trusted'), ['status' => 400]);
        }

        $weekStart = $this->mondayOf($weekStart);
        $created   = $this->applicator->apply($templateId, $weekStart, $replace);

        return new WP_REST_Response([
            'created'    => count($created),
            'week_start' => $weekStart,
        ], 201);
    }

    // --- Helpers ------------------------------------------------------------

    private function sanitiseDate(mixed $value): ?string
    {
        return $this->isDate($value) ? (string) $value : null;
    }

    private function sanitiseTime(mixed $value): ?string
    {
        if (! is_string($value) || ! preg_match('/^([01]?\d|2[0-3]):[0-5]\d$/', $value)) {
            return null;
        }

        [$h, $m] = explode(':', $value);

        return str_pad($h, 2, '0', STR_PAD_LEFT) . ':' . $m;
    }

    private function mondayOf(string $date): string
    {
        $dt  = new \DateTimeImmutable($date);
        $dow = (int) $dt->format('N'); // 1 (Mon) … 7 (Sun)

        return $dt->modify('-' . ($dow - 1) . ' days')->format('Y-m-d');
    }

    private function addDays(string $date, int $days): string
    {
        return (new \DateTimeImmutable($date))->modify("+{$days} days")->format('Y-m-d');
    }
}
