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
