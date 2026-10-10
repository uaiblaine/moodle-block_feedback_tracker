@block @block_feedback_tracker
Feature: The group drill-down labels each pending submission's band in the user's language
  In order to read a submission's status at a glance
  As an editing teacher
  I need the drill-down badge to show the band's name rather than its internal slug

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
      | student1 | Sam       | Student  | student1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
    And the following "blocks" exist:
      | blockname        | contextlevel | reference |
      | feedback_tracker | Course       | C1        |
    And the following "activities" exist:
      | activity | name      | course | submissiondrafts | assignsubmission_onlinetext_enabled |
      | assign   | Essay one | C1     | 0                | 1                                   |
    And the following "mod_assign > submissions" exist:
      | assign    | user     | onlinetext |
      | Essay one | student1 | My work    |

  # A submission handed in moments ago has waited under the first threshold,
  # so it sits in the excellent band, whose slug differs from its label only
  # by case; the assertion is case-sensitive.
  Scenario: A fresh submission's badge reads Excellent
    Given I log in as "teacher1"
    When I am on the "Course 1" "block_feedback_tracker > Group drilldown" page
    Then I should see "Excellent" in the "Sam Student" "table_row"
    And I should not see "excellent" in the "Sam Student" "table_row"
