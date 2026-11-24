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

/**
 * A known OAuth client. Immutable value object built from its database record.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class client {
    /** @var string Client identified by a client ID metadata document. */
    public const TYPE_CIMD = 'cimd';
    /** @var string Dynamically registered client. */
    public const TYPE_DCR = 'dcr';
    /** @var string Client created by hand. Reserved. */
    public const TYPE_MANUAL = 'manual';
    /** @var string The Pluginia Connect gateway. */
    public const TYPE_GATEWAY = 'gateway';

    /** @var string Public client. */
    public const AUTH_NONE = 'none';
    /** @var string Secret in the Authorization header. */
    public const AUTH_BASIC = 'client_secret_basic';
    /** @var string Secret in the request body. */
    public const AUTH_POST = 'client_secret_post';

    /** @var int Record id. */
    public readonly int $id;
    /** @var string Client identifier: an opaque value or a URL. */
    public readonly string $clientid;
    /** @var string Client type. */
    public readonly string $type;
    /** @var string Declared name. Untrusted: always escape it. */
    public readonly string $name;
    /** @var string[] Registered redirect URIs. */
    public readonly array $redirecturis;
    /** @var string Token endpoint authentication method. */
    public readonly string $authmethod;
    /** @var string|null Hash of the client secret. */
    public readonly ?string $secrethash;
    /** @var bool Whether the client is active (not blocked). */
    public readonly bool $active;

    /**
     * Constructor.
     *
     * @param \stdClass $record Row of local_pluginiaconnect_client.
     */
    public function __construct(\stdClass $record) {
        $this->id = (int) $record->id;
        $this->clientid = (string) $record->clientid;
        $this->type = (string) $record->type;
        $this->name = (string) $record->name;
        $uris = json_decode((string) $record->redirecturis, true);
        $this->redirecturis = is_array($uris) ? array_values(array_filter($uris, 'is_string')) : [];
        $this->authmethod = (string) $record->authmethod;
        $this->secrethash = empty($record->secrethash) ? null : (string) $record->secrethash;
        $this->active = !empty($record->status);
    }

    /**
     * Whether the client authenticates with a secret.
     *
     * @return bool
     */
    public function is_confidential(): bool {
        return $this->authmethod !== self::AUTH_NONE;
    }

    /**
     * Host of the client identifier when it is a URL, otherwise an empty string.
     *
     * @return string
     */
    public function host(): string {
        $host = parse_url($this->clientid, PHP_URL_HOST);
        return is_string($host) ? strtolower($host) : '';
    }
}
