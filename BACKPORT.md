# TYPO3 12.4 backport

`lingner/typo3-mcp-server` makes `hn/typo3-mcp-server` run on TYPO3 12.4 LTS
(including ELTS releases) while keeping TYPO3 13.4 and 14.3 support intact.

Package identity: the Composer name is `lingner/typo3-mcp-server`, which
`replace`s `hn/typo3-mcp-server`. Extension key (`mcp_server`) and PHP namespace
(`Hn\McpServer`) stay unchanged on purpose: renaming them would touch every file
and turn each upstream merge into a conflict. Consequence: the two packages can
never be installed side by side, which the `replace` entry enforces.

The upstream TER release workflow is removed: the extension key `mcp_server`
belongs to the upstream author on TER, this fork is distributed via Composer only.

- Upstream: https://github.com/hauptsacheNet/typo3-mcp-server
- Repository: https://github.com/lingnerweb/typo3-mcp-server, Composer name `lingner/typo3-mcp-server`
- Base: upstream `main` at `0a29d61` (2026-10-19, `v0.6.2` + 18 commits).
  `main` was chosen over the `v0.6.2` tag because the commits after it fix a
  privilege escalation in the `where` filter (#126) and leaking exception details
  in `/mcp` responses (#134).
- License: GPL-2.0-or-later, unchanged. All changes are under the same license.

## Verification

The complete upstream functional test suite was run against each major version
(PHP 8.3, SQLite, `typo3/testing-framework` 8.x for TYPO3 12, 9.x for 13/14):

| TYPO3   | Result                                  |
|---------|-----------------------------------------|
| 12.4.45 | 782 tests, all passing, 1 skipped       |
| 13.4.36 | 782 tests, all passing, 1 skipped       |
| 14.3.9  | 782 tests, all passing, 6 skipped       |

All skips are upstream's own version guards: the one on 12.4 and 13.4
(`RequestGlobalTest::testRteLinkToPageCanBeWrittenThroughTheMcpEndpoint`) covers
TYPO3 14 behaviour, the six on 14.3 cover APIs removed in 14.

The CI matrix in `.github/workflows/tests.yml` contains 12.4 jobs, so every
future change is checked against all three versions.

Not verified automatically: the Playwright E2E suite and the LLM test suite
(both run on the newest TYPO3 only in upstream CI).

## Design rule

Every TYPO3 12 API difference goes through one class:
`Classes/Compatibility/Typo3Compatibility.php`. Upstream files only swap a core
call for a call into that class. This keeps the diff against upstream small and
mechanical, which is what makes merging new upstream releases cheap.

Nothing is detected by "is this TYPO3 12?" when a feature check works:
`class_exists()` / `enum_exists()` decide which API is used, so the code keeps
working if a version ships a class earlier or later than expected.

## Changes to production code

### 1. Compatibility layer (new files)

| File | Purpose |
|------|---------|
| `Classes/Compatibility/Typo3Compatibility.php` | Static helpers, one per API difference (see below) |
| `Classes/Compatibility/Schema/LegacyTcaSchemaFactory.php` | TYPO3 12 stand-in for `TYPO3\CMS\Core\Schema\TcaSchemaFactory` (`has()`, `get()`) |
| `Classes/Compatibility/Schema/LegacyTcaSchema.php` | Stand-in for `TcaSchema`. Field resolution is a port of `TcaSchemaFactory::build()` / `findRelevantFieldsForSubSchema()` from TYPO3 13.4: showitem + palettes, `columnsOverrides`, label overrides, flattened `config` |
| `Classes/Compatibility/Schema/LegacyFieldType.php` | Stand-in for `FieldTypeInterface` (`getName()`, `getConfiguration()`) |
| `Classes/Compatibility/Schema/LegacySchemaTypeInformation.php` | Stand-in for `SchemaTypeInformation` |

The schema stand-in implements only the methods this extension calls. If
upstream starts using another `TcaSchema` method, the TYPO3 12 test run fails
with "call to undefined method" and the method has to be added there.

### 2. Core API differences (TYPO3 13+ only APIs)

| Upstream call | Since | Replaced by | Files |
|---|---|---|---|
| `TcaSchemaFactory` (Schema API) | 13.2 | `Typo3Compatibility::getTcaSchemaFactory()` | `Service/TableAccessService.php` |
| `->hasCapability(TcaSchemaCapability::Workspace)` | 13.2 | `->isWorkspaceAware()` (exists on both the core class and the stand-in) | `Service/TableAccessService.php` |
| `TcaFactory::get()` | 13.0 | `Typo3Compatibility::loadTca()` (`ExtensionManagementUtility::loadBaseTca()` on 12) | `Command/McpServerCommand.php`, `Service/BackendUserContextService.php` |
| `Resource\Enum\DuplicationBehavior::RENAME` (enum) | 13.0 | `Typo3Compatibility::duplicationBehaviorRename()` (string constant on 12) | `Service/FileUploadService.php` |
| `VersionState::X->value` (enum) | 13.0 | `Typo3Compatibility::versionStateValue('X')` (int constant on 12) | `Database/Query/Restriction/WorkspaceDeletePlaceholderRestriction.php` |

### 3. Behaviour differences found by the test suite

| Problem on TYPO3 12 | Fix | File |
|---|---|---|
| `WorkspaceService` and `DataHandler` read `$GLOBALS['LANG']` when listing/creating workspaces. Without it the automatic "MCP Workspace" creation fails and the tool silently falls back to the live workspace, so writes would go live. The HTTP and CLI entry points set `LANG`, this only hit direct callers, but the failure mode is severe. | `Typo3Compatibility::ensureLanguageService()` at the start of `switchToOptimalWorkspace()` | `Service/WorkspaceContextService.php` |
| TYPO3 12 stores FlexForm XML without the `<?xml` prolog. Read and write detected FlexForm XML only by that prolog, so on 12 `ReadTable` returned raw XML instead of the settings array. Also affects TYPO3 13 sites with records saved before an upgrade. | Accept `<T3FlexForms>` as start of the value as well | `MCP/Tool/Record/ReadTableTool.php`, `MCP/Tool/Record/WriteTableTool.php` |
| `DataHandler::checkValueForCheck()` applies a bitmask without casting, so a non-numeric string for a checkbox throws a `TypeError` (TYPO3 13 casts to int first). | Cast non-numeric strings for `check` fields to int before handing them to DataHandler, matching TYPO3 13 | `MCP/Tool/Record/WriteTableTool.php` |
| Nested file references (a file on an embedded inline child) used datamap keys `NEW<id>_<index>`. DataHandler's remap stack reads `a_b` as table `a`, uid `b`, which turns the reference into `NEW<id>_`. TYPO3 12's `FileExtensionFilter` throws on that, TYPO3 13 catches it and drops the reference (upstream then repairs the pointer). This is an upstream bug that TYPO3 13 hides. | Key without underscore (`NEW<id>n<index>`) for nested children | `MCP/Tool/Record/WriteTableTool.php` |

### 4. Metadata

- `composer.json`: `typo3/cms-core`, `cms-backend`, `cms-workspaces`, `cms-install` (dev)
  accept `^12.4`; dev dependencies `georgringer/news ^12.3 || ^14.0`,
  `b13/container ^3.2 || ^4.0` (the TYPO3 12 compatible majors).
- `ext_emconf.php`: `typo3` and `workspaces` `12.4.0-14.99.99`.
- `composer.json`: name `lingner/typo3-mcp-server`, `replace` `hn/typo3-mcp-server`,
  backport author entry.
- `README.md`: reduced to a short note pointing to the original README.
- `.github/workflows/release-ter.yml`: removed (see above).
- `.github/workflows/tests.yml`: 12.4 jobs (PHP 8.2, 8.3). Composer blocks the
  public 12.4 releases because of security advisories (TYPO3 12 is in ELTS), so
  those jobs disable advisory blocking. This only affects CI.

### 5. Additions beyond the port

These are not TYPO3 12 related and are candidates for an upstream pull request.

| Change | Files |
|---|---|
| The module status check uses `auth_header_detected` instead of `headers_received.authorization`: behind PHP-FPM the header arrives as `REDIRECT_HTTP_AUTHORIZATION`, which the endpoint already accepts. | `Resources/Public/JavaScript/mcp-module.js` |
| Security: read tools respect page permissions and webmounts (new `PageAccessService`, applied via `BeforeRecordReadEvent` and in GetPage/GetPageTree). | `Classes/Service/PageAccessService.php`, `Classes/EventListener/PagePermissionRestrictionListener.php`, `Classes/MCP/Tool/GetPageTool.php`, `Classes/MCP/Tool/GetPageTreeTool.php` |
| Security: uploads refuse the fallback storage `0:/`, which bypassed file mounts (same rule as core `ExtendedFileUtility`). | `Classes/Service/FileUploadService.php` |
| Security: updates with inline/file fields require edit permission on the parent record (page edit, editlock, language, authMode). | `Classes/MCP/Tool/Record/WriteTableTool.php` |
| Security: `search_replace` only reads records of live or the current workspace on readable pages; other records get the same answer as a nonexistent uid. | `Classes/MCP/Tool/Record/WriteTableTool.php` |

Regression tests for the security changes: `Tests/Functional/Security/PermissionRegressionTest.php`.

## Changes to tests

Only where TYPO3 12 behaves differently from 13. All adapted tests still pass on
13 and 14.

| Test | TYPO3 12 difference | Change |
|---|---|---|
| `NewsLinkInlineTest`, `NewsContentElementsTest`, `ReadTableFieldFilterTest`, `InlineRelationWriteTest` | TYPO3 13 lets admins insert records on a non-existing page, TYPO3 12 rejects it. These tests wrote to pid 1 without a page fixture. | Import `pages.csv` |
| `WriteTableToolErrorTest::testUpdateNonExistentRecord`, `::testCreateWithInvalidParent` | Same: TYPO3 12 logs an error for a missing record/page, which the tool reports | Expect `isError` on 12 |
| `PermissionEdgeCaseTest::testPartialTablePermissions` | Updates `tt_content:1`, which is not in the fixture; TYPO3 12 reports that | On 12 only assert that table access was granted |
| `LanguageServiceTest`, `PermissionEdgeCaseTest`, `WriteTablePageTSconfigTest` | TYPO3 12 `SiteFinder` loads all sites in its constructor and caches them; site files written during a test are not seen | Reload sites after writing the file (`getAllSites(false)`), reset the `LanguageService` site list |
| `SubdirectoryRoutingTest`, `UploadFileToolTest::testPresignedUploadUrlIncludesSubdirectoryPrefix` | Under testing-framework 8, `Environment::getCurrentScript()` points to phpunit, so `NormalizedParams::createFrom*()` computes a wrong site path | Construct `NormalizedParams` with explicit script and site path |
| `FileUploadEndpointTest::testExpiredTokenIsRejected` | doctrine/dbal 3 renders `Connection::update()` with empty criteria as invalid SQL | Use the query builder |
| `GetFlexFormSchemaToolTest::testTableWithoutTca` | `sys_log` still has TCA in TYPO3 12 | Use `sys_registry` |
| `WriteTableToolTest::testFlexFormFieldHandling` | No `<?xml` prolog on 12 | Drop that assertion, the `T3FlexForms` check stays |
| `TableAccessServiceTSconfigTest` | Was skipped below 13, passes on 12 | Skip only below 12 |

## Updating to a new upstream release

`main` of this repository is published, so upstream releases are merged, not rebased:

```bash
git remote add upstream https://github.com/hauptsacheNet/typo3-mcp-server.git  # once
git fetch upstream --tags
git merge <new-upstream-tag-or-commit>
# re-run the suite on 12.4 and 13.4 (see "Test runs")
```

What to expect:

- Conflicts are limited to `README.md`, `composer.json`, `ext_emconf.php` and the ~15 changed lines in upstream files listed above,
  mostly `use` statements and single calls. Files under `Classes/Compatibility/`
  never conflict.
- New upstream code that uses a TYPO3 13+ API compiles fine but fails on 12.
  Find it before running tests:

  ```bash
  # in a TYPO3 12 install of this package
  vendor/bin/phpstan analyse -l 2 Classes
  ```

  "Class ... not found" and "unknown class" findings are the work list. Route
  each through `Typo3Compatibility` the same way.
- Behaviour differences (like the four in section 3) only show up in the
  TYPO3 12 test run. Upstream's large functional suite is what makes this
  backport maintainable; keep the 12.4 CI jobs green.

Effort estimate per upstream release, based on this backport: a merge with no
new 13+ APIs is about an hour including both test runs. A release that adopts
more of the Schema API (`TcaSchema` capabilities, relation map, `FlexFormSchema`)
can take a day, because each newly used method needs a TYPO3 12 equivalent.

Risks that would make updates expensive:

- Upstream moving large parts to TYPO3 13/14-only subsystems (Schema API
  relations, `RecordFactory`/Record API, Content Blocks specifics).
- Upstream dropping TYPO3 13 (when 13 leaves its support window): then
  14-only APIs arrive without a 13 code path to copy from.

The cheapest long-term option is to offer the compatibility layer upstream as a
pull request. It is isolated, feature-detected and covered by CI, so upstream
could carry 12.4 support until they drop 13.

## Test runs

```bash
# TYPO3 12.4 (public packages carry advisories, allowed for testing only)
composer config policy.advisories.block false
composer require --no-update "typo3/cms-core:^12.4" "typo3/cms-backend:^12.4" "typo3/cms-workspaces:^12.4"
composer require --dev --no-update "typo3/cms-install:^12.4"
composer update
vendor/bin/paratest -c phpunit.xml.dist
```

Same with `^13.4` / `^14.3` for the other versions.
