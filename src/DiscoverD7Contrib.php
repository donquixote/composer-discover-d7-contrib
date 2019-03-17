<?php

namespace donquixote\Composer;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\IO\IOInterface;
use Composer\Package\Link;
use Composer\Package\RootPackageInterface;
use Composer\Plugin\PluginInterface;
use Composer\Semver\VersionParser;

class DiscoverD7Contrib implements PluginInterface, EventSubscriberInterface {

  /**
   * Returns an array of event names this subscriber wants to listen to.
   *
   * The array keys are event names and the value can be:
   *
   * * The method name to call (priority defaults to 0)
   * * An array composed of the method name to call and the priority
   * * An array of arrays composed of the method names to call and respective
   *   priorities, or 0 if unset
   *
   * For instance:
   *
   * * array('eventName' => 'methodName')
   * * array('eventName' => array('methodName', $priority))
   * * array('eventName' => array(array('methodName1', $priority), array('methodName2'))
   *
   * @return array The event names to listen to
   */
  public static function getSubscribedEvents() {
    return [];
  }

  /**
   * Apply plugin modifications to Composer
   *
   * @param Composer $composer
   * @param IOInterface $io
   */
  public function activate(Composer $composer, IOInterface $io) {

    $rootPackage = $composer->getPackage();

    $requires = $rootPackage->getRequires();
    $requires += self::discoverRequires($io, $rootPackage);
    $rootPackage->setRequires($requires);
  }

  /**
   * @param \Composer\IO\IOInterface $io
   * @param \Composer\Package\RootPackageInterface $rootPackage
   *
   * @return \Composer\Package\Link[]|null[]
   *   Format: $["drupal/$name"] = new Link(..)|null
   */
  public static function discoverRequires(IOInterface $io, RootPackageInterface $rootPackage) {

    $versionParser = new VersionParser();

    $extra = $rootPackage->getExtra();
    if (empty($extra['discover-d7-contrib'])) {
      return [];
    }

    $contrib_dir = $extra['discover-d7-contrib'];

    if (!is_dir($contrib_dir)) {
      return [];
    }

    $discovered_requires = [];
    foreach (self::discoverVersions($io, $contrib_dir) as $module_package_name => $semver_or_null) {
      $composer_package_name = "drupal/$module_package_name";
      $discovered_requires[$composer_package_name] = new Link(
        $rootPackage->getName(),
        $composer_package_name,
        $versionParser->parseConstraints($semver_or_null),
        'requires',
        $semver_or_null);
    }

    return $discovered_requires;
  }

  /**
   * @param \Composer\IO\IOInterface $io
   * @param string $contrib_dir
   *
   * @return string[]
   *   Format: $[$module_package_name] = $semantic_version|null
   */
  public static function discoverVersions(IOInterface $io = NULL, $contrib_dir) {

    $stats = [];
    $semantic_versions = [];
    foreach (glob($contrib_dir . '/*/*.info') as $file) {
      $module_package_name = basename(dirname($file));
      $module_name = basename($file, '.info');
      $path = "../sites/all/modules/contrib/$module_package_name/$module_name.info";
      $info_file_contents = file_get_contents($file);
      if (!preg_match('@\\nversion = "7\.x-(\d+\..+)"\\n@', $info_file_contents, $m)) {
        $io && $io->write("No module version found in '$path'. Skipping.");
        $stats[$module_package_name][$module_name] = NULL;
        continue;
      }
      $version_string = $m[1];
      if (NULL === $semver = self::versionGetSemver($version_string)) {
        $io && $io->write("Invalid version string '$version_string' found in '$path'. Skipping.");
        $stats[$module_package_name][$module_name] = "? <- $version_string";
        continue;
      }
      $stats[$module_package_name][$module_name] = "$semver <- $version_string";
      $semantic_versions[$module_package_name] = $semver;
    }

    # return $stats;

    return $semantic_versions;
  }

  private static function versionGetSemver($version_string) {
    if (preg_match('@^(\d+\.\d+)$@', $version_string, $m)) {
      // 3.2 -> 3.2.0
      return $m[1] . '.0';
    }
    if (preg_match('@^(\d+\.\d+-\w+\d*)$@', $version_string, $m)) {
      // 3.0-alpha1 -> 3.0-alpha1
      return $m[1];
    }
    if (preg_match('@^(\d+)\.x-dev$@', $version_string, $m)) {
      return NULL;
    }
    if (preg_match('@^(\d+\.\d+)(|-\w+\d*)\+(\d+)-dev$@', $version_string, $m)) {
      return NULL;
    }
    return NULL;
  }
}
