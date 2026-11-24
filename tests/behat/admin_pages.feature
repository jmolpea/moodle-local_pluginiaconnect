@local @local_pluginiaconnect
Feature: Administration of the connector
  In order to govern what AI assistants can do on the site
  As an administrator
  I need the tool matrix, the list of connections, the audit report and the diagnostics page

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
      | teacher2 | Tina      | Other    | teacher2@example.com |
      | manager1 | Mona      | Manager  | manager1@example.com |
    And the following "system role assigns" exist:
      | user     | role    | contextlevel | reference |
      | manager1 | manager | System       |           |
    And the following "local_pluginiaconnect > clients" exist:
      | name            |
      | Test assistant  |
      | Other assistant |
    And the following "local_pluginiaconnect > connections" exist:
      | user     | client          | profile |
      | teacher1 | Test assistant  | teacher |
      | teacher2 | Other assistant | teacher |
    And the following "local_pluginiaconnect > calls" exist:
      | user     | client          | tool            | outcome      | reason            |
      | teacher1 | Test assistant  | list_my_courses | ok           |                   |
      | teacher1 | Test assistant  | get_my_grades   | denied       | permission_denied |
      | teacher2 | Other assistant | list_my_courses | rate_limited | rate_limited      |

  # Switching a cell off needs a real browser: without JavaScript the test browser does not send the
  # hidden field of the checkbox, so the form keeps the stored value. The cell starts off here instead.
  Scenario: Switching an action on for one profile leaves the others as they were
    Given the following config values are set as admin:
      | tool_list_my_courses_student | 0 | local_pluginiaconnect |
    And I log in as "admin"
    And I navigate to "Plugins > Local plugins > Pluginia Connect > Actions by profile" in site administration
    And I should see "Actions that read"
    And I should see "List my courses"
    And the field with xpath "//input[@type='checkbox'][@name='cell_list_my_courses_student']" matches value "0"
    When I set the field with xpath "//input[@type='checkbox'][@name='cell_list_my_courses_student']" to "1"
    And I press "Save changes"
    Then I should see "Changes saved: 1."
    And the field with xpath "//input[@type='checkbox'][@name='cell_list_my_courses_student']" matches value "1"
    And the field with xpath "//input[@type='checkbox'][@name='cell_list_my_courses_teacher']" matches value "1"

  Scenario: Revoking every connection of a user from the list
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > Pluginia Connect > Connections" in site administration
    And I should see "Active connections: 2"
    And I should see "Terry Teacher"
    When I click on "All of this user" "button" in the "Terry Teacher" "table_row"
    And I should see "Disconnect every assistant connected by the user"
    And I press "Disconnect"
    Then I should see "Connections disconnected: 1."
    And I should see "Active connections: 1"
    And I should not see "Terry Teacher"
    And I should see "Tina Other"

  Scenario: Filtering the audit report
    Given I log in as "admin"
    And I navigate to "Plugins > Local plugins > Pluginia Connect > Audit report" in site administration
    And I should see "Calls: 3"
    And I should see "Refused calls and limits reached"
    When I set the field "Outcome" to "denied"
    And I press "Apply filters"
    Then I should see "Calls: 1"
    And I should see "get_my_grades" in the "[data-region=pc-audit]" "css_element"
    And I should not see "rate_limited" in the "[data-region=pc-audit]" "css_element"
    And I press "Clear filters"
    And I should see "Calls: 3"

  Scenario: A manager reads the audit report and is not offered the connections page
    Given I log in as "manager1"
    When I visit "/local/pluginiaconnect/admin/audit.php"
    Then I should see "Calls: 3"
    And I should see "Audit report"
    And "Connections" "link" should not exist in the "region-main" "region"

  Scenario: The diagnostics page reports the state of the connector
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > Pluginia Connect > Diagnostics" in site administration
    Then I should see "Connector state"
    And I should see "The connector is switched off."
    And I should see "Pluginia Connect gateway"
    And I should see "The site is not registered with the gateway."
    And "Run the checks again" "button" should exist

  Scenario: The gateway registration page says why the site cannot register yet
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > Pluginia Connect > Gateway registration" in site administration
    Then I should see "This site is not registered."
    And I should see "The site cannot be registered yet:"
    And I should see "The connector is switched off."

  Scenario: The settings page opens with a link to the configuration manual
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > Pluginia Connect > Settings" in site administration
    Then I should see "Configuration manual"
    And "Open the configuration manual of Pluginia Connect (opens in a new tab)" "link" should exist
    And the "target" attribute of "Open the configuration manual of Pluginia Connect (opens in a new tab)" "link" should contain "_blank"
    And the "rel" attribute of "Open the configuration manual of Pluginia Connect (opens in a new tab)" "link" should contain "noopener"
    And "Open the configuration manual of Pluginia Connect (opens in a new tab)" "link" should appear before "Enable the connector" "text"
