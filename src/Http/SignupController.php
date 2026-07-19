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

        register_rest_route(self::NAMESPACE, '/signup/(?P<rota>\d+)', [
            'methods'             => WP_REST_Server::DELETABLE,
            'callback'            => [$this, 'removeSignUp'],
            'permission_callback' => [$this, 'can'],
            'args'                => [
                'rota' => ['validate_callback' => static fn ($value): bool => ctype_digit((string) $value)],
            ],
        ]);

        // These endpoints are per-member and authorised by Reach's session
        // cookie, which shared caches (SiteGround, Cloudflare, the browser)
        // don't recognise. WordPress only sends REST no-cache headers when the
        // request is from a logged-in WP user (`rest_send_nocache_headers`
        // defaults to is_user_logged_in()), so an anonymous responder's shift
        // list would otherwise be cached and served stale to the next visitor —
        // making a successful sign-up look like it didn't take. Force no-store
        // across the sign-up namespace so one member's view is never reused.
        add_filter('rest_post_dispatch', [$this, 'sendNoCacheHeaders'], 10, 3);
    }

    /**
     * Mark the member-facing sign-up responses as uncacheable. See the note in
     * registerRoutes() for why core's default REST headers aren't enough here.
     */
    public function sendNoCacheHeaders(
        WP_REST_Response $response,
        WP_REST_Server $server,
        WP_REST_Request $request
    ): WP_REST_Response {
        if (str_starts_with(ltrim((string) $request->get_route(), '/'), self::NAMESPACE . '/signup')) {
            $response->header('Cache-Control', 'no-cache, no-store, must-revalidate, max-age=0, private');
        }

        return $response;
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
        $member   = $this->actingMember();
        $memberId = $member !== null ? (string) $member->getId() : null;

        return new WP_REST_Response($this->signup->openShiftsForDate((string) $request['date'], $memberId));
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
     * Remove the acting responder's own sign-up from a shift.
     *
     * A member can only ever remove their own assignment — the service deletes
     * nothing when the shift is unassigned or held by someone else.
     */
    public function removeSignUp(WP_REST_Request $request): WP_REST_Response|WP_Error
    {
        $member = $this->actingMember();

        if ($member === null) {
            return new WP_Error('trusted_unauthorised', __('Not signed in as a telephone responder.', 'trusted'), ['status' => 401]);
        }

        try {
            $removed = $this->signup->removeResponder($member, (int) $request['rota']);
        } catch (\InvalidArgumentException $e) {
            return new WP_Error('trusted_forbidden', __('You are not a telephone responder.', 'trusted'), ['status' => 403]);
        }

        if (! $removed) {
            return new WP_Error('trusted_not_assigned', __('You are not signed up for that shift.', 'trusted'), ['status' => 404]);
        }

        return new WP_REST_Response(['removed' => true, 'rota_id' => (int) $request['rota']]);
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
        if (! is_string($value)) {
            return false;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        // createFromFormat() overflows silently rather than failing:
        // "2026-02-31" parses happily and becomes 2026-03-03, and "2026-13-01"
        // becomes 2027-01-01. Round-tripping the parsed date back through the
        // format is what rejects a date that does not exist.
        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
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
