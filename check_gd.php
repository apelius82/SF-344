<?php
// Tarkista GD
echo "<h2>GD Library:</h2>";
if (extension_loaded('gd')) {
    echo "✅ GD on asennettu<br>";
    print_r(gd_info());
} else {
    echo "❌ GD ei ole asennettu<br>";
}

echo "<hr>";

// Tarkista Imagick
echo "<h2>Imagick:</h2>";
if (extension_loaded('imagick')) {
    echo "✅ Imagick on asennettu<br>";
    $imagick = new Imagick();
    print_r($imagick->queryFormats());
} else {
    echo "❌ Imagick ei ole asennettu<br>";
}

echo "<hr>";

// Tarkista myös Puppeteer/Node mahdollisuus
echo "<h2>Node.js / Chromium:</h2>";
$nodeVersion = shell_exec('node -v 2>&1');
$chromiumPath = shell_exec('which chromium-browser 2>&1 || which chromium 2>&1 || which google-chrome 2>&1');

echo "Node.js: " . ($nodeVersion ?: "❌ Ei asennettu") . "<br>";
echo "Chromium: " . ($chromiumPath ?: "❌ Ei löydy") . "<br>";