<?php

/**
 * Pickle.
 *
 * @license
 *
 * New BSD License
 *
 * Copyright © 2015-2015, Pickle community. All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *     * Redistributions of source code must retain the above copyright
 *       notice, this list of conditions and the following disclaimer.
 *     * Redistributions in binary form must reproduce the above copyright
 *       notice, this list of conditions and the following disclaimer in the
 *       documentation and/or other materials provided with the distribution.
 *     * Neither the name of the Hoa nor the names of its contributors may be
 *       used to endorse or promote products derived from this software without
 *       specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDERS AND CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace Pickle\Package\Util\Header;

use Composer\Package\Version\VersionParser;
use Pickle\Base\Interfaces;
use Pickle\Package\Util;
use Symfony\Component\Finder\Finder;

class Version
{
    protected $package;
    protected $header;
    protected $version;
    protected $macroName;

    public function __construct(Interfaces\Package $package)
    {
        $this->package = $package;
        $this->macroName = $this->getMacroName();

        $this->version = $this->getVersionFromHeader();
    }

    public function getMacroName($prefix = 'PHP_')
    {
        return $prefix.strtoupper($this->package->getSimpleName()).'_VERSION';
    }

    protected function findHeaders()
    {
        //$header = $this->package->getSourceDir().DIRECTORY_SEPARATOR.'php_'.$this->package->getSimpleName().'.h';

        if (!file_exists($header) || !$this->fileHasVersionMacro($header)) {
            $headers = (array) glob($this->package->getSourceDir().\DIRECTORY_SEPARATOR.'*.h');
            $found = false;
            foreach ($headers as $h) {
                if ($this->fileHasVersionMacro($h)) {
                    $header = $h;
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                throw new \Exception("No macro named {$this->macroName} was found in the headers. ".'This macro is recommended to be defined with the current extension version');
            }
        }

        return $header;
    }

    public function fileHasVersionMacro($fname)
    {
        $cont = file_get_contents($fname);

        return false !== strstr($cont, $this->macroName);
    }

    public function getVersionFromHeader()
    {
        // $headers = glob($this->package->getSourceDir().DIRECTORY_SEPARATOR.'*.h');

        $finder = new Finder();
        $headers = $finder->files()
        ->in($this->package->getSourceDir())
        ->name('*.h');

        // Match versions surrounded by quotes and versions without quotes
        // #define PHP_YAML_VERSION "2.0.5-dev"
        $versionMatcher = '(".*"|.*\b)';
        $pat = ',define\s+'.preg_quote($this->macroName, ',').'\s+'.$versionMatcher.',i';

        // xdebug
        // #define XDEBUG_VERSION    "2.8.0beta2"
        $pat2 = ',define\s+'.preg_quote($this->getMacroName(''), ',').'\s+'.$versionMatcher.',i';

        foreach ($headers as $header) {
            // $header->getRealPath()
            $headerContent = file_get_contents($header);
            if (!$headerContent) {
                // throw new \Exception("Could not read $header");
                continue;
            }
            if (preg_match($pat, $headerContent, $result) || preg_match($pat2, $headerContent, $result)) {
                // Remove any quote characters we may have matched on
                $version = trim($result[1], '"');

                // check version format
                try {
                    (new VersionParser())->normalize($version);
                } catch (\Exception $e) {
                    // 版本号格式不正确,继续遍历(寻找)
                    continue;
                }

                return $version;
            }
        }
        // 版本号解析错误，返回 0.0.0
        return '0.0.0';

        // throw new \Exception("Couldn't parse the version defined in the {$this->macroName} macro");
    }

    public function updateJSON()
    {
        if ($this->package->getPrettyVersion() == $this->version) {
            /* Don't touch, it's the same. */
            return;
        }

        $dumper = new Util\Dumper();
        $composer_json = $this->package->getRootDir().\DIRECTORY_SEPARATOR.'composer.json';

        $this->package->replaceVersion((new VersionParser())->normalize($this->version), $this->version);

        $len = file_put_contents($composer_json, json_encode($dumper->dump($this->package), \JSON_PRETTY_PRINT));

        if (!$len) {
            throw new \Exception("Failed to update '$composer_json'");
        }
    }

    public function __toString()
    {
        return $this->version;
    }
}

/* vim: set tabstop=4 shiftwidth=4 expandtab: fdm=marker */
