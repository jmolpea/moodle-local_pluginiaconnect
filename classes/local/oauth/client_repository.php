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

namespace local_pluginiaconnect\local\oauth;

use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\urls;

/**
 * Storage and resolution of OAuth clients.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class client_repository {
    /** @var string Table name. */
    private const TABLE = 'local_pluginiaconnect_client';
    /** @var int Seconds between updates of the last used time. */
    private const TOUCH_INTERVAL = 60;

    /**
     * Resolves a client identifier.
     *
     * Registered clients (gateway, DCR, manual) come from the database. A URL
     * shaped identifier is a metadata document client: with $refresh the
     * document is validated again (from cache or network) and the stored copy
     * is updated; without it only a previously stored copy is returned, so the
     * token endpoint never depends on a third party being reachable.
     *
     * Callers still have to check {@see client::$active} and the connection mode.
     *
     * @param string $clientid Identifier received from the client.
     * @param bool $refresh Validate metadata document clients against their document.
     * @param string|null $ip Address of the caller, to limit the downloads it can cause.
     * @return client|null
     */
    public static function find(string $clientid, bool $refresh = false, ?string $ip = null): ?client {
        global $DB;
        if ($clientid === '' || strlen($clientid) > 2000) {
            return null;
        }
        $record = $DB->get_record(self::TABLE, ['clientidhash' => hash('sha256', $clientid)]);
        if ($record && !hash_equals((string) $record->clientid, $clientid)) {
            return null;
        }
        if ($record && ($record->type !== client::TYPE_CIMD || !$refresh || empty($record->status))) {
            return new client($record);
        }
        if (!$refresh || !cimd_fetcher::is_candidate($clientid)) {
            return null;
        }
        try {
            $document = cimd_fetcher::fetch($clientid, $ip);
        } catch (oauth_exception $e) {
            return null;
        }
        $now = time();
        if ($record) {
            $record->name = $document['name'];
            $record->redirecturis = json_encode($document['redirecturis'], JSON_UNESCAPED_SLASHES);
            $record->metadata = json_encode($document['metadata'], JSON_UNESCAPED_SLASHES);
            $record->timemodified = $now;
            $DB->update_record(self::TABLE, $record);
        } else {
            $record = (object) [
                'clientidhash' => hash('sha256', $clientid),
                'clientid' => $clientid,
                'type' => client::TYPE_CIMD,
                'name' => $document['name'],
                'redirecturis' => json_encode($document['redirecturis'], JSON_UNESCAPED_SLASHES),
                'authmethod' => client::AUTH_NONE,
                'secrethash' => null,
                'metadata' => json_encode($document['metadata'], JSON_UNESCAPED_SLASHES),
                'status' => 1,
                'timecreated' => $now,
                'timemodified' => $now,
                'timelastused' => 0,
            ];
            $record->id = $DB->insert_record(self::TABLE, $record);
        }
        return new client($record);
    }

    /**
     * Client by record id.
     *
     * @param int $id Record id.
     * @return client|null
     */
    public static function get_by_id(int $id): ?client {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['id' => $id]);
        return $record ? new client($record) : null;
    }

    /**
     * Registers a client dynamically (RFC 7591).
     *
     * @param string $name Client name, already cleaned.
     * @param string[] $redirecturis Redirect URIs, already checked against the redirect policy.
     * @param string $authmethod Token endpoint authentication method.
     * @param array $metadata Sanitised registration metadata to keep.
     * @return array [client, string|null client secret]. The secret is returned once and stored only as a hash.
     */
    public static function register_dcr(string $name, array $redirecturis, string $authmethod, array $metadata): array {
        global $DB;
        $clientid = 'pc_client_' . bin2hex(random_bytes(16));
        $secret = null;
        if ($authmethod !== client::AUTH_NONE) {
            $secret = token_service::random_token('pc_cs_');
        }
        $now = time();
        $record = (object) [
            'clientidhash' => hash('sha256', $clientid),
            'clientid' => $clientid,
            'type' => client::TYPE_DCR,
            'name' => $name,
            'redirecturis' => json_encode(array_values($redirecturis), JSON_UNESCAPED_SLASHES),
            'authmethod' => $authmethod,
            'secrethash' => $secret === null ? null : password_hash($secret, PASSWORD_DEFAULT),
            'metadata' => json_encode($metadata, JSON_UNESCAPED_SLASHES),
            'status' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'timelastused' => 0,
        ];
        $record->id = $DB->insert_record(self::TABLE, $record);
        return [new client($record), $secret];
    }

    /**
     * Creates or replaces the client of the Pluginia Connect gateway. Called when the site registers.
     *
     * @param string $secret Client secret of this site. Only its hash is stored.
     * @param string $authmethod client_secret_post or client_secret_basic.
     * @return client
     */
    public static function save_gateway_client(string $secret, string $authmethod = client::AUTH_POST): client {
        global $DB;
        $now = time();
        $hash = hash('sha256', urls::GATEWAY_CLIENT_ID);
        $record = $DB->get_record(self::TABLE, ['clientidhash' => $hash]) ?: (object) [
            'clientidhash' => $hash,
            'clientid' => urls::GATEWAY_CLIENT_ID,
            'timecreated' => $now,
            'timelastused' => 0,
        ];
        $record->type = client::TYPE_GATEWAY;
        $record->name = 'Pluginia Connect';
        $record->redirecturis = json_encode([urls::GATEWAY_REDIRECT], JSON_UNESCAPED_SLASHES);
        $record->authmethod = $authmethod === client::AUTH_BASIC ? client::AUTH_BASIC : client::AUTH_POST;
        $record->secrethash = password_hash($secret, PASSWORD_DEFAULT);
        $record->metadata = null;
        $record->status = 1;
        $record->timemodified = $now;
        if (empty($record->id)) {
            $record->id = $DB->insert_record(self::TABLE, $record);
        } else {
            $DB->update_record(self::TABLE, $record);
        }
        return new client($record);
    }

    /**
     * Records that a client was used, at most once per minute.
     *
     * @param client $client Client.
     * @return void
     */
    public static function touch(client $client): void {
        global $DB;
        $now = time();
        $DB->set_field_select(
            self::TABLE,
            'timelastused',
            $now,
            'id = :id AND timelastused < :threshold',
            ['id' => $client->id, 'threshold' => $now - self::TOUCH_INTERVAL]
        );
    }

    /**
     * Whether the site still accepts a client of this kind. Checked live at the token endpoint and
     * on every tool call, so switching a registration method off, untrusting a domain or removing a
     * redirect address takes effect on existing connections at once.
     *
     * @param client $client Client.
     * @return bool
     */
    public static function is_still_accepted(client $client): bool {
        if (!$client->active) {
            return false;
        }
        if ($client->type === client::TYPE_GATEWAY) {
            return config::bool('gatewayenabled');
        }
        if ($client->type === client::TYPE_CIMD) {
            if (!config::bool('allowcimd') || !cimd_fetcher::is_trusted_host($client->host())) {
                return false;
            }
        } else if ($client->type === client::TYPE_DCR && !config::bool('allowdcr')) {
            return false;
        }
        // At least one of its redirect addresses must still be accepted by the site.
        foreach ($client->redirecturis as $uri) {
            if (redirect_policy::site_allows($uri, $client)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Deletes metadata document clients that nobody used for a while and that hold no grants.
     *
     * @param int $olderthan Unix time; clients created and last used before it are removed.
     * @return void
     */
    public static function delete_stale_cimd(int $olderthan): void {
        self::delete_stale(client::TYPE_CIMD, $olderthan);
    }

    /**
     * Deletes dynamically registered clients that nobody used for a while and that hold no grants.
     *
     * @param int $olderthan Unix time; clients created and last used before it are removed.
     * @return void
     */
    public static function delete_stale_dcr(int $olderthan): void {
        self::delete_stale(client::TYPE_DCR, $olderthan);
    }

    /**
     * Deletes clients of one type that nobody used for a while and that hold no grants or codes.
     *
     * @param string $type Client type.
     * @param int $olderthan Unix time; clients created and last used before it are removed.
     * @return void
     */
    private static function delete_stale(string $type, int $olderthan): void {
        global $DB;
        $sql = "SELECT c.id
                  FROM {local_pluginiaconnect_client} c
                 WHERE c.type = :type AND c.timecreated < :created AND c.timelastused < :used
                       AND NOT EXISTS (SELECT 1 FROM {local_pluginiaconnect_grant} g WHERE g.clientid = c.id)
                       AND NOT EXISTS (SELECT 1 FROM {local_pluginiaconnect_authcode} a WHERE a.clientid = c.id)";
        $ids = $DB->get_fieldset_sql($sql, ['type' => $type, 'created' => $olderthan, 'used' => $olderthan]);
        if ($ids) {
            $DB->delete_records_list(self::TABLE, 'id', $ids);
        }
    }
}
