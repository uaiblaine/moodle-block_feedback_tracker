@block @block_feedback_tracker @javascript
Feature: The score simulator writes each slider value as a number of the language
  In order to read the weights I tune in my own number format
  As an administrator
  I need each slider to show its value through the plugin's decimal formatter

  # The read-out goes through formatDecimal with as many places as the slider's
  # step has, so the 0.01-step weights show two places ("0.40") and, in a
  # language whose decimal separator is a comma, "0,40". A plain JS number
  # would read "0.4" in every language. The Behat site has only the English
  # pack, so this pins the formatter path; the separator it applies comes from
  # bootstrap::config_bundle(), pinned by bootstrap_test.
  Scenario: The weight sliders show two decimal places
    Given I log in as "admin"
    When I am on the "block_feedback_tracker > Score simulator" page
    Then I should see "Σ 1.00" in the "//section[contains(concat(' ', normalize-space(@class), ' '), ' bft-sim-panel ')][h3[normalize-space(.)='Criterion weights']]" "xpath_element"
    And I should see "0.40" in the "//label[contains(concat(' ', normalize-space(@class), ' '), ' bft-sim-slider ')][span[normalize-space(.)='Compliance']]" "xpath_element"
    And I should see "0.10" in the "//label[contains(concat(' ', normalize-space(@class), ' '), ' bft-sim-slider ')][span[normalize-space(.)='Trend']]" "xpath_element"
