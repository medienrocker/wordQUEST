<?php
/**
 * wordQUEST – Wortlisten-Auto-Discovery
 *
 * Scannt das eigene Verzeichnis nach *.json und liefert die Liste als JSON-Array.
 * Das Frontend ruft diese Datei per GET /wordlists/index.php auf.
 *
 * Rückgabe-Format:
 *   [
 *     { "file": "food.json", "title": "Obst & Gemüse", "description": "...", "count": 26 },
 *     ...
 *   ]
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');

$dir   = __DIR__;
$files = glob($dir . '/*.json');
$result = [];

if ($files !== false) {
    foreach ($files as $path) {
        $name = basename($path);

        // index.json (manueller Fallback-Manifest) nicht anzeigen
        if ($name === 'index.json') continue;

        $raw  = @file_get_contents($path);
        if ($raw === false) continue;

        $data = json_decode($raw, true);
        if (!is_array($data)) continue;

        $words = isset($data['words']) && is_array($data['words']) ? $data['words'] : [];

        $result[] = [
            'file'        => $name,
            'title'       => isset($data['title']) && is_string($data['title'])
                                ? $data['title']
                                : pathinfo($name, PATHINFO_FILENAME),
            'description' => isset($data['description']) && is_string($data['description'])
                                ? $data['description']
                                : '',
            'count'       => count($words),
        ];
    }
}

// alphabetisch nach Titel sortieren
usort($result, function ($a, $b) {
    return strcasecmp($a['title'], $b['title']);
});

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
