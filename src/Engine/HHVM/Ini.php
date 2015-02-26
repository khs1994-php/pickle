<?php

namespace Pickle\Engine\HHVM;

use Pickle\Base\Interfaces;
use Pickle\Base\Abstracts;

class Ini extends Abstracts\Engine\Ini implements Interfaces\Engine\Ini
{
    public function __construct(Interfaces\Engine $php)
    {
        parent::__construct($php);

        $this->setupPickleSectionPositions();
    }

    /**
     * @param string $pickleSection
     * @param array  $dsos
     *
     * @return string
     */
    protected function rebuildPickleParts($pickleSection, array $dsos)
    {
        $lines = explode("\n", $pickleSection);
        $new = [];
        foreach ($lines as $l) {
            $l = trim($l);
            if (0 !== strpos($l, "hhvm.pickle_extensions[")) {
                continue;
            }
            list(, $dllname) = explode('=', $l);
            if (in_array(trim($dllname), $dsos)) {
                continue;
            }
            $new[] = $l;
        }

        return implode("\n", $new);
    }

    protected function setupPickleSectionPositions()
    {
        $posHeader = strpos($this->raw, self::PICKLE_HEADER);
        if (false === $posHeader) {
            /* no pickle section here yet */
            $this->pickleHeaderStartPos = strlen($this->raw);
            return;
        }

        $this->pickleHeaderStartPos = $posHeader;
        $this->pickleHeaderEndPos = $this->pickleHeaderStartPos + strlen(self::PICKLE_HEADER);

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
                $pos = strpos($this->raw, "hhvm.dynamic_extensions[", $pos);
                if (false !== $pos) {
                    $this->pickleFooterStartPos = $pos;
                    $pos++;
                }
            } while (false !== $pos);

            $this->pickleFooterStartPos = strpos($this->raw, "\n", $this->pickleFooterStartPos);
        } else {
            $this->pickleFooterStartPos = $posFooter;
            $this->pickleFooterEndPos = $this->pickleFooterStartPos + strlen(self::PICKLE_FOOTER);
        }
    }

    public function updatePickleSection(array $dsos)
    {
        $before = "";
        $after = "";

        $pickleSection = '';
    $names = array();
    foreach ($dsos as $dso) {
        /* HHVM currently doesn't support Windows, so just a guess here. */
        $pre = defined('PHP_WINDOWS_VERSION_MAJOR') ? "php_" : "";
        $suf = defined('PHP_WINDOWS_VERSION_MAJOR') ? ".dll" : ".so";
        $item = "$pre$dso$suf";
        $names[] = $item;
            $pickleSection .=  "hhvm.dynamic_extensions[$dso]=$item\n";
        }

        if ($this->pickleHeaderStartPos > 0) {
            $pickleSection = $this->rebuildPickleParts($this->getPickleSection(), $names) . "\n" . $pickleSection;

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
        }

        $this->raw = $before . "\n\n" . self::PICKLE_HEADER . "\n" . trim($pickleSection) . "\n" . self::PICKLE_FOOTER . "\n\n" . $after;
        if (!@file_put_contents($this->path, $this->raw)) {
            throw new \Exception('Cannot update php.ini');
        }
    }
}

