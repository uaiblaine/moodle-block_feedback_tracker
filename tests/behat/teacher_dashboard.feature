@block @block_feedback_tracker @javascript
Feature: Teacher dashboard renders for a multi-course editing teacher
  In order to triage feedback turnaround across my courses
  As an editing teacher
  I need the Feedback Flow teacher dashboard to render the hero + courses table

  Background:
    Given the following "courses" exist:
      | fullname  | shortname |
      | Course A  | CA        |
      | Course B  | CB        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | CA     | editingteacher |
      | teacher1 | CB     | editingteacher |
    # Repeats the editingteacher archetype default in db/access.php, so on a
    # standard install it changes nothing (role_change_permission() skips a
    # permission the role already has); it keeps the scenario independent of
    # that default.
    And the following "permission overrides" exist:
      | capability                              | permission | role           | contextlevel | reference |
      | block/feedback_tracker:viewdashboard    | Allow      | editingteacher | System       |           |

  Scenario: Editing teacher sees the hero greeting and the courses heading
    Given I log in as "teacher1"
    When I am on the "block_feedback_tracker > Teacher dashboard" page
    Then I should see "Terry"
    And I should see "Your courses"

  # The refusal path (a student has no dashboard scope) is covered by PHPUnit
  # get_dashboard_test::test_student_is_rejected: the page and the web service
  # share dashboard_scope. Behat fails any step whose page renders an exception
  # (behat_session_trait::look_for_exceptions()), so the refusal cannot be
  # asserted here.
