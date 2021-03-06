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

namespace Pickle\Package\PHP\Convey\Command;

use Pickle\Base\Interfaces;
use Pickle\Package\PHP\Util\PackageXml;
use Symfony\Component\Finder\Finder;

class DefaultExecutor implements Interfaces\Package\Convey\DefaultExecutor
{
    public function __construct(Interfaces\Package\Convey\Command $command)
    {
    }

    public function generatePickleJson($target, $pickle_json)
    {
        $files = (new Finder())->in($target)->files()->name('/^php_.*\.h$/');
        foreach ($files as $file) {
            $name = $file->getRelativePathname();
            $name = substr($name, 4);
            $name = substr($name, 0, -2);
            if (strpos($file->getContents(), 'PHP_'.strtoupper($name).'_VERSION')) {
                break;
            }
        }

        if (!$name) {
            throw new \Exception('get package name error');
        }

        $pickle_json_content = json_encode([
           'name' => $name,
           'type' => 'extension',
           'description' => 'extension not include package.xml, this file is auto generate',
        ]);

        file_put_contents($pickle_json, $pickle_json_content);
    }

    public function execute($target, $no_convert)
    {
        $jsonLoader = new \Pickle\Package\Util\JSON\Loader(new \Pickle\Package\Util\Loader());
        $pickle_json = $target.\DIRECTORY_SEPARATOR.'composer.json';
        $package_xml = $target.\DIRECTORY_SEPARATOR.'package.xml';
        $package = null;

        if (file_exists($pickle_json)) {
            $package = $jsonLoader->load($pickle_json);
        }

        // xml 文件不存在，尝试生成 composer.json
        if (!file_exists($package_xml)) {
            $this->generatePickleJson($target, $pickle_json);
            $package = $jsonLoader->load($pickle_json);
        }

        // 从 composer.json 获取 package 失败，并且传入 --no-convert 则退出
        if (null === $package && $no_convert) {
            throw new \RuntimeException('XML package are not supported. Please convert it before install');
        }

        // 从 composer.json 获取 package 失败，从 xml 文件转化
        if (null === $package) {
            try {
                $pkgXml = new PackageXml($target);
                $pkgXml->dump();

                $jsonPath = $pkgXml->getJsonPath();
                unset($package);

                $package = $jsonLoader->load($jsonPath);
            } catch (\Exception $e) {
                echo $e->getMessage();
                // 7.2 及以下内置扩展的 package.xml 为 1.0 版本，不支持
                // 从 package.xml 转 package.json 出现错误
                // 尝试生成 package.json
                $this->generatePickleJson($target, $pickle_json);
                $package = $jsonLoader->load($pickle_json);
            }
        }

        if (null === $package) {
            throw new \Exception('Conver package.json error');
        }

        $package->setRootDir($target);
        $package->updateVersion();

        return $package;
    }
}

/* vim: set tabstop=4 shiftwidth=4 expandtab: fdm=marker */
