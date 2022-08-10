<?php

namespace Pickle;

use Composer;

class Config extends Composer\Config
{
    public const DEFAULT_BASE_DIRNAME = '.pickle';

    public function __construct($useEnvironment = true, $baseDir = null)
    {
        if (true === $useEnvironment) {
            $baseDir = $baseDir ?: (getenv('PICKLE_BASE_DIR') ?: null);
        }

        $baseDir = $baseDir ?: (getenv('HOME') ?: sys_get_temp_dir());
        $baseDir = rtrim($baseDir, \DIRECTORY_SEPARATOR).\DIRECTORY_SEPARATOR.self::DEFAULT_BASE_DIRNAME;

        parent::__construct($useEnvironment, $baseDir);
    }
}
