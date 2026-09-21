<?php

declare(strict_types=1);

$commerceSurface = 'item';
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
            $commerceHeroContent = controller('moduleH1Type01', 1);
            echo controller('hero00', 0, [
                '{hero00-content}' => $commerceHeroContent,
            ]);
            ?>

            <main>
                <?php
                echo controller('artCommerceItem01', 0, [
                    'header_level' => 2,
                    'item_data' => $commerceItem->product(),
                    'catalog_path' => $commerceItem->catalogPath(),
                    'inquiry_path' => $commerceItem->inquiryPath(),
                    'labels' => $commerceItem->labels(),
                    'locale' => $lang,
                    'return_to' => $commerceItem->product()->path(),
                    'inquiry_count' => $commerceItem->inquiryCount(),
                    'selected_items' => $commerceItem->basketProductIds(),
                ]);
                ?>
            </main>

            <?php include __DIR__ . '/../includes/_footer.php' ?>
        </div>
    </div>
</body>

</html>
