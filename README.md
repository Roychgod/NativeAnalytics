NativeAnalytics 1.0.19

# NativeAnalytics

Native first-party analytics module for ProcessWire CMS. It tracks traffic and engagement directly inside ProcessWire, without Google Analytics or external APIs.

## Features in v1.0.19

- Page views, unique visitors and sessions
- Current visitors based on active sessions
- Top pages, landing pages and exit pages
- Referrers, UTM campaigns, browser/device/OS breakdowns
- Internal search-term tracking via query parameters (`q`, `s`, `search` by default)
- 404 hit reporting for missing URLs
- Overview, Compare, Sources, Engagement and System tabs
- Compare mode for previous period and same period last year
- CSV, PDF and DOCX exports
- Event tracking for forms, downloads, contact links, outbound links and custom CTA events
- Tracking helper with copy-ready snippets and a mini snippet generator
- Per-page mini analytics box inside `ProcessPageEdit`
- Daily aggregate rebuild helpers and data cleanup tools
- Server-side pageview tracking with optional event JS tracking, bot filtering and optional consent cookie gate
- Cookie-less visitor/session storage mode for EU sites
- PrivacyWire localStorage consent helper

## Installation

1. Copy the `NativeAnalytics` folder to `/site/modules/`
2. In ProcessWire admin, go to **Modules > Refresh**
3. Install **NativeAnalytics**
4. Install **NativeAnalytics Dashboard**
5. Open **Admin > Analytics**
6. Configure the module under **Modules > Configure > NativeAnalytics**

## Upgrade notes

- Refreshing the module is usually enough after replacing the folder.
- New schema elements are created automatically on the next request.
- DOCX export requires PHP `ZipArchive` support.

## Current scope

This version already covers the core analytics needs for most ProcessWire sites:

- traffic overview
- period comparison
- source analysis
- engagement/event tracking
- exportable reports
- helper tools for custom tracked CTAs

## Optional future upgrades

- Conversion goals and funnels
- Scheduled email reports
- Alerts for traffic spikes or drops
- Page-level engagement score
- Multi-site analytics (per-site dashboards inside a multi-site ProcessWire install)

Enjoy — [Pyxios](https://www.pyxios.com)


## Upgrade note

This release is renamed to **NativeAnalytics** and is intended as a fresh install. Uninstall older PW Native Analytics versions before installing this one.

## 1.0.10 notes

- Added cookie-less visitor/session storage mode. In this mode NativeAnalytics does not set `pwna_vid` / `pwna_sid` cookies and the tracker does not create browser-storage visitor IDs. Unique visitor and session counts are approximate because they are derived server-side from a short-lived request fingerprint.
- Added PrivacyWire localStorage consent helper settings. When enabled together with “Require consent cookie”, NativeAnalytics can read the PrivacyWire localStorage consent object, set/unset the configured NativeAnalytics consent cookie, and track the current page once consent is granted.
- Added `window.PWNA.trackIfConsented()`, `window.PWNA.setConsent()`, `window.PWNA.clearConsent()` and `window.PWNA.syncPrivacyWireConsent()` helper methods for custom consent integrations.
- Event-tracking and other tracking-related module settings are hidden when global tracking is disabled.
- Admin dashboard CSS has been restored to the 1.0.8 look; no forced border-radius override is applied.

## 1.0.11 notes

- Removed border-radius across the dashboard UI for a flat, squared look (cards, panels, tabs, buttons, inputs, code blocks, tooltips, badges, charts).
- Active sub-tab and active WireTab now have a transparent bottom border, so the active tab visually merges with the panel below instead of showing a stray bottom line.
- Inline CSS fallback automatically picks up the new admin.css, no extra steps needed.

## 1.0.12 notes

- Hardened the active-tab bottom-border fix. The previous CSS only neutralised `border-bottom-color`, which still left a visible line in some admin themes (AdminThemeUikit, jQuery UI variants, anchors with `uk-active` / `aria-selected="true"`).
- New rules now also strip `border-bottom`, `box-shadow` and `outline` from the active `<li>` and its inner `<a>`, and explicitly cover all known active-state classes (`ui-tabs-active`, `ui-state-active`, `uk-active`, `on`, `aria-selected="true"`).
- The fallback `.pwna-tab.is-active` (pill nav rendered when JqueryWireTabs is unavailable) also loses its bottom edge when active.

## 1.0.13 notes

- WireTabs now use a deterministic, theme-agnostic style that looks identical across AdminThemeDefault, AdminThemeReno and AdminThemeUikit: visible top / left / right borders on every tab, light grey background on inactive tabs, white background on the active tab, and a transparent bottom border on the active tab so it merges with the panel below.
- Added a horizontal baseline (1px bottom border on the `<ul>` itself) and a `-1px` negative margin on each `<li>`, so the active tab cleanly cuts through that baseline — the classic "folder tab" look, but flat (no rounded corners).
- Compare tab: added `margin-top: 16px` to any `.pwna-panel` that directly follows another `.pwna-panel`. Previously the toolbar panel and the "Compare periods" panel sat flush against each other; now there is consistent spacing wherever two panels stack vertically.

## 1.0.14 notes

- Added a "Module settings" shortcut button on the right side of the brand header (with a cog icon). One-click access to **Modules → NativeAnalytics → Configure**, no more navigating through the modules list.
- The shortcut is shown only to users who can manage modules (superuser or the `module-admin` permission), so editor-only roles do not see it.

## 1.0.15 notes

- Fixed an overlap in AdminThemeDefault where the WireTabs strip could render over the brand header (NativeAnalytics title + version + Donate + Module settings shortcut).
- The brand panel now creates its own stacking context with `position: relative` and `z-index: 10`, so the tabs (`z-index: 1`) can never paint over it regardless of admin theme.
- Increased the brand panel `margin-bottom` from 12px to 20px, giving the WireTabs strip extra clearance below the brand header in every theme.

## 1.0.16 notes

- Definitive fix for the AdminThemeDefault tab/brand spacing issue. In 1.0.15 the WireTabs strip still rendered visually flush against the bottom edge of the brand panel because Default-theme CSS overrode the `<ul>` margin even with `!important`, and parent margin collapse ate the gap.
- New strategy: spacing is now carried by the `.pwna-wiretabs` wrapper `<div>` (`margin-top: 16px !important` + `padding-top: 8px !important` + `clear: both`), not by the inner `<ul>`. Padding cannot be collapsed or overridden by sibling rules, so the gap is guaranteed.
- Brand `margin-bottom` raised to 24px (with `!important`), so worst-case total clearance is at least 32px in every admin theme.

## 1.0.17 notes

- Aligned grid panels by their top edge. In `pwna-grid-2` and `pwna-grid-3`, side-by-side panels (e.g. Browsers / Devices / Operating systems on the System tab) now have their headers on the same horizontal line — previously panels with less content could appear vertically offset because the default `align-items: stretch` stretched them to equal heights and the inner content drifted.
- Added `align-items: start` on the grid and `align-self: start` on the panels, so each panel uses its natural height and starts at the top of its row.

## 1.0.18 notes

- Reverted the 1.0.17 approach. Side-by-side panels in `pwna-grid-2` / `pwna-grid-3` (Traffic trend / Traffic by hour, Top pages / Current visitors, Browsers / Devices / OS, etc.) now share equal heights again — `align-items: stretch` is back, so each row of panels has matching top and bottom edges.
- To prevent inner content from drifting to the middle of a stretched panel, grid children now use `display: flex; flex-direction: column; height: 100%` — headers and tables stay anchored to the top of the panel; the panel itself stretches to match its neighbour's height.

## 1.0.19 notes

- Fixed the real reason side-by-side panels looked vertically offset in 1.0.13–1.0.18. The `.pwna-panel + .pwna-panel { margin-top: 16px }` rule introduced in 1.0.13 (for the Compare tab) leaked into grid layouts too: every second panel inside `pwna-grid-2` / `pwna-grid-3` was getting an extra 16px top margin inside the stretched grid cell, pushing its content down so the headers no longer lined up.
- The sibling-margin rule is now scoped: it applies only to panels that are *not* direct children of `pwna-grid-2` / `pwna-grid-3`. Inside grids, the grid `gap` already provides horizontal spacing, so no extra vertical margin is needed.

