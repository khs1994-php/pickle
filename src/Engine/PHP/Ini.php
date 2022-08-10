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

namespace Pickle\Engine\PHP;

use Pickle\Base\Abstracts;
use Pickle\Base\Interfaces;

class Ini extends Abstracts\Engine\Ini implements Interfaces\Engine\Ini
{
    private $ZEND_EXTENSION = [
      'xdebug',
      'php_xdebug.dll',
      'xdebug.so',
      'opcache',
      'php_opcache.dll',
      'opcache.so',
    ];

    public function __construct(Interfaces\Engine $php)
    {
        parent::__construct($php);

        $this->setupPickleSectionPositions();
    }

    /**
     * @param string $pickleSection
     * @param array  $dlls
     *
     * @return string
     */
    protected function rebuildPickleParts($pickleSection, array $dlls_add, array $dlls_del = [])
    {
        $lines = explode("\n", $pickleSection);
        $new = [];

        /* First add the lines for exts that are requested to be added. */
        foreach ($dlls_add as $dll) {
            $new[] = $this->buildDllIniLine($dll);
        }

        /* Then, go over the existing lines, restore those that are not
            requested to be deleted and not already added. */
        foreach ($lines as $l) {
            $l = trim($l);
            if (0 !== strpos($l, 'extension') && 0 !== strpos($l, 'zend_extension')) {
                // 不是以 extension 和 zend_extension 开头
                continue;
            }
            list(, $dllname) = explode('=', $l);

            if (\in_array(trim($dllname), $dlls_add)) {
                /* don't create a duplicated item */
                continue;
            }
            if (\in_array(trim($dllname), $dlls_del)) {
                /* don't restore as it should be deleted */
                continue;
            }
            $new[] = $l;
        }

        sort($new);

        return implode("\n", $new);
    }

    protected function setupPickleSectionPositions()
    {
        $posHeader = strpos($this->raw ?? '', self::PICKLE_HEADER);
        if (false === $posHeader) {
            /* no pickle section here yet */
            $this->pickleHeaderStartPos = \strlen($this->raw ?? '');

            return;
        }

        $this->pickleHeaderStartPos = $posHeader;
        $this->pickleHeaderEndPos = $this->pickleHeaderStartPos + \strlen(self::PICKLE_HEADER);

        $posFooter = strpos($this->raw, self::PICKLE_FOOTER);
        if (false === $posFooter) {
            /* This is bad, no end of section marker, will have to lookup. The strategy is
                - look for the last extension directve after the header
                - extension directives are expected to come one after another one per line
                - comments are not expected inbetveen
                - mark the next pos after the last extension directive as the footer pos
            */
            $pos = $this->pickleHeaderEndPos;
            do {
                $pos = strpos($this->raw, 'extension', $pos);
                if (false !== $pos) {
                    $this->pickleFooterStartPos = $pos;
                    ++$pos;
                }
            } while (false !== $pos);

            $this->pickleFooterStartPos = strpos($this->raw, "\n", $this->pickleFooterStartPos);
        } else {
            $this->pickleFooterStartPos = $posFooter;
            $this->pickleFooterEndPos = $this->pickleFooterStartPos + \strlen(self::PICKLE_FOOTER);
        }
    }

    /**
     * @param ext xdebug xdebug.so
     */
    protected function isZendExtension($ext)
    {
        if (false !== strpos($ext, '.so')) {
            $ext = substr($ext, 0, -3);
        }

        return \in_array($ext, ['xdebug', 'opcache']);
    }

    /**
     * @param exts 'curl.so'
     */
    public function updatePickleSectionOnLinux(array $exts_add, $phpIniDir, $no_write)
    {
        if (!$phpIniDir) {
            return;
        }

        $packageName = $exts_add[0];

        if ($this->ge72()) {
            if ($pos = strpos($packageName, '.so')) {
                false !== $pos && $packageName = substr($packageName, 0, $pos);
            }
        }

        $phpIniDirContent = ($this->isZendExtension($packageName) ? 'zend_' : '')
            .'extension='.$packageName;

        $packageRealName = $packageName;
        if ($pos = strpos($packageName, '.so')) {
            false !== $pos && $packageRealName = substr($packageName, 0, $pos);
        }

        $phpIniDirFile = $phpIniDir.\DIRECTORY_SEPARATOR.'php-ext-'.$packageRealName.'.ini';

        if ($no_write) {
            $phpIniDirFile .= '.default';
        }

        file_put_contents($phpIniDirFile, $phpIniDirContent);
    }

    public function updatePickleSection(array $dlls_add, array $dlls_del = [])
    {
        $before = '';
        $after = '';

        $pickleSection = $this->rebuildPickleParts($this->getPickleSection(), $dlls_add, $dlls_del);

        $before = substr($this->raw, 0, $this->pickleHeaderStartPos);

        /* If the footer end pos is < 0, there was no footer in php.ini. In this case the footer start pos
           means the end of the last extension directive after the header start, where the footer should  be */
        if ($this->pickleFooterEndPos > 0) {
            $after = substr($this->raw, $this->pickleFooterEndPos);
        } else {
            $after = substr($this->raw, $this->pickleFooterStartPos);
        }

        $before = rtrim($before);
        $after = ltrim($after);

        $this->raw = $before."\n\n".self::PICKLE_HEADER."\n".trim($pickleSection)."\n".self::PICKLE_FOOTER."\n\n".$after;
        if (!@file_put_contents($this->path, $this->raw)) {
            throw new \Exception('Cannot update php.ini');
        }
    }

    protected function buildDllIniLine($dll)
    {
        // 7.2 extension=modulename

        // 7.1 extension=php_bz2.dll
        //     extension=msql.so

        if ($this->ge72()) {
            if ($pos = strpos($dll, '.dll')) {
                false !== $pos && $dll = substr($dll, 0, $pos);
            }

            if ($pos = strpos($dll, '.so')) {
                false !== $pos && $dll = substr($dll, 0, $pos);
            }

            if (0 === strpos($dll, 'php_')) {
                $dll = substr($dll, 4);
            }
        }

        if (\in_array($dll, $this->ZEND_EXTENSION)) {
            return 'zend_extension='.$dll;
        }

        return 'extension='.$dll;
    }

    protected function ge72()
    {
        $engine = $this->engine;
        $major = $engine->getMajorVersion();
        $minor = $engine->getMinorVersion();

        if (($major >= 7 && $minor >= 2) || $major > 7) {
            return true;
        }

        return false;
    }
}

/* vim: set tabstop=4 shiftwidth=4 expandtab: fdm=marker */
