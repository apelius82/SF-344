<?php
// app/services/render_services.php

declare(strict_types=1);

require_once __DIR__ . '/../lib/sf_terms.php';

/**
 * Luo yksinkertaisen JPG-esikatselukuvan Safetyflashista GD-kirjastolla.
 * Palauttaa TIEDOSTOJÄRJESTELMÄN polun (esim. /home/.../uploads/previews/flash_123.jpg)
 * tai null, jos generointi epäonnistui.
 *
 * HUOM: ei käytä mitään ulkoisia binäärejä (wkhtmltoimage tms.), vain PHP:n GD:tä.
 */

function sf_generate_flash_preview(PDO $pdo, int $flashId): ?string
{
    // Varmista, että GD on käytössä
    if (!extension_loaded('gd')) {
        error_log('sf_generate_flash_preview: GD extension not loaded');
        return null;
    }

    // Haetaan tarvittavat tiedot tietokannasta
    $stmt = $pdo->prepare("
        SELECT
            id,
            type,
            lang,
            site,
            site_detail,
            occurred_at,
            title_short,
            description,
            root_causes,
            actions
        FROM sf_flashes
        WHERE id = :id
        LIMIT 1
    ");    $stmt->execute([':id' => $flashId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        error_log("sf_generate_flash_preview: flash id {$flashId} not found");
        return null;
    }

$type        = $row['type'] ?? 'yellow';
$lang        = $row['lang'] ??  'fi';
$site        = $row['site'] ?? '';
    $siteDetail  = $row['site_detail'] ?? '';
    $occurredAt  = $row['occurred_at'] ?? null;
    $titleShort  = $row['title_short'] ?? '';
    $description = $row['description'] ?? '';
    $rootCauses  = $row['root_causes'] ?? '';
    $actions     = $row['actions'] ?? '';

    // Muodostetaan työmaa-teksti
    $siteText = '–';
    if ($site && $siteDetail) {
        $siteText = $site . ' – ' . $siteDetail;
    } elseif ($site) {
        $siteText = $site;
    } elseif ($siteDetail) {
        $siteText = $siteDetail;
    }

    // Muodostetaan päivämäärä tekstiksi
    $dateText = '–';
    if ($occurredAt) {
        $ts = strtotime($occurredAt);
        if ($ts !== false) {
            $dateText = date('d.m.Y H:i', $ts);
        }
    }

    // Kuvan koko
    $width  = 1920;
    $height = 1080;

    $im = imagecreatetruecolor($width, $height);
    if (!$im) {
        error_log('sf_generate_flash_preview: imagecreatetruecolor failed');
        return null;
    }

    // Värit
    $white      = imagecolorallocate($im, 255, 255, 255);
    $black      = imagecolorallocate($im, 0, 0, 0);
    $grayLight  = imagecolorallocate($im, 240, 240, 240);
    $grayDark   = imagecolorallocate($im, 60, 60, 60);
    $yellow     = imagecolorallocate($im, 254, 224, 0);   // Tapojärvi-keltainen
    $red        = imagecolorallocate($im, 200, 30, 30);
    $green      = imagecolorallocate($im, 0, 150, 80);

    // Tausta
    imagefilledrectangle($im, 0, 0, $width, $height, $grayLight);

    // Vasemman reunan väripalkki
    $stripeColor = $yellow;
    if ($type === 'red') {
        $stripeColor = $red;
    } elseif ($type === 'green') {
        $stripeColor = $green;
    }
    imagefilledrectangle($im, 0, 0, 80, $height, $stripeColor);

    // Yläosan musta otsikkopalkki
    imagefilledrectangle($im, 80, 0, $width, 120, $black);

    // Tyyppiteksti otsikkopalkkiin - käytetään keskitettyä käännöstä
    $typeLabel = 'SAFETYFLASH'; // fallback
    if ($type === 'red') {
        $typeLabel = sf_term('preview_type_red', $lang);
    } elseif ($type === 'yellow') {
        $typeLabel = sf_term('preview_type_yellow', $lang);
    } elseif ($type === 'green') {
        $typeLabel = sf_term('preview_type_green', $lang);
    }

    // Yritetään käyttää TTF-fonttia, jos olemassa
    $fontDir    = __DIR__ . '/../../assets/fonts';
    $fontBold   = $fontDir . '/OpenSans-Bold.ttf';
    $fontRegular= $fontDir . '/OpenSans-Regular.ttf';
    $useTtf     = file_exists($fontBold) && function_exists('imagettftext');

    // Yläosan tekstit (tyyppi)
    if ($useTtf) {
        // Tyyppiteksti vasemmalle
        imagettftext($im, 42, 0, 110, 80, $white, $fontBold, $typeLabel);
        // Bränditeksti oikealle
        $brandText = sf_term('preview_brand', $lang);
        imagettftext($im, 28, 0, $width - 400, 80, $white, $fontBold, $brandText);
    } else {
        imagestring($im, 5, 110, 40, $typeLabel, $white);
        imagestring($im, 4, $width - 300, 44, sf_term('preview_brand', $lang), $white);
    }

    // Pääalueen tausta (tekstilaatikko)
    imagefilledrectangle($im, 120, 160, $width - 80, $height - 160, $white);
    imagerectangle($im, 120, 160, $width - 80, $height - 160, $grayDark);

    // Title (lyhyt kuvaus)
    $titleText = $titleShort ?: 'Lyhyt kuvaus tapahtumasta.';
    if ($useTtf) {
        // Rivitetään otsikko karkeasti
        $maxLen = 60;
        $wrappedTitle = wordwrap($titleText, $maxLen, "\n");
        $lines = explode("\n", $wrappedTitle);
        $y = 230;
        foreach ($lines as $line) {
            imagettftext($im, 40, 0, 150, $y, $black, $fontBold, $line);
            $y += 50;
        }
        $bodyStartY = $y + 20;
    } else {
        imagestring($im, 5, 150, 200, $titleText, $black);
        $bodyStartY = 260;
    }

// Kuvaus / juurisyyt / toimenpiteet tekstiksi
$body = '';
if ($description) {
    $body .= sf_term('preview_description_label', $lang) . "\n" . $description . "\n\n";
}
if ($type === 'green') {
    if ($rootCauses) {
        $body .= sf_term('preview_root_causes_label', $lang) . "\n" . $rootCauses . "\n\n";
    }
    if ($actions) {
        $body .= sf_term('preview_actions_label', $lang) . "\n" .  $actions . "\n";
    }
}
    if ($useTtf) {
        $maxLenBody = 90;
        $wrappedBody = wordwrap($body, $maxLenBody, "\n");
        $lines = explode("\n", $wrappedBody);
        $y = $bodyStartY;
        foreach ($lines as $line) {
            if ($y > $height - 220) {
                // Ei enempää tilaa – katkaistaan
                $continuesText = sf_term('preview_text_continues', $lang);
                imagettftext($im, 24, 0, 150, $y, $grayDark, $fontRegular, $continuesText);
                break;
            }
            imagettftext($im, 26, 0, 150, $y, $grayDark, $fontRegular, $line);
            $y += 32;
        }
    } else {
        // Hyvin yksinkertainen fallback, jos ei TTF:ää
        $maxLenBody = 60;
        $wrappedBody = wordwrap($body, $maxLenBody, "\n");
        $lines = explode("\n", $wrappedBody);
        $y = $bodyStartY;
        foreach ($lines as $line) {
            if ($y > $height - 220) {
                imagestring($im, 3, 150, $y, sf_term('preview_text_continues', $lang), $grayDark);
                break;
            }
            imagestring($im, 3, 150, $y, $line, $grayDark);
            $y += 18;
        }
    }

    // Alaosan meta: työmaa + milloin
$metaY = $height - 120;
$siteLabel = sf_term('preview_site_label', $lang);
$whenLabel = sf_term('preview_when_label', $lang);
if ($useTtf) {
    imagettftext($im, 24, 0, 150, $metaY, $black, $fontBold, $siteLabel);
    imagettftext($im, 24, 0, 320, $metaY, $black, $fontRegular, $siteText);

    imagettftext($im, 24, 0, 150, $metaY + 40, $black, $fontBold, $whenLabel);
    imagettftext($im, 24, 0, 320, $metaY + 40, $black, $fontRegular, $dateText);
} else {
    imagestring($im, 4, 150, $metaY - 10, $siteLabel . ' ' . $siteText, $black);
    imagestring($im, 4, 150, $metaY + 20, $whenLabel . ' ' . $dateText, $black);
}

    // Tiedostonimen ja hakemiston määritys
    $baseDir = dirname(__DIR__, 2); // safetyflash-system
    $previewDir = $baseDir . '/uploads/previews';

    if (!is_dir($previewDir)) {
        if (!mkdir($previewDir, 0775, true) && !is_dir($previewDir)) {
            error_log('sf_generate_flash_preview: mkdir failed for ' . $previewDir);
            imagedestroy($im);
            return null;
        }
    }

    $filename = 'flash_' . $flashId . '.jpg';
    $fullPath = $previewDir . '/' . $filename;

    // Tallennetaan JPG
    if (!imagejpeg($im, $fullPath, 90)) {
        error_log('sf_generate_flash_preview: imagejpeg failed for ' . $fullPath);
        imagedestroy($im);
        return null;
    }

    imagedestroy($im);

    // Päivitetään tietokantaan preview_filename
    try {
        $stmtUp = $pdo->prepare("
            UPDATE sf_flashes
            SET preview_filename = :preview, updated_at = NOW()
            WHERE id = :id
        ");
        $stmtUp->execute([
            ':preview' => $filename,
            ':id'      => $flashId,
        ]);
    } catch (Throwable $e) {
        error_log('sf_generate_flash_preview: update preview_filename failed: ' . $e->getMessage());
    }

    return $fullPath; // tiedostojärjestelmän polku, sopii liitteeksi sähköpostiin
}

/**
 * Palauttaa kaikki saman translation_group_id:n kieliversiot muodossa
 * ['fi' => 123, 'sv' => 124, ...]
 *
 * @param PDO|\mysqli $db
 * @param int $translation_group_id
 * @return array
 */
function sf_get_flash_translations($db, int $translation_group_id): array
{
    $result = [];

    // --- VERSIO 1: PDO ---
    if ($db instanceof PDO) {
        $stmt = $db->prepare(
            'SELECT id, lang 
             FROM sf_flashes 
             WHERE translation_group_id = :group_id OR id = :group_id2'
        );
        $stmt->execute(['group_id' => $translation_group_id, 'group_id2' => $translation_group_id]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            if (!empty($row['lang'])) {
                $result[$row['lang']] = (int) $row['id'];
            }
        }

        return $result;
    }

    // --- VERSIO 2: mysqli ---
    if ($db instanceof mysqli) {
        $stmt = $db->prepare(
            'SELECT id, lang 
             FROM sf_flashes 
             WHERE translation_group_id = ? OR id = ?'
        );
        $stmt->bind_param('ii', $translation_group_id, $translation_group_id);
        $stmt->execute();
        $res = $stmt->get_result();

        while ($row = $res->fetch_assoc()) {
            if (!empty($row['lang'])) {
                $result[$row['lang']] = (int) $row['id'];
            }
        }

        $stmt->close();
        return $result;
    }

    // Jos $db-tyyppi on jotakin muuta, palautetaan tyhjä
    return $result;
}