# TYPO3 MCP Connector (.mcpb)

A Claude Desktop extension that connects to a TYPO3 site running
EXT:mcp_server. It runs on Claude Desktop's built-in Node.js and needs no other
software, also on Windows.

## Install

1. Download the file in the TYPO3 backend under User > MCP Server >
   Claude Desktop.
2. In Claude Desktop, open Settings > Desktop App > Extensions and drag the
   file there, or open the file directly.
3. Fill in the TYPO3 URL and an OAuth token. Create the token in the TYPO3
   backend under User > MCP Server > Active OAuth Tokens.

The token is stored by Claude Desktop in the operating system's credential
store and sent as Bearer token. Errors end up in Claude Desktop's MCP log
(`mcp-server-*.log`).
