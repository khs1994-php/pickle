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

namespace Pickle;

use Pickle\Package\HHVM;
use Pickle\Package\PHP;

class Package
{
    protected static $instance;

    protected static function deliverFresh($force)
    {
        if ($force && self::$instance || null === self::$instance) {
            /* XXX does this leak the previous instance? */
            self::$instance = null;

            return true;
        }

        return false;
    }

    public static function factory($name, $version, $prettyVersion, $force = false)
    {
        if (self::deliverFresh($force)) {
            $engine = Engine::factory();

            switch ($engine->getName()) {
                case 'php':
                    self::$instance = new PHP\Package($name, $version, $prettyVersion);
                    break;

                case 'hhvm':
                    self::$instance = new HHVM\Package($name, $version, $prettyVersion);
                    break;

                default:
                    throw new \Exception("Unsupported engine '{$engine->getName()}'. Implement it!");
            }
        }

        return self::$instance;
    }
}

/* vim: set tabstop=4 shiftwidth=4 expandtab: fdm=marker */
