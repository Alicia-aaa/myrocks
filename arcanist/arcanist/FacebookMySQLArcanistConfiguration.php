<?php
// Copyright 2004-present Facebook. All Rights Reserved.

class FacebookMySQLArcanistConfiguration extends ArcanistConfiguration {
  // This hook is called just after this class is instantiated.
  public function updateConfig($working_copy, $configuration_manager) {
    $using_sandcastle = FALSE;
    $console = PhutilConsole::getConsole();

    $tools_config_file = self::pathInTools($working_copy, '.arcconfig');
    if (!Filesystem::pathExists($tools_config_file)) {
      // At this point we have determined that we aren't running in the
      // environment where there's a `tools` directory at the same level as
      // MySQL source code. Let's check if we're inside Sandcastle first.
      if (getenv("SANDCASTLE")) {
        $using_sandcastle = TRUE;
        $tools_config_file = self::pathInSandcastle(
          $working_copy, '.arcconfig');
        $console->writeOut(
          "Operating in Sandcastle. Using: %s\n", $tools_config_file);
      } else {
        $console->writeOut(
          "Operating in Facebook-external mode. File not found: %s\n",
          $tools_config_file
        );

        return;
      }
    }

    $tools_config_data = Filesystem::readFile($tools_config_file);
    $tools_config = json_decode($tools_config_data, true);
    if (!is_array($tools_config)) {
      throw new ArcanistUsageException(
        $tools_config_file." is not a valid JSON file."
      );
    }

    // Load the tool's libraries too.
    foreach (idx($tools_config, 'load', array()) as $location) {
      if ($using_sandcastle) {
        $full_path = self::pathInSandcastle($working_copy, $location);
      } else {
        $full_path = self::pathInTools($working_copy, $location);
      }
      $console->writeOut("Loading library from ".$full_path."\n");
      phutil_load_library($full_path);
    }

    $keys_to_merge = array('unit.engine', 'events.listeners');
    foreach ($keys_to_merge as $key) {
      $value = idx($tools_config, $key);
      if (!is_null($value)) {
        $configuration_manager->setRuntimeConfig($key, $value);
      }
    }
  }

  private static function pathInSandcastle($working_copy, $suffix) {
    $project_root = $working_copy->getProjectRoot();
    return $project_root.'/tools/'.$suffix;
  }

  private static function pathInTools($working_copy, $suffix) {
    $project_root = $working_copy->getProjectRoot();
    return $project_root.'/../tools/'.$suffix;
  }
}
