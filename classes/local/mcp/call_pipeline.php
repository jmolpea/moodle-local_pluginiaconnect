<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_pluginiaconnect\local\mcp;

use local_pluginiaconnect\local\audit\audit_exception;
use local_pluginiaconnect\local\audit\logger;
use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\guardrail\action_store;
use local_pluginiaconnect\local\guardrail\approval_service;
use local_pluginiaconnect\local\guardrail\confirmation_service;
use local_pluginiaconnect\local\guardrail\exposure_budget;
use local_pluginiaconnect\local\guardrail\idempotency_store;
use local_pluginiaconnect\local\guardrail\output_filter;
use local_pluginiaconnect\local\guardrail\rate_limiter;
use local_pluginiaconnect\local\oauth\grant_context;
use local_pluginiaconnect\local\oauth\token_service;
use local_pluginiaconnect\local\policy\decision;
use local_pluginiaconnect\local\policy\eligibility;
use local_pluginiaconnect\local\policy\site_policy;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\tool_exception;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\write_tool;
use local_pluginiaconnect\local\user_session;

/**
 * The chain every tools/call goes through.
 *
 * Each step can deny. The first one that fails ends the chain, is recorded in
 * the audit trail and becomes a tool error. A failure inside a security check
 * denies the call: the chain fails closed.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class call_pipeline {
    /**
     * Runs one tool call for an authenticated grant whose user is already the current user.
     *
     * @param grant_context $grant Grant behind the token.
     * @param \stdClass $user User installed by user_session::load().
     * @param string $toolname Tool name sent by the client.
     * @param mixed $args Decoded arguments sent by the client.
     * @param string $requestid Request identifier.
     * @param string|null $ip Remote address, for the audit trail.
     * @return pipeline_result
     */
    public static function run(
        grant_context $grant,
        \stdClass $user,
        string $toolname,
        $args,
        string $requestid,
        ?string $ip = null
    ): pipeline_result {
        return self::process($grant, $user, $toolname, $args, $requestid, $ip, null);
    }

    /**
     * Applies a write the user has just approved on the site.
     *
     * The whole chain runs again from scratch — site switches, tool matrix, profile,
     * scope, context, live eligibility and capabilities — so a permission lost since
     * the assistant asked stops the change. Only the approval step itself is skipped,
     * and the call is not counted against the limits again.
     *
     * @param grant_context $grant Grant the action belongs to.
     * @param \stdClass $user Session user, who is the user of the grant.
     * @param \stdClass $action Pending action being approved. Its lock is held by the caller.
     * @param array $args Arguments stored with the action.
     * @param string $requestid Request identifier.
     * @param string|null $ip Remote address, for the audit trail.
     * @return pipeline_result
     */
    public static function run_approved(
        grant_context $grant,
        \stdClass $user,
        \stdClass $action,
        array $args,
        string $requestid,
        ?string $ip = null
    ): pipeline_result {
        return self::process($grant, $user, (string) $action->tool, $args, $requestid, $ip, $action);
    }

    /**
     * Description of a write as it would be applied now, after every permission check. Changes nothing.
     *
     * @param grant_context $grant Grant the change belongs to.
     * @param \stdClass $user User the change would run as. Must be the current user.
     * @param string $toolname Tool name.
     * @param array $args Arguments of the change, without control arguments.
     * @return string
     * @throws tool_exception When the change is not permitted or not possible.
     */
    public static function preview(grant_context $grant, \stdClass $user, string $toolname, array $args): string {
        global $PAGE, $COURSE;
        $savedpage = $PAGE;
        $savedcourse = $COURSE;
        try {
            $class = tool_registry::get($toolname);
            if ($class === null || !$class::requirement()->write) {
                throw new tool_exception('tool_disabled', 'This action does not exist.');
            }
            $decision = decision::before_context($grant, $user, $toolname);
            if (!$decision->allowed) {
                throw new tool_exception($decision->reason, $decision->message);
            }
            $definition = $class::definition();
            $problem = schema_validator::validate($args, $definition->properties, $definition->required);
            if ($problem !== null) {
                throw new tool_exception('validation_error', $problem);
            }
            $PAGE = new \moodle_page();
            $tool = new $class();
            $context = $tool->resolve_context($args);
            $decision = decision::in_context($grant, $user, $toolname, $context);
            if (!$decision->allowed) {
                throw new tool_exception($decision->reason, $decision->message);
            }
            self::login($context);
            return (string) $tool->preview($args, new call_context($grant, $user, $context, ''));
        } catch (tool_exception $e) {
            throw $e;
        } catch (\Throwable $e) {
            $mapped = $e instanceof \moodle_exception ? user_session::from_login_exception($e) : null;
            throw $mapped ?? new tool_exception('permission_denied', 'This change can no longer be made.');
        } finally {
            $PAGE = $savedpage;
            $COURSE = $savedcourse;
        }
    }

    /**
     * The chain itself, shared by calls from the assistant and by approved actions.
     *
     * @param grant_context $grant Grant behind the token.
     * @param \stdClass $user User the call runs as.
     * @param string $toolname Tool name.
     * @param mixed $args Decoded arguments.
     * @param string $requestid Request identifier.
     * @param string|null $ip Remote address, for the audit trail.
     * @param \stdClass|null $approved Action the user approved on the site, when this is its execution.
     * @return pipeline_result
     */
    private static function process(
        grant_context $grant,
        \stdClass $user,
        string $toolname,
        $args,
        string $requestid,
        ?string $ip,
        ?\stdClass $approved
    ): pipeline_result {
        global $DB, $PAGE, $COURSE;

        $start = microtime(true);
        $class = tool_registry::get($toolname);
        $iswrite = $class !== null && $class::requirement()->write;
        // The audit trail gets a keyed fingerprint; changes are matched by the plain hash of their arguments.
        $argshash = logger::audit_hash($args);
        $context = null;
        $details = ['ip' => $ip];
        $savedpage = $PAGE;
        $savedcourse = $COURSE;

        $transaction = null;
        $lock = null;
        $budgetlock = null;
        $confirmed = null;
        $idempotencykey = null;
        $changehash = logger::args_hash($args);

        try {
            // Step 11, applied first: every authenticated call uses up the limits of the connection,
            // denied calls included, so that probing cannot go on without bound. An approved action
            // was already counted when the assistant requested it.
            $retryafter = $approved ? null : self::over_limit($grant, $iswrite);
            if ($retryafter !== null) {
                $details['retryafter'] = $retryafter;
                throw new tool_exception('rate_limited', "Too many requests. Try again in {$retryafter} seconds.");
            }

            // A user who may no longer use the connector at all loses the connection here and now.
            if (eligibility::access_denial($user) !== null) {
                token_service::revoke_grant($grant->grantid, 'system');
                throw new tool_exception('permission_denied', 'Your account may no longer use the connector on this site.');
            }

            // Step 3: the tool exists in the static registry.
            if ($class === null) {
                self::audit($grant, $requestid, $toolname, false, $argshash, 'denied', 'unknown_tool', null, $details, $start);
                $envelope = result_envelope::error('not_found', 'Unknown tool.', $grant->profile, $requestid);
                return new pipeline_result($envelope, true, true);
            }
            $requirement = $class::requirement();
            $definition = $class::definition();

            // Steps 1 and 4 to 6: site switches, site policy, profile and scope.
            $decision = decision::before_context($grant, $user, $toolname);
            if (!$decision->allowed) {
                throw new tool_exception($decision->reason, $decision->message);
            }

            // Step 2: the user has nothing pending on the site (password change, policies).
            $pending = user_session::pending_action($user);
            if ($pending !== null) {
                throw $pending;
            }

            // Step 7: strict validation of the arguments against the input schema.
            $problem = schema_validator::validate($args, $definition->properties, $definition->required);
            if ($problem !== null) {
                throw new tool_exception('validation_error', $problem);
            }

            // Step 8: the Moodle context the call acts on.
            $PAGE = new \moodle_page();
            $tool = new $class();
            $context = $tool->resolve_context($args);

            // Steps 8 to 10: site scope, live eligibility and Moodle capabilities.
            $decision = decision::in_context($grant, $user, $toolname, $context);
            if (!$decision->allowed) {
                throw new tool_exception($decision->reason, $decision->message);
            }
            self::login($context);

            $call = new call_context($grant, $user, $context, $requestid);
            if ($iswrite) {
                // The change is identified by its own arguments; the control arguments steer the call.
                $controls = is_array($args) ? array_intersect_key($args, array_flip(write_tool::CONTROL_ARGUMENTS)) : [];
                $args = write_tool::change_args($args);
                $changehash = logger::args_hash($args);
            }
            if ($iswrite && !$approved) {
                // Step 12: a repeated call with the same idempotency key gets the stored answer back.
                $idempotencykey = $controls['idempotency_key'] ?? null;
                if ($idempotencykey !== null) {
                    $stored = idempotency_store::find($grant->grantid, $toolname, $idempotencykey, $changehash);
                    if ($stored !== null) {
                        return self::replay($grant, $requestid, $toolname, $argshash, $stored, $context, $details, $start);
                    }
                }

                // Step 13: no write runs on its first call. The effective mode is the strictest of the
                // catalogue minimum, the administrator's choice and the floor of the assistant.
                $mode = site_policy::approval_mode($toolname, $grant->actorid);
                if ($mode === tool_requirement::APPROVAL_APPROVAL) {
                    $summary = self::summary($grant, $toolname, $tool, $args, $call, $details);
                    $action = approval_service::request($grant, $user, $toolname, $args, $changehash, $summary);
                    $envelope = result_envelope::pending('approval_pending', [
                        'summary' => $summary,
                        'action_id' => (int) $action->id,
                        'approval_url' => approval_service::url((int) $action->id)->out(false),
                        'expires_in' => action_store::APPROVAL_TTL,
                    ], $grant->profile, $requestid);
                    return self::pending(
                        $grant,
                        $requestid,
                        $toolname,
                        $argshash,
                        $envelope,
                        $action,
                        $context,
                        $details,
                        $start,
                        $idempotencykey,
                        $changehash
                    );
                }
                if ($mode === tool_requirement::APPROVAL_CONFIRM) {
                    $token = $controls['confirm_token'] ?? null;
                    $summary = null;
                    if ($token !== null) {
                        $redeemed = confirmation_service::redeem($token, $grant, $toolname, $changehash);
                        $lock = $redeemed['lock'];
                        if ($redeemed['stored'] !== null) {
                            return self::replay(
                                $grant,
                                $requestid,
                                $toolname,
                                $argshash,
                                $redeemed['stored'],
                                $context,
                                $details,
                                $start
                            );
                        }
                        // What is confirmed is what was summarised: if the data moved since the first call
                        // (another grade, other recipients, another state), nothing is applied, the old
                        // token dies and the caller gets the current summary with a new token.
                        $current = (string) $tool->preview($args, $call);
                        if (hash_equals(hash('sha256', (string) $redeemed['action']->summary), hash('sha256', $current))) {
                            $confirmed = $redeemed['action'];
                        } else {
                            action_store::resolve((int) $redeemed['action']->id, 'expired');
                            $summary = $current;
                        }
                    }
                    if ($confirmed === null) {
                        $summary = $summary ?? self::summary($grant, $toolname, $tool, $args, $call, $details);
                        [$token, $action] = confirmation_service::request($grant, $toolname, $args, $changehash, $summary);
                        $envelope = result_envelope::pending('confirmation_required', [
                            'summary' => $summary,
                            'confirm_token' => $token,
                            'expires_in' => action_store::CONFIRM_TTL,
                        ], $grant->profile, $requestid);
                        return self::pending(
                            $grant,
                            $requestid,
                            $toolname,
                            $argshash,
                            $envelope,
                            $action,
                            $context,
                            $details,
                            $start,
                            null,
                            $changehash
                        );
                    }
                }
            }

            // Step 14: execution as the current user. A write tool runs in one transaction with its
            // audit row and the state of its action: if any of them cannot be written, the change is
            // rolled back.
            if ($iswrite) {
                $transaction = $DB->start_delegated_transaction();
            }

            // Step 11, second part: a tool that returns records about other people is refused once the
            // connection has received its hourly budget of such records.
            // The check, the execution and the charge happen under one lock per connection: calls sent at
            // the same time take turns, so they cannot all pass the check before any of them is charged.
            $exposes = method_exists($class, 'returns_third_party_data') && $class::returns_third_party_data();
            if ($exposes) {
                $budgetlock = self::budget_lock($grant, $details);
            }
            if ($exposes && exposure_budget::exhausted($grant->grantid)) {
                $details['retryafter'] = exposure_budget::retry_after();
                throw new tool_exception(
                    'rate_limited',
                    'This connection has reached its hourly limit of records about other people. Try again in '
                        . $details['retryafter'] . ' seconds.'
                );
            }
            $result = $tool->execute($args, $call);
            if ($result->thirdparty > 0 && exposure_budget::add($grant->grantid, $result->thirdparty)) {
                self::budget_reached($grant, $toolname);
            }
            if ($budgetlock) {
                $budgetlock->release();
                $budgetlock = null;
            }

            // Step 15: closed field list and size limits. The fields are those of the profile the user
            // really holds in this context, never above the profile of the connection.
            $fieldprofile = eligibility::effective_profile((int) $user->id, $grant->profile, $context)
                ?? $requirement->profile;
            [$data, $truncated] = output_filter::apply($result->data, $class::output_fields($fieldprofile));

            // Step 16: audit trail and event, written before anything is returned. If the row cannot
            // be written the call fails and returns no data.
            $details['itemcount'] = $result->itemcount;
            $details['objecttype'] = $result->objecttype;
            $details['objectid'] = $result->objectid;
            $envelope = result_envelope::success($data, $grant->profile, $truncated, $result->nextcursor, $requestid);
            $resolved = $approved ?? $confirmed;
            if ($resolved) {
                // The action is closed in the same transaction as the change: applied means recorded as applied.
                action_store::resolve((int) $resolved->id, 'executed', $envelope);
            }
            if ($idempotencykey !== null) {
                idempotency_store::save($grant->grantid, $toolname, $idempotencykey, $changehash, $envelope);
            }
            self::audit($grant, $requestid, $toolname, $iswrite, $argshash, 'ok', null, $context, $details, $start);
            if ($transaction) {
                $transaction->allow_commit();
                $transaction = null;
            }
            if ($confirmed) {
                approval_service::event('action_approved', $confirmed);
            }
            return new pipeline_result($envelope, false);
        } catch (audit_exception $e) {
            self::rollback($transaction);
            return self::unaudited($grant, $requestid);
        } catch (tool_exception $e) {
            self::rollback($transaction);
            return self::fail($grant, $requestid, $toolname, $iswrite, $argshash, $e, $context, $details, $start, $class);
        } catch (\required_capability_exception $e) {
            $error = new tool_exception('permission_denied', 'You do not have permission to do this here.');
            self::rollback($transaction);
            return self::fail($grant, $requestid, $toolname, $iswrite, $argshash, $error, $context, $details, $start, $class);
        } catch (\moodle_exception $e) {
            $error = user_session::from_login_exception($e) ?? self::from_core_exception($e, $requestid);
            self::rollback($transaction);
            return self::fail($grant, $requestid, $toolname, $iswrite, $argshash, $error, $context, $details, $start, $class);
        } catch (\Throwable $e) {
            // Only the exception class is logged: messages may contain user content.
            // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
            error_log('local_pluginiaconnect: request ' . $requestid . ' failed with ' . get_class($e));
            $error = new tool_exception(
                'internal_error',
                "Unexpected error. Quote request ID {$requestid} to your administrator."
            );
            self::rollback($transaction);
            return self::fail($grant, $requestid, $toolname, $iswrite, $argshash, $error, $context, $details, $start, $class);
        } finally {
            if ($budgetlock) {
                $budgetlock->release();
            }
            if ($lock) {
                $lock->release();
            }
            $PAGE = $savedpage;
            $COURSE = $savedcourse;
        }
    }

    /**
     * Takes the lock that puts in a row the calls of a connection that return records about other people.
     *
     * @param grant_context $grant Grant behind the token.
     * @param array $details Audit details, completed when the call is refused.
     * @return \core\lock\lock
     * @throws tool_exception rate_limited when another call of the connection holds the lock for too long.
     */
    private static function budget_lock(grant_context $grant, array &$details): \core\lock\lock {
        $lock = exposure_budget::lock($grant->grantid);
        if ($lock === null) {
            $details['retryafter'] = 5;
            throw new tool_exception(
                'rate_limited',
                'Another request of this connection is still reading records about other people. Try again in 5 seconds.'
            );
        }
        return $lock;
    }

    /**
     * Answer of a write that was not applied: it waits for a token or for approval. Audited before it is returned.
     *
     * @param grant_context $grant Grant behind the token.
     * @param string $requestid Request identifier.
     * @param string $toolname Tool name.
     * @param string $argshash Hash of the arguments of the call.
     * @param array $envelope Envelope to return.
     * @param \stdClass $action Pending action just created.
     * @param \context $context Context of the change.
     * @param array $details Audit details.
     * @param float $start Start time.
     * @param string|null $idempotencykey Key to remember the answer under, if the caller sent one.
     * @param string $changehash Hash of the arguments of the change.
     * @return pipeline_result
     */
    private static function pending(
        grant_context $grant,
        string $requestid,
        string $toolname,
        string $argshash,
        array $envelope,
        \stdClass $action,
        \context $context,
        array $details,
        float $start,
        ?string $idempotencykey,
        string $changehash
    ): pipeline_result {
        $details['objecttype'] = 'action';
        $details['objectid'] = (int) $action->id;
        if ($idempotencykey !== null) {
            idempotency_store::save($grant->grantid, $toolname, $idempotencykey, $changehash, $envelope);
        }
        self::audit(
            $grant,
            $requestid,
            $toolname,
            true,
            $argshash,
            (string) $envelope['status'],
            null,
            $context,
            $details,
            $start
        );
        approval_service::event('action_confirmation_requested', $action);
        return new pipeline_result($envelope, false);
    }

    /**
     * Answer of a repeated write: the stored envelope, with nothing applied again. Audited as a replay.
     *
     * @param grant_context $grant Grant behind the token.
     * @param string $requestid Request identifier.
     * @param string $toolname Tool name.
     * @param string $argshash Hash of the arguments of the call.
     * @param array $envelope Stored envelope.
     * @param \context $context Context of the change.
     * @param array $details Audit details.
     * @param float $start Start time.
     * @return pipeline_result
     */
    private static function replay(
        grant_context $grant,
        string $requestid,
        string $toolname,
        string $argshash,
        array $envelope,
        \context $context,
        array $details,
        float $start
    ): pipeline_result {
        self::audit($grant, $requestid, $toolname, true, $argshash, 'ok', 'replayed', $context, $details, $start);
        return new pipeline_result($envelope, ($envelope['status'] ?? '') === 'error');
    }

    /**
     * Summary of a change for the caller. The people it names count against the hourly budget
     * of records about other people, so previews cannot be used to look names up without bound.
     *
     * @param grant_context $grant Grant behind the token.
     * @param string $toolname Tool name.
     * @param base_tool $tool Tool instance.
     * @param array $args Arguments of the change.
     * @param call_context $call Call context.
     * @param array $details Audit details, completed when the call is refused.
     * @return string
     * @throws tool_exception rate_limited when the budget is used up.
     */
    private static function summary(
        grant_context $grant,
        string $toolname,
        base_tool $tool,
        array $args,
        call_context $call,
        array &$details
    ): string {
        $named = $tool instanceof write_tool ? $tool::preview_exposure($args) : 0;
        if ($named <= 0) {
            return (string) $tool->preview($args, $call);
        }
        // Check and charge under one lock per connection, like the result of a read tool.
        $budgetlock = self::budget_lock($grant, $details);
        try {
            if (exposure_budget::exhausted($grant->grantid)) {
                $details['retryafter'] = exposure_budget::retry_after();
                throw new tool_exception(
                    'rate_limited',
                    'This connection has reached its hourly limit of records about other people. Try again in '
                        . $details['retryafter'] . ' seconds.'
                );
            }
            $summary = (string) $tool->preview($args, $call);
            if (exposure_budget::add($grant->grantid, $named)) {
                self::budget_reached($grant, $toolname);
            }
            return $summary;
        } finally {
            $budgetlock->release();
        }
    }

    /**
     * Records that a connection used up its hourly budget of records about other people.
     *
     * @param grant_context $grant Grant behind the access token.
     * @param string $toolname Tool whose result reached the budget.
     * @return void
     */
    private static function budget_reached(grant_context $grant, string $toolname): void {
        \local_pluginiaconnect\event\exposure_budget_exceeded::create([
            'context' => \context_system::instance(),
            'objectid' => $grant->grantid,
            'relateduserid' => $grant->userid,
            'other' => ['tool' => $toolname],
        ])->trigger();
    }

    /**
     * Discards the transaction of a write tool that did not complete.
     *
     * @param \moodle_transaction|null $transaction Open transaction, if any.
     * @return void
     */
    private static function rollback(?\moodle_transaction $transaction): void {
        global $DB;
        if ($transaction && !$transaction->is_disposed()) {
            try {
                $DB->force_transaction_rollback();
            } catch (\Throwable $e) {
                // Nothing else can be done here; the transaction is discarded with the request.
                return;
            }
        }
    }

    /**
     * Result of a call whose audit row could not be written: an error and no data.
     *
     * @param grant_context $grant Grant behind the token.
     * @param string $requestid Request identifier.
     * @return pipeline_result
     */
    private static function unaudited(grant_context $grant, string $requestid): pipeline_result {
        // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
        error_log('local_pluginiaconnect: request ' . $requestid . ' could not be audited and was refused');
        $envelope = result_envelope::error(
            'internal_error',
            "Unexpected error. Quote request ID {$requestid} to your administrator.",
            $grant->profile,
            $requestid
        );
        return new pipeline_result($envelope, true);
    }

    /**
     * Maps an exception thrown by a core API during execution.
     *
     * Core refuses access with several error codes; all of them mean the user
     * lacks permission. Anything else is an unexpected failure.
     *
     * @param \moodle_exception $exception Exception thrown by core.
     * @param string $requestid Request identifier.
     * @return tool_exception
     */
    private static function from_core_exception(\moodle_exception $exception, string $requestid): tool_exception {
        $denied = ['nopermissions', 'nopermissiontoviewgrades', 'requireloginerror', 'usernotincourse', 'notingroup',
            'accessdenied', 'cannotviewreport', 'nopermissiontoshow', 'coursehidden', 'errorinvalidcontext',
            'guestsarenotallowed', 'notenrolledprofile', 'cannotviewprofile'];
        if (
            $exception instanceof \required_capability_exception
            || $exception instanceof \require_login_exception
            || in_array($exception->errorcode, $denied, true)
        ) {
            return new tool_exception('permission_denied', 'You do not have permission to do this here.');
        }
        // Only the error code is logged: messages may contain user content.
        // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
        error_log('local_pluginiaconnect: request ' . $requestid . ' failed with core error ' . $exception->errorcode);
        return new tool_exception('internal_error', "Unexpected error. Quote request ID {$requestid} to your administrator.");
    }

    /**
     * Turns a denial into an audited tool error.
     *
     * @param grant_context $grant Grant behind the token.
     * @param string $requestid Request identifier.
     * @param string $toolname Tool name.
     * @param bool $iswrite Whether the tool changes anything.
     * @param string $argshash Hash of the arguments.
     * @param tool_exception $error The denial.
     * @param \context|null $context Context, when it was resolved.
     * @param array $details Audit details.
     * @param float $start Start time.
     * @param string|null $class Tool class.
     * @return pipeline_result
     */
    private static function fail(
        grant_context $grant,
        string $requestid,
        string $toolname,
        bool $iswrite,
        string $argshash,
        tool_exception $error,
        ?\context $context,
        array $details,
        float $start,
        ?string $class
    ): pipeline_result {
        $code = $error->error_code();
        $outcome = $code === 'rate_limited' ? 'rate_limited' : ($code === 'internal_error' ? 'error' : 'denied');
        try {
            self::audit($grant, $requestid, $toolname, $iswrite, $argshash, $outcome, $code, $context, $details, $start);
        } catch (audit_exception $e) {
            return self::unaudited($grant, $requestid);
        }
        $retryafter = $code === 'rate_limited' ? ($details['retryafter'] ?? 60) : null;
        $envelope = result_envelope::error($code, $error->getMessage(), $grant->profile, $requestid, $retryafter);
        $missingscope = $code === 'scope_missing' && $class !== null ? $class::requirement()->scope : null;
        return new pipeline_result($envelope, true, false, $missingscope);
    }

    /**
     * Writes the audit row of a call.
     *
     * @param grant_context $grant Grant behind the token.
     * @param string $requestid Request identifier.
     * @param string $toolname Tool name.
     * @param bool $iswrite Whether the tool changes anything.
     * @param string $argshash Hash of the arguments.
     * @param string $outcome Outcome.
     * @param string|null $reason Error code.
     * @param \context|null $context Context.
     * @param array $details Audit details.
     * @param float $start Start time.
     * @return void
     */
    private static function audit(
        grant_context $grant,
        string $requestid,
        string $toolname,
        bool $iswrite,
        string $argshash,
        string $outcome,
        ?string $reason,
        ?\context $context,
        array $details,
        float $start
    ): void {
        $details['durationms'] = (int) round((microtime(true) - $start) * 1000);
        logger::log($grant, $requestid, $toolname, $iswrite, $argshash, $outcome, $reason, $context, $details);
    }

    /**
     * Step 10: enters the course or activity as the current user, with redirects disabled.
     *
     * @param \context $context Context resolved from the arguments.
     * @return void
     * @throws tool_exception
     */
    private static function login(\context $context): void {
        $course = null;
        $cm = null;
        $coursecontext = $context->get_course_context(false);
        if ($coursecontext && $coursecontext->instanceid != SITEID) {
            $course = get_course((int) $coursecontext->instanceid);
            if ($context->contextlevel == CONTEXT_MODULE) {
                $cm = get_fast_modinfo($course)->get_cm((int) $context->instanceid);
            }
        }
        try {
            require_login($course, false, $cm, false, true);
        } catch (\Throwable $e) {
            $mapped = user_session::from_login_exception($e);
            if ($mapped !== null) {
                throw $mapped;
            }
            if ($course !== null) {
                // Only a call that named the course gets its id back. For an activity the answer is the one
                // given for an identifier that does not exist.
                throw $context->contextlevel == CONTEXT_COURSE
                    ? base_tool::course_not_found((int) $course->id)
                    : new tool_exception('not_found', base_tool::ITEM_NOT_FOUND);
            }
            throw new tool_exception('permission_denied', 'You do not have permission to do this here.');
        }
    }

    /**
     * Step 11: counts the call against the limits of the grant.
     *
     * Reads and writes are both counted under a lock, so calls sent at the same
     * time are counted one by one, and are refused when the counter cannot be updated.
     *
     * @param grant_context $grant Grant behind the token.
     * @param bool $iswrite Whether the tool changes anything.
     * @return int|null Seconds to wait when a limit is exceeded; null when within limits.
     */
    private static function over_limit(grant_context $grant, bool $iswrite): ?int {
        $kind = $iswrite ? 'write' : 'read';
        $subject = (string) $grant->grantid;
        if (!rate_limiter::hit("{$kind}_minute", $subject, config::int("rate{$kind}minute"), MINSECS, true)) {
            return rate_limiter::retry_after(MINSECS);
        }
        if (!rate_limiter::hit("{$kind}_day", $subject, config::int("rate{$kind}day"), DAYSECS, true)) {
            return rate_limiter::retry_after(DAYSECS);
        }
        return null;
    }
}
