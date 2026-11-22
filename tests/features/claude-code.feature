Feature: Claude Code sees what you have open
  Porta is an IDE the claude CLI connects to: it announces itself in Claude
  Code's ide folder, and a change Claude proposes is shown in the file — side
  by side in the git view, or as a question — until you keep it or put it
  back. Send Selection to Claude Code points Claude at what you selected.
  Here the CLI is played by the mcp plugin's own test client.

  Background:
    Given a PHP project
    And the bundled plugins "mcp" are enabled
    And Claude Code's configuration is in the workspace
    And a file "src/A.php" containing:
      """
      <?php
      // one
      // two
      """
    And a file "src/B.php" containing "<?php"

  @setting:mcp.claudeCode
  Scenario: Porta announces itself to Claude Code
    When I start the app with the arguments "src/A.php"
    Then the IDE should be announced to Claude Code

  @setting:mcp.claudeCode
  Scenario: The Claude Code connection can be turned off
    Given the project configuration:
      """
      {"plugins": {"mcp": {"claudeCode": false}}}
      """
    When I start the app with the arguments "src/A.php"
    And I should see "A.php"
    And an agent is connected over MCP
    Then no IDE should be announced to Claude Code

  @setting:mcp.claudeSideBySide
  Scenario: A proposed change shown side by side is kept with its key
    Given I start the app in a 120x40 terminal with the arguments "src/B.php"
    And Claude Code is connected to the IDE
    When Claude Code proposes to change "src/A.php" to:
      """
      <?php
      // one
      // TWO
      """
    Then I should see "Claude proposes changes to A.php"
    And the editor should show "src/A.php"
    And the editor should contain "// TWO"
    When I use the action "mcp:accept_claude_change"
    Then Claude Code should hear "FILE_SAVED"
    And a toast should say "Kept Claude's change to A.php"

  Scenario: A proposed change is put back with its key
    Given I start the app in a 120x40 terminal with the arguments "src/B.php"
    And Claude Code is connected to the IDE
    When Claude Code proposes to change "src/A.php" to:
      """
      <?php
      """
    Then I should see "Claude proposes changes to A.php"
    When I use the action "mcp:reject_claude_change"
    Then Claude Code should hear "DIFF_REJECTED"
    And the editor text should be:
      """
      <?php
      // one
      // two
      """

  @setting:mcp.claudeSideBySide
  Scenario: Without the side-by-side view, the change is a question to answer
    Given the project configuration:
      """
      {"plugins": {"mcp": {"claudeSideBySide": false}}}
      """
    And I start the app in a 120x40 terminal with the arguments "src/B.php"
    And Claude Code is connected to the IDE
    When Claude Code proposes to change "src/A.php" to:
      """
      <?php
      // one
      // TWO
      """
    Then I should see "Accept — keep and save"
    When I press "Escape"
    Then I should not see "Accept — keep and save"
    When I use the action "mcp:review_claude_change"
    Then I should see "Accept — keep and save"
    When I press "Enter"
    Then Claude Code should hear "FILE_SAVED"

  Scenario: Reviewing with nothing proposed says so
    Given I start the app with the arguments "src/A.php"
    When I use the action "mcp:review_claude_change"
    Then a toast should say "No change from Claude is waiting"

  Scenario: Send Selection to Claude Code mentions the open file
    Given I start the app with the arguments "src/A.php"
    And Claude Code is connected to the IDE
    When I use the action "mcp:mention_in_claude"
    Then Claude Code should be pointed at "src/A.php"
