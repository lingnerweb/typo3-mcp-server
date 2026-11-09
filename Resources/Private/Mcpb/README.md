# Claude Desktop extension (.mcpb)

Packs a small bridge into a Claude Desktop extension, so team members can
connect to a TYPO3 site by double-clicking a file instead of setting up a
connector by hand. The bridge runs on Claude Desktop's built-in Node.js and
needs no other software, also on Windows.

## Build

```bash
Resources/Private/Mcpb/build.sh https://dev.example.com "Example (dev)"
```

Writes `example-dev.mcpb` to the current directory. The URL argument is
optional and only pre-fills the URL field. The file contains no secrets and can
be shared with the team.

## Install

1. Double-click the `.mcpb` file, or drag it into Claude Desktop under
   Settings > Extensions.
2. Enter the TYPO3 URL (if not pre-filled) and your personal MCP token. Create
   the token in the TYPO3 backend under User > MCP Server > Create Token.
3. Enable the extension.

The token is stored by Claude Desktop in the operating system's credential
store and sent as Bearer token. Errors end up in Claude Desktop's MCP log
(`mcp-server-*.log`).
