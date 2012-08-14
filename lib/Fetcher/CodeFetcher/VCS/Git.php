<?php

namespace Fetcher\CodeFetcher\VCS;

class Git implements \Fetcher\CodeFetcher\SetupInterface, \Fetcher\CodeFetcher\UpdateInterface {

  protected $site = FALSE;

  public function __construct(\Pimple $site) {
    $this->site = $site;
  }

  public function setup() {
    $site = $this->site;
    $this->executeGitCommand('clone %s %s --branch=%s --recursive', $this->site['code_fetcher.config']['url'], $this->site['site.code_directory'], $this->site['code_fetcher.config']['branch']);
  }

  public function update() {
    $site = $this->site;
    // If we have a branch set, ensure that we're on it.
    if (isset($site['code_fetcher.config']['branch'])) {
      $this->executeGitCommand('--work-tree=%s --git-dir=%s checkout %s', $site['site.code_directory'], $site['site.code_directory'] . '/.git', $site['code_fetcher.config']['branch']);
    }
    // Pull in the latest code.
    $this->executeGitCommand('pull --work-tree=%s --git-dir=%s');
    // If we have submodules update them.
    if (is_file($this->codeDirectory . '/.gitmodules')) {
      $oldWD = getcwd();
      chdir($this->codeDirectory);
      $this->executeGitCommand('submodule sync');
      $this->executeGitCommand('submodule update --init --recursive');
      chdir($oldWD);
    }
  }

  /**
   * Execute a git command.
   *
   * @param $command
   *   The command to execute without the `git` prefix (e.g. `pull`).
   */
  private function executeGitCommand($command) {

    $args = func_get_args();
    $site = $this->site;

    // By default, allow git to be located automatically within the include path.
    $gitBinary = 'git';
    // If an alternate binary path is specified, use it.
    if (isset($site['git binary'])) {
      $gitBinary = $site['git binary'];
    }
    $args[0] = $gitBinary . ' ' . $args[0];
    $command = call_user_func_array('sprintf', $args);
    $site['log']('Executing `' . $command . '`.');

    // Attempt to ramp up the memory limit and execution time
    // to ensure big or slow chekcouts are not interrupted, storing
    // the current values so they may be restored.
    $timeLimit = ini_get('memory_limit');
    ini_set('memory_limit', 0);
    $memoryLimit = ini_get('max_execution_time');
    ini_set('max_execution_time', 0);

    $process = $site['process']($command);
    if (!$site['simulate']) {
      // Git operations can run long, set our timeout to an hour.
      $process->setTimeout(3600);
      $process->run(function ($type, $buffer) {
        if ('err' === $type) {
          drush_print_prompt('Git Status: '.$buffer, 4);
        } else {
          drush_print_prompt('Git Output: '.$buffer, 4);
        }
      });
    }

    // Restore the memory limit and execution time.
    ini_set('memory_limit', $timeLimit);
    ini_set('max_execution_time', $memoryLimit);

    if (!$process->isSuccessful()) {
      throw new \Exception('Executing Git command failed: `' . $command . '`.  Git responded with: ' . $process->getErrorOutput() . ' ' . $process->getOutput());
    }

    return $process->isSuccessful();
  }
}