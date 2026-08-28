<?php
$scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
$scriptDir = str_replace('\\', '/', dirname($scriptName));
if ($scriptDir === '.' || $scriptDir === '') {
    $scriptDir = '';
} elseif ($scriptDir !== '/') {
    $scriptDir = '/' . ltrim($scriptDir, '/');
    $scriptDir = rtrim($scriptDir, '/');
} else {
    $scriptDir = '/';
}
$basename = $scriptDir === '' ? '/' : $scriptDir;
$prefix = $basename === '/' ? '/' : $basename . '/';
$assetPrefix = $prefix;
$rootForApi = $basename === '/' ? '/' : rtrim(dirname($basename), '/');
if ($rootForApi === '' || $rootForApi === '.') {
    $rootForApi = '/';
}
$apiPath = $rootForApi === '/' ? '/api' : $rootForApi . '/api';
$forwardedProto = $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '';
if (is_string($forwardedProto) && $forwardedProto !== '') {
    $scheme = explode(',', $forwardedProto)[0];
} elseif (!empty($_SERVER['REQUEST_SCHEME'])) {
    $scheme = $_SERVER['REQUEST_SCHEME'];
} else {
    $https = $_SERVER['HTTPS'] ?? '';
    $scheme = (!empty($https) && $https !== 'off') ? 'https' : 'http';
}
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$apiUrl = rtrim($scheme . '://' . $host, '/') . $apiPath;
$config = [
    'basename' => $basename,
    'prefix' => $prefix,
    'apiUrl' => $apiUrl,
    'assetPrefix' => $assetPrefix,
];

// Resolve the built entry (script + CSS + modulepreload chunks) from Vite's
// manifest instead of hardcoding hashed filenames, so `npm run build` in
// miniapp/ is self-deploying with no edits needed here.
$manifestPath = __DIR__ . '/assets/.vite/manifest.json';
$entryScript = null;
$entryCssFiles = [];
$entryPreloadFiles = [];
if (is_file($manifestPath)) {
    $manifest = json_decode(file_get_contents($manifestPath), true);
    if (is_array($manifest)) {
        $entryKey = null;
        foreach ($manifest as $key => $chunk) {
            if (!empty($chunk['isEntry'])) {
                $entryKey = $key;
                break;
            }
        }
        if ($entryKey !== null) {
            $entry = $manifest[$entryKey];
            $entryScript = $entry['file'];
            $entryCssFiles = $entry['css'] ?? [];
            foreach (($entry['imports'] ?? []) as $importKey) {
                if (isset($manifest[$importKey]['file'])) {
                    $entryPreloadFiles[] = $manifest[$importKey]['file'];
                }
                foreach (($manifest[$importKey]['css'] ?? []) as $importCss) {
                    $entryCssFiles[] = $importCss;
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
  <head>
    <meta charset="UTF-8" />
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover" />
    <title>Mirza Web App</title>
    <base href="<?php echo htmlspecialchars($prefix, ENT_QUOTES); ?>" />
    <script src="<?php echo htmlspecialchars($assetPrefix . 'js/telegram-web-app.js', ENT_QUOTES); ?>"></script>
    <script>
      window.__APP_CONFIG__ = <?php echo json_encode($config, JSON_UNESCAPED_SLASHES); ?>;
    </script>
    <?php if ($entryScript): ?>
      <script type="module" crossorigin src="<?php echo htmlspecialchars($assetPrefix . 'assets/' . $entryScript, ENT_QUOTES); ?>"></script>
      <?php foreach ($entryPreloadFiles as $preloadFile): ?>
        <link rel="modulepreload" crossorigin href="<?php echo htmlspecialchars($assetPrefix . 'assets/' . $preloadFile, ENT_QUOTES); ?>">
      <?php endforeach; ?>
      <?php foreach (array_unique($entryCssFiles) as $cssFile): ?>
        <link rel="stylesheet" crossorigin href="<?php echo htmlspecialchars($assetPrefix . 'assets/' . $cssFile, ENT_QUOTES); ?>">
      <?php endforeach; ?>
    <?php else: ?>
      <p style="font-family:sans-serif;padding:24px">اپلیکیشن هنوز ساخته نشده است. دستور «npm run build» را در پوشه miniapp اجرا کنید.</p>
    <?php endif; ?>
  </head>
  <body>
    <div id="root"></div>
  </body>
</html>
