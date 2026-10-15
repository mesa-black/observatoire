<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

class Kernel extends BaseKernel
{
    use MicroKernelTrait;

    /**
     * The compiled container lives apart from the runtime cache: built into the
     * image (read-only root filesystem), while var/cache is a writable volume
     * that starts empty at every pod start.
     */
    public function getBuildDir(): string
    {
        return $this->getProjectDir().'/var/build/'.$this->environment;
    }
}
