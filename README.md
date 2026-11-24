# Pluginia Connect (local_pluginiaconnect)

Pluginia Connect lets a user connect an AI assistant (such as Claude, ChatGPT or Microsoft 365
Copilot Cowork) to their own account on a site running Moodle™ LMS, through the Model Context
Protocol (MCP) and OAuth 2.1.

**Status: alpha (0.2.0). Not for production sites.** See "Status of this alpha".

## What it does

- The user signs in on this site, the usual way, and approves the connection on a consent page of
  this site. The assistant never sees the user's password.
- The user chooses a profile (student, teacher, manager or administrator), only among the ones
  their roles on this site give them, and an access level. Read only is the default.
- The assistant can then call a closed list of actions. Every call is checked against the user's
  real capabilities on this site, at the time of the call, and is recorded in an audit table.
- The plugin does not use the site's web service tokens and does not require web services to be
  enabled. It has no generic "run this function" or "run this query" action.

Effective permission = site capabilities ∩ profile ∩ granted scopes ∩ site policy. Each layer can
only remove permissions.

## How changes are approved

No action that changes something is applied the first time it is called.

- **Confirmation.** The first call returns a description of the exact change, built by this site from its
  own data, and a token valid for five minutes. The change is applied only when the assistant calls again
  with the same arguments and that token. An assistant can do this by itself, so confirmation is a record
  and a chance to show the change, not a substitute for a person.
- **Approval.** The call creates a pending change and returns a link to a page of this site. The change is
  applied only when the user it belongs to opens that page with their own session and approves it.
  Suspending or reactivating enrolments and suspending accounts always work this way, and the
  administrator can require it for any other action.
- Changes requested through an assistant that can run without a person present (Microsoft 365 Copilot
  Cowork) always need approval, whatever the action.
- A pending change expires after 15 minutes. A connection can hold five pending changes at most.
- No action deletes anything: the most an assistant can do is hide an activity or suspend an enrolment or
  an account, and each of those can be undone. Every action runs, and is recorded, as the connected user;
  none can act in the name of somebody else.

## Requirements

- Moodle 4.5 to 5.3.
- PHP 8.1 or later (as required by the Moodle version), with the sodium extension.
- HTTPS with a certificate from a recognised authority.
- The web server must pass the `Authorization` header and path arguments (`PATH_INFO`) to PHP.
  The diagnostics page checks both.
- Cron running every minute.
- On sites with several web servers, a shared application cache (for example Redis) for the
  plugin's `ratelimit` cache.

No Composer step, no third-party libraries, no changes to core.

## Installation

1. Download the ZIP and install it from *Site administration → Plugins → Install plugins*, or
   unpack it into `local/pluginiaconnect` (`public/local/pluginiaconnect` from Moodle 5.1).
2. Complete the upgrade in *Site administration → Notifications*.
3. Open *Site administration → Plugins → Local plugins → Pluginia Connect → Diagnostics* and fix
   anything marked as an error.

## Configuration

Everything is off after installation.

1. *Settings → General*: enable the connector and at least one connection mode.
   - **Direct connections**: the assistant talks to this site. No third party is involved other
     than the provider of the assistant.
   - **Pluginia Connect gateway**: a single connection address for assistant directories. It needs
     the site to be registered with the gateway (*Gateway registration*). The gateway is optional and,
     for now, free of charge: no licence key is needed.
2. *Settings → Profiles*: choose which profiles are offered. The student profile is off by default;
   enabling it carries a declaration about minors (see the setting description).
3. *Settings → Scope*: limit the connector to some course categories, or exclude categories,
   courses and cohorts.
4. Roles: the capabilities `local/pluginiaconnect:connect`, `:usestudentprofile`,
   `:useteacherprofile`, `:usemanagerprofile` and `:write` decide who can connect, with which
   profile, and whether they can request changes.

Emergency switch: `php local/pluginiaconnect/cli/revoke_all.php --yes --disable` revokes every
connection and switches the connector off.

## External services and the data sent to them

- **The AI assistant chosen by the user** (provided by Anthropic, OpenAI or Microsoft, depending on
  the assistant) receives the information the user asks for through the connector, limited to
  what that user may see on this site. That provider handles it under its own terms.
- **The Pluginia Connect gateway** (`connect.pluginia.es`, operated by RSMAX Consulting S.L.), only
  if the site administrator registers the site and enables it. It carries requests and answers
  and does not store their content. It is optional and, for now, free of charge. The plugin works
  without it in direct mode.

The plugin itself contains no licence check.

## Privacy

The plugin implements the Moodle Privacy API: it declares the data it stores (connections, token
hashes, the audit record, pending changes) and the external destinations above, and supports
export and deletion. Tokens are stored only as SHA-256 hashes. The audit record never contains
the details of a request or the information returned. Retention is configurable (180 days by
default).

## Status of this alpha

This alpha contains sign-in and consent, tokens, the MCP endpoint, the permission engine, the
audit record, the 31 actions of the catalogue (22 that read and 9 that change something, listed in
[CHANGES.md](CHANGES.md)), two-step confirmation and approval of changes on the site, the
"My AI connections" page, the administrator pages (connections, audit report, actions by profile,
diagnostics) and registration with the Pluginia Connect gateway. Nothing of the catalogue is
missing.

What has been checked so far, all of it on development sites (see [CHANGES.md](CHANGES.md)):

- Three security reviews of the code, including the actions that change something, and a
  penetration test against local sites. They were done with AI tools during development; none of
  them is an audit by an independent security firm, and no such audit has been done yet.
- The unit tests (PHPUnit) and the browser tests (Behat) pass on Moodle 4.5 and 5.3.

What is still pending:

- An independent security audit.
- The first run of the continuous integration on GitHub Actions, which also runs the template and
  JavaScript checks: those have not been run anywhere yet.
- No AI assistant has been connected through the gateway yet: it is deployed, but the first real
  connection has only been made in direct mode.

Actions that change something are switched off after installing. Enable them one by one on
"Actions by profile", and read "How changes are approved" above first.

## Support

- Issues: the issue tracker of this repository.
- Security problems: see [SECURITY.md](SECURITY.md). Do not open a public issue.
- Configuration manual: linked from the top of the plugin's settings page
  (*Site administration → Plugins → Local plugins → Pluginia Connect*).

## Licence

GNU GPL v3 or later. See [LICENSE](LICENSE).

Copyright 2026 RSMAX Consulting S.L. (Pluginia).

Moodle is a registered trademark of Moodle Pty Ltd. Pluginia Connect is an independent product and
is not affiliated with or endorsed by Moodle.
