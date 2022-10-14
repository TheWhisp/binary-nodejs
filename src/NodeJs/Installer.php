<?php
namespace Mouf\NodeJsInstaller\Nodejs;

use Composer\Downloader\TarDownloader;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use FilesystemIterator;
use Mouf\NodeJsInstaller\Utils\FileUtils;
use Mouf\NodeJsInstaller\Composer\Environment;
use Mouf\NodeJsInstaller\Composer\Internal\Files;
use React\Promise\FulfilledPromise;
use React\Promise\Promise;
use React\Promise\PromiseInterface;
use React\Promise\RejectedPromise;

class Installer
{
    /**
     * @var \Composer\Package\PackageInterface
     */
    private $ownerPackage;

    /**
     * @var IOInterface
     */
    private $cliIo;

    /**
     * @var \Composer\Package\Version\VersionParser
     */
    private $versionParser;

    /**
     * @var string
     */
    private $vendorDir;

    /**
     * @var \Composer\Downloader\DownloadManager
     */
    private $downloadManager;

    public function __construct(
        \Composer\Package\PackageInterface $ownerPackage,
        \Composer\Downloader\DownloadManager $downloadManager,
        IOInterface $cliIo,
        $vendorDir
    ) {
        $this->ownerPackage = $ownerPackage;
        $this->downloadManager = $downloadManager;
        $this->cliIo = $cliIo;
        $this->vendorDir = $vendorDir;

        $this->versionParser = new \Composer\Package\Version\VersionParser();
    }

    /**
     * Checks if NodeJS is installed globally.
     * If yes, will return the version number.
     * If no, will return null.
     *
     * Note: trailing "v" will be removed from version string.
     *
     * @return null|string
     */
    public function getNodeJsGlobalInstallVersion()
    {
        $returnCode = 0;
        $output = '';

        ob_start();
        $version = exec('nodejs -v 2>&1', $output, $returnCode);
        ob_end_clean();

        if ($returnCode !== 0) {
            ob_start();
            $version = exec('node -v 2>&1', $output, $returnCode);
            ob_end_clean();

            if ($returnCode !== 0) {
                return;
            }
        }

        return ltrim($version, 'v');
    }

    /**
     * Returns the full path to NodeJS global install (if available).
     */
    public function getNodeJsGlobalInstallPath()
    {
        $pathToNodeJS = $this->getGlobalInstallPath('nodejs');

        if (!$pathToNodeJS) {
            $pathToNodeJS = $this->getGlobalInstallPath('node');
        }

        return $pathToNodeJS;
    }

    /**
     * Returns the full install path to a command
     *
     * @param string $command
     * @return string
     */
    public function getGlobalInstallPath($command)
    {
        if (Environment::isWindows()) {
            $result = trim(
                shell_exec('where /F ' . escapeshellarg($command)),
                "\n\r"
            );

            // "Where" can return several lines.
            $lines = explode("\n", $result);

            return $lines[0];
        } else {
            // We want to get output from stdout, not from stderr.
            // Therefore, we use proc_open.
            $descriptorSpec = array(
                0 => array('pipe', 'r'),  // stdin
                1 => array('pipe', 'w'),  // stdout
                2 => array('pipe', 'w'),  // stderr
            );
            $pipes = array();

            $process = proc_open('which ' . escapeshellarg($command), $descriptorSpec, $pipes);

            $stdout = stream_get_contents($pipes[1]);
            fclose($pipes[1]);

            // Let's ignore stderr (it is possible we do not find anything and depending on the OS, stderr will
            // return things or not)
            fclose($pipes[2]);

            proc_close($process);

            return trim($stdout, "\n\r");
        }
    }

    /**
     * Checks if NodeJS is installed locally.
     *
     * If yes, will return the version number.
     * If no, will return null.
     *
     * Note: trailing "v" will be removed from version string.
     *
     * @param string $binDir
     *
     * @return null|string
     */
    public function getNodeJsLocalInstallVersion($binDir)
    {
        $returnCode = 0;
        $output = '';

        $cwd = getcwd();

        $projectRoot = FileUtils::getClosestFilePath($this->vendorDir, Files::PACKAGE_CONFIG);

        chdir($projectRoot);

        ob_start();

        $cmd = FileUtils::composePath($binDir, 'node -v 2>&1');

        $version = exec($cmd, $output, $returnCode);

        ob_end_clean();

        chdir($cwd);

        if ($returnCode !== 0) {
            return null;
        }

        return ltrim($version, 'v');
    }

    private function getArchitectureLabel()
    {
        $code = Environment::getArchitecture();

        $labels = array(
            32 => 'x86',
            64 => 'x64'
        );

        return isset($labels[$code]) ? $labels[$code] : $code;
    }

    private function getOsLabel()
    {
        $osLabel = '';

        $isArm = Environment::isLinux() && Environment::isArm();

        if (Environment::isMacOS()) {
            $osLabel = 'darwin';
        } elseif (Environment::isSunOS()) {
            $osLabel =  'sunos';
        } elseif ($isArm && Environment::isArmV6l()) {
            $osLabel = 'linux-armv6l';
        } elseif ($isArm && Environment::isArmV7l()) {
            $osLabel = 'linux-armv7l';
        } elseif ($isArm && Environment::getArchitecture() === 64) {
            $osLabel = 'linux-arm64';
        } elseif (Environment::isLinux()) {
            $osLabel = 'linux';
        } elseif (Environment::isWindows()) {
            $osLabel = 'windows';
        }

        if (!$osLabel) {
            throw new \Mouf\NodeJsInstaller\Exception\InstallerException(
                'Unsupported architecture: ' . PHP_OS . ' - ' . Environment::getArchitecture() . ' bits'
            );
        }

        return $osLabel;
    }

    /**
     * Returns URL based on version.
     *
     * URL is dependent on environment
     *
     * @param string $version
     * @return string
     *
     * @throws \Mouf\NodeJsInstaller\Exception\InstallerException
     */
    public function getDownloadUrl($version)
    {

        $baseUrl = \Mouf\NodeJsInstaller\NodeJs\Version\Lister::NODEJS_DIST_URL . 'v{{VERSION}}';
        $downloadPath = '';

        if (Environment::isWindows()) {
            $binaryName = 'node.exe';

            if (version_compare($version, '4.0.0') >= 0) {
                $downloadPath = FileUtils::composePath('win-{{ARCHITECTURE}}', $binaryName);
            } else {
                $downloadPath = Environment::getArchitecture() === 32
                    ? $binaryName
                    : FileUtils::composePath('{{ARCHITECTURE}}', $binaryName);
            }
        } elseif (Environment::isMacOS() || Environment::isSunOS() || Environment::isLinux()) {
            $downloadPath = 'node-v{{VERSION}}-{{OS}}-{{ARCHITECTURE}}.tar.gz';
        } elseif (Environment::isLinux() && Environment::isArm()) {
            if (version_compare($version, '4.0.0') < 0) {
                throw new \Mouf\NodeJsInstaller\Exception\InstallerException(
                    'NodeJS-installer cannot install Node <4.0 on computers with ARM processors. Please ' .
                    'install NodeJS globally on your machine first, then run composer again, or consider ' .
                    'installing a version of NodeJS >=4.0.'
                );
            }

            if (Environment::isArmV6l() || Environment::isArmV7l() || Environment::getArchitecture()) {
                $downloadPath = 'node-v{{VERSION}}-{{OS}}.tar.gz';
            } else {
                throw new \Mouf\NodeJsInstaller\Exception\InstallerException(
                    'NodeJS-installer cannot install Node on computers with ARM 32bits processors ' .
                    'that are not v6l or v7l. Please install NodeJS globally on your machine first, ' .
                    'then run composer again.'
                );
            }
        }

        return str_replace(
            array('{{VERSION}}', '{{ARCHITECTURE}}', '{{OS}}'),
            array($version, $this->getArchitectureLabel(), $this->getOsLabel()),
            $baseUrl . '/' . $downloadPath
        );
    }

    public function download(\Composer\Package\PackageInterface $package, $targetDir = null)
    {
        try {
            /** @var \Composer\Downloader\DownloaderInterface $downloader */
            $downloader = $this->downloadManager->getDownloaderForPackage($package);

            /**
             * Some downloader types have the option to mute the output,
             * which is why there is the third call argument (not present
             * in interface footprint).
             */
            $promise = $downloader->download($package, $targetDir, null, true);

            if (!$promise) {
                throw new \Exception('Could not download the file');
            }

            return $promise;
        } catch (\Exception $exception) {
            $errorMessage = sprintf(
                'Unexpected error while downloading v%s: %s',
                $package->getVersion(),
                $exception->getMessage()
            );

            throw new \Exception($errorMessage);
        }
    }

    public function getInstallPath(\Composer\Package\PackageInterface $package)
    {
        return FileUtils::composePath($this->vendorDir, $package->getTargetDir());
    }

    public function install($version)
    {
        if ($this->isInstalled($this->versionParser->normalize($version))) {
            $this->cliIo->write(sprintf('NodeJS <info>v%s</info> is already installed', $version));

            return null;
        }

        $this->cliIo->write(
            sprintf('Installing <info>NodeJS v%s</info>', $version)
        );

        $ownerName = $this->ownerPackage->getName();

        $relativePath = FileUtils::composePath(
            $ownerName,
            'downloads',
            'nodejs'
        );

        $nodePackage = $this->createPackage(
            sprintf('%s-virtual', $ownerName),
            $version,
            $relativePath
        );

        $fullPath = FileUtils::composePath($this->vendorDir, $relativePath);

        $this->cliIo->write(
            sprintf('Downloading <info>%s</info> to <info>%s</info>', $nodePackage->getName(), $fullPath)
        );

        $promise = $this->download($nodePackage, $fullPath);

        $promise->then(function ($fullPath) use ($nodePackage) {
            $this->cliIo->write(sprintf('Downloaded <info>%s</info>', $fullPath));

            $this->installDownloadedPackage($nodePackage);
        },
        function () {
            $this->cliIo->writeError('Package could not be downloaded');
        });

        return $nodePackage;
    }

    /**
     * @param PackageInterface $package
     * @return void
     */
    protected function installDownloadedPackage(PackageInterface $package): void
    {
        /** @var TarDownloader $downloader */
        $downloader = $this->downloadManager->getDownloaderForPackage($package);

        $installDir = $this->getInstallDir();

        $this->cliIo->write(
            sprintf('Installing to <info>%s</info>', $installDir)
        );

        $downloader->install($package, $installDir);

        $sourceDir = sprintf('%slib/node_modules', $this->getInstallDir());
        $sourceDir = str_replace('/', DIRECTORY_SEPARATOR, $sourceDir);

        $targetDir = sprintf('%s/lib/node_modules', getcwd());
        $targetDir = str_replace('/', DIRECTORY_SEPARATOR, $targetDir);

        $this->deleteDirectory($targetDir);

        $this->cliIo->write(
            sprintf('Finishing installation, moving: <info>%s</info> to <info>%s</info>', $sourceDir, $targetDir)
        );
        rename($sourceDir, $targetDir);

        $this->deleteDirectory($this->getInstallDir());

        $this->deleteInstallFiles();
        $this->createInstallVersionFile($package->getVersion());

        $this->cliIo->write(sprintf('<info>Done. Installed NodeJS v%s</info>', $package->getVersion()));
    }

    /**
     * @param string $version
     * @return void
     */
    protected function createInstallVersionFile(string $version): void
    {
        $file = sprintf('%s/lib/node_%s.txt', getcwd(), str_replace('.', '_', $version));

        file_put_contents($file, json_encode(['version' => $version]));
    }

    /**
     * @param string $version
     * @return bool
     */
    protected function isInstalled(string $version): bool
    {
        $file = sprintf('%s/lib/node_%s.txt', getcwd(), str_replace('.', '_', $version));

        return file_exists($file);
    }

    /**
     * @return void
     */
    protected function deleteInstallFiles(): void
    {
        $files = sprintf('%s/lib/node_*.txt', getcwd());

        array_map('unlink', glob($files));
    }

    /**
     * @return string
     */
    protected function getInstallDir(): string
    {
        $targetDir = sprintf('%s/lib/node_modules_install/', getcwd());
        $targetDir = str_replace('/', DIRECTORY_SEPARATOR, $targetDir);

        if (!file_exists($targetDir)) {
            $this->cliIo->write(
                sprintf('Creating directory <info>%s</info>', $targetDir)
            );

            mkdir($targetDir, 0775, true);
        }

        return $targetDir;
    }

    /**
     * @param string $path
     * @param bool $isPrint
     * @return bool
     */
    protected function deleteDirectory(string $path, bool $isPrint = true): bool
    {
        if (!file_exists($path)) {
            return false;
        }
        
        $iterator = new \RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS);
        $files = new \RecursiveIteratorIterator($iterator, \RecursiveIteratorIterator::CHILD_FIRST);

        foreach( $files as $file) {
            if ($file->isDir()){
                rmdir($file->getRealPath());
            } else {
                unlink($file->getRealPath());
            }
        }

        $result = rmdir($path);

        if ($isPrint) {
            if ($result) {
                $this->cliIo->write(
                    sprintf('Deleted directory: %s', $path)
                );
            } else {
                $this->cliIo->writeError(
                    sprintf('Could not delete: %s', $path)
                );
            }
        }

        return $result;
    }

    public function createPackage($name, $version, $targetDir, $binFiles = array())
    {
        $remoteFile = $this->getDownloadUrl($version);

        $package = new \Composer\Package\Package(
            $name,
            $this->versionParser->normalize($version),
            $version
        );

        if (Environment::isWindows()) {
            $binFiles = array_map(function ($item) {
                return $item . '.bat';
            }, $binFiles);
        }

        $package->setBinaries($binFiles);
        $package->setInstallationSource('dist');

        $package->setDistType(
            $this->resolveDistType($remoteFile)
        );

        $package->setTargetDir($targetDir);
        $package->setDistUrl($remoteFile);

        return $package;
    }

    private function resolveDistType($remoteFile)
    {
        switch (pathinfo($remoteFile, PATHINFO_EXTENSION)) {
            case 'zip':
                return 'zip';
            case 'exe':
                return 'file';
        }

        return 'tar';
    }

    public function createBinScripts($binDir, $targetDir, $isLocal)
    {
        $cwd = getcwd();

        $projectRoot = FileUtils::getClosestFilePath($this->vendorDir, Files::PACKAGE_CONFIG);

        chdir($projectRoot);

        if (!file_exists($binDir)) {
            $result = mkdir($binDir, 0775, true);
            if ($result === false) {
                throw new \Mouf\NodeJsInstaller\Exception\InstallerException(
                    'Unable to create directory ' . $binDir
                );
            }
        }

        $fullTargetDir = realpath($targetDir);
        $binDir = realpath($binDir);

        $binFiles = array('node', 'npm');

        if (Environment::isWindows()) {
            $binFiles = array_map(function ($item) {
                return $item . '.bat';
            }, $binFiles);
        }

        foreach ($binFiles as $binFile) {
            $this->createBinScript($binDir, $fullTargetDir, $binFile, $binFile, $isLocal);
        }

        chdir($cwd);
    }

    /**
     * Copy script into $binDir, replacing PATH with $fullTargetDir
     *
     * @param string $binDir
     * @param string $fullTargetDir
     * @param string $scriptName
     * @param string $target
     * @param bool $isLocal
     */
    private function createBinScript($binDir, $fullTargetDir, $scriptName, $target, $isLocal)
    {
        $packageRoot = FileUtils::getClosestFilePath(__DIR__, Files::PACKAGE_CONFIG);
        $binScriptPath = FileUtils::composePath($packageRoot, 'bin', ($isLocal ? 'local' : 'global'), $scriptName);

        $content = file_get_contents($binScriptPath);

        if ($isLocal) {
            $path = rtrim($this->makePathRelative($fullTargetDir, $binDir), DIRECTORY_SEPARATOR);
        } else {
            if ($scriptName === 'node') {
                $path = $this->getNodeJsGlobalInstallPath();
            } else {
                $path = $this->getGlobalInstallPath($target);
            }

            if (strpos($path, $binDir) === 0) {
                // we found the local installation that already exists.

                return;
            }
        }

        $scriptPath = FileUtils::composePath($binDir, $scriptName);

        file_put_contents($scriptPath, sprintf($content, $path));

        chmod($scriptPath, 0755);
    }

    /**
     * Shamelessly stolen from Symfony's FileSystem. Thanks guys!
     * Given an existing path, convert it to a path relative to a given starting path.
     *
     * @param string $endPath Absolute path of target
     * @param string $startPath Absolute path where traversal begins
     *
     * @return string Path of target relative to starting path
     */
    private function makePathRelative($endPath, $startPath)
    {
        // Normalize separators on Windows
        if ('\\' === DIRECTORY_SEPARATOR) {
            $endPath = strtr($endPath, '\\', '/');
            $startPath = strtr($startPath, '\\', '/');
        }

        // Split the paths into arrays
        $startPathArr = explode('/', trim($startPath, '/'));
        $endPathArr = explode('/', trim($endPath, '/'));
        // Find for which directory the common path stops
        $index = 0;

        while (isset($startPathArr[$index], $endPathArr[$index]) && $startPathArr[$index] === $endPathArr[$index]) {
            $index++;
        }

        // Determine how deep the start path is relative to the common path (ie, "web/bundles" = 2 levels)
        $depth = count($startPathArr) - $index;

        $traverser = str_repeat('../', $depth);
        $endPathRemainder = implode('/', array_slice($endPathArr, $index));

        // Construct $endPath from traversing to the common path, then to the remaining $endPath
        $relativePath = $traverser . ($endPathRemainder !== '' ? $endPathRemainder . '/' : '');

        return ($relativePath === '') ? './' : $relativePath;
    }
}
