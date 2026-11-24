@local @local_pluginiaconnect
Feature: My AI connections
  In order to stay in control of the assistants connected to my account
  As a user
  I need to see my connections and what they did, and to disconnect them

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
      | teacher2 | Tina      | Other    | teacher2@example.com |
    And the following config values are set as admin:
      | enabled    | 1 | local_pluginiaconnect |
      | directmode | 1 | local_pluginiaconnect |
    And the following "local_pluginiaconnect > clients" exist:
      | name            |
      | Test assistant  |
      | Other assistant |
    And the following "local_pluginiaconnect > connections" exist:
      | user     | client          | profile |
      | teacher1 | Test assistant  | teacher |
      | teacher2 | Other assistant | teacher |
    And the following "local_pluginiaconnect > calls" exist:
      | user     | client          | tool            | outcome | reason            |
      | teacher1 | Test assistant  | list_my_courses | ok      |                   |
      | teacher1 | Test assistant  | get_my_grades   | denied  | permission_denied |
      | teacher2 | Other assistant | search_courses  | ok      |                   |

  Scenario: A user sees their own connections and calls only
    Given I log in as "teacher1"
    When I follow "Preferences" in the user menu
    And I follow "My AI connections"
    Then I should see "Test assistant" in the "[data-region=pc-connections]" "css_element"
    And I should see "Teacher" in the "[data-region=pc-connections]" "css_element"
    And I should see "Read only" in the "[data-region=pc-connections]" "css_element"
    And I should not see "Other assistant"
    And I should see "list_my_courses" in the "[data-region=pc-calls]" "css_element"
    And I should see "denied (permission_denied)" in the "[data-region=pc-calls]" "css_element"
    And I should not see "search_courses"

  Scenario: A user disconnects an assistant after confirming
    Given I log in as "teacher1"
    And I follow "Preferences" in the user menu
    And I follow "My AI connections"
    When I press "Disconnect"
    Then I should see "It loses access to your account at once"
    And I press "Disconnect"
    And I should see "The assistant was disconnected."
    And I should see "You have not connected any assistant."

  Scenario: Cancelling the confirmation keeps the connection
    Given I log in as "teacher1"
    And I follow "Preferences" in the user menu
    And I follow "My AI connections"
    When I press "Disconnect"
    And I press "Cancel"
    Then I should see "Test assistant" in the "[data-region=pc-connections]" "css_element"
