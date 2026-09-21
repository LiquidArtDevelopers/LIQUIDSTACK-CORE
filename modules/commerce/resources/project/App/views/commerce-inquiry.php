<?php

declare(strict_types=1);

$commerceSurface = 'inquiry';
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
            $commerceHeroContent = controller('moduleH1Type01', 2);
            echo controller('hero00', 0, [
                '{hero00-content}' => $commerceHeroContent,
            ]);
            ?>

            <main>
                <?php
                echo controller('sectionCommerceInquiry01', 0, [
                    'header_level' => 2,
                    'header_text' => $commerceInquiry->heading(),
                    'intro_text' => $commerceInquiry->intro(),
                    'items_data' => $commerceInquiry->items(),
                    'items' => count($commerceInquiry->items()),
                    'action' => '/_liquidstack/commerce/inquiry/submit',
                    'labels' => $commerceInquiry->labels(),
                    'locale' => $lang,
                    'return_to' => $commerceInquiry->path(),
                ]);
                ?>
            </main>

            <?php include __DIR__ . '/../includes/_footer.php' ?>
        </div>
    </div>
</body>

</html>
