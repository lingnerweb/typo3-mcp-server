<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\QueryBuilder;
use TYPO3\CMS\Core\Database\Query\QueryHelper;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Type\Bitmask\Permission;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Page-level read access for MCP tools, matching the TYPO3 backend:
 * a non-admin user may read a page only if it lies inside one of their
 * webmounts AND passes the PAGE_SHOW permission clause. Records stored on a
 * page are readable only if their page is.
 *
 * getPagePermsClause() alone is not enough: it checks the perms_* bits but
 * not the webmounts, so both are combined here.
 */
class PageAccessService implements SingletonInterface
{
    private const CHUNK_SIZE = 500;

    /**
     * Readable page uids per user/workspace/mount combination.
     * @var array<string, int[]>
     */
    private array $cache = [];

    /**
     * Returns the uids of all pages the current user may read, or null if no
     * restriction applies (admin or no backend user context, e.g. CLI tooling
     * that already runs as admin).
     *
     * @return int[]|null
     */
    public function getReadablePageIds(): ?array
    {
        $user = $GLOBALS['BE_USER'] ?? null;
        if (!$user instanceof BackendUserAuthentication || $user->isAdmin()) {
            return null;
        }

        $mounts = array_map('intval', $user->returnWebmounts());
        $permsClause = $user->getPagePermsClause(Permission::PAGE_SHOW);
        $cacheKey = md5(implode(',', [
            (int)($user->user['uid'] ?? 0),
            (int)$user->workspace,
            implode('|', $mounts),
            $permsClause,
            $this->getPagesFingerprint(),
        ]));

        return $this->cache[$cacheKey] ??= $this->computeReadablePageIds($mounts, $permsClause);
    }

    /**
     * Cheap change marker for the pages table, so the cache stays correct in
     * long-running processes (tests, CLI) when pages are created, moved or
     * their permissions change.
     */
    private function getPagesFingerprint(): string
    {
        $queryBuilder = $this->createPagesQueryBuilder();
        $row = $queryBuilder
            ->selectLiteral(
                'COUNT(*) AS cnt',
                'MAX(uid) AS max_uid',
                'MAX(tstamp) AS max_tstamp',
                'SUM(pid + perms_userid + perms_groupid + perms_user + perms_group + perms_everybody) AS perms_sum'
            )
            ->from('pages')
            ->executeQuery()
            ->fetchAssociative();
        return implode(':', array_map('strval', $row ?: []));
    }

    public function canReadPage(int $pageUid): bool
    {
        $readable = $this->getReadablePageIds();
        return $readable === null || in_array($pageUid, $readable, true);
    }

    /**
     * Builds a WHERE expression restricting a query on $table to readable pages:
     * uid for 'pages', pid for page-bound tables. Returns null when no
     * restriction applies (admin, or a root-level table that does not live on pages).
     */
    public function buildRestriction(QueryBuilder $queryBuilder, string $table): ?string
    {
        $readable = $this->getReadablePageIds();
        if ($readable === null) {
            return null;
        }

        if ($table === 'pages') {
            return $this->inList($queryBuilder, 'uid', $readable);
        }

        $rootLevel = (int)($GLOBALS['TCA'][$table]['ctrl']['rootLevel'] ?? 0);
        if ($rootLevel === 1) {
            // Root-level tables (sys_file, sys_workspace, ...) are not bound to pages
            return null;
        }

        $restriction = $this->inList($queryBuilder, 'pid', $readable);
        if ($rootLevel === -1) {
            // Tables allowed on root level and on pages keep their pid=0 records
            $restriction = (string)$queryBuilder->expr()->or(
                $restriction,
                $queryBuilder->expr()->eq('pid', 0)
            );
        }
        return $restriction;
    }

    /**
     * @param int[] $mounts
     * @return int[]
     */
    private function computeReadablePageIds(array $mounts, string $permsClause): array
    {
        $inMount = $this->collectPagesInMounts($mounts);
        if ($inMount === []) {
            return [];
        }

        $readable = [];
        foreach (array_chunk($inMount, self::CHUNK_SIZE) as $chunk) {
            $queryBuilder = $this->createPagesQueryBuilder();
            $rows = $queryBuilder->select('uid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->in('uid', array_map('intval', $chunk)),
                    QueryHelper::stripLogicalOperatorPrefix($permsClause)
                )
                ->executeQuery()
                ->fetchFirstColumn();
            foreach ($rows as $uid) {
                $readable[] = (int)$uid;
            }
        }
        return $readable;
    }

    /**
     * Breadth-first walk from the webmounts through the page tree. Includes
     * workspace versions and translations, since records on those pages use
     * the same pids; workspace visibility is handled by the callers' own
     * workspace restrictions.
     *
     * @param int[] $mounts
     * @return int[]
     */
    private function collectPagesInMounts(array $mounts): array
    {
        if (in_array(0, $mounts, true)) {
            $found = [];
            $queue = [0];
        } else {
            $found = $this->existingPages($mounts);
            $queue = $found;
        }

        $seen = array_fill_keys($found, true);
        while ($queue !== []) {
            $next = [];
            foreach (array_chunk($queue, self::CHUNK_SIZE) as $chunk) {
                $queryBuilder = $this->createPagesQueryBuilder();
                $children = $queryBuilder->select('uid')
                    ->from('pages')
                    ->where($queryBuilder->expr()->in('pid', array_map('intval', $chunk)))
                    ->executeQuery()
                    ->fetchFirstColumn();
                foreach ($children as $uid) {
                    $uid = (int)$uid;
                    if (!isset($seen[$uid])) {
                        $seen[$uid] = true;
                        $next[] = $uid;
                    }
                }
            }
            $queue = $next;
        }

        return array_keys($seen);
    }

    /**
     * @param int[] $uids
     * @return int[]
     */
    private function existingPages(array $uids): array
    {
        if ($uids === []) {
            return [];
        }
        $queryBuilder = $this->createPagesQueryBuilder();
        return array_map('intval', $queryBuilder->select('uid')
            ->from('pages')
            ->where($queryBuilder->expr()->in('uid', array_map('intval', $uids)))
            ->executeQuery()
            ->fetchFirstColumn());
    }

    private function createPagesQueryBuilder(): QueryBuilder
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)->getQueryBuilderForTable('pages');
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        return $queryBuilder;
    }

    /**
     * Integer list inlined into SQL instead of bound parameters: large sites
     * have thousands of readable pages, which would exceed the bound-parameter
     * limit of some database drivers. The values are cast to int, so inlining is safe.
     *
     * @param int[] $values
     */
    private function inList(QueryBuilder $queryBuilder, string $field, array $values): string
    {
        if ($values === []) {
            return '1 = 0';
        }
        return $queryBuilder->expr()->in($field, array_map('intval', $values));
    }
}
