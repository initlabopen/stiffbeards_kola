<?php

namespace Kola;

trait KolaContainerTrait
{
    private $coreUrlInKolaTrait; // fucking php can actually have private names conflict with other fucking private names

    abstract protected function ensureParameters(array $config, array $parameterNames);

    protected function loadKolaConfig($config)
    {
        $this->ensureParameters($config, array('kola.core_url'));
        $this->coreUrlInKolaTrait = $config['kola.core_url'];
    }

    protected function getCoreUrl()
    {
        return $this->coreUrlInKolaTrait;
    }
}
