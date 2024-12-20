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

namespace Pickle\Console\Command;

use Pickle\Base\Abstracts\Console\Command\BuildCommand;
use Pickle\Base\Interfaces\Package;
use Pickle\Base\Util;
use Pickle\Engine;
use Pickle\Engine\Ini;
use Pickle\Package\Command\Install;
use Pickle\Package\Util\Windows;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\ConfirmationQuestion;

class InstallerCommand extends BuildCommand
{
    protected function configure()
    {
        parent::configure();

        $this
            ->setName('install')
            ->setDescription('Install a php extension')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Do not install extension'
            )
            ->addOption(
                'php',
                null,
                InputOption::VALUE_REQUIRED,
                'path to an alternative php (exec)'
            )
            ->addOption(
                'ini',
                null,
                InputOption::VALUE_REQUIRED,
                'path to an alternative php.ini'
            )
            ->addOption(
                'source',
                null,
                InputOption::VALUE_NONE,
                'use source package (build from source)'
            )
            ->addOption(
                'save-logs',
                null,
                InputOption::VALUE_REQUIRED,
                'path to save the build logs'
            )
            ->addOption(
                'tmp-dir',
                null,
                InputOption::VALUE_REQUIRED,
                'path to a custom temp dir',
                sys_get_temp_dir()
            )->addOption(
                'force',
                'f',
                InputOption::VALUE_NONE,
                'Force install, don\'t check extension loaded'
            )->addOption(
                'no-write',
                null,
                InputOption::VALUE_NONE,
                'Disable write extension=XXX or zend_extension=XXX to ini file'
            )->addOption(
                'strip',
                null,
                InputOption::VALUE_NONE,
                'exec strip command, strip --strip-all'
            )->addOption(
                'cleanup',
                null,
                InputOption::VALUE_NONE,
                'cleanup useless files lib/doc/ lib/test/ include/ext/'
            )->addOption(
                'php-src',
                null,
                InputOption::VALUE_REQUIRED,
                'php src dir',
                '/usr/src/php'
            )->addOption(
                'phpize',
                null,
                InputOption::VALUE_REQUIRED,
                'path to an alternative phpize',
                'phpize'
            )->addOption(
                'continue-on-error',
                null,
                InputOption::VALUE_NONE,
                'Don\'t exit on fail'
            );

        if (\defined('PHP_WINDOWS_VERSION_MAJOR')) {
            $this->addOption(
                'binary',
                null,
                InputOption::VALUE_NONE,
                'use binary package (download ext binary)'
            );
        }
    }

    /**
     * @param string $path
     */
    protected function binaryInstallWindows($path, InputInterface $input, OutputInterface $output)
    {
        $php = Engine::factory();
        $table = new Table($output);
        $table
            ->setRows([
               ['<info>'.$php->getName().' Path</info>', $php->getPath()],
               ['<info>'.$php->getName().' Version</info>', $php->getVersion()],
               ['<info>Compiler</info>', $php->getCompiler()],
               ['<info>Architecture</info>', $php->getArchitecture()],
               ['<info>Thread safety</info>', $php->getZts() ? 'yes' : 'no'],
               ['<info>Extension dir</info>', $php->getExtensionDir()],
               ['<info>php.ini</info>', $php->getIniPath()],
            ])
            ->render();

        $inst = Install::factory($path);
        $progress = new ProgressBar($output, 100);
        // $progress = $this->getHelperSet()->get('progress');
        $inst->setProgress($progress);
        $inst->setInput($input);
        $inst->setOutput($output);
        $inst->install();

        $deps_handler = new Windows\DependencyLib($php);
        $deps_handler->setProgress($progress);
        $deps_handler->setInput($input);
        $deps_handler->setOutput($output);

        $helper = $this->getHelperSet()->get('question');

        $cb = function ($choices) use ($helper, $input, $output) {
            $question = new ChoiceQuestion(
                'Multiple choices found, please select the appropriate dependency package',
                $choices
            );
            $question->setMultiselect(false);

            return $helper->ask($input, $output, $question);
        };

        foreach ($inst->getExtDllPaths() as $dll) {
            if (!$deps_handler->resolveForBin($dll, $cb)) {
                throw new \Exception('Failed to resolve dependencies');
            }
        }
    }

    /*  The most of this needs to be incapsulated into an extra Build class */
    protected function sourceInstall($package, InputInterface $input, OutputInterface $output, $optionsValue = [], $force_opts = '')
    {
        $php = Engine::factory();
        $helper = $this->getHelperSet()->get('question');

        $build = \Pickle\Package\Command\Build::factory($package, $optionsValue);

        $isSuccess = true;

        try {
            $build->prepare($input->getOption('phpize'));
            $build->createTempDir($package->getUniqueNameForFs());
            $build->configure($force_opts);
            $build->make();
            $build->install($php, $input->getOption('strip'), $input->getOption('cleanup'));

            $this->saveBuildLogs($input, $build);
        } catch (\Exception $e) {
            $this->saveBuildLogs($input, $build);

            $output->writeln('The following error(s) happened: '.$e->getMessage());
            $prompt = new ConfirmationQuestion('Would you like to read the log?', true);
            if ($helper->ask($input, $output, $prompt)) {
                $output->write($build->getLog());
            }

            $isSuccess = false;
        }
        $build->cleanup();

        return $isSuccess;
    }

    /**
     * 检查扩展是否已经加载.
     */
    protected function isLoaded(
        InputInterface $input,
        OutputInterface $output,
        $path,
        $php
    ) {
        if ($input->getOption('force')) {
            // 指定 --force, 不检查扩展是否加载，强制安装
            return false;
        }

        if ('opcache' === $path) {
            $path = 'Zend OPcache';
        }

        [$path,] = explode('@', $path);

        if ($input->getOption('php')) {
            // 指定了 PHP, 检查该 PHP
            $command = $php->getPath().' -r '.'"echo extension_loaded(\''.$path.'\');"';
            if (exec($command)) {
                $output->writeln("<fg=red>[ $path ] is loaded, skip</>");

                return true;
            }
        }

        if (\extension_loaded($path)) {
            $output->writeln("<fg=red>[ $path ] is loaded, skip</>");

            return true;
        }

        return false;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $paths = $input->getArgument('path');
        foreach ($paths as $path) {
            try {
                $this->handler($input, $output, $path);
            } catch (\Exception $e) {
                $message = $e->getMessage();
                if (!$input->getOption('continue-on-error')) {
                    throw new \Exception($message, $e->getCode(), $e);
                }

                $output->writeln($message);
            }
        }
    }

    protected function handler(InputInterface $input, OutputInterface $output, $path)
    {
        $path = rtrim($path, '/\\');

        Util\TmpDir::set($input->getOption('tmp-dir'));

        /* Respect the --php option. This will setup the engine instance. */
        $php = Engine::factory($input->getOption('php'));

        if ($this->isLoaded($input, $output, $path, $php)) {
            return 0;
        }

        // find ext from ext_dir
        $extensionDir = $php->getExtensionDir().\DIRECTORY_SEPARATOR;
        $ini = Ini::factory($php);
        $phpIniDir = $php->getIniDir();

        if ($php->isWindows && file_exists($extensionDir.'php_'.$path.'.dll')) {
            $output->writeln('find ext file in '.$extensionDir);

            $ini->updatePickleSection(['php_'.$path.'.dll']);

            return 0;
        }

        if ((!$php->isWindows) && file_exists($extensionDir.$path.'.so')) {
            $output->writeln('find ext file in '.$extensionDir);

            $output->writeln('<info>php_ini_dir [ '.$phpIniDir.' ] found, write ini</info>');

            $ini->updatePickleSectionOnLinux(
                [$path.'.so'], $phpIniDir, $input->getOption('no-write')
            );

            return 0;
        }

        /* if windows, try bin install by default */
        if (\defined('PHP_WINDOWS_VERSION_MAJOR')) {
            $sourceRequested = $input->getOption('source');
            if (!$sourceRequested) {
                // 不包含 --source 直接下载二进制
                $this->binaryInstallWindows($path, $input, $output);

                return 0;
            }
        }

        // 从 php-src 寻找扩展
        $php_src = $input->getOption('php-src');
        if (is_dir($php_src.'/ext/'.$path)) {
            $output->writeln('find ext [ '.$path.' ] src from php-src');
            $path = $php_src.'/ext/'.$path;
        }

        // 转换扩展
        $package = $this->getHelper('package')->convey($input, $output, $path);

        $packageName = $package->getName();

        if ($this->isLoaded($input, $output, $packageName, $php)) {
            return 0;
        }

        /* TODO Info package command should be used here. */
        $this->getHelper('package')->showInfo($output, $package);

        list($optionsValue, $force_opts) = $this->buildOptions($package, $input, $output);

        if ($input->getOption('dry-run')) {
            return 0;
        }

        $result = $this->sourceInstall($package, $input, $output, $optionsValue, $force_opts);

        if (!$result) {
            throw new \Exception('install error, please check build log');
        }

        if ($php->isWindows) {
            return 0;
        }

        if (!$phpIniDir) {
            // ini dir 不存在
            $output->writeln('<info>php_ini_dir not found, skip write ini</info>');

            return 0;
        }

        $output->writeln('<info>php_ini_dir [ '.$phpIniDir.' ] found, write ini</info>');

        $ini->updatePickleSectionOnLinux(
            [$packageName.'.so'], $phpIniDir, $input->getOption('no-write')
        );

        return 0;
    }
}

/* vim: set tabstop=4 shiftwidth=4 expandtab: fdm=marker */
