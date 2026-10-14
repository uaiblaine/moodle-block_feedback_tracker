@block @block_feedback_tracker @javascript
Feature: Removing the block from many courses at once
  In order to stop measuring courses that are over
  As an administrator
  I need to queue courses for removal in batches and see which are already queued

  Background:
    Given the following "courses" exist:
      | fullname     | shortname | visible |
      | Archived one | AR1       | 0       |
      | Archived two | AR2       | 0       |
    And the following "blocks" exist:
      | blockname        | contextlevel | reference | pagetypepattern | defaultregion |
      | feedback_tracker | Course       | AR1       | course-view-*   | side-pre      |
      | feedback_tracker | Course       | AR2       | course-view-*   | side-pre      |

  Scenario: A queued course leaves the list, shows as waiting and is reported once removed
    Given I log in as "admin"
    And I am on the "block_feedback_tracker > Bulk removal" page
    And I set the field "Hidden courses only" to "1"
    And I press "Find courses"
    When I set the field "Archived one" to "1"
    And I set the field "Type the number of selected courses" to "1"
    And I press "Remove the block from the selected courses"
    Then I should see "Queued: the block will be removed from 1 course(s)"
    And I should see "1 course(s) are queued"
    And I should see "Archived two" in the ".bft-bulk-table" "css_element"
    And I should not see "Archived one" in the ".bft-bulk-table" "css_element"
    When I set the field "Select all 1 listed courses" to "1"
    And I set the field "Type the number of selected courses" to "1"
    And I press "Remove the block from the selected courses"
    Then I should see "2 course(s) are queued"
    And I should see "No courses carrying the block match this filter."
    When I run all adhoc tasks
    And I reload the page
    Then I should not see "Waiting for removal"
    And I should see "Recently completed"
    And I should see "1 course(s), 1 block(s) removed, 0 skipped"
