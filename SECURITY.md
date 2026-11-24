# Security policy

## Reporting a vulnerability

Write to **security@pluginia.es**. Please do not open a public issue and do not disclose the
problem publicly until a fix is available.

Include, if you can:

- the version of the plugin and of Moodle;
- what an attacker can do and what they need to do it;
- the steps to reproduce it.

Never include real access tokens, passwords or personal data of real users in a report. Use a
test site with invented data.

## What to expect

- We acknowledge the report within 3 working days.
- We tell you whether we can reproduce it, and our assessment, within 10 working days.
- We publish a fixed version and credit you in the release notes, unless you prefer otherwise.
- We ask for 90 days, or until the fix is released if that is sooner, before public disclosure.

## Supported versions

Only the latest released version receives security fixes.

## Emergency measures for site administrators

- Switch the connector off: *Site administration → Plugins → Local plugins → Pluginia Connect →
  Settings → Enable the connector*.
- Revoke every connection and switch it off from the command line:
  `php local/pluginiaconnect/cli/revoke_all.php --yes --disable`
- Cut the gateway off only: switch off *Allow the Pluginia Connect gateway*.
