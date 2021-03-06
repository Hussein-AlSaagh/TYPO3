<?php

/*
 * This file is part of the TYPO3 project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

namespace TYPO3\CMS\Composer\Plugin\Util;

use Composer\Package\PackageInterface;

class T3xDownloaderUtility
{
    /**
     * @param PackageInterface $package
     * @param string $file
     * @param string $path
     */
    public static function extract(PackageInterface $package, $file, $path)
    {
        // get file contents
        $fileContentStream = file_get_contents($file);
        $extensionData = self::decodeTerExchangeData($fileContentStream);

        if (substr($path, -1) !== DIRECTORY_SEPARATOR) {
            $path .= DIRECTORY_SEPARATOR;
        }

        $files = self::extractFilesArrayFromExtensionData($extensionData);
        $directories = self::extractDirectoriesFromExtensionData($files);
        self::createDirectoriesForExtensionFiles($directories, $path);
        self::writeExtensionFiles($files, $path);
        self::writeEmConf($package, $extensionData, $path);
    }

    /**
     * @param PackageInterface $package
     * @param string $path
     */
    public static function getLocalChanges(PackageInterface $package, $path)
    {
        $messages = [];
        $path = rtrim($path, '/') . '/';

        // check if there is a ext_emconf.php
        try {
            $emMetaData = self::getEmConfMetaData($package, $path);
            $extensionFiles = unserialize($emMetaData['_md5_values_when_last_written'], ['allowed_classes' => false]);
            foreach ($extensionFiles as $extensionFileName => $extensionFileHash) {
                if (substr($extensionFileName, -1) === '/') {
                    continue;
                }
                if (is_file($path . $extensionFileName)) {
                    $localFileContentHash = md5(file_get_contents($path . $extensionFileName));

                    if (substr($localFileContentHash, 0, 4) !== $extensionFileHash) {
                        $messages[] = $extensionFileName . ' - File is modified';
                    }
                } else {
                    $messages[] = $extensionFileName . ' - File is missing';
                }
            }

            if ($package->getPrettyVersion() !== $emMetaData['version']) {
                $messages[] = 'Local Version is ' . $emMetaData['version'] . ' but should be ' . $package->getPrettyVersion();
            }

            unset($EM_CONF);
        } catch (\RuntimeException $e) {
            $messages[] = $e->getMessage();
        }

        return implode("\n", $messages);
    }

    /**
     * @param string $path
     * @return array mixed
     */
    public static function getEmConfMetaData(PackageInterface $package, $path)
    {
        if (!is_file($path . 'ext_emconf.php')) {
            throw new \RuntimeException(sprintf('Package "%s" is unstable, "ext_emconf.php" is missing', $package->getName()), 1439568877);
        }
        $_EXTKEY = ExtensionKeyResolver::resolve($package);
        $EM_CONF = [];

        include($path . 'ext_emconf.php');

        if (!isset($EM_CONF) || !is_array($EM_CONF)) {
            throw new \RuntimeException('Package is unstable, "ext_emconf.php" is invalid: missing $EM_CONF array', 1484578406);
        }
        if (!isset($EM_CONF[$_EXTKEY])) {
            throw new \RuntimeException('Package is unstable, "ext_emconf.php" is invalid: missing $EM_CONF[$_EXTKEY]', 1484578650);
        }
        if (!is_array($EM_CONF[$_EXTKEY])) {
            throw new \RuntimeException('Package is unstable, "ext_emconf.php" is invalid: expected array for $EM_CONF[$_EXTKEY], got ' . gettype($EM_CONF[$_EXTKEY]) . ' instead', 1439569163);
        }

        return $EM_CONF[$_EXTKEY];
    }

    /**
     * Returns the "FILES" part from the data array
     *
     * @param array $extensionData
     * @return mixed
     */
    public static function extractFilesArrayFromExtensionData(array $extensionData)
    {
        return $extensionData['FILES'];
    }

    /**
     * Extract needed directories from given extensionDataFilesArray
     *
     * @param array $files
     * @return array
     */
    public static function extractDirectoriesFromExtensionData(array $files)
    {
        $directories = [];
        foreach ($files as $filePath => $file) {
            preg_match('/(.*)\\//', $filePath, $matches);
            if (count($matches) > 0) {
                $directories[] = $matches[0];
            }
        }
        return $directories;
    }

    /**
     * Loops over an array of files and writes them to the given rootPath
     *
     * @param array $files
     * @param string $rootPath
     * @return void
     */
    public static function writeExtensionFiles(array $files, $rootPath)
    {
        foreach ($files as $file) {
            if (empty($file['name']) || substr($file['name'], -1) === '/') {
                continue;
            }
            $filename = $rootPath . $file['name'];
            $content = $file['content'];
            if ($fd = fopen($filename, 'wb')) {
                fwrite($fd, $content);
                fclose($fd);
            }
        }
    }

    /**
     * Loops over an array of directories and creates them in the given root path
     * It also creates nested directory structures
     *
     * @param array $directories
     * @param string $rootPath
     * @return void
     */
    public static function createDirectoriesForExtensionFiles(array $directories, $rootPath)
    {
        foreach ($directories as $directory) {
            self::createNestedDirectory($rootPath . $directory);
        }
    }

    /**
     * Wrapper for utility method to create directory recusively
     *
     * @param string $directory Absolute path
     * @throws \RuntimeException
     */
    public static function createNestedDirectory($directory)
    {
        $currentPath = $directory;
        if (!@is_dir($currentPath)) {
            do {
                $separatorPosition = strrpos($currentPath, DIRECTORY_SEPARATOR);
                $currentPath = substr($currentPath, 0, $separatorPosition);
            } while (!is_dir($currentPath) && $separatorPosition !== false);

            $result = @mkdir($directory, 0777, true);
            if (!$result) {
                throw new \RuntimeException('Could not create directory "' . $directory . '"!', 1170251400);
            }
        }
    }

    /**
     * @param $stream
     * @throws \RuntimeException
     * @return array
     */
    public static function decodeTerExchangeData($stream)
    {
        $parts = explode(':', $stream, 3);
        if ($parts[1] === 'gzcompress') {
            if (function_exists('gzuncompress')) {
                $parts[2] = gzuncompress($parts[2]);
            } else {
                throw new \RuntimeException('Decoding Error: No decompressor available for compressed content. gzcompress()/gzuncompress() functions are not available!', 1359124403);
            }
        }
        if (md5($parts[2]) === $parts[0]) {
            $output = unserialize($parts[2], ['allowed_classes' => false]);
            if (!is_array($output)) {
                throw new \RuntimeException('Error: Content could not be unserialized to an array. Strange (since MD5 hashes match!)', 1359124554);
            }
        } else {
            throw new \RuntimeException('Error: MD5 mismatch. Maybe the extension file was downloaded and saved as a text file and thereby corrupted!?', 1359124556);
        }
        return $output;
    }

    /**
     * Checks whether the passed dependency is TER2-style (array) and returns a
     * single string for displaying the dependencies.
     *
     * It leaves out all version numbers and the "php" and "typo3" dependencies,
     * as they are implicit and of no interest without the version number.
     *
     * @param mixed $dependency Either a string or an array listing dependencies.
     * @param string $type The dependency type to list if $dep is an array
     * @return string A simple dependency list for display
     */
    public static function dependencyToString($dependency, $type = 'depends')
    {
        if (is_array($dependency)) {
            if (isset($dependency[$type]['php'])) {
                unset($dependency[$type]['php']);
            }
            if (isset($dependency[$type]['typo3'])) {
                unset($dependency[$type]['typo3']);
            }
            $dependencyString = !empty($dependency[$type]) ? implode(',', array_keys($dependency[$type])) : '';
            return $dependencyString;
        }
        return '';
    }

    /**
     * Checks whether the passed dependency is TER-style (string) or
     * TER2-style (array) and returns a single string for displaying the
     * dependencies.
     *
     * It leaves out all version numbers and the "php" and "typo3" dependencies,
     * as they are implicit and of no interest without the version number.
     *
     * @param mixed $dependency Either a string or an array listing dependencies.
     * @return string A simple dependency list for display
     */
    public static function stringToDependency($dependency)
    {
        $constraint = [];
        if (is_string($dependency) && strlen($dependency)) {
            $dependency = explode(',', $dependency);
            foreach ($dependency as $v) {
                $constraint[$v] = '';
            }
        }
        return $constraint;
    }

    /**
     * Fix the em conf - Converts old / ter em_conf format to new format
     *
     * @param array $emConf
     * @return array
     */
    public static function fixEmConf(array $emConf)
    {
        if (
            !isset($emConf['constraints']) || !isset($emConf['constraints']['depends'])
            || !isset($emConf['constraints']['conflicts']) || !isset($emConf['constraints']['suggests'])
        ) {
            if (!isset($emConf['constraints']) || !isset($emConf['constraints']['depends'])) {
                $emConf['constraints']['depends'] = isset($emConf['dependencies']) ? self::stringToDependency($emConf['dependencies']) : [];
                if ((string)$emConf['PHP_version'] !== '') {
                    $emConf['constraints']['depends']['php'] = $emConf['PHP_version'];
                }
                if ((string)$emConf['TYPO3_version'] !== '') {
                    $emConf['constraints']['depends']['typo3'] = $emConf['TYPO3_version'];
                }
            }
            if (!isset($emConf['constraints']) || !isset($emConf['constraints']['conflicts'])) {
                $emConf['constraints']['conflicts'] = isset($emConf['conflicts']) ? self::stringToDependency($emConf['conflicts']) : [];
            }
            if (!isset($emConf['constraints']) || !isset($emConf['constraints']['suggests'])) {
                $emConf['constraints']['suggests'] = [];
            }
        } elseif (isset($emConf['constraints']) && isset($emConf['dependencies'])) {
            $emConf['suggests'] = isset($emConf['suggests']) ? $emConf['suggests'] : [];
            $emConf['dependencies'] = self::dependencyToString($emConf['constraints']);
            $emConf['conflicts'] = self::dependencyToString($emConf['constraints'], 'conflicts');
        }

        // Remove TER v1-style entries
        unset($emConf['dependencies']);
        unset($emConf['conflicts']);
        unset($emConf['suggests']);
        unset($emConf['private']);
        unset($emConf['download_password']);
        unset($emConf['TYPO3_version']);
        unset($emConf['PHP_version']);
        unset($emConf['internal']);
        unset($emConf['module']);
        unset($emConf['loadOrder']);
        unset($emConf['lockType']);
        unset($emConf['shy']);
        unset($emConf['priority']);
        unset($emConf['modify_tables']);
        unset($emConf['CGLcompliance']);
        unset($emConf['CGLcompliance_note']);

        return $emConf;
    }

    /**
     * Creates a MD5-hash array over the current files in the extension
     *
     * @param    array $filesArray
     * @return    array MD5-keys
     */
    public static function extensionMD5array(array $filesArray)
    {
        $md5Array = [];

        // Traverse files.
        foreach ($filesArray as $fileName => $fileInfo) {
            $fileName = ltrim($fileName, '/');
            if ($fileName !== 'ext_emconf.php') {
                $md5Array[$fileName] = substr($fileInfo['content_md5'], 0, 4);
            }
        }

        return $md5Array;
    }

    /**
     * Generates the content for the ext_emconf.php file
     *
     * @internal
     * @param array $extensionData
     * @return string
     */
    public static function constructEmConf(array $extensionData)
    {
        $emConf = self::fixEmConf($extensionData['EM_CONF']);
        $emConf['_md5_values_when_last_written'] = serialize(self::extensionMD5array($extensionData['FILES']));
        $uniqueIdentifier = md5($emConf['_md5_values_when_last_written']);
        $emConf = var_export($emConf, true);
        $code = '<?php

/***************************************************************
 * Extension Manager/Repository config file for ext "' . $extensionData['extKey'] . '".
 *
 * Auto generated | Identifier: ' . $uniqueIdentifier . '
 *
 * Manual updates:
 * Only the data in the array - everything else is removed by next
 * writing. "version" and "dependencies" must not be touched!
 ***************************************************************/

$EM_CONF[$_EXTKEY] = ' . $emConf . ';

?>';
        return str_replace('  ', chr(9), $code);
    }

    /**
     * @param array $extensionData
     * @param string $path path of the extension folder
     */
    public static function writeEmConf(PackageInterface $package, array $extensionData, $path)
    {
        $emConfFileData = [];
        if (file_exists($path . 'ext_emconf.php')) {
            $emConfFileData = self::getEmConfMetaData($package, $path);
        }
        $extensionData['EM_CONF'] = array_replace_recursive($emConfFileData, $extensionData['EM_CONF']);
        $emConfContent = self::constructEmConf($extensionData);
        if ($fd = fopen($path . 'ext_emconf.php', 'wb')) {
            fwrite($fd, $emConfContent);
            fclose($fd);
        }
    }
}
