<?php
namespace Mouf\NodeJsInstaller\Nodejs\Version;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Util\RemoteFilesystem;

/**
 * A class in charge of retrieving all the available versions of NodeJS.
 */
class Lister
{
    protected $remoteFilesystem;

    const NODEJS_DIST_URL = 'https://nodejs.org/dist/';

    public function __construct(IOInterface $io)
    {
        $config = new Config();

        $this->remoteFilesystem = new RemoteFilesystem($io, $config);
    }

    public function getList()
    {
        // Let's download the content of HTML page https://nodejs.org/dist/
        $html = $this->remoteFilesystem->getContents(
            parse_url(self::NODEJS_DIST_URL, PHP_URL_HOST),
            self::NODEJS_DIST_URL,
            false
        );

        // Now, let's parse it!
        $matches = array();
        preg_match_all("$>v([0-9]*\\.[0-9]*\\.[0-9]*)/<$", $html, $matches);

        if (!isset($matches[1])) {
            throw new \Mouf\NodeJsInstaller\Exception\InstallerException(
                'Error while querying ' . self::NODEJS_DIST_URL . '. Unable to find NodeJS' .
                'versions on this page.'
            );
        }

        return $matches[1];
    }
}
