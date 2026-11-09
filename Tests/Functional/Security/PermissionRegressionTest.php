<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Security;

use Hn\McpServer\MCP\Tool\GetPageTool;
use Hn\McpServer\MCP\Tool\GetPageTreeTool;
use Hn\McpServer\MCP\Tool\Record\ReadTableTool;
use Hn\McpServer\MCP\Tool\Record\WriteTableTool;
use Hn\McpServer\MCP\Tool\SearchTool;
use Hn\McpServer\Service\FileUploadService;
use Hn\McpServer\Service\LanguageService as McpLanguageService;
use Hn\McpServer\Service\SiteInformationService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use Mcp\Types\CallToolResult;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Regression tests for permission findings of the 2026-11 security audit.
 * Each test pairs the blocked case with a control that must keep working.
 */
class PermissionRegressionTest extends AbstractFunctionalTest
{
    private const EDITOR_UID = 99;

    protected function setUp(): void
    {
        parent::setUp();
        $this->getConnectionForTable('be_users')->insert('be_users', [
            'uid' => self::EDITOR_UID,
            'pid' => 0,
            'username' => 'audit_editor',
            'password' => 'x',
            'admin' => 0,
            'tstamp' => time(),
            'crdate' => time(),
            // No workspace module: the user must not auto-create workspaces in these tests
            'userMods' => '',
        ]);
        // Every page belongs to the admin and is invisible to others unless granted below
        $this->getConnectionForTable('pages')->update('pages', [
            'perms_userid' => 1,
            'perms_user' => 31,
            'perms_groupid' => 0,
            'perms_group' => 0,
            'perms_everybody' => 0,
        ], ['deleted' => 0]);
    }

    public function testReadToolsOnlyReturnPagesInsideWebmountsWithShowPermission(): void
    {
        $this->grantPagePermissions([7, 8], 31);
        $user = $this->setUpEditor(webmounts: '7', tables: 'pages,tt_content');
        self::assertFalse($user->isAdmin());

        // Control: content on a granted page stays readable
        $granted = $this->execute(ReadTableTool::class, ['table' => 'tt_content', 'pid' => 7]);
        self::assertFalse($granted->isError, $this->text($granted));
        self::assertStringContainsString('News Header', $this->text($granted));

        // Page 4 lies outside the webmount and has no permissions for the editor
        $restricted = $this->execute(ReadTableTool::class, ['table' => 'tt_content', 'pid' => 4]);
        self::assertFalse($restricted->isError, $this->text($restricted));
        self::assertStringNotContainsString('John Doe', $this->text($restricted));

        $restrictedByUid = $this->execute(ReadTableTool::class, ['table' => 'tt_content', 'uid' => [111, 112]]);
        self::assertStringNotContainsString('John Doe', $this->text($restrictedByUid));

        // Same answer as for a nonexistent page
        $pageResult = $this->execute(GetPageTool::class, ['uid' => 4]);
        self::assertTrue($pageResult->isError);
        self::assertSame($this->text($this->execute(GetPageTool::class, ['uid' => 99999])), $this->text($pageResult));
        self::assertFalse($this->execute(GetPageTool::class, ['uid' => 7])->isError);

        $tree = $this->text($this->execute(GetPageTreeTool::class, ['startPage' => 0, 'depth' => 3]));
        self::assertStringContainsString('[7]', $tree);
        self::assertStringContainsString('[8]', $tree);
        self::assertStringNotContainsString('[1]', $tree);
        self::assertStringNotContainsString('[3]', $tree);
        self::assertStringNotContainsString('[4]', $tree);

        // Search output echoes the terms, so compare result UIDs
        $search = $this->text($this->execute(SearchTool::class, ['terms' => ['Team'], 'table' => 'tt_content']));
        self::assertStringNotContainsString('UID: 111', $search);
        self::assertStringNotContainsString('UID: 112', $search);
        $grantedSearch = $this->text($this->execute(SearchTool::class, ['terms' => ['News Header'], 'table' => 'tt_content']));
        self::assertStringContainsString('UID: 106', $grantedSearch);
    }

    public function testShowPermissionAloneOutsideWebmountsIsNotEnough(): void
    {
        // Page 4 is visible to everybody, but it is not inside the editor's webmount
        $this->getConnectionForTable('pages')->update('pages', ['perms_everybody' => 1], ['uid' => 4]);
        $this->grantPagePermissions([7, 8], 31);
        $this->setUpEditor(webmounts: '7', tables: 'pages,tt_content');

        $restricted = $this->execute(ReadTableTool::class, ['table' => 'tt_content', 'pid' => 4]);
        self::assertStringNotContainsString('John Doe', $this->text($restricted));
        self::assertTrue($this->execute(GetPageTool::class, ['uid' => 4])->isError);
    }

    public function testUploadToFallbackStorageIsRejected(): void
    {
        // Rejected for every user, as TYPO3 core does for backend file operations
        $this->setupDefaultBackendUser(1);
        $service = GeneralUtility::makeInstance(FileUploadService::class);

        foreach (['0:/', '0:/fileadmin/secret/', '0:/.well-known/'] as $targetFolder) {
            try {
                $service->resolveTargetFolder($targetFolder);
                self::fail('Fallback storage target "' . $targetFolder . '" must be rejected');
            } catch (\InvalidArgumentException $e) {
                self::assertStringContainsString('outside of a configured file storage', $e->getMessage());
            }
        }
    }

    public function testRelationOnlyUpdateRequiresParentEditPermission(): void
    {
        $this->importCSVDataSet(__DIR__ . '/../Fixtures/sys_file.csv');
        $this->createEditorWorkspace();
        // Page 1: show and content edit for the editor. Page 100 below it: show only.
        $this->grantPagePermissions([1], 17);
        $this->getConnectionForTable('pages')->insert('pages', [
            'uid' => 100,
            'pid' => 1,
            'title' => 'Protected Page',
            'slug' => '/protected',
            'doktype' => 1,
            'perms_userid' => 1,
            'perms_user' => 31,
            'perms_everybody' => 1,
            'tstamp' => time(),
            'crdate' => time(),
        ]);
        $this->getConnectionForTable('tt_content')->update('tt_content', ['editlock' => 1], ['uid' => 101]);

        $this->setUpEditor(
            webmounts: '1',
            tables: 'pages,tt_content,sys_file_reference,sys_file',
            nonExcludeFields: 'pages:title,pages:media,tt_content:header,tt_content:assets',
            explicitAllowDeny: 'tt_content:CType:textmedia',
        );
        $this->switchToWorkspace(50);

        // Control: DataHandler refuses a plain field update on the protected page
        $this->execute(WriteTableTool::class, [
            'action' => 'update', 'table' => 'pages', 'uid' => 100, 'data' => ['title' => 'Defaced'],
        ]);
        self::assertSame(0, (int)$this->getConnectionForTable('pages')->count('*', 'pages', ['title' => 'Defaced']));

        // A relation-only update must be refused the same way
        $pageMedia = $this->execute(WriteTableTool::class, [
            'action' => 'update', 'table' => 'pages', 'uid' => 100,
            'data' => ['media' => [['uid_local' => 1, 'sys_language_uid' => 0]]],
        ]);
        self::assertTrue($pageMedia->isError, $this->text($pageMedia));
        self::assertStringContainsString('without permission', $this->text($pageMedia));

        $lockedAssets = $this->execute(WriteTableTool::class, [
            'action' => 'update', 'table' => 'tt_content', 'uid' => 101,
            'data' => ['assets' => [['uid_local' => 1, 'sys_language_uid' => 0]]],
        ]);
        self::assertTrue($lockedAssets->isError, $this->text($lockedAssets));

        self::assertSame(0, $this->countReferences('pages', 100));
        self::assertSame(0, $this->countReferences('tt_content', 101));

        // Combined with a regular field, the relation must not slip through either
        $combined = $this->execute(WriteTableTool::class, [
            'action' => 'update', 'table' => 'pages', 'uid' => 100,
            'data' => ['title' => 'Defaced', 'media' => [['uid_local' => 1, 'sys_language_uid' => 0]]],
        ]);
        self::assertTrue($combined->isError, $this->text($combined));
        self::assertSame(0, $this->countReferences('pages', 100));

        // Control: the same relation update on an editable record still works
        $allowed = $this->execute(WriteTableTool::class, [
            'action' => 'update', 'table' => 'tt_content', 'uid' => 100,
            'data' => ['assets' => [['uid_local' => 1, 'sys_language_uid' => 0]]],
        ]);
        self::assertFalse($allowed->isError, $this->text($allowed));
        self::assertSame(1, $this->countReferences('tt_content', 100));
    }

    public function testSearchReplaceDoesNotReadOtherWorkspacesDrafts(): void
    {
        $this->createEditorWorkspace();
        $this->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'uid' => 51,
            'pid' => 0,
            'title' => 'Other Team',
            'adminusers' => 'be_users_1',
            'members' => '',
        ]);
        $this->getConnectionForTable('tt_content')->insert('tt_content', [
            'uid' => 900,
            'pid' => 1,
            'header' => 'Welcome Header',
            'bodytext' => 'EMBARGOED draft text',
            'CType' => 'textmedia',
            't3ver_wsid' => 51,
            't3ver_oid' => 100,
            't3ver_state' => 0,
            'tstamp' => time(),
            'crdate' => time(),
        ]);
        $this->grantPagePermissions([1], 31);
        $this->setUpEditor(
            webmounts: '1',
            tables: 'pages,tt_content',
            nonExcludeFields: 'tt_content:header,tt_content:bodytext',
            explicitAllowDeny: 'tt_content:CType:textmedia',
        );
        $this->switchToWorkspace(50);

        $foreignDraft = $this->searchReplace(900, 'EMBARGOED');
        $nonexistent = $this->searchReplace(99999, 'EMBARGOED');
        self::assertTrue($foreignDraft->isError);
        self::assertStringContainsString('Record 900 not found', $this->text($foreignDraft));
        // No content-dependent difference to a nonexistent uid
        self::assertSame(
            str_replace('99999', '900', $this->text($nonexistent)),
            $this->text($foreignDraft)
        );
        // A substring that occurs several times gets the same answer, no count oracle
        self::assertStringContainsString('Record 900 not found', $this->text($this->searchReplace(900, 'a')));

        // Control: search_replace on a live record works in the own workspace
        $own = $this->searchReplace(100, 'Welcome to');
        self::assertFalse($own->isError, $this->text($own));
    }

    private function setUpEditor(
        string $webmounts,
        string $tables,
        string $nonExcludeFields = '',
        string $explicitAllowDeny = '',
    ): BackendUserAuthentication {
        $user = $this->setupDefaultBackendUser(self::EDITOR_UID);
        $user->groupData['tables_select'] = $tables;
        $user->groupData['tables_modify'] = $tables;
        $user->groupData['webmounts'] = $webmounts;
        $user->groupData['non_exclude_fields'] = $nonExcludeFields;
        $user->groupData['explicit_allowdeny'] = $explicitAllowDeny;
        $user->user['db_mountpoints'] = $webmounts;
        return $user;
    }

    /**
     * @param int[] $pageUids
     */
    private function grantPagePermissions(array $pageUids, int $permissions): void
    {
        foreach ($pageUids as $uid) {
            $this->getConnectionForTable('pages')->update(
                'pages',
                ['perms_userid' => self::EDITOR_UID, 'perms_user' => $permissions],
                ['uid' => $uid]
            );
        }
    }

    private function createEditorWorkspace(): void
    {
        $this->getConnectionForTable('sys_workspace')->insert('sys_workspace', [
            'uid' => 50,
            'pid' => 0,
            'title' => 'Editor Workspace',
            'adminusers' => 'be_users_1',
            'members' => 'be_users_' . self::EDITOR_UID,
        ]);
    }

    private function searchReplace(int $uid, string $search): CallToolResult
    {
        return $this->execute(WriteTableTool::class, [
            'action' => 'update',
            'table' => 'tt_content',
            'uid' => $uid,
            'data' => ['bodytext' => [['search' => $search, 'replace' => $search]]],
        ]);
    }

    private function countReferences(string $table, int $uid): int
    {
        return (int)$this->getConnectionForTable('sys_file_reference')->count(
            '*',
            'sys_file_reference',
            ['tablenames' => $table, 'uid_foreign' => $uid, 'deleted' => 0]
        );
    }

    /**
     * @param class-string $toolClass
     */
    private function execute(string $toolClass, array $params): CallToolResult
    {
        $tool = match ($toolClass) {
            GetPageTool::class, GetPageTreeTool::class => new $toolClass(
                GeneralUtility::makeInstance(SiteInformationService::class),
                GeneralUtility::makeInstance(McpLanguageService::class),
            ),
            default => GeneralUtility::makeInstance($toolClass),
        };
        return $tool->execute($params);
    }

    private function text(CallToolResult $result): string
    {
        return implode("\n", array_map(static fn($content) => $content->text ?? '', $result->content));
    }
}
