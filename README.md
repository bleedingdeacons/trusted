# Trusted

A 7-day telephone shift **rota manager** for WordPress.

**Version:** 1.7.2
**Requires:** WordPress 6.0+ · PHP 8.1+
**License:** MIT (Modified)

---

- Build reusable **weekly shift templates** (ACF custom post type, free fields only).
- **Apply** a template to any week to generate the rota.
- A **calendar view** in the admin for editing slots and assigning members.
- **Assign one member to each slot** via a dropdown. The same member can cover
  different shifts on different days; each slot just holds a single person.
- Rota and assignment data lives in **custom database tables** behind an
  interface / factory / repository layer — *not* a custom post type — so week
  reads are a single indexed range scan rather than slow meta joins.
- **Built on [Unity](https://github.com/thebleedingdeacons/unity).** Trusted
  registers its services into Unity's dependency container (the same pattern as
  Amber) and sources members directly from Unity's `MemberRepository`, filtered
  to telephone responders.
- No shortcodes, no front-end output, no bulletins. Admin-only.

## Requirements

- WordPress 6.0+
- PHP 8.1+
- **Unity plugin — required.** Trusted boots on Unity's `unity/loaded` hook and
  uses Unity's `MemberRepository`. If Unity is not active, Trusted shows an admin
  notice and does nothing.
- Advanced Custom Fields (free) — required for editing **templates**. Manual
  slot editing and assignment work without it.

## Install

1. Install and activate **Unity** first.
2. Copy the `trusted` folder into `wp-content/plugins/`.
3. (Optional, for the Composer autoloader) run `composer install` inside the
   folder. Without it, a bundled PSR-4 fallback autoloader is used. `psr/container`
   is provided by Unity at runtime either way.
4. Activate **Trusted** in *Plugins*. This creates two tables:
   `{prefix}trusted_rota` and `{prefix}trusted_assignments`.

## Usage

1. **Trusted → Shift Templates → Add Template.** Give it a title and fill in the
   seven day fields. One shift per line:

   ```
   08:00-12:00 | Morning desk
   12:00-16:00 | Afternoon desk
   16:00-20:00 | Evening on-call
   ```

   Give every shift a **name** after the `|`. If you leave it off, the time
   range is used as the name. Each day can hold as many shifts as you like.
2. **Trusted → Rota Calendar.** Pick a week, choose a template, and click
   *Apply template*. Applying is **non-destructive**: shifts that already exist
   on a date/time are left untouched (their names and assignments are kept), and
   only new shifts are added — so re-applying never overwrites or duplicates your
   existing shifts. Tick *Replace* only when you want to wipe the week first.
3. On any slot, pick a member from the **dropdown** and click **Assign**. Each
   shift holds one member, shown with their name, telephone and email. To swap
   who's on a shift, remove the current person (×) and assign someone else. The
   same member can be assigned to other shifts and other days. Add ad-hoc slots
   per day with **+ Add shift**.

### Developer Tools

**Trusted → Developer** is a maintenance page for admins, with two destructive
tools:

- **Delete a week** — pick any date and remove every shift (and its assignments)
  for that whole Monday–Sunday week.
- **Clear everything** — empty the entire rota: all shifts and all assignments
  across every week. Requires typing `DELETE` to confirm. Shift *templates* are
  left untouched.

Both are nonce-protected, ask for confirmation, and cannot be undone. Hide the
page in production by returning `false` from the `trusted_developer_tools`
filter.

## Why a textarea per day (and not a repeater)?

ACF's **Repeater** and **Flexible Content** fields are *Pro only*. To allow an
arbitrary number of shifts per day using **only free fields**, each weekday is a
plain **Textarea**, one shift per line, parsed by `TemplateApplicator`. This is
the cleanest free-ACF approach to "multiple shifts in a day".

## Architecture

```
src/
  Core/           TrustedServiceProvider  (registers services into Unity's container)
  Domain/         Rota, Assignment, Member, Shift  (entities & value objects)
  Contracts/      RotaRepositoryInterface, AssignmentRepositoryInterface, *FactoryInterface
  Factory/        RotaFactory, AssignmentFactory
  Repository/     RotaRepository, AssignmentRepository   (wpdb / custom tables)
  Template/       TemplatePostType, TemplateFields (ACF), TemplateApplicator
  Http/           RestController   (trusted/v1 REST namespace)
  Admin/          CalendarPage, DeveloperPage, Assets
  Support/        Database (tables + dbDelta), MemberPresenter (Unity → Trusted Member)
  Plugin.php      Boots on unity/loaded; wires WordPress hooks
```

- **Rota** = one shift slot on one date. A week's rota is the set of rows in that
  date range. **Assignment** links a member (by Unity member id) to a slot.
- Repositories are typed against interfaces and hydrate entities through the
  factories. They are resolved lazily from Unity's container at request time —
  after Unity has finished loading.

### Service registration (Amber-style) & member source

Trusted does not own a container. Like `AmberServiceProvider`, it registers its
services **into Unity's container**:

1. On `unity/register_services`, `TrustedServiceProvider::register()` binds
   Trusted's repositories, factories, applicator and REST controller. Their
   factory closures resolve dependencies — including Unity's
   `Unity\Members\Interfaces\MemberRepository` — from the same container.
2. On `unity/loaded`, `Plugin::boot()` wires the WordPress hooks (CPT, admin
   menu, assets, REST routes).

There is **no Trusted member factory** any more. Members come straight from
Unity's `MemberRepository`:

- `RestController::getMembers()` calls `findAll()` and keeps only members where
  `isTelephoneResponder()` is true, mapping each via `MemberPresenter`
  (`getAnonymousName → name`, `getPersonalEmail → email`, `getMobileNumber →
  telephone`), sorted by name.
- `AssignmentRepository` resolves the member for each assignment with
  `findById()`. This lookup is **not** responder-filtered, so historical
  assignments still display members who are no longer responders.

### Extension points (filters)

| Filter | Purpose | Default |
| --- | --- | --- |
| `trusted_capability` | Capability required to use Trusted & the REST API | `manage_options` |
| `trusted_developer_tools` | Show the **Developer** admin page (week bulk-delete) | `true` |

The `Member` value object exposes `name`, `email` and `telephone`. To change the
member source, register a different `Unity\Members\Interfaces\MemberRepository`
in Unity's container — Trusted consumes whatever Unity provides.

## REST API (`/wp-json/trusted/v1`)

All routes require the Trusted capability.

| Method | Route | Body |
| --- | --- | --- |
| GET | `/week/{YYYY-MM-DD}` | — |
| POST | `/rota` | `date,start,end,label` (all required) |
| PATCH | `/rota/{id}` | partial slot |
| DELETE | `/rota/{id}` | — |
| POST | `/assignment` | `rota_id`, `member_id` (one member per slot; `member_ids[]` accepted, first id used), `notes?` |
| DELETE | `/assignment/{id}` | — |
| GET | `/members?search=` | — |
| GET | `/templates` | — |
| POST | `/apply-template` | `template_id,week_start,replace?` |
