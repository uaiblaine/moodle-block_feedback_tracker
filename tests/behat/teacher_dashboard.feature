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
      | manager1 | Mandy     | Manager  | manager1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | CA     | editingteacher |
      | teacher1 | CB     | editingteacher |
      | manager1 | CA     | manager        |
    And the following "role assigns" exist:
      | user     | role    | contextlevel | reference |
      | manager1 | manager | System       |           |
    And the following "block_feedback_tracker > site days" exist:
      | daysago | medianh_eff | p10h_eff | p90h_eff | compliance_pct_site | numgraded |
      | 1       | 12.5        | 3.25     | 40.5     | 82.4                | 1234      |

  Scenario: Editing teacher sees the hero greeting and the courses heading
    Given I log in as "teacher1"
    When I am on the "block_feedback_tracker > Teacher dashboard" page
    Then I should see "Terry"
    And I should see "Your courses"
    # The site benchmarks need block/feedback_tracker:viewschoolcomparison at
    # system context, which an editing teacher's course role does not give.
    And I should not see "Site benchmarks"

  @accessibility
  Scenario: A manager opens the site benchmarks table
    Given I log in as "manager1"
    And I am on the "block_feedback_tracker > Teacher dashboard" page
    And I should see "Your courses"
    When I press "Site benchmarks"
    Then I should see "12.5 h" in the "Site benchmarks per day, last 30 days" "table"
    And I should see "40.5 h" in the "Site benchmarks per day, last 30 days" "table"
    And I should see "82%" in the "Site benchmarks per day, last 30 days" "table"
    And I should see "1,234" in the "Site benchmarks per day, last 30 days" "table"
    And the ".bft-dashboard-comparison" "css_element" should meet accessibility standards with "wcag143" extra tests

  # The refusal path (a student has no dashboard scope) is covered by PHPUnit
  # get_dashboard_test::test_student_is_rejected: the page and the web service
  # share dashboard_scope. Behat fails any step whose page renders an exception
  # (behat_session_trait::look_for_exceptions()), so the refusal cannot be
  # asserted here.
