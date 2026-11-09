<?php

declare(strict_types=1);

namespace Hn\McpServer\EventListener;

use Hn\McpServer\Event\BeforeRecordReadEvent;
use Hn\McpServer\Service\PageAccessService;

/**
 * Restricts every MCP record read (ReadTable, inline children, Search) to
 * pages the current backend user may show, i.e. inside their webmounts and
 * with PAGE_SHOW permission. Admins are not restricted.
 */
final class PagePermissionRestrictionListener
{
    public function __construct(
        private readonly PageAccessService $pageAccessService,
    ) {}

    public function __invoke(BeforeRecordReadEvent $event): void
    {
        $queryBuilder = $event->getQueryBuilder();
        $restriction = $this->pageAccessService->buildRestriction($queryBuilder, $event->getTable());
        if ($restriction !== null) {
            $queryBuilder->andWhere($restriction);
        }
    }
}
