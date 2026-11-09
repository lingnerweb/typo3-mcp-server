<?php

declare(strict_types=1);

namespace Hn\McpServer\Service;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Builds the Claude Desktop extension "TYPO3 MCP Connector" (.mcpb) from
 * Resources/Private/Mcpb with the TYPO3 URL pre-filled.
 */
class McpbBundleService
{
    private const SOURCE_DIR = 'EXT:mcp_server/Resources/Private/Mcpb/';

    /**
     * Files copied into the bundle as they are; manifest.json is generated.
     */
    private const FILES = ['server/index.js', 'icon.png'];

    /**
     * @return array{filename: string, content: string}
     */
    public function build(string $serverUrl, string $instanceName = ''): array
    {
        $manifest = json_decode($this->readSource('manifest.json'), true, 512, JSON_THROW_ON_ERROR);
        $manifest['user_config']['server_url']['default'] = $serverUrl;

        $entries = [
            'manifest.json' => json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n",
        ];
        foreach (self::FILES as $file) {
            $entries[$file] = $this->readSource($file);
        }

        return [
            'filename' => $this->buildFilename($instanceName),
            'content' => $this->createZip($entries),
        ];
    }

    /**
     * "TYPO3 MCP Connector (<instance name>).mcpb", so downloads from several
     * instances (dev, live, other customers) stay apart.
     */
    private function buildFilename(string $instanceName): string
    {
        // Characters not allowed in Windows or macOS file names, and control characters
        $instanceName = trim((string)preg_replace('/[\\\\\/:*?"<>|\x00-\x1F\x7F]+/u', ' ', $instanceName));
        $instanceName = (string)preg_replace('/\s+/u', ' ', $instanceName);
        return $instanceName !== ''
            ? 'TYPO3 MCP Connector (' . $instanceName . ').mcpb'
            : 'TYPO3 MCP Connector.mcpb';
    }

    private function readSource(string $file): string
    {
        $path = GeneralUtility::getFileAbsFileName(self::SOURCE_DIR . $file);
        $content = $path !== '' ? @file_get_contents($path) : false;
        if ($content === false) {
            throw new \RuntimeException('Missing .mcpb source file: ' . $file);
        }
        return $content;
    }

    /**
     * Files only, no directory entries: those break some unpackers.
     *
     * @param array<string, string> $entries path => content
     */
    private function createZip(array $entries): string
    {
        $path = GeneralUtility::tempnam('mcpb_');
        try {
            $zip = new \ZipArchive();
            if ($zip->open($path, \ZipArchive::OVERWRITE) !== true) {
                throw new \RuntimeException('Cannot create .mcpb archive');
            }
            foreach ($entries as $name => $content) {
                $zip->addFromString($name, $content);
            }
            $zip->close();
            $content = file_get_contents($path);
            if ($content === false) {
                throw new \RuntimeException('Cannot read .mcpb archive');
            }
            return $content;
        } finally {
            GeneralUtility::unlink_tempfile($path);
        }
    }
}
