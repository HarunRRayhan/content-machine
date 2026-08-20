<?php

namespace App\Support\LinkResolution;

interface LinkResolverContract
{
    public function resolve(string $url): ResolvedLink;
}
