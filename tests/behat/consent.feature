@local @local_pluginiaconnect
Feature: Consent to connect an AI assistant
  In order to decide what an AI assistant may do in my name
  As a user
  I need a consent page that offers only the profiles that apply to me and read-only access by default

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following config values are set as admin:
      | enabled       | 1 | local_pluginiaconnect |
      | directmode    | 1 | local_pluginiaconnect |
      | allowloopback | 1 | local_pluginiaconnect |
    And the following "local_pluginiaconnect > clients" exist:
      | name           |
      | Test assistant |

  Scenario: A teacher is offered the teacher profile and read-only access by default
    Given I log in as "teacher1"
    When I open the Pluginia Connect consent page for the client "Test assistant"
    Then I should see "Connect an AI assistant"
    And I should see "Test assistant"
    And "input[name=profile][value=teacher]" "css_element" should exist
    And "input[name=profile][value=student]" "css_element" should not exist
    And "input[name=profile][value=manager]" "css_element" should not exist
    And "input[name=profile][value=admin]" "css_element" should not exist
    And the "checked" attribute of "#pc-level-read" "css_element" should be set
    And no Pluginia Connect authorization code should exist for "teacher1"

  Scenario: Approving sends the user back to the assistant with a code
    Given I log in as "teacher1"
    And I open the Pluginia Connect consent page for the client "Test assistant"
    When I press "Allow"
    Then I should see "Assistant callback reached"
    And I should see "Code: present"
    And I should see "Error: none"
    And I should see "State: behat-state"
    And I should see "Issuer: present"
    And a Pluginia Connect authorization code should exist for "teacher1"

  Scenario: Cancelling sends the user back without a code
    Given I log in as "teacher1"
    And I open the Pluginia Connect consent page for the client "Test assistant"
    When I press "Cancel"
    Then I should see "Assistant callback reached"
    And I should see "Code: absent"
    And I should see "Error: access_denied"
    And I should see "State: behat-state"
    And I should see "Issuer: present"
    And no Pluginia Connect authorization code should exist for "teacher1"

  Scenario: A student cannot connect while the student profile is off
    Given I log in as "student1"
    When I open the Pluginia Connect consent page for the client "Test assistant"
    Then I should see "None of the connection profiles enabled on this site applies to your account"
    And "Allow" "button" should not exist

  Scenario: A student is offered only the student profile once it is allowed
    Given the following config values are set as admin:
      | allowstudentprofile | 1 | local_pluginiaconnect |
    And I log in as "student1"
    When I open the Pluginia Connect consent page for the client "Test assistant"
    Then "input[name=profile][value=student]" "css_element" should exist
    And "input[name=profile][value=teacher]" "css_element" should not exist
    And "input[name=level][value=write]" "css_element" should not exist

  Scenario: Changes are not offered while every action that changes something is switched off
    Given I log in as "teacher1"
    When I open the Pluginia Connect consent page for the client "Test assistant"
    Then "input[name=level][value=read]" "css_element" should exist
    And "input[name=level][value=write]" "css_element" should not exist
    And I should not see "Read and changes"

  Scenario: Changes are offered once an action that changes something is switched on for the profile
    Given the following config values are set as admin:
      | tool_post_course_announcement_teacher | 1 | local_pluginiaconnect |
    And I log in as "teacher1"
    When I open the Pluginia Connect consent page for the client "Test assistant"
    Then "input[name=level][value=write]" "css_element" should exist
    And I should see "Read and changes"
    And I should see "Post course announcement"
    And the "checked" attribute of "#pc-level-read" "css_element" should be set
