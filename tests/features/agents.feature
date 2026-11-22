Feature: Agents over MCP
  The mcp plugin serves the IDE you have open to an agent: it reads buffers
  with their unsaved edits, edits them as one undo step each, and — when you
  allow it — saves, runs tasks and tests, and creates or deletes files. You
  see the agent at work: its caret and name in the text, a ✦ on the tab, and
  what it last did in the status bar. Here the agent is a client of the
  plugin's socket, as `porta mcp` would connect it.

  Background:
    Given a PHP project
    And the bundled plugins "mcp" are enabled
    And Claude Code's configuration is in the workspace
    And a file "src/A.php" containing:
      """
      <?php
      // first
      echo 'a';
      """
    And a file "src/B.php" containing "<?php"

  @setting:mcp.allowEdits
  Scenario: An agent's edit is made in the editor, unsaved
    Given I start the app with the arguments "src/A.php"
    And an agent is connected over MCP
    When the agent calls "edit_buffer" with:
      """
      {"oldText": "// first", "newText": "// edited"}
      """
    Then the editor should contain "// edited"
    And the file "src/A.php" should contain "// first"

  @setting:mcp.allowEdits
  Scenario: With edits turned off, the edit tools are not offered
    Given the project configuration:
      """
      {"plugins": {"mcp": {"allowEdits": false}}}
      """
    And I start the app with the arguments "src/A.php"
    When an agent is connected over MCP
    Then the agent should be offered "read_buffer"
    And the agent should not be offered "edit_buffer"
    And the agent should not be offered "replace_buffer"

  @setting:mcp.allowSave
  Scenario: Saving is the agent's only once allowed
    Given the project configuration:
      """
      {"plugins": {"mcp": {"allowSave": true}}}
      """
    And I start the app with the arguments "src/A.php"
    And an agent is connected over MCP
    When the agent calls "edit_buffer" with:
      """
      {"oldText": "// first", "newText": "// saved"}
      """
    And the agent calls "save_buffer"
    Then the file "src/A.php" should contain "// saved"

  Scenario: Without leave to save, there is no save tool
    Given I start the app with the arguments "src/A.php"
    When an agent is connected over MCP
    Then the agent should not be offered "save_buffer"

  @setting:mcp.allowPublish
  Scenario: The tools that publish to a forge are offered only when allowed
    Given the bundled plugins "review" are enabled
    And the project configuration:
      """
      {"plugins": {"mcp": {"allowPublish": true}}}
      """
    And I start the app with the arguments "src/A.php"
    When an agent is connected over MCP
    Then the agent should be offered "review_threads"
    And the agent should be offered "review_reply"

  @setting:mcp.allowPublish
  Scenario: Without leave to publish, the agent only reads the review
    Given the bundled plugins "review" are enabled
    And I start the app with the arguments "src/A.php"
    When an agent is connected over MCP
    Then the agent should be offered "review_threads"
    And the agent should not be offered "review_reply"

  Scenario: An agent's edit to another file stays behind the tab in front
    Given I start the app with the arguments "src/A.php"
    And an agent is connected over MCP
    When the agent calls "edit_buffer" with:
      """
      {"path": "src/B.php", "oldText": "<?php", "newText": "<?php // b"}
      """
    Then I should see "B.php ✦"
    And the editor should show "src/A.php"

  @setting:mcp.followAgent
  Scenario: Following the agent brings the file it edits to the front
    Given the project configuration:
      """
      {"plugins": {"mcp": {"followAgent": true}}}
      """
    And I start the app with the arguments "src/A.php"
    And an agent is connected over MCP
    When the agent calls "edit_buffer" with:
      """
      {"path": "src/B.php", "oldText": "<?php", "newText": "<?php // b"}
      """
    Then the editor should show "src/B.php"
    And the editor should contain "<?php // b"

  @setting:mcp.showAgents @setting:mcp.showAgentCursor
  Scenario: The agent shows where it edits: its name at the line, a star on the tab, a word in the status bar
    # Wide enough for the status bar to reach the agent's cell
    Given I start the app in a 200x32 terminal with the arguments "src/A.php"
    And an agent is connected over MCP
    When the agent calls "edit_buffer" with:
      """
      {"oldText": "// first", "newText": "// edited"}
      """
    Then I should see text matching "/\/\/ edited\s+✦ test/"
    And I should see "A.php ✦"
    And I should see "✦ test editing A.php"

  @setting:mcp.showAgentCursor
  Scenario: The agent's marks can be kept without its caret in the text
    Given the project configuration:
      """
      {"plugins": {"mcp": {"showAgentCursor": false}}}
      """
    And I start the app with the arguments "src/A.php"
    And an agent is connected over MCP
    When the agent calls "edit_buffer" with:
      """
      {"oldText": "// first", "newText": "// edited"}
      """
    Then I should see "A.php ✦"
    And I should not see text matching "/\/\/ edited\s+✦ test/"

  @setting:mcp.showAgents
  Scenario: Agents at work can be kept out of sight
    Given the project configuration:
      """
      {"plugins": {"mcp": {"showAgents": false}}}
      """
    And I start the app with the arguments "src/A.php"
    And an agent is connected over MCP
    When the agent calls "edit_buffer" with:
      """
      {"oldText": "// first", "newText": "// edited"}
      """
    Then the editor should contain "// edited"
    And I should not see "✦"

  @setting:mcp.agentFade
  Scenario: The agent's marks go once it stops touching the file, and the status bar keeps its last act
    Given the project configuration:
      """
      {"plugins": {"mcp": {"agentFade": 2}}}
      """
    And I start the app with the arguments "src/A.php"
    And an agent is connected over MCP
    When the agent calls "edit_buffer" with:
      """
      {"oldText": "// first", "newText": "// edited"}
      """
    Then I should see text matching "/\/\/ edited\s+✦ test/"
    And I should see "A.php ✦"
    # Gone within the check's few seconds: the default fade is ten
    Then I should not see text matching "/\/\/ edited\s+✦ test/"
    And I should not see "A.php ✦"
    # The status bar keeps what the agent last did until it disconnects
    But I should see "✦ test editing A.php"

  @setting:mcp.allowFileOps
  Scenario: Creating a file is the agent's once allowed
    Given the project configuration:
      """
      {"plugins": {"mcp": {"allowFileOps": true}}}
      """
    And I start the app with the arguments "src/A.php"
    And an agent is connected over MCP
    When the agent calls "create_file" with:
      """
      {"path": "src/C.php", "text": "<?php // made by the agent\n"}
      """
    Then the file "src/C.php" should contain "made by the agent"

  Scenario: Without leave, an agent cannot create, move or delete files
    Given I start the app with the arguments "src/A.php"
    When an agent is connected over MCP
    Then the agent should not be offered "create_file"
    And the agent should not be offered "delete_file"

  @setting:mcp.allowRuns
  Scenario: An agent runs what the Run menu offers, once allowed
    Given the project configuration:
      """
      {"plugins": {"mcp": {"allowRuns": true}}}
      """
    And I start the app with the arguments "src/A.php"
    And an agent is connected over MCP
    When the agent calls "run" with:
      """
      {"label": "Run current file: A.php"}
      """
    Then the agent's answer should report the exit code 0

  Scenario: Without leave, there is nothing for an agent to run
    Given I start the app with the arguments "src/A.php"
    When an agent is connected over MCP
    Then the agent should not be offered "run"
    And the agent should not be offered "run_tests"

  @setting:mcp.runTimeout
  Scenario: A run longer than the wait goes on, and the agent is told so
    Given the project configuration:
      """
      {"plugins": {"mcp": {"allowRuns": true, "runTimeout": 1}}}
      """
    And a file "src/Slow.php" containing "<?php sleep(20);"
    And I start the app with the arguments "src/Slow.php"
    And an agent is connected over MCP
    When the agent calls "run" with:
      """
      {"label": "Run current file: Slow.php"}
      """
    Then the agent's answer should contain "Still running after 1s"

  @setting:mcp.lspTimeout
  Scenario: A language server that does not answer in time is given up on
    Given the project configuration, its paths filled in:
      """
      {"lsp": {"php": "%php% %plugins%/mcp/tests/fixtures/code-lsp-server.php"}, "plugins": {"mcp": {"lspTimeout": 1}}}
      """
    And a file "src/B.php" containing:
      """
      <?php
      new A();
      """
    And I start the app with the arguments "src/B.php"
    And I should see "LSP:code-lsp-server"
    And an agent is connected over MCP
    When the agent calls "hover" with:
      """
      {"path": "src/B.php", "line": 10}
      """
    Then the agent's answer should contain "The language server did not answer within 1s"
