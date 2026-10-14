@block @block_feedback_tracker @javascript
Feature: A grade link opens the grader on the linked student
  In order to grade the submission I picked
  As an editing teacher
  I need the grader to open on that student even when my saved filters hide them

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
      | student1 | Sam       | Student  | student1@example.com |
      | student2 | Robin     | Learner  | student2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |
    And the following "blocks" exist:
      | blockname        | contextlevel | reference |
      | feedback_tracker | Course       | C1        |
    # local_unifiedgrader, when installed, replaces the assign grader this
    # scenario is about; where it is absent the step writes an unused config row.
    And the following config values are set as admin:
      | enable_assign | 0 | local_unifiedgrader |
    And the following "activities" exist:
      | activity | name      | course | submissiondrafts | assignsubmission_onlinetext_enabled |
      | assign   | Essay one | C1     | 0                | 1                                   |
    And the following "mod_assign > submissions" exist:
      | assign    | user     | onlinetext |
      | Essay one | student1 | My essay   |

  # With "Not submitted" saved, mod_assign's grader lists only Robin and, given
  # Sam's id, opens Robin instead. The link must clear the filter first.
  Scenario: A saved filter that hides the student is cleared on the way to the grader
    Given the following "user preferences" exist:
      | user     | preference    | value        |
      | teacher1 | assign_filter | notsubmitted |
    And I am on the "Course 1" "block_feedback_tracker > Pending report" page logged in as "teacher1"
    And I change window size to "large"
    # The table arrives through a web service after mount; wait for the row.
    And I should see "Sam Student"
    When I click on "Grade" "link" in the "Sam Student" "table_row"
    Then I should see "Your saved filters or selected group were hiding this student in the grader"
    And I should see "Sam Student" in the "[data-region='grading-navigation-panel']" "css_element"
