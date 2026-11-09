# TYPO3 MCP Server (TYPO3 12 port)

TYPO3 12.4 compatible port of [hauptsacheNet/typo3-mcp-server](https://github.com/hauptsacheNet/typo3-mcp-server).
TYPO3 13.4 and 14.3 remain supported. Features, configuration and usage are unchanged,
see the [original README](https://github.com/hauptsacheNet/typo3-mcp-server#readme).

```bash
composer require lingner/typo3-mcp-server
```

The package replaces `hn/typo3-mcp-server`; extension key (`mcp_server`) and namespace are unchanged.
All changes against the original are documented in [BACKPORT.md](BACKPORT.md).

The backend module offers the Claude Desktop extension
[TYPO3 MCP Connector](Resources/Private/Mcpb/README.md) for download.

## License

GPL-2.0-or-later, like the original. See [LICENSE](LICENSE).
