<?php
/**
 * wordQUEST – Emoji-Vorschläge und Kategorienvorlagen für den Listeneditor.
 *
 * Warum eine Tabelle und kein Modell: Für "apple → 🍎" braucht es keine
 * Künstliche Intelligenz. Eine Zuordnung ist nachvollziehbar, sofort da,
 * kostet nichts und liefert immer dasselbe Ergebnis. Ein Sprachmodell wäre
 * hier langsamer, teurer und unzuverlässiger.
 *
 * Die Tabelle deckt bewusst den Schulwortschatz der ersten Lernjahre ab. Was
 * nicht darin steht, bleibt leer, und das ist in Ordnung: Ein falsches Emoji
 * verwirrt mehr als gar keines. Die App zeigt dann einen farbigen Kreis mit
 * dem Anfangsbuchstaben.
 */
declare(strict_types=1);

/** Vorschläge für Kategorien, wenn eine Liste noch keine hat. */
const WQ_KATEGORIE_VORLAGEN = [
    'words'     => 'Einzelwörter',
    'phrases'   => 'Wendungen',
    'food'      => 'Essen und Trinken',
    'animals'   => 'Tiere',
    'school'    => 'Schule',
    'family'    => 'Familie und Menschen',
    'body'      => 'Körper und Gesundheit',
    'home'      => 'Wohnen',
    'clothes'   => 'Kleidung',
    'freetime'  => 'Freizeit und Sport',
    'travel'    => 'Unterwegs und Reisen',
    'nature'    => 'Natur und Wetter',
    'time'      => 'Zeit und Zahlen',
    'verbs'     => 'Verben',
    'adjectives' => 'Eigenschaften',
];

/**
 * Wortschatz zu Emoji. Schlüssel sind englische Grundformen in
 * Kleinbuchstaben, ohne "to" und ohne Artikel.
 */
function wq_emoji_tabelle(): array
{
    static $tabelle = null;
    if ($tabelle !== null) {
        return $tabelle;
    }
    return $tabelle = [
        // Essen und Trinken
        'apple' => '🍎', 'banana' => '🍌', 'bread' => '🍞', 'butter' => '🧈',
        'cake' => '🍰', 'carrot' => '🥕', 'cheese' => '🧀', 'chicken' => '🍗',
        'chocolate' => '🍫', 'coffee' => '☕', 'cookie' => '🍪', 'egg' => '🥚',
        'fish' => '🐟', 'food' => '🍽️', 'fruit' => '🍓', 'grapes' => '🍇',
        'honey' => '🍯', 'ice cream' => '🍦', 'juice' => '🧃', 'lemon' => '🍋',
        'meat' => '🥩', 'milk' => '🥛', 'onion' => '🧅', 'orange' => '🍊',
        'pear' => '🍐', 'pizza' => '🍕', 'potato' => '🥔', 'rice' => '🍚',
        'salad' => '🥗', 'salt' => '🧂', 'sandwich' => '🥪', 'soup' => '🍲',
        'strawberry' => '🍓', 'sugar' => '🍬', 'tea' => '🍵', 'tomato' => '🍅',
        'water' => '💧', 'vegetable' => '🥦', 'breakfast' => '🥐',
        'lunch' => '🍽️', 'dinner' => '🍝', 'cherry' => '🍒', 'corn' => '🌽',
        'mushroom' => '🍄', 'peach' => '🍑', 'popcorn' => '🍿',

        // Tiere
        'animal' => '🐾', 'bear' => '🐻', 'bee' => '🐝', 'bird' => '🐦',
        'butterfly' => '🦋', 'cat' => '🐱', 'chicken hen' => '🐔', 'cow' => '🐮',
        'dog' => '🐶', 'duck' => '🦆', 'elephant' => '🐘', 'fox' => '🦊',
        'frog' => '🐸', 'giraffe' => '🦒', 'hamster' => '🐹', 'horse' => '🐴',
        'lion' => '🦁', 'monkey' => '🐵', 'mouse' => '🐭', 'mice' => '🐭',
        'owl' => '🦉', 'penguin' => '🐧', 'pig' => '🐷', 'rabbit' => '🐰',
        'sheep' => '🐑', 'snake' => '🐍', 'spider' => '🕷️', 'tiger' => '🐯',
        'turtle' => '🐢', 'whale' => '🐳', 'wolf' => '🐺', 'shark' => '🦈',
        'bat' => '🦇', 'goat' => '🐐', 'hedgehog' => '🦔',

        // Schule
        'book' => '📕', 'blackboard' => '🧑‍🏫', 'chair' => '🪑', 'class' => '🧑‍🎓',
        'classroom' => '🏫', 'desk' => '🪑', 'eraser' => '🧽', 'exam' => '📝',
        'exercise' => '📝', 'glue' => '🩹', 'homework' => '📓', 'lesson' => '📖',
        'library' => '📚', 'notebook' => '📓', 'page' => '📄', 'pen' => '🖊️',
        'pencil' => '✏️', 'question' => '❓', 'answer' => '💬', 'ruler' => '📏',
        'school' => '🏫', 'scissors' => '✂️', 'student' => '🧑‍🎓',
        'teacher' => '🧑‍🏫', 'test' => '📝', 'word' => '🔤', 'number' => '🔢',
        'break' => '⏸️', 'timetable' => '🗓️', 'backpack' => '🎒',

        // Menschen und Familie
        'aunt' => '👩', 'baby' => '👶', 'boy' => '👦', 'brother' => '👦',
        'child' => '🧒', 'daughter' => '👧', 'family' => '👨‍👩‍👧‍👦', 'father' => '👨',
        'friend' => '🧑‍🤝‍🧑', 'girl' => '👧', 'grandfather' => '👴',
        'grandmother' => '👵', 'man' => '👨', 'mother' => '👩', 'parents' => '👫',
        'people' => '👥', 'sister' => '👧', 'son' => '👦', 'uncle' => '👨',
        'woman' => '👩', 'neighbour' => '🏘️', 'neighbor' => '🏘️',

        // Körper und Gesundheit
        'arm' => '💪', 'back' => '🔙', 'blood' => '🩸', 'body' => '🧍',
        'doctor' => '🧑‍⚕️', 'ear' => '👂', 'eye' => '👁️', 'face' => '😀',
        'finger' => '👆', 'foot' => '🦶', 'hair' => '💇', 'hand' => '✋',
        'hands' => '🙌', 'head' => '🧠', 'heart' => '❤️', 'hospital' => '🏥',
        'leg' => '🦵', 'medicine' => '💊', 'mouth' => '👄', 'nose' => '👃',
        'nurse' => '🧑‍⚕️', 'pain' => '🤕', 'sick' => '🤒', 'tooth' => '🦷',
        'teeth' => '🦷', 'toothbrush' => '🪥', 'toothpaste' => '🧴',
        'soap' => '🧼', 'towel' => '🧻', 'shower' => '🚿', 'bath' => '🛁',
        'shampoo' => '🧴', 'fingernail' => '💅', 'fingernails' => '💅',
        'nail' => '💅', 'tissue' => '🤧', 'germ' => '🦠', 'germs' => '🦠',
        'hygiene' => '🧼', 'disinfectant' => '🧴', 'plaster' => '🩹',
        'bandage' => '🩹', 'fever' => '🌡️', 'cough' => '😷',

        // Wohnen
        'bed' => '🛏️', 'bedroom' => '🛏️', 'bathroom' => '🛁', 'door' => '🚪',
        'floor' => '🪜', 'garden' => '🌳', 'garage' => '🚗', 'house' => '🏠',
        'home' => '🏡', 'kitchen' => '🍳', 'lamp' => '💡', 'key' => '🔑',
        'mirror' => '🪞', 'room' => '🚪', 'roof' => '🏠', 'sofa' => '🛋️',
        'stairs' => '🪜', 'table' => '🪑', 'television' => '📺', 'tv' => '📺',
        'wall' => '🧱', 'window' => '🪟', 'flat' => '🏢', 'cupboard' => '🗄️',

        // Kleidung
        'cap' => '🧢', 'clothes' => '👕', 'coat' => '🧥', 'dress' => '👗',
        'glasses' => '👓', 'gloves' => '🧤', 'hat' => '👒', 'jacket' => '🧥',
        'jeans' => '👖', 'scarf' => '🧣', 'shirt' => '👔', 'shoe' => '👟',
        'shoes' => '👟', 'skirt' => '👗', 'socks' => '🧦', 'trousers' => '👖',
        't-shirt' => '👕',

        // Freizeit und Sport
        'ball' => '⚽', 'bike' => '🚲', 'bicycle' => '🚲', 'camera' => '📷',
        'computer' => '💻', 'dance' => '💃', 'film' => '🎬', 'movie' => '🎬',
        'game' => '🎮', 'guitar' => '🎸', 'holiday' => '🏖️', 'music' => '🎵',
        'party' => '🎉', 'phone' => '📱', 'picture' => '🖼️', 'song' => '🎶',
        'sport' => '🏃', 'swimming' => '🏊', 'football' => '⚽', 'tennis' => '🎾',
        'basketball' => '🏀', 'run' => '🏃', 'swim' => '🏊', 'play' => '🎮',
        'sing' => '🎤', 'read' => '📖', 'write' => '✍️', 'draw' => '🎨',

        // Unterwegs
        'airport' => '✈️', 'boat' => '⛵', 'bus' => '🚌', 'car' => '🚗',
        'city' => '🏙️', 'map' => '🗺️', 'plane' => '✈️', 'road' => '🛣️',
        'ship' => '🚢', 'shop' => '🏪', 'station' => '🚉', 'street' => '🛣️',
        'ticket' => '🎫', 'train' => '🚂', 'village' => '🏘️', 'bridge' => '🌉',
        'money' => '💶', 'suitcase' => '🧳',

        // Natur und Wetter
        'beach' => '🏖️', 'cloud' => '☁️', 'cold' => '🥶', 'fire' => '🔥',
        'flower' => '🌸', 'forest' => '🌲', 'grass' => '🌱', 'hot' => '🥵',
        'ice' => '🧊', 'island' => '🏝️', 'lake' => '🏞️', 'leaf' => '🍃',
        'moon' => '🌙', 'mountain' => '⛰️', 'rain' => '🌧️', 'river' => '🏞️',
        'sea' => '🌊', 'sky' => '🌌', 'snow' => '❄️', 'star' => '⭐',
        'storm' => '⛈️', 'sun' => '☀️', 'tree' => '🌳', 'weather' => '🌤️',
        'wind' => '💨', 'world' => '🌍', 'earth' => '🌍',

        // Zeit
        'autumn' => '🍂', 'clock' => '🕐', 'day' => '☀️', 'evening' => '🌆',
        'month' => '🗓️', 'morning' => '🌅', 'night' => '🌙', 'spring' => '🌷',
        'summer' => '☀️', 'time' => '⏰', 'today' => '📅', 'week' => '🗓️',
        'winter' => '⛄', 'year' => '📆', 'birthday' => '🎂', 'clean' => '✨',

        // Handlungen und Eigenschaften
        'eat' => '🍽️', 'drink' => '🥤', 'sleep' => '😴', 'walk' => '🚶',
        'talk' => '💬', 'speak' => '🗣️', 'listen' => '👂', 'look' => '👀',
        'see' => '👀', 'think' => '🤔', 'help' => '🤝', 'work' => '💼',
        'buy' => '🛒', 'give' => '🎁', 'find' => '🔍', 'open' => '🔓',
        'close' => '🔒', 'wash' => '🧼', 'brush' => '🪥', 'cook' => '🍳',
        'laugh' => '😄', 'cry' => '😢', 'love' => '❤️', 'happy' => '😊',
        'sad' => '😢', 'angry' => '😠', 'tired' => '🥱', 'big' => '🔺',
        'small' => '🔻', 'good' => '👍', 'bad' => '👎', 'new' => '🆕',
        'old' => '👴', 'fast' => '⚡', 'slow' => '🐌',
        'dangerous' => '⚠️', 'quiet' => '🤫', 'loud' => '📢',
    ];
}

/**
 * Normalisiert ein englisches Stichwort für die Suche in der Tabelle.
 * "to bring up" wird zu "bring up", "the apple" zu "apple".
 */
function wq_emoji_schluessel(string $en): string
{
    $wort = mb_strtolower(trim($en));
    $wort = (string) preg_replace('/^(to|the|a|an)\s+/u', '', $wort);
    $wort = (string) preg_replace('/\s*\(.*\)\s*/u', ' ', $wort);   // Klammern weg
    return trim((string) preg_replace('/\s+/u', ' ', $wort));
}

/**
 * Schlägt ein Emoji vor. Gibt null zurück, wenn nichts Passendes bekannt ist,
 * denn ein falsches Emoji verwirrt mehr als gar keines.
 */
function wq_emoji_vorschlag(string $en): ?string
{
    $tabelle = wq_emoji_tabelle();
    $schluessel = wq_emoji_schluessel($en);
    if ($schluessel === '') {
        return null;
    }
    if (isset($tabelle[$schluessel])) {
        return $tabelle[$schluessel];
    }
    /* Zusammensetzungen wie "school bus": Im Englischen steht das Grundwort
       hinten, deshalb zählt **nur das letzte Wort**, nicht irgendeines.
       Die strengere Regel ist hier bewusst gewählt. Mit einer grosszügigen
       Suche wurde aus "hand sanitizer" ein ✋ und aus "to look after" ein 👀,
       beides führt in die Irre. Lieber kein Vorschlag als ein falscher. */
    $teile = explode(' ', $schluessel);
    if (count($teile) > 1) {
        $grundwort = $teile[count($teile) - 1];
        if (isset($tabelle[$grundwort])) {
            return $tabelle[$grundwort];
        }
    }
    return null;
}

/** Emoji für die Auswahlhilfe im Editor, nach Themen sortiert. */
function wq_emoji_auswahl(): array
{
    $gesehen = [];
    foreach (wq_emoji_tabelle() as $emoji) {
        $gesehen[$emoji] = true;
    }
    return array_keys($gesehen);
}
