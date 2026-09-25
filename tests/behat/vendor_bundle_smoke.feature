@block @block_feedback_tracker @javascript
Feature: Every page that loads the vendored Preact bundle renders its Preact tree
  In order to see the Feedback Flow interface at all
  As a teacher or an administrator
  I need each page that loads the vendored bundle to render its application

  # Each assertion reads an element only the Preact view creates inside the
  # server-rendered mount point. A wrong bundle file name, or a bundle that
  # fails to set window.bftPreact, leaves the mount point holding only its
  # JSON payload, and the element is missing.

  Background:
    Given the following "courses" exist:
      | fullname | shortname |
      | Course 1 | C1        |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Terry     | Teacher  | teacher1@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
    And the following "blocks" exist:
      | blockname        | contextlevel | reference |
      | feedback_tracker | Course       | C1        |

  Scenario: The block renders on the course page
    Given I log in as "teacher1"
    When I am on "Course 1" course homepage
    Then "[data-bft-block-root] .bft-block-root" "css_element" should exist

  Scenario: The teacher dashboard renders
    Given I log in as "teacher1"
    When I am on the "block_feedback_tracker > Teacher dashboard" page
    Then "[data-bft-dashboard-root] .bft-dashboard" "css_element" should exist

  Scenario: The pending report renders
    Given I log in as "teacher1"
    When I am on the "Course 1" "block_feedback_tracker > Pending report" page
    Then "[data-bft-pending-report-root] .bft-report" "css_element" should exist

  Scenario: The score simulator renders
    Given I log in as "admin"
    When I am on the "block_feedback_tracker > Score simulator" page
    Then "[data-bft-simulator-root] .bft-sim" "css_element" should exist

  Scenario: The component smoke-test page renders both of its roots
    Given I log in as "admin"
    When I visit "/blocks/feedback_tracker/pages/spike_react.php"
    Then "[data-bft-spike-root] .bft-card" "css_element" should exist
    And "hr + [data-bft-spike-root] .bft-card" "css_element" should exist
