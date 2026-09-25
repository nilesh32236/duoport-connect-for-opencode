# RELEASE-003: 0.1.6 listing-screenshot release

## Purpose

Publish the two verified WordPress.org screenshots and the factual listing sections added by GROWTH-001. This is a packaging/documentation release; it does not add a model, capability, transport, router, UI, or credential path.

## Source boundary

- start: `d7cc77a858261b3babd49c5d4b814704a4523408` (`origin/main` after GROWTH-001 closeout)
- target version: `0.1.6`
- screenshots: `screenshot-1.png` and `screenshot-2.png`, with hashes recorded in `WORDPRESS-ORG.md`
- title, tags, banner, and icon remain unchanged

## Release gates

1. Synchronize the plugin header, `VERSION`, readme stable tag, changelog, and upgrade notice.
2. Run WPCS, PHPUnit, PHPCompatibilityWP, reviewer/catalog/model-radar contracts, and screenshot/ZIP checks on the exact head.
3. Build a ZIP with no vendor, tests, local research artifacts, or secret-shaped content; verify screenshot/readme entries and unzip integrity.
4. Merge the release-preparation PR with the exact-head rule.
5. Tag the exact reviewed commit as `v0.1.6`, create the GitHub release asset, and verify tag/asset provenance.
6. Deploy the exact tag through the existing WordPress.org SVN workflow; verify the public version and screenshot URLs.
7. Synchronize the live plugin and repeat WordPress, settings, Connectors, and Playwright checks.

No automatic release is allowed before all gates pass. If WordPress.org deployment fails, keep the tag/release evidence explicit and do not claim publication.

## Published evidence

- exact release head: `8bd45a4fe1da7b7f7d1af706d123e92ee52ff060`
- exact reviewed PR head: `7cbd81469788b53b73996e4d88585dfef5ff76e7` (PR #99)
- GitHub release: `v0.1.6`; asset SHA-256 `b4b19a48322ebfb8392d34642dd10cd57c0ec2cfd069fd67785b5f07153e7608`
- WordPress.org SVN: `tags/0.1.6` deployed at revision `3712542`
- public API: version `0.1.6`, last updated `2026-09-25 7:02am GMT`, both screenshot URLs present
- screenshot hashes: `89859473776b4cc5f735676fc663f7743a31524fc131d1e16553aadc635a5727` and `d737a505fce7cf3f7db5616e0f4946d1928ff4dba9a38ae5168e7df94f73987c`
- live WordPress 7.1.2/PHP 8.3.33/AI Client 1.3.1: plugin 0.1.6 activation, provider registration, diagnostics, settings, and Playwright pass with no network/console errors
