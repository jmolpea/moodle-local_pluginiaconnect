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

// NOTE: no MOODLE_INTERNAL test here, this file may be required by behat before including /config.php.

require_once(__DIR__ . '/../../../../lib/behat/behat_base.php');

/**
 * Behat steps of the connector.
 *
 * @package    local_pluginiaconnect
 * @category   test
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_pluginiaconnect extends behat_base {
    /** @var string PKCE challenge of a fixed test verifier. Only the challenge is ever sent. */
    private const CHALLENGE = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';

    /**
     * Opens the consent page as an assistant would, for a client created with the generator.
     *
     * The return address is a fixture page of this site, so that the scenario never leaves it.
     *
     * @Given I open the Pluginia Connect consent page for the client :name
     * @param string $name Client name.
     */
    public function i_open_the_consent_page_for_the_client(string $name): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/local/pluginiaconnect/tests/generator/lib.php');
        $client = $DB->get_record('local_pluginiaconnect_client', ['name' => $name], '*', MUST_EXIST);
        $uris = local_pluginiaconnect_generator::callback_uris();
        $url = new moodle_url('/local/pluginiaconnect/oauth/authorize.php', [
            'response_type' => 'code',
            'client_id' => $client->clientid,
            'redirect_uri' => end($uris),
            'code_challenge' => self::CHALLENGE,
            'code_challenge_method' => 'S256',
            'resource' => rtrim($CFG->wwwroot, '/') . '/local/pluginiaconnect/mcp.php',
            'state' => 'behat-state',
        ]);
        $this->execute('behat_general::i_visit', [$url->out_as_local_url(false)]);
    }

    /**
     * Checks whether an authorization code was issued to a user.
     *
     * @Then a Pluginia Connect authorization code should exist for :username
     * @param string $username Username.
     */
    public function an_authorization_code_should_exist_for(string $username): void {
        if (!$this->count_codes($username)) {
            throw new ExpectationException("No authorization code was issued to '$username'.", $this->getSession());
        }
    }

    /**
     * Checks that no authorization code was issued to a user.
     *
     * @Then no Pluginia Connect authorization code should exist for :username
     * @param string $username Username.
     */
    public function no_authorization_code_should_exist_for(string $username): void {
        if ($this->count_codes($username)) {
            throw new ExpectationException("An authorization code was issued to '$username'.", $this->getSession());
        }
    }

    /**
     * Number of authorization codes of a user.
     *
     * @param string $username Username.
     * @return int
     */
    private function count_codes(string $username): int {
        global $DB;
        $userid = $DB->get_field('user', 'id', ['username' => $username], MUST_EXIST);
        return $DB->count_records('local_pluginiaconnect_authcode', ['userid' => $userid]);
    }
}
