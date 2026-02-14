@block @block_ranking
Feature: Ranking block displays student leaderboard
  In order to motivate students through gamification
  As a teacher
  I need the ranking block to display points when students complete activities

  Background:
    Given the following "courses" exist:
      | fullname | shortname | enablecompletion |
      | Course 1 | C1        | 1                |
    And the following "users" exist:
      | username | firstname | lastname | email                |
      | teacher1 | Teacher   | One      | teacher1@example.com |
      | student1 | Student   | Alpha    | student1@example.com |
      | student2 | Student   | Beta     | student2@example.com |
    And the following "course enrolments" exist:
      | user     | course | role           |
      | teacher1 | C1     | editingteacher |
      | student1 | C1     | student        |
      | student2 | C1     | student        |

  @javascript
  Scenario: Teacher adds ranking block to course
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    When I add the "Ranking block" block
    Then I should see "Ranking" in the ".block_ranking" "css_element"
    And I should see "General" in the ".block_ranking" "css_element"
    And I should see "Weekly" in the ".block_ranking" "css_element"
    And I should see "Monthly" in the ".block_ranking" "css_element"

  @javascript
  Scenario: Student sees their score section
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I add the "Ranking block" block
    And I log out
    When I log in as "student1"
    And I am on "Course 1" course homepage
    Then I should see "Your score" in the ".block_ranking" "css_element"

  @javascript
  Scenario: Teacher can view full ranking report
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I add the "Ranking block" block
    And I am on "Course 1" course homepage
    When I click on "See full ranking" "link" in the ".block_ranking" "css_element"
    Then I should see "General students ranking"

  @javascript
  Scenario: Report page has period filter and CSV export
    Given I log in as "teacher1"
    And I am on "Course 1" course homepage with editing mode on
    And I add the "Ranking block" block
    And I am on "Course 1" course homepage
    And I click on "See full ranking" "link" in the ".block_ranking" "css_element"
    Then I should see "Period"
    And I should see "Export CSV"
