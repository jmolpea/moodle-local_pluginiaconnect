@local @local_pluginiaconnect
Feature: Approving changes requested by an AI assistant
  In order to stay in control of what an assistant changes in my name
  As a teacher
  I need to review each change on the site and approve or reject it myself

  Background:
    Given the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
      | teacher2 | Tina      | Other    | teacher2@example.com |
    And the following "courses" exist:
      | fullname     | shortname |
      | Statistics I | STAT1     |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | STAT1  | editingteacher |
      | teacher2 | STAT1  | editingteacher |
    And the following "activities" exist:
      | activity | course | name        | idnumber |
      | page     | STAT1  | Unit 3 test | page1    |
    And the following config values are set as admin:
      | enabled                               | 1 | local_pluginiaconnect |
      | directmode                            | 1 | local_pluginiaconnect |
      | allowloopback                         | 1 | local_pluginiaconnect |
      | tool_set_activity_visibility_teacher  | 1 | local_pluginiaconnect |
    And the following "local_pluginiaconnect > clients" exist:
      | name           |
      | Test assistant |
    And the following "local_pluginiaconnect > connections" exist:
      | user     | client         | profile | scopes                                              |
      | teacher1 | Test assistant | teacher | profile courses.read content.write offline_access   |
    And the following "local_pluginiaconnect > pending changes" exist:
      | user     | client         | activity | visible |
      | teacher1 | Test assistant | page1    | 0       |

  Scenario: The user sees the pending change and approves it
    Given I log in as "teacher1"
    And I follow "Preferences" in the user menu
    And I follow "My AI connections"
    And I should see "Changes waiting for your approval"
    When I follow "Review"
    Then I should see "Approve a change requested by an AI assistant"
    And I should see "Nothing has been changed yet"
    And I should see "Hide 'Unit 3 test' (Course: Statistics I). Currently visible to students." in the "[data-region=pc-approval-summary]" "css_element"
    And I should see "Test assistant"
    And I should see "set_activity_visibility"
    And I press "Approve"
    And I should see "The change was applied."
    And I should not see "Changes waiting for your approval"
    And I am on "Statistics I" course homepage
    And I should see "Hidden from students"

  Scenario: The user rejects the pending change and nothing is changed
    Given I log in as "teacher1"
    And I follow "Preferences" in the user menu
    And I follow "My AI connections"
    When I follow "Review"
    And I press "Reject"
    Then I should see "The change was rejected. Nothing was changed."
    And I should not see "Changes waiting for your approval"
    And I am on "Statistics I" course homepage
    And I should not see "Hidden from students"

  Scenario: Another user cannot see or approve the change
    Given I log in as "teacher2"
    When I follow "Preferences" in the user menu
    And I follow "My AI connections"
    Then I should not see "Changes waiting for your approval"
    And I should not see "Review"

  Scenario: The administrator switches a write action on and cannot go below its minimum approval step
    Given I log in as "admin"
    When I navigate to "Plugins > Local plugins > Pluginia Connect > Actions by profile" in site administration
    Then I should see "Actions that change something"
    And I should see "Save assignment grade"
    And I should see "Set enrolment status"
    And the field with xpath "//input[@type='checkbox'][@name='cell_save_assignment_grade_teacher']" matches value "0"
    And the field with xpath "//input[@type='checkbox'][@name='cell_set_activity_visibility_teacher']" matches value "1"
    And "//select[@name='approval_set_enrolment_status']/option[@value='confirm']" "xpath_element" should not exist
    And "//select[@name='approval_set_enrolment_status']/option[@value='none']" "xpath_element" should not exist
    And "//select[@name='approval_save_assignment_grade']/option[@value='none']" "xpath_element" should not exist
    And I set the field with xpath "//input[@type='checkbox'][@name='cell_save_assignment_grade_teacher']" to "1"
    And I set the field with xpath "//select[@name='approval_save_assignment_grade']" to "approval"
    And I press "Save changes"
    And I should see "Changes saved: 2."
    And the field with xpath "//input[@type='checkbox'][@name='cell_save_assignment_grade_teacher']" matches value "1"
