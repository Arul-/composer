<?php

/*
 * This file is part of Composer.
 *
 * (c) Nils Adermann <naderman@naderman.de>
 *     Jordi Boggiano <j.boggiano@seld.be>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Composer\Downloader;

use Composer\Package\PackageInterface;

/**
 * Base downloader for archives
 *
 * @author Kirill chEbba Chebunin <iam@chebba.org>
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @author François Pluchino <francois.pluchino@opendisplay.com>
 */
abstract class ArchiveDownloader extends FileDownloader
{
    /**
     * {@inheritDoc}
     */
    public function download(PackageInterface $package, $path)
    {
        parent::download($package, $path);

        $fileName = $this->getFileName($package, $path);
        if ($this->io->isVerbose()) {
            $this->io->write('    Unpacking archive');
        }
        try {
            try {
                $this->extract($fileName, $path);
            } catch (\Exception $e) {
                // remove cache if the file was corrupted
                parent::clearCache($package, $path);
                throw $e;
            }

            if ($this->io->isVerbose()) {
                $this->io->write('    Cleaning up');
            }
            unlink($fileName);

            // If we have only a one dir inside it suppose to be a package itself
            $contentDir = glob($path . '/*');
            if (1 === count($contentDir)) {
                $contentDir = $contentDir[0];

                if (is_file($contentDir)) {
                    $this->filesystem->rename($contentDir, $path . '/' . basename($contentDir));
                } else {
                    // Rename the content directory to avoid error when moving up
                    // a child folder with the same name
                    $temporaryDir = sys_get_temp_dir().'/'.md5(time().rand());
                    $this->filesystem->rename($contentDir, $temporaryDir);
                    $contentDir = $temporaryDir;

                    foreach (array_merge(glob($contentDir . '/.*'), glob($contentDir . '/*')) as $file) {
                        if (trim(basename($file), '.')) {
                            $this->filesystem->rename($file, $path . '/' . basename($file));
                        }
                    }

                    $this->filesystem->removeDirectory($contentDir);
                }
            }
        } catch (\Exception $e) {
            // clean up
            $this->filesystem->removeDirectory($path);
            throw $e;
        }

        $this->io->write('');
    }

    /**
     * {@inheritdoc}
     */
    protected function getFileName(PackageInterface $package, $path)
    {
        return rtrim($path.'/'.md5($path.spl_object_hash($package)).'.'.pathinfo($package->getDistUrl(), PATHINFO_EXTENSION), '.');
    }

    /**
     * {@inheritdoc}
     */
    protected function processUrl(PackageInterface $package, $url)
    {
        if ($package->getDistReference() && strpos($url, 'github.com')) {
            if (preg_match('{^https?://(?:www\.)?github\.com/([^/]+)/([^/]+)/(zip|tar)ball/(.+)$}i', $url, $match)) {
                // update legacy github archives to API calls with the proper reference
                $url = 'https://api.github.com/repos/' . $match[1] . '/'. $match[2] . '/' . $match[3] . 'ball/' . $package->getDistReference();
            } elseif ($package->getDistReference() && preg_match('{^https?://(?:www\.)?github\.com/([^/]+)/([^/]+)/archive/.+\.(zip|tar)(?:\.gz)?$}i', $url, $match)) {
                // update current github web archives to API calls with the proper reference
                $url = 'https://api.github.com/repos/' . $match[1] . '/'. $match[2] . '/' . $match[3] . 'ball/' . $package->getDistReference();
            } elseif ($package->getDistReference() && preg_match('{^https?://api\.github\.com/repos/([^/]+)/([^/]+)/(zip|tar)ball(?:/.+)?$}i', $url, $match)) {
                // update api archives to the proper reference
                $url = 'https://api.github.com/repos/' . $match[1] . '/'. $match[2] . '/' . $match[3] . 'ball/' . $package->getDistReference();
            }
        }

        if (!extension_loaded('openssl') && (0 === strpos($url, 'https:') || 0 === strpos($url, 'http://github.com'))) {
            // bypass https for github if openssl is disabled
            if (preg_match('{^https://api\.github\.com/repos/([^/]+/[^/]+)/(zip|tar)ball/([^/]+)$}i', $url, $match)) {
                $url = 'http://nodeload.github.com/'.$match[1].'/'.$match[2].'/'.$match[3];
            } elseif (preg_match('{^https://github\.com/([^/]+/[^/]+)/(zip|tar)ball/([^/]+)$}i', $url, $match)) {
                $url = 'http://nodeload.github.com/'.$match[1].'/'.$match[2].'/'.$match[3];
            } elseif (preg_match('{^https://github\.com/([^/]+/[^/]+)/archive/([^/]+)\.(zip|tar\.gz)$}i', $url, $match)) {
                $url = 'http://nodeload.github.com/'.$match[1].'/'.$match[3].'/'.$match[2];
            } else {
                throw new \RuntimeException('You must enable the openssl extension to download files via https');
            }
        }

        return parent::processUrl($package, $url);
    }

    /**
     * Extract file to directory
     *
     * @param string $file Extracted file
     * @param string $path Directory
     *
     * @throws \UnexpectedValueException If can not extract downloaded file to path
     */
    abstract protected function extract($file, $path);
}
