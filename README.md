NativeAnalytics 1.0.24

# NativeAnalytics

Native first-party analytics module for ProcessWire CMS. It tracks traffic and engagement directly inside ProcessWire, without Google Analytics or external APIs.

## Features in v1.0.24

NativeAnalytics is a first-party analytics dashboard for ProcessWire. It keeps the tracking data inside your ProcessWire installation and does not rely on Google Analytics, external tracking scripts or remote analytics APIs.

Main features currently included:

- Page views, unique visitors, sessions and current visitors
- Top pages, landing pages, exit pages, referrers and UTM campaign reporting
- Browser, device and operating-system breakdowns
- Internal search-term tracking via configurable query parameters (`q`, `s`, `search` by default)
- Smarter 404 reporting with support for redirect/history modules, so renamed pages and redirected URLs do not stay listed as real 404s
- Improved bot and crawler filtering, including AI crawlers, SEO bots, social preview bots, uptime monitors and common HTTP libraries
- Optional Matomo `device-detector` support with automatic Composer detection and bundled fallback
- Suspicious probe detection for common scanner URLs, CMS/admin probes, `.env`, `.git`, config leaks, shell upload attempts and similar noise
- Optional custom URL/path filter for site-specific tracking exclusions
- Maintenance tools for data cleanup, including **Cleanup resolvable 404s** and **Cleanup suspicious probes**
- IP blocklist support and quick blocking from the Current visitors panel
- Overview, Engagement, Goals, Compare, Sources and System tabs
- Compare mode for previous period and same period last year
- Event tracking for forms, downloads, contact links, outbound links and custom CTA events
- Goal tracking with event-based and page/path-based goals
- Conversion rates based on sessions or unique visitors
- Event and goal daily aggregates for high-traffic retention workflows
- Tracking helper with copy-ready snippets and a mini snippet generator
- Per-page mini analytics box inside `ProcessPageEdit`
- CSV, PDF and DOCX exports
- Optional monthly email reports with configurable recipients, report sections and PDF attachment
- Server-side pageview tracking with optional event JS tracking, bot filtering and optional consent cookie gate
- Cookie-less visitor/session storage mode for EU/privacy-focused sites
- PrivacyWire localStorage consent helper
- Cleaner, grouped module settings with collapsible sections for tracking, filters, bot detection, privacy/consent, retention, reports and advanced options

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
- basic goal/conversion tracking

## Optional future upgrades

- Funnel reports across multiple goals
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


- Improved the compact page-level analytics box shown inside ProcessPageEdit.
- The page edit analytics summary now uses a small responsive card grid instead of plain stacked text.
- The admin CSS is explicitly loaded for the page edit analytics box, so the mini summary is styled correctly outside the main analytics dashboard.

## 1.0.20 notes

- Added optional monthly email reports.
- Reports are sent once per month for the previous calendar month via ProcessWire/WireMail.
- Module settings now include report recipients, send day of month, optional sender email and section toggles for top pages, referrers and engagement events.
- Added **Send test report now** for manually testing report delivery from module settings.
- Added **Report preview**, so admins can view the monthly report directly in the module settings before sending it.
- Test/preview reports use the previous calendar month when data exists, and automatically fall back to current month-to-date if the previous month has no data yet.
- Test reports are clearly marked as `[TEST]` and do not update the last sent month marker.

- Added optional PDF attachment for monthly reports, enabled by default.
- Test reports and scheduled reports can now include a clean PDF version of the same analytics summary.
- Report event targets now avoid full external URLs in the email body to prevent email clients from showing unrelated rich link previews, for example YouTube previews.
- Module info version now uses an integer version value (`1020`) so the ProcessWire modules directory / Upgrade module can detect the version reliably.

- Improved the PDF report layout and made PDF text handling more robust for special characters and punctuation.
- Added a setting to hide/show the compact page analytics summary in ProcessPageEdit.
- Improved spacing above the Page Edit analytics summary and made the “Open full analytics” button behave more like a native ProcessWire admin button.
- Hardened inline JavaScript JSON output to avoid unsafe `</script>` edge cases in page titles or other dynamic values.


## 1.0.21 notes

- Added a dedicated **Goals** tab for tracking meaningful conversions directly inside ProcessWire.
- Goals can be based on tracked engagement events, such as form submits, downloads, phone/email clicks, outbound/custom CTA clicks, or on page/path rules such as thank-you and confirmation pages.
- Added conversion-rate reporting based on either sessions or unique visitors.
- Added Goals overview cards, a goal trend chart, and a goals/conversion-rate table.
- Added a guided Goal setup panel with helper text, quick setup presets, recent tracked event selection, recent page/path selection, and editable datalist suggestions for event groups, event names, labels, targets and paths.
- Improved the Goals empty states so new installs do not show a large empty chart before goals or conversions exist.
- Added new aggregate tables for event and goal reporting: `pwna_event_daily` and `pwna_goal_daily`.
- Added goal definition storage in `pwna_goals`.
- Added raw event retention settings and a high-traffic helper option for aggregate-first maintenance workflows.
- Added extra composite indexes for larger datasets and more efficient filtered reporting.
- Daily and hourly maintenance now rebuilds traffic, event and goal aggregates.
- Maintenance actions now include raw event purging, and analytics reset now clears hit/event/session/aggregate data while keeping configured goal definitions.
- Fixed the Goal trend chart so it plots goal completions correctly, while still showing unique visitors and sessions in the tooltip.
- Fixed chart x-axis labels so long date/time labels remain fully visible on the right edge of all SVG charts.
- Updated module version metadata to `1.0.21` / integer `1021` for both NativeAnalytics and the dashboard process module.

## 1.0.22 notes

- Added CSP nonce support for NativeAnalytics frontend and admin script tags.
- NativeAnalytics now reuses an existing nonce from `$config->cspNonce`, `$config->cspNonce()` or the current `Content-Security-Policy` / `Content-Security-Policy-Report-Only` header when available.
- Sites without a CSP nonce continue to render normal script tags, so this change is backwards compatible.
- Hardened admin inline script rendering so JavaScript variables such as jQuery `$root`, `$links` and `$link` are not interpreted as PHP variables before output.
- Updated module version metadata to `1.0.22` / integer `1022` for both NativeAnalytics and the dashboard process module.

## 1.0.23 notes

- Theme-adaptive dashboard styling: the admin UI now natively follows the active ProcessWire admin theme via Konkat `--pw-*` CSS custom properties.
- Automatic light/dark mode support — panels, tables, charts, filters, tooltips, sub-tabs and helper popups all adapt to the active color scheme.
- Status colors (Danger zone, success buttons, deltas) now use a dedicated `light-dark()` palette with proper contrast in both modes.
- Fixed solid-color buttons (delete, quick-link) so their label text remains readable on dark backgrounds.
- Added `.pwna-app` wrapper around the dashboard output for cleaner CSS scoping.
- Refactored ~175 hardcoded color values in `admin.css` to CSS custom properties.
- Updated module version metadata to `1.0.23` / integer `1023` for both NativeAnalytics and the dashboard process module.

## 1.0.24 notes

This release mainly focuses on cleaner analytics data, better bot/noise filtering and a more understandable configuration screen.

- Added smarter 404 handling. NativeAnalytics now checks whether a requested path can be resolved by redirect/history modules before treating it as a real 404.
- Added support for common redirect/history modules: PagePathHistory, ProcessRedirects and Jumplinks.
- The 404 pages section now excludes paths that already resolve through one of these modules, so renamed pages and valid redirects no longer stay listed as broken URLs.
- Added the **Cleanup resolvable 404s** maintenance action. This can retroactively remove old 404 records whose URLs now resolve correctly through redirects.
- Expanded bot and crawler detection for modern AI crawlers, SEO bots, social previewers, uptime monitors, common HTTP libraries and automated requests.
- Added optional Matomo `device-detector` support for more reliable bot/device detection.
- NativeAnalytics first checks for a site-wide Composer installation of Matomo Device Detector and then falls back to the bundled library included with the module.
- Added a clearer bot-detection status in module settings, including which detector source is being used and whether the bundled library appears available.
- Added suspicious probe detection for common scanner URLs, including WordPress/Joomla/Drupal/Magento/admin/login probes, `.env`, `.git`, config files, shell upload attempts, path traversal attempts and similar noise.
- Added the **Cleanup suspicious probes** maintenance action for removing already-recorded scanner/probe hits from the analytics database.
- Added an optional custom URL/path filter for project-specific exclusions. This is useful when a site has its own noisy paths or patterns that should not be tracked.
- Added IP blocklist support and a **Block this IP** action in the Current visitors panel.
- Improved realtime/current visitor filtering so likely bots, probes and blocked IPs are hidden from the live visitor view.
- Reorganized the module settings screen into clearer grouped/collapsible sections for tracking, filters, bot detection, privacy/consent, retention, reports and advanced options.
- Improved bundled library fallback handling and admin status messages around optional detection libraries.
- Updated module version metadata to `1.0.24` / integer `1024` for both NativeAnalytics and the dashboard process module.
