<?php

declare(strict_types=1);

namespace Trusted\Http;

use Trusted\Service\ShiftSignup;
use Unity\Members\Interfaces\Member as UnityMember;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * Member-facing sign-up endpoints under `trusted/v1`.
 *
 * Unlike RestController (admin, capability-gated), these are for an authenticated
 * telephone responder arriving from a sibling plugin. The acting member is
 * resolved ONLY through the `trusted_signup_member` filter, which the sibling
 * plugin implements after its own OAuth + Unity responder check — the request
 * never carries the member's identity, so it can't be spoofed.
 *
 * The surface is read + assign only: a member can list a day's shifts and attach
 * themselves to open ones. There is no way to create, edit or delete shifts.
 */
final class SignupController
{
    public const NAMESPACE = 'trusted/v1';

    public function __construct(private ShiftSignup $signup)
    {
    }

    public function registerRoutes(): void
    {
        register_rest_route(self::NAMESPACE, '/signup/shifts/(?P<date>\d{4}-\d{2}-\d{2})', [
            'methods'             => WP_REST_Server::READABLE,
            'callback'            => [$this, 'shifts'],
            'permission_callback' => [$this, 'can'],
            'args'                => [
                'date' => ['validate_callback' => [$this, 'isDate']],
            ],
        ]);

        register_rest_route(self::NAMESPACE, '/signup', [
            'methods'             => WP_REST_Server::CREATABLE,
            'callback'            => [$this, 'signUp'],
            'permission_callback' => [$this, 'can'],
        ]);
    }

    /**
     * Allow only when the request resolves to an authenticated responder.
     */
    public function can(): bool
    {
        return $this->actingMember() !== null;
    }

    public function shifts(WP_REST_Request $request): WP_REST_Response
    {
        return new WP_REST_Response($this->signup->openShiftsForDate((string) $request['date']));
    }

    public function signUp(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $member = $this->actingMember();

        if ($member === null) {
            return new WP_Error('trusted_unauthorised', __('Not signed in as a telephone responder.', 'trusted'), ['status' => 401]);
        }

        $rotaIds = $this->rotaIdsFromRequest($request);

        if ($rotaIds === []) {
            return new WP_Error('trusted_invalid', __('Select at least one shift.', 'trusted'), ['status' => 400]);
        }

        try {
            $result = $this->signup->assignResponder($member, $rotaIds);
        } catch (\InvalidArgumentException $e) {
            return new WP_Error('trusted_forbidden', __('You are not a telephone responder.', 'trusted'), ['status' => 403]);
        }

        return new WP_REST_Response($result, 201);
    }

    /**
     * The authenticated responder for this request, supplied by a sibling plugin
     * via the `trusted_signup_member` filter. Null when none is resolved.
     */
    private function actingMember(): ?UnityMember
    {
        /**
         * Filters the Unity member acting on the sign-up endpoints.
         *
         * Sibling plugins return their OAuth-verified telephone responder here;
         * the default null means "no one is signed in". The returned member is
         * re-checked as a telephone responder before any access is granted.
         *
         * @param UnityMember|null $member
         */
        $member = apply_filters('trusted_signup_member', null);

        return ($member instanceof UnityMember && $member->isTelephoneResponder()) ? $member : null;
    }

    public function isDate(mixed $value): bool
    {
        return is_string($value) && (bool) \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
    }

    /**
     * Normalise the posted shift ids into a unique list of positive ints.
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
                $clean[$id] = $id;
            }
        }

        return array_values($clean);
    }
}
