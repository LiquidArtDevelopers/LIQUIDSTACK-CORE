<?php

declare(strict_types=1);

require __DIR__ . '/../app/_moduleBlogPublicArticle.php';

$articleShellText = match (strtolower(explode('-', $lang, 2)[0])) {
    'es' => [
        'related' => 'Noticias relacionadas',
        'index' => 'Ver todas las noticias',
    ],
    'eu' => [
        'related' => 'Lotutako albisteak',
        'index' => 'Albiste guztiak ikusi',
    ],
    default => [
        'related' => 'Related news',
        'index' => 'View all news',
    ],
};
$articleShellCatalogText = static function (
    string $key,
    string $fallback
): string {
    $entry = $GLOBALS[$key] ?? null;
    $value = is_object($entry)
        ? ($entry->text ?? null)
        : (is_array($entry) ? ($entry['text'] ?? null) : null);

    return is_string($value) && trim($value) !== ''
        ? trim($value)
        : $fallback;
};
$relatedHeading = $articleShellCatalogText(
    'blog_article_related_heading',
    $articleShellText['related']
);
$newsIndexLabel = $articleShellCatalogText(
    'blog_article_all_news_label',
    $articleShellText['index']
);
?>
<!DOCTYPE html>
<html lang="<?= $escape($lang) ?>"<?php if ($blogArticle->analyticsEnabled()): ?>
      data-blog-analytics-enabled="true"
      data-blog-analytics-retention-days="<?= $escape($blogArticle->analyticsRetentionDays()) ?>"
      data-blog-analytics-session-timeout="<?= $escape($blogArticle->analyticsSessionTimeoutSeconds()) ?>"
      data-blog-analytics-page-grant="<?= $escape($blogArticle->analyticsPageGrant()) ?>"<?php endif ?>>

<head>
    <?php include_once __DIR__ . '/../includes/_globalHead.php' ?>
    <?php if ($articleCustomCss !== ''): ?>
        <style nonce="<?= $escape($cspNonce) ?>"><?= $articleCustomCss ?></style>
    <?php endif ?>
    <script nonce="<?= $escape($cspNonce) ?>"
            defer
            src="<?= $escape($blogPublicRuntimeUrl) ?>"></script>
</head>

<body class="blog-article-page">
    <?php include_once __DIR__ . '/../includes/_globalBody.php' ?>
    <?php include __DIR__ . '/../includes/_nav.php' ?>

    <div id="smooth-wrapper">
        <div id="smooth-content">
            <?= $articleHero ?>

            <main class="blogArticleMain blog-article artBlogArticle01 artBlogArticle01--<?= $escape($articleModifier) ?>">
                <?= $articleTaxonomiesHtml ?>
                <?= $articleMain ?>

                <?php
                echo controller('sectionBlogRelated01', 0, [
                    'items_data' => $relatedArticles,
                    'items' => count($relatedArticles),
                    'header_level' => 2,
                    'header_text' => $relatedHeading,
                    'header_lang' => 'blog_article_related_heading',
                ]);
                ?>

                <div class="artBlogArticle01-footer artBlogArticle01-footer--newsIndex">
                    <?php
                    echo controller('moduleButtonType04', 0, [
                        '{classVar}' => 'artBlogArticle01-backAction artBlogArticle01-newsIndexAction',
                        '{cta-link-dl}' => 'blog_article_all_news_label',
                        '{cta-link-href}' => $escape($indexPath),
                        '{cta-link-title}' => $escape($newsIndexLabel),
                        '{cta-link-span-dl}' => 'blog_article_all_news_label',
                        '{cta-link-span-text}' => $escape($newsIndexLabel),
                        '{cta-link-attributes}' => ' rel="up"',
                    ]);
                    ?>
                </div>
            </main>

            <?php include __DIR__ . '/../includes/_footer.php' ?>
        </div>
    </div>
</body>

</html>
