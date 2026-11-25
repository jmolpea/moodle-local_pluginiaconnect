# Changes

## 0.3.0-dev (unreleased, first part)

Alpha. Not for production sites. Amendment 2 of the specification, block C and the extension point.

- Fifteen new actions, all switched off on new and upgraded sites until the administrator enables them in
  "Actions by profile", reads included: course activity log (31 days at most, never addresses or anonymous
  events), activity participation, completion per activity, quiz attempts (no questions or answers), course
  progress summary (figures only), overdue submissions (with overrides and extensions), forums (list, read
  discussions, participation summary), site activity (sign-ins, course views and active users, counts only),
  failed tasks (redacted output excerpt, withheld when it looks like a secret) and site checks; and two
  changes: start a forum discussion and reply to a forum post, always as the connected user, confirmed in two
  steps, at most "Forum posts per day" per connection. Separate groups apply to every report.
- The 22 read actions of version 1.0 keep their default (on); every action added later starts off.
- Five new scopes (reports, forums, extensions) granted by area: the consent page shows "Additional areas"
  with one box each, empty unless the user already granted it to that application.
- Extension point for whitelisted Pluginia plugins (`db/pluginiaconnect.php`, classes in
  `classes/pluginiaconnect/`): fixed whitelist with a reserved name infix per plugin, minimum version,
  validation of every entry against the permanent prohibitions (no deletion, no acting as another user, no
  secrets, prompts, licences, models or costs, closed values only for writes), and a catalogue file per
  plugin (`cli/export_catalog.php --component=...`). Refused entries are listed on the Diagnostics page.
  First plugin: Pluginia Analytics 1.2.0.
- New limits "Forum posts per day" (20) and "Days per log query" (31).
- Tests: static analysis of the code of every tool and extension for forbidden calls, the prohibitions on
  the base catalogue and on every loaded extension, factory defaults, and the extension loader.

## 0.2.0 (unreleased)

Alpha. Not for production sites.

- Gateway registration: the licence key is optional. For now the Pluginia Connect gateway is free and
  needs no key, so the administrator leaves the field empty; a key typed anyway is still checked for
  length and sent. Everything else is unchanged: the declarations, the proof that the site controls its
  address and the removal from the gateway.
- New setting "Permissions an assistant asks for when it connects" (Sign-in and tokens). By default the
  site now advertises, in the 401 challenge and in `scopes_supported` of its metadata, every scope with at
  least one action switched on for an allowed profile (no scope that changes data in read-only mode), not
  only the four basic read scopes. Claude asks for exactly the scopes of the challenge and never for more,
  so until now the consent page only offered "Read only" and the actions of teachers, managers and
  administrators were out of reach. The consent page still cuts each connection down to the chosen profile
  and access level. "Basic reading only" keeps the previous behaviour. The metadata documents are no longer
  cached for five minutes. Users connected before the change disconnect and connect the assistant again.
- The nine actions that change something: save assignment grade, post course announcement, send message to
  participants, set activity visibility, update assignment dates, enrol users in course, set enrolment
  status, create course and set user suspension. All of them are switched off after installing or upgrading:
  the administrator enables each one per profile on the "Actions by profile" page.
- "Unenrol users from course" replaced by "Set enrolment status" before any release: it suspends or
  reactivates the manual enrolment of named users and nothing is deleted. The enrolment, the roles, the
  groups, the grades and the submissions of a suspended user are kept, and the answer gives the previous and
  the new status of each user. It needs the capability to manage manual enrolments and the user's approval
  on the site, and never acts on the enrolment of the connected user. Upgrading clears what a site had
  stored for the old action; the new one arrives switched off.
- No action of the catalogue deletes anything or acts in the name of another user. Tests now fail if an
  action is added whose name says it removes something or that takes an author or sender.
- No change is applied on its first call. Depending on the action and on the site, a change needs a
  confirmation token (two-step confirmation) or the user's approval on a page of the site.
- New page: approval of a change requested by an assistant, linked from "My AI connections" and from a
  notification the user can switch off. The change is authorised again when it is approved.
- Changes requested by an assistant that can run unattended always need approval on the site.
- Idempotency keys for the actions that change something, at most five changes waiting per connection, and a
  daily number of message recipients per connection.
- "Get pending action status" also returns the outcome of an approved change.
- "List submissions to grade" says when a very large course was only partly examined (`scan_incomplete`).
- Fix: announcements and grade feedback are now cleaned of hidden text from the stored text. They were
  cleaned from text the site had already filtered, which no longer carried the styles that mark hidden text.
- Fix: on Moodle 5.2, with marker allocation, the assignment actions failed with an internal error for a
  marker (5.2 keeps allocations in their own table but without optional markers, which came in 5.3).
- Catalogue: 31 actions.
- Second security review (details in the gateway repository, `docs/reviews/security-review-phase3.md`):
  - The assistant named when a connection is made through the gateway is now confirmed by the gateway at the
    token endpoint (`pc_actor_id`). A value dropped or changed in the browser no longer becomes a connection.
  - Assignments: the group mode of the activity, not of the course, decides whose submissions a teacher sees,
    and marker allocation now limits the reading actions as well as grading, on every supported version.
  - "Save assignment grade" no longer touches the marking workflow state, shows the feedback text in full
    before it is saved, and refuses a locked, overridden or read-only grade instead of reporting success.
    Saving a grade without feedback no longer raises a warning when feedback comments are enabled.
  - Course summaries returned by "Search courses" are cleaned of hidden text from the stored text. A text
    that carries its own style sheet is not returned: a note says to read it on the site.
  - A confirmation token applies the change that was summarised: if the data changed meanwhile, a new
    summary and token are returned and nothing is applied.
  - "Enrol users in course" refuses users whose manual enrolment is suspended or limited in time, and the
    summaries of enrolments, enrolment changes and messages show the user id next to each name.
  - The redirect address shared by all Microsoft 365 Copilot plugins is refused in direct mode whatever the
    list of allowed addresses says.
  - Smaller fixes: stealth activities in course progress, row order under anonymous marking, names in
    summaries counted against the hourly limit of records about other people, error messages that named the
    course of an activity, date order against "Remind me to grade by".
- Verification pass of that review:
  - Hidden-text removal: a style sheet anywhere on the course page, or in any summary of a search result,
    withholds every text of that page; repeated style declarations hide the text when any of them would;
    images, ruby fallbacks, meters and other elements that show no text are dropped; more hiding classes of
    the theme are recognised; smaller thresholds for tiny, faint, clipped and squeezed text. A text in
    which a moved element could cover the rest is withheld as a whole, with a note to read it on the site.
  - Assignment instructions and online-text submissions go through the site filters before cleaning, so a
    multi-language text gives only the reader's language.
  - A hidden activity, an activity of another course and an identifier that does not exist give the same
    answer. Participant numbers under anonymous marking are handed out in random order.
  - The refusal of an enrolment that would be reactivated gives user ids only.
  - Privacy metadata declares every stored field.
- Third security review and local penetration test (details in the gateway repository,
  `docs/reviews/security-review-phase5.md` and `docs/reviews/pentest-local.md`):
  - Token endpoint: only failed client authentications count towards the temporary block of a client
    and address. Refused codes and refresh tokens no longer do, so requests made on behalf of third
    parties cannot stop the token exchange of a site.
  - Every limit is counted under a lock, the ones of the reading actions and the one per address
    included, and calls that return records about other people take turns per connection, so the
    limits and the hourly budget hold when calls are sent at the same time.
  - "List course participants" names only the roles the user may view or assign in the course, and
    shows group membership as the visibility setting of each group says for that user.
  - Hidden-text removal: font size and opacity are reckoned along the whole chain of enclosing
    elements; background images, blend modes, percentage margins and margins that cannot be measured
    hide the text they apply to; more hiding classes of YUI, of the Moodle dialogues and of
    Bootstrap 5 are recognised, and clipping classes count as the overflow style.
  - A call that lacks a scope is answered with HTTP 403 and the `insufficient_scope` challenge. It
    was sent as 401.
  - "Post course announcement" refuses a subject with markup when the change is requested, with a
    message that says what to correct, instead of failing when it is confirmed.
  - Catalogue: every object of the output schemas is closed (`additionalProperties: false`), the
    states, error codes and profiles are enumerated, and links must be https addresses (or
    `http://localhost` on a development site).
  - Editing the permissions of a role, or editing or deleting a role, drops the cached profiles of
    every user at once.
  - The fingerprint of the request kept in the audit record is keyed with a secret of the site.
    Earlier rows keep the fingerprint they were stored with.
  - The consent page offers "Read and changes" only when at least one action that changes something
    is switched on for a profile the user can choose.
  - The settings page links to the configuration manual.
- Fix: applying filters on the audit report raised an error (the date fields of the form clashed with the
  parameters of the address). Found by the Behat run.
- Tested as well on MySQL 8.4 and MariaDB 11.4 (Moodle 5.3), with Behat on both supported versions, and
  through install, upgrade, uninstall and reinstall on a throwaway site.

## 0.1.0 (unreleased)

First alpha. Not for production sites.

- OAuth 2.1 authorization server inside the plugin: authorization code flow with PKCE (S256),
  consent page, token endpoint with refresh token rotation, client registration and revocation.
- MCP endpoint over Streamable HTTP, stateless, tools only.
- Profiles (student, teacher, manager, administrator), scopes and site policy. Every call is
  checked against the user's real capabilities.
- Audit record of every call, without request details or returned content.
- Settings, capabilities, scheduled clean-up tasks, Privacy API provider.
- Diagnostics page.
- The 22 read-only tools of the catalogue. For any user: `get_connection_info`,
  `list_my_courses`, `search_courses`, `get_course_outline`, `list_upcoming_deadlines`,
  `get_assignment_details`, `list_course_announcements`, `get_my_grades`,
  `get_my_course_progress`, `get_pending_action_status`. For teachers: `list_course_participants`,
  `list_submissions_to_grade`, `get_submission`, `get_course_grade_summary`,
  `get_course_completion_report`, `list_inactive_participants`. For managers: `list_categories`,
  `get_course_enrolment_summary`, `find_users`. For administrators: `get_site_status`,
  `list_scheduled_tasks`, `list_installed_plugins`.
- Limit on how many records about other people a connection can read per hour, with an event
  when it is reached. Group mode, anonymous marking and the site rules on identity fields are
  respected by every tool that returns other people's data.
- No tool changes anything yet.
- Emergency command `cli/revoke_all.php`.
- "My AI connections" page in the user preferences: connected assistants, the last 50 calls made
  in the user's name, and disconnecting with confirmation. A notification is sent to the user
  when a new connection is created; it can be switched off in the notification preferences.
- Administrator pages: connections of the site (disconnect one, all of a user or all of an
  application), audit report with filters and CSV export, and the matrix of actions by profile.
- Registration of the site with the Pluginia Connect gateway and its removal, with a domain
  verification endpoint that only answers while a registration is in progress.

Changes after the independent security review of this version (no critical or high findings):

- Token and revocation endpoints: failed attempts are counted per client and address, so junk
  requests that name a client can no longer lock it out for everybody.
- The connect capability and the excluded cohorts are checked again on every call and on every
  token renewal; a user who may no longer connect loses the connection at once.
- Hidden-text removal rewritten: colour contrast against the inherited background, sizes in any
  unit, CSS escapes, off-screen positioning, clipping, transforms and the whole family of hiding
  classes. Anything the plugin does not understand is removed.
- A call whose audit record cannot be written returns an error and no data.
- Activities not shown on the course page are no longer listed to students; hidden grades no
  longer show when they were given.
- Every authenticated call counts against the limits, denied ones included; the MCP endpoint has a
  limit per address; the limits refuse calls when their counters are unavailable.
- Application metadata downloads are limited per address, failures are remembered for five
  minutes, the site proxy is never used for them and the size limit is enforced while downloading.
- Switching a registration method off, untrusting a domain or removing a redirect address stops
  the existing connections of those applications.
- The retry data of token renewal is cleared within a minute of its window.
- Output fields follow the profile the user really holds in the course, and the write capability
  is checked in the course a change is made in.

Not in this version: tools that change anything (the connector refuses every write), the rest of
the read-only tools, confirmation and approval of changes, and registration with the Pluginia
Connect gateway. The MCP endpoint speaks protocol revisions 2026-07-28, 2025-11-25 and 2025-06-18.
