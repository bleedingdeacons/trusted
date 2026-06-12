=== Trusted ===
Contributors: thebleedingdeacons
Tags: rota, shifts, telephony, responders, scheduling
Requires at least: 6.0
Tested up to: 6.9
Stable tag: 1.6.2
Build date: 2026/06/12 22:01:53
Requires PHP: 8.1
License: MIT (Modified)

A 7-day telephone shift rota manager built on the Unity plugin.

== Description ==

Trusted is a 7-day telephone shift rota manager built on the Unity plugin. Build reusable weekly shift templates, apply them to any week to generate the rota, and assign Unity telephone responders from a calendar view in the admin.

Rota and assignment data lives in custom database tables behind an interface / factory / repository layer registered in Unity's container — not a custom post type — so week reads are a single indexed range scan rather than slow meta joins.

**Key features:**

* Reusable weekly shift templates (ACF custom post type, free fields only).
* Non-destructive "apply template to a week" to generate the rota.
* Calendar view in the admin for editing slots and assigning members.
* One member per slot, sourced directly from Unity's telephone responders.
* Custom database tables (`{prefix}trusted_rota`, `{prefix}trusted_assignments`).
* Admin-only — no shortcodes, no front-end output.

== Requirements ==

* WordPress 6.0+
* PHP 8.1+
* Unity plugin — required. Trusted boots on Unity's `unity/loaded` hook and uses Unity's `MemberRepository`.
* Advanced Custom Fields (free) — required for editing templates. Manual slot editing and assignment work without it.

== Changelog ==

= 1.3.1 =
* Current release.
