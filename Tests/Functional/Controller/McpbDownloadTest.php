<?php

declare(strict_types=1);

namespace Hn\McpServer\Tests\Functional\Controller;

use Hn\McpServer\Controller\McpServerModuleController;
use Hn\McpServer\Service\McpbBundleService;
use Hn\McpServer\Tests\Functional\AbstractFunctionalTest;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The backend module offers the Claude Desktop extension (.mcpb) for download,
 * with the site URL pre-filled and no secrets inside.
 */
class McpbDownloadTest extends AbstractFunctionalTest
{
    public function testBundleIsAValidZipWithPrefilledUrl(): void
    {
        $bundle = GeneralUtility::makeInstance(McpbBundleService::class)
            ->build('https://dev.example.com', 'Example');

        $this->assertSame('TYPO3 MCP Connector (Example).mcpb', $bundle['filename']);

        $files = $this->unzip($bundle['content']);
        $this->assertSame(['manifest.json', 'server/index.js', 'icon.png'], array_keys($files));

        $manifest = json_decode($files['manifest.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('typo3-mcp-connector', $manifest['name']);
        $this->assertSame('TYPO3 MCP Connector', $manifest['display_name']);
        $this->assertSame('https://dev.example.com', $manifest['user_config']['server_url']['default']);
        $this->assertTrue($manifest['user_config']['token']['sensitive']);
        $this->assertArrayNotHasKey('default', $manifest['user_config']['token']);
        $this->assertSame('icon.png', $manifest['icon']);
        $this->assertStringStartsWith("\x89PNG", $files['icon.png']);
        $this->assertStringContainsString('Bearer', $files['server/index.js']);
    }

    public function testFilenameIsSafeForAllOperatingSystems(): void
    {
        $service = GeneralUtility::makeInstance(McpbBundleService::class);

        $this->assertSame('TYPO3 MCP Connector (A B x ty).mcpb', $service->build('https://x.example', ' A/B: "x"\ty ')['filename']);
        $this->assertSame('TYPO3 MCP Connector.mcpb', $service->build('https://x.example', '')['filename']);
        $this->assertSame('TYPO3 MCP Connector.mcpb', $service->build('https://x.example', ' ?* ')['filename']);
    }

    public function testDownloadActionReturnsAttachment(): void
    {
        $this->setupDefaultBackendUser();
        $controller = $this->get(McpServerModuleController::class);
        $request = new ServerRequest(new Uri('https://www.example.com/typo3/module/user/mcp-server/download-mcpb'), 'GET');

        $response = $controller->downloadMcpbAction($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/zip', $response->getHeaderLine('Content-Type'));
        $this->assertSame(
            'attachment; filename="TYPO3 MCP Connector (www.example.com).mcpb"; filename*=UTF-8\'\'TYPO3%20MCP%20Connector%20%28www.example.com%29.mcpb',
            $response->getHeaderLine('Content-Disposition')
        );
        $files = $this->unzip((string)$response->getBody());
        $manifest = json_decode($files['manifest.json'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('https://www.example.com', $manifest['user_config']['server_url']['default']);
    }

    public function testDownloadActionRequiresBackendUser(): void
    {
        unset($GLOBALS['BE_USER']);
        $controller = $this->get(McpServerModuleController::class);

        $response = $controller->downloadMcpbAction(new ServerRequest(new Uri('https://www.example.com/'), 'GET'));

        $this->assertSame(403, $response->getStatusCode());
    }

    /**
     * @return array<string, string>
     */
    private function unzip(string $content): array
    {
        $path = tempnam(sys_get_temp_dir(), 'mcpb');
        file_put_contents($path, $content);
        $zip = new \ZipArchive();
        $this->assertTrue($zip->open($path, \ZipArchive::CHECKCONS) === true, 'Archive must pass the consistency check');
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string)$zip->getNameIndex($i);
            $files[$name] = (string)$zip->getFromIndex($i);
        }
        $zip->close();
        unlink($path);
        return $files;
    }
}
