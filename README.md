# 4WP MCP Abilities

A minimal abilities provider for the WordPress MCP Adapter.

> This is **not** a production plugin. Use it as a reference implementation or temporary scaffolding in a theme or a private plugin. Review security, permissions, and data exposure before using in any real environment.

## What it does
- Registers admin‑only MCP abilities (tools)
- Exposes abilities only when `meta.mcp.public = true`

## Why this exists
The MCP Adapter exposes **only** abilities that are explicitly registered and marked public. This package provides a clean example you can adapt.

## Code is poetry.

## Credits
Thanks to the Automattic team for making MCP Adapter possible.
