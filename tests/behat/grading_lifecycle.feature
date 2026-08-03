@block @block_feedback_tracker @javascript
Feature: The report reflects what Moodle actually did to a submission
  In order to trust the pending list
  As an editing teacher
  I need a submission's history and its current state to both be visible

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
    # These scenarios drive core's own grading form, so pin it: a sibling
    # plugin that replaces the assign grading UI would otherwise change the
    # field labels underneath them. Harmless where that plugin is absent —
    # the step only writes a config row.
    And the following config values are set as admin:
      | enable_assign | 0 | local_unifiedgrader |

  # The reported defect: a student re-saving already-graded work used to
  # un-grade the ledger row, destroying the recorded response time. Both facts
  # must now be on screen at once, because Moodle itself reports both — the
  # completed grading in the history, the re-look as a fresh pending item.
  Scenario: Re-saving graded work leaves the grading in history and opens a new pending item
    Given the following "activities" exist:
      | activity | name      | course | submissiondrafts | assignsubmission_onlinetext_enabled |
      | assign   | Essay one | C1     | 0                | 1                                   |
    And the following "mod_assign > submissions" exist:
      | assign    | user     | onlinetext    |
      | Essay one | student1 | First version |
    And I am on the "Essay one" Activity page logged in as teacher1
    And I change window size to "large"
    And I go to "Sam Student" "Essay one" activity advanced grading page
    And I set the field "Grade out of 100" to "80"
    And I press "Save changes"
    And I log out
    When I am on the "Essay one" "assign activity" page logged in as "student1"
    And I press "Edit submission"
    And I set the field "Online text" to "Second version"
    And I press "Save changes"
    And I log out
    And I am on the "Course 1" "block_feedback_tracker > Pending report" page logged in as "teacher1"
    Then I should see "Sam Student"
    And I should not see "No pending submissions match the current filter."
    And I click on "Graded" "button"
    And I should see "Sam Student"

  # Marking workflow hides a saved grade from the student until it is released,
  # and releasing needs a capability the marker often does not hold — so the
  # row must say so rather than reading as finished.
  Scenario: A marked but unreleased submission is flagged as awaiting release
    Given the following "activities" exist:
      | activity | name      | course | submissiondrafts | markingworkflow | assignsubmission_onlinetext_enabled |
      | assign   | Essay two | C1     | 0                | 1               | 1                                   |
    And the following "mod_assign > submissions" exist:
      | assign    | user     | onlinetext |
      | Essay two | student1 | My work    |
    And I am on the "Essay two" Activity page logged in as teacher1
    And I change window size to "large"
    And I go to "Sam Student" "Essay two" activity advanced grading page
    And I set the field "Grade out of 100" to "70"
    And I set the field "Marking workflow state" to "In marking"
    And I press "Save changes"
    When I am on the "Course 1" "block_feedback_tracker > Pending report" page
    And I click on "Graded" "button"
    Then I should see "Awaiting release"

  # mod_assign stores a team's work in a single row with no user of its own.
  # Mirroring it produced a pending item the list could never show, because
  # every list joins the user table; the fan-out has to put every member on
  # screen.
  Scenario: A group submission is listed against every member of the team
    Given the following "groups" exist:
      | name    | course | idnumber |
      | Group A | C1     | ga       |
    And the following "group members" exist:
      | user     | group |
      | student1 | ga    |
      | student2 | ga    |
    And the following "activities" exist:
      | activity | name       | course | submissiondrafts | teamsubmission | assignsubmission_onlinetext_enabled |
      | assign   | Team essay | C1     | 0                | 1              | 1                                   |
    And I am on the "Team essay" "assign activity" page logged in as "student1"
    And I press "Add submission"
    And I set the field "Online text" to "Our group work"
    And I press "Save changes"
    And I log out
    When I am on the "Course 1" "block_feedback_tracker > Pending report" page logged in as "teacher1"
    Then I should see "Sam Student"
    And I should see "Robin Learner"
