<?php

declare(strict_types=1);

namespace App\Core\Blog\Seo;

interface BlogSeoHttpRuntimeInterface
{
    public function seoAnalysis(): BlogSeoAnalysisService;
}
