# NativeAnalytics

First-party analytics for ProcessWire. All tracking data stays in your own database: no Google Analytics, no external scripts, no remote APIs.

**Current version: 1.0.38** · [Changelog](https://github.com/Roychgod/NativeAnalytics/blob/main/CHANGELOG.md) · [Forum thread](https://processwire.com/talk/topic/31808-native-analytics-%E2%80%94-a-native-analytics-module-for-processwire/)

## Features

- **Traffic:** page views, unique visitors, sessions, current visitors, top/landing/exit pages, browsers, devices, OS
- **Acquisition:** referrers, UTM campaigns, first-touch channel attribution (Direct, Organic, Social, Referral, Paid, Email…)
- **Engagement:** events for forms, downloads, contact and outbound links, custom CTAs, plus internal site search terms
- **Goals and funnels:** event or path goals, conversion rates, multi-step funnels with drop-off
- **Compare:** previous period or same period last year
- **Content insights:** growing and declining pages, published pages without visits, smart 404 report
- **Bot filtering:** AI crawlers, SEO bots, scanners and probe URLs, optional Matomo device-detector, IP blocklist
- **Privacy:** cookie-less mode, consent gate, PrivacyWire helper, respects GPC and Do Not Track
- **Reports:** CSV, PDF and DOCX exports, optional monthly email reports
- **Admin:** dashboard with tabs, per-page stats in the page editor, data retention and cleanup tools
- **Developers:** hooks `shouldTrack`, `beforeSaveHit`, `beforeSaveEvent`, `honeypotTriggered`, optional form spam honeypot

## Requirements

- ProcessWire 3.0.173+
- PHP 7.4+
- LazyCron (core module)
- `ZipArchive` for DOCX export

## Installation

1. Copy the `NativeAnalytics` folder to `/site/modules/`.
2. **Modules → Refresh**, then install **NativeAnalytics** (the dashboard installs with it).
3. Open **Admin → Analytics**. Settings are under **Modules → Configure → NativeAnalytics**.

## Upgrading

Replace the module folder and run **Modules → Refresh**. Database changes are applied automatically.

## Latest release: 1.0.38

Security update, reported privately by Adrian (adrianbj). Thank you!

- Tracking endpoint refuses requests over 8 KB, and event extra data is capped at 2 KB.
- New setting: max tracking requests per IP per minute (default 240, 0 disables).
- CSV exports are protected against formula injection in Excel and Google Sheets.

Full history: [CHANGELOG.md](https://github.com/Roychgod/NativeAnalytics/blob/main/CHANGELOG.md)

---

Made by [Pyxios](https://www.pyxios.com)
