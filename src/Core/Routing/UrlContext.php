<?php

namespace App\Core\Routing;

class UrlContext
{
    /** @var string[] */
    public array $langs;
    public string $lang;
    public string $url;
    public string $urlWithQuery;
    public ?string $urlLang;

    /**
     * @param string[] $langs
     */
    public function __construct(array $langs, string $lang, string $url, string $urlWithQuery, ?string $urlLang = null)
    {
        $this->langs        = $langs;
        $this->lang         = $lang;
        $this->url          = $url;
        $this->urlWithQuery = $urlWithQuery;
        $this->urlLang      = $urlLang;
    }
}
