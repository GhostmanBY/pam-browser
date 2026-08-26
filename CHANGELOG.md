# Changelog

All notable changes to PAM Browser will be documented here.

## 1.0.0 - 2026-08-24

- Add a typed PHP 8.5+ Chrome DevTools Protocol client for PAM.
- Add supervised Chrome and Chromium lifecycle management with persistent
  browser profiles.
- Add CDP targets, sessions, pages, execution contexts, bindings, network
  events, dialogs, downloads, screenshots, PDFs, cookies, and browser contexts.
- Add a PAM-native WebSocket transport without Node.js, Puppeteer, Playwright,
  or a third-party PHP WebSocket dependency.
- Avoid optional Ctype functions in PAM Embed and discard stale CDP endpoint
  markers before relaunching persistent Chromium profiles.
- Add PHPUnit, PHPStan level 9, protocol, lifecycle, and live Chromium gates.
