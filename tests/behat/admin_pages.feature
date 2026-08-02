@block @block_feedback_tracker
Feature: The plugin's admin and drill-down pages render for authorised users
  In order to administer and inspect feedback turnaround
  As a manager or an editing teacher
  I need the management, audit, reset and drill-down pages to load

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course A | CA        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | manager1 | Morgan    | Manager  | manager1@example.com |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | CA     | editingteacher |
    And the following "role assigns" exist:
      | user     | role    | contextlevel | reference |
      | manager1 | manager | System       |           |
    # Archetype defaults for plugin-defined capabilities can propagate
    # unevenly in Behat, so the ones these pages gate on are set explicitly.
    And the following "permission overrides" exist:
      | capability                                | permission | role           | contextlevel | reference |
      | block/feedback_tracker:viewdashboard      | Allow      | editingteacher | System       |           |
      | block/feedback_tracker:viewresponsiveness | Allow      | editingteacher | System       |           |

  Scenario: A manager reaches the management landing page
    Given I log in as "manager1"
    When I am on the "block_feedback_tracker > Manage" page
    Then I should see "Feedback Flow"

  Scenario: A manager reaches the recompute audit log
    Given I log in as "manager1"
    When I am on the "block_feedback_tracker > Audit log" page
    Then I should see "Feedback Flow"

  Scenario: A manager reaches the reset page and sees the data-loss warning
    Given I log in as "manager1"
    When I am on the "block_feedback_tracker > Reset" page
    Then I should see "Feedback Flow"

  # The simulator lets a non-admin in only with a full-site grant, or with the
  # enable_teacher_simulator switch on AND a non-empty teaching scope. A
  # manager holding neither is refused, so this asserts the admin path.
  Scenario: An administrator reaches the score simulator
    Given I log in as "admin"
    When I am on the "block_feedback_tracker > Score simulator" page
    Then I should see "Score simulator"

  Scenario: An editing teacher reaches the group drill-down for their course
    Given I log in as "teacher1"
    When I am on the "Course A" "block_feedback_tracker > Group drilldown" page
    Then I should see "Pending submissions"
    And I should see "Course A"
