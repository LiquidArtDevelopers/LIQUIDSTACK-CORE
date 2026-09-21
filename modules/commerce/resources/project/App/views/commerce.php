<?php

declare(strict_types=1);

$commerceSurface = 'catalog';
require __DIR__ . '/../app/_moduleCommercePublic.php';
?>
<!DOCTYPE html>
<html lang="<?= htmlspecialchars($lang, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') ?>">

<head>
    <?php include_once __DIR__ . '/../includes/_globalHead.php' ?>
</head>

<body>
    <?php include_once __DIR__ . '/../includes/_globalBody.php' ?>
    <?php include __DIR__ . '/../includes/_nav.php' ?>

    <div id="smooth-wrapper">
        <div id="smooth-content">
            <?php
            $commerceHeroContent = controller('moduleH1Type01', 0);
            echo controller('hero00', 0, [
                '{hero00-content}' => $commerceHeroContent,
            ]);
            ?>

            <main>
                <?php
                echo controller('sectionCommerceCatalog01', 0, [
                    'header_level' => 2,
                    'header_text' => $commerceCatalog->heading(),
                    'intro_text' => $commerceCatalog->intro(),
                    'items_data' => $commerceCatalog->items(),
                    'items' => count($commerceCatalog->items()),
                    'inquiry_path' => $commerceCatalog->inquiryPath(),
                    'labels' => $commerceCatalog->labels(),
                    'locale' => $lang,
                    'return_to' => $url,
                    'catalog_path' => $url,
                    'query' => $commerceCatalog->query(),
                    'category_options' => $commerceCatalog->categoryOptions(),
                    'tag_options' => $commerceCatalog->tagOptions(),
                    'selected_items' => $commerceCatalog->basketProductIds(),
                ]);
                ?>
            </main>

            <?php include __DIR__ . '/../includes/_footer.php' ?>
        </div>
    </div>
</body>

</html>
